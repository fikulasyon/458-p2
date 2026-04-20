<?php

use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\SurveyVersion;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('blocks non-admin users from survey architect routes', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get('/admin/surveys')
        ->assertForbidden();
});

it('allows admin users to load survey architect index', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get('/admin/surveys')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/surveys/index'));
});

it('lets admin create, model, and publish a draft survey version', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->post('/admin/surveys', [
            'title' => 'Team Project Health',
            'description' => 'Quick adaptive pulse survey',
        ])
        ->assertRedirect();

    $survey = Survey::query()->where('title', 'Team Project Health')->firstOrFail();
    $version = SurveyVersion::query()->where('survey_id', $survey->id)->where('version_number', 1)->firstOrFail();

    $this->actingAs($admin)
        ->post("/admin/surveys/{$survey->id}/versions/{$version->id}/questions", [
            'stable_key' => 'q_entry',
            'title' => 'Do you feel blocked this week?',
        ])
        ->assertRedirect();

    $entryQuestion = SurveyQuestion::query()->where('survey_version_id', $version->id)->where('stable_key', 'q_entry')->firstOrFail();

    $this->actingAs($admin)
        ->post("/admin/surveys/{$survey->id}/versions/{$version->id}/questions", [
            'stable_key' => 'q_followup',
            'title' => 'What is the blocker?',
        ])
        ->assertRedirect();

    $followupQuestion = SurveyQuestion::query()->where('survey_version_id', $version->id)->where('stable_key', 'q_followup')->firstOrFail();

    $this->actingAs($admin)
        ->post("/admin/surveys/{$survey->id}/versions/{$version->id}/questions/{$entryQuestion->id}/options", [
            'value' => 'yes',
            'label' => 'Yes',
        ])
        ->assertRedirect();

    $yesOptionId = $entryQuestion->options()->where('value', 'yes')->value('id');

    $this->actingAs($admin)
        ->post("/admin/surveys/{$survey->id}/versions/{$version->id}/edges", [
            'from_option_id' => $yesOptionId,
            'to_question_id' => $followupQuestion->id,
        ])
        ->assertRedirect();

    $this->actingAs($admin)
        ->post("/admin/surveys/{$survey->id}/versions/{$version->id}/publish")
        ->assertRedirect("/admin/surveys/{$survey->id}/versions/{$version->id}");

    expect($survey->fresh()->active_version_id)->toBe($version->id);
    expect($version->fresh()->status)->toBe('published')
        ->and($version->fresh()->is_active)->toBeTrue();

    $this->assertDatabaseHas('question_edges', [
        'survey_version_id' => $version->id,
        'from_question_id' => $entryQuestion->id,
        'to_question_id' => $followupQuestion->id,
        'condition_value' => 'yes',
    ]);
});

it('removes invalid edges when source options change and deletes question nodes', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->post('/admin/surveys', [
            'title' => 'Node Retention Survey',
            'description' => 'Edge cleanup behavior',
        ])
        ->assertRedirect();

    $survey = Survey::query()->where('title', 'Node Retention Survey')->firstOrFail();
    $version = SurveyVersion::query()->where('survey_id', $survey->id)->where('version_number', 1)->firstOrFail();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/questions", [
        'stable_key' => 'q_source',
        'title' => 'Source question',
    ])->assertRedirect();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/questions", [
        'stable_key' => 'q_target',
        'title' => 'Target question',
    ])->assertRedirect();

    $source = SurveyQuestion::query()->where('survey_version_id', $version->id)->where('stable_key', 'q_source')->firstOrFail();
    $target = SurveyQuestion::query()->where('survey_version_id', $version->id)->where('stable_key', 'q_target')->firstOrFail();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/questions/{$source->id}/options", [
        'value' => 'a',
        'label' => 'Option A',
    ])->assertRedirect();

    $option = $source->options()->where('value', 'a')->firstOrFail();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/edges", [
        'from_option_id' => $option->id,
        'to_question_id' => $target->id,
    ])->assertRedirect();

    $this->assertDatabaseHas('question_edges', [
        'survey_version_id' => $version->id,
        'from_question_id' => $source->id,
        'to_question_id' => $target->id,
        'condition_value' => 'a',
    ]);

    $this->actingAs($admin)->patch("/admin/surveys/{$survey->id}/versions/{$version->id}/questions/{$source->id}/options/{$option->id}", [
        'value' => 'b',
        'label' => 'Option B',
    ])->assertRedirect();

    $this->assertDatabaseMissing('question_edges', [
        'survey_version_id' => $version->id,
        'from_question_id' => $source->id,
        'condition_value' => 'a',
    ]);

    $this->actingAs($admin)->delete("/admin/surveys/{$survey->id}/versions/{$version->id}/questions/{$target->id}")
        ->assertRedirect();

    $this->assertDatabaseMissing('survey_questions', [
        'id' => $target->id,
    ]);
});

it('supports result nodes as edge targets and blocks options on result nodes', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->post('/admin/surveys', [
            'title' => 'Career Outcome Survey',
            'description' => 'Result node behavior',
        ])
        ->assertRedirect();

    $survey = Survey::query()->where('title', 'Career Outcome Survey')->firstOrFail();
    $version = SurveyVersion::query()->where('survey_id', $survey->id)->where('version_number', 1)->firstOrFail();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/questions", [
        'stable_key' => 'q_interest',
        'title' => 'Do you like design work?',
    ])->assertRedirect();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/questions", [
        'stable_key' => 'r_designer',
        'title' => 'Recommended path: Product Designer',
        'node_kind' => 'result',
    ])->assertRedirect();

    $question = SurveyQuestion::query()->where('survey_version_id', $version->id)->where('stable_key', 'q_interest')->firstOrFail();
    $result = SurveyQuestion::query()->where('survey_version_id', $version->id)->where('stable_key', 'r_designer')->firstOrFail();

    expect($result->type)->toBe('result');

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/questions/{$question->id}/options", [
        'value' => 'yes',
        'label' => 'Yes',
    ])->assertRedirect();

    $yesOptionId = $question->options()->where('value', 'yes')->value('id');

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/edges", [
        'from_option_id' => $yesOptionId,
        'to_question_id' => $result->id,
    ])->assertRedirect();

    $this->assertDatabaseHas('question_edges', [
        'survey_version_id' => $version->id,
        'from_question_id' => $question->id,
        'to_question_id' => $result->id,
        'condition_value' => 'yes',
    ]);

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/questions/{$result->id}/options", [
        'value' => 'unexpected',
        'label' => 'Should Fail',
    ])->assertSessionHasErrors('option');
});

it('replaces existing edge when adding another edge from the same option', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->post('/admin/surveys', [
            'title' => 'Single Option Route Survey',
            'description' => 'Option to one target only',
        ])
        ->assertRedirect();

    $survey = Survey::query()->where('title', 'Single Option Route Survey')->firstOrFail();
    $version = SurveyVersion::query()->where('survey_id', $survey->id)->where('version_number', 1)->firstOrFail();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/questions", [
        'stable_key' => 'q_source',
        'title' => 'Source',
    ])->assertRedirect();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/questions", [
        'stable_key' => 'q_target_one',
        'title' => 'Target One',
    ])->assertRedirect();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/questions", [
        'stable_key' => 'q_target_two',
        'title' => 'Target Two',
    ])->assertRedirect();

    $source = SurveyQuestion::query()->where('survey_version_id', $version->id)->where('stable_key', 'q_source')->firstOrFail();
    $targetOne = SurveyQuestion::query()->where('survey_version_id', $version->id)->where('stable_key', 'q_target_one')->firstOrFail();
    $targetTwo = SurveyQuestion::query()->where('survey_version_id', $version->id)->where('stable_key', 'q_target_two')->firstOrFail();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/questions/{$source->id}/options", [
        'value' => 'yes',
        'label' => 'Yes',
    ])->assertRedirect();

    $optionId = $source->options()->where('value', 'yes')->value('id');

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/edges", [
        'from_option_id' => $optionId,
        'to_question_id' => $targetOne->id,
    ])->assertRedirect();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/edges", [
        'from_option_id' => $optionId,
        'to_question_id' => $targetTwo->id,
    ])->assertRedirect();

    $this->assertDatabaseMissing('question_edges', [
        'survey_version_id' => $version->id,
        'from_question_id' => $source->id,
        'to_question_id' => $targetOne->id,
        'condition_value' => 'yes',
    ]);

    $this->assertDatabaseHas('question_edges', [
        'survey_version_id' => $version->id,
        'from_question_id' => $source->id,
        'to_question_id' => $targetTwo->id,
        'condition_value' => 'yes',
    ]);
});

it('supports linear survey builders for rating and open-ended types', function (string $surveyType, string $expectedQuestionType) {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->post('/admin/surveys', [
            'title' => "Linear {$surveyType} Survey",
            'description' => 'Linear ordering test',
            'survey_type' => $surveyType,
        ])
        ->assertRedirect();

    $survey = Survey::query()->where('title', "Linear {$surveyType} Survey")->firstOrFail();
    $version = SurveyVersion::query()->where('survey_id', $survey->id)->where('version_number', 1)->firstOrFail();

    $this->actingAs($admin)
        ->get("/admin/surveys/{$survey->id}/versions/{$version->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/surveys/edit-linear-version')
            ->where('survey.survey_type', $surveyType),
        );

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/questions", [
        'stable_key' => 'q_one',
        'title' => 'First linear question',
    ])->assertRedirect();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/questions", [
        'stable_key' => 'q_two',
        'title' => 'Second linear question',
    ])->assertRedirect();

    $first = SurveyQuestion::query()->where('survey_version_id', $version->id)->where('stable_key', 'q_one')->firstOrFail();
    $second = SurveyQuestion::query()->where('survey_version_id', $version->id)->where('stable_key', 'q_two')->firstOrFail();

    expect($first->type)->toBe($expectedQuestionType)
        ->and($second->type)->toBe($expectedQuestionType);

    $this->actingAs($admin)->patch("/admin/surveys/{$survey->id}/versions/{$version->id}/question-order", [
        'question_ids' => [$second->id, $first->id],
    ])->assertRedirect();

    expect($second->fresh()->order_index)->toBeLessThan($first->fresh()->order_index);

    if ($surveyType === 'rating') {
        $this->actingAs($admin)->patch("/admin/surveys/{$survey->id}/versions/{$version->id}/rating-scale", [
            'count' => 4,
            'labels' => ['Poor', 'Average', 'Good', 'Excellent'],
        ])->assertRedirect();

        $scale = $version->fresh()->schema_meta['rating_scale'] ?? null;
        expect($scale)->not()->toBeNull()
            ->and($scale['count'])->toBe(4)
            ->and($scale['labels'])->toBe(['Poor', 'Average', 'Good', 'Excellent']);
    }

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/questions/{$first->id}/options", [
        'value' => 'x',
        'label' => 'x',
    ])->assertSessionHasErrors('survey');

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$version->id}/edges", [
        'from_option_id' => 999999,
        'to_question_id' => $second->id,
    ])->assertSessionHasErrors('survey');

    $this->actingAs($admin)->delete("/admin/surveys/{$survey->id}/versions/{$version->id}/questions/{$first->id}")
        ->assertRedirect();

    $this->assertDatabaseMissing('survey_questions', [
        'id' => $first->id,
    ]);
})->with([
    ['rating', 'rating'],
    ['open_ended', 'text'],
]);

it('allows deleting a draft version while keeping the survey', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->post('/admin/surveys', [
            'title' => 'Draft Delete Survey',
            'description' => 'Has published and draft versions',
            'survey_type' => 'multiple_choice',
        ])
        ->assertRedirect();

    $survey = Survey::query()->where('title', 'Draft Delete Survey')->firstOrFail();
    $v1 = SurveyVersion::query()->where('survey_id', $survey->id)->where('version_number', 1)->firstOrFail();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$v1->id}/questions", [
        'stable_key' => 'q_start',
        'title' => 'Start',
    ])->assertRedirect();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$v1->id}/questions/".SurveyQuestion::query()->where('survey_version_id', $v1->id)->where('stable_key', 'q_start')->value('id')."/options", [
        'value' => 'go',
        'label' => 'Go',
    ])->assertRedirect();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$v1->id}/publish")
        ->assertRedirect();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$v1->id}/clone")
        ->assertRedirect();

    $draft = SurveyVersion::query()
        ->where('survey_id', $survey->id)
        ->where('status', 'draft')
        ->orderByDesc('version_number')
        ->firstOrFail();

    $this->actingAs($admin)
        ->delete("/admin/surveys/{$survey->id}/versions/{$draft->id}")
        ->assertRedirect("/admin/surveys/{$survey->id}/versions/{$v1->id}");

    $this->assertDatabaseMissing('survey_versions', [
        'id' => $draft->id,
    ]);

    $this->assertDatabaseHas('surveys', [
        'id' => $survey->id,
    ]);
});

it('allows deleting non-active published versions', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->post('/admin/surveys', [
            'title' => 'Delete Non Active Version Survey',
            'description' => 'Delete published non-active version',
            'survey_type' => 'multiple_choice',
        ])
        ->assertRedirect();

    $survey = Survey::query()->where('title', 'Delete Non Active Version Survey')->firstOrFail();
    $v1 = SurveyVersion::query()->where('survey_id', $survey->id)->where('version_number', 1)->firstOrFail();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$v1->id}/questions", [
        'stable_key' => 'q_start',
        'title' => 'Start',
    ])->assertRedirect();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$v1->id}/publish")
        ->assertRedirect();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$v1->id}/clone")
        ->assertRedirect();

    $v2 = SurveyVersion::query()
        ->where('survey_id', $survey->id)
        ->where('status', 'draft')
        ->orderByDesc('version_number')
        ->firstOrFail();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$v2->id}/publish")
        ->assertRedirect();

    $v1 = $v1->fresh();
    expect($v1->is_active)->toBeFalse();

    $this->actingAs($admin)
        ->delete("/admin/surveys/{$survey->id}/versions/{$v1->id}")
        ->assertRedirect("/admin/surveys/{$survey->id}/versions/{$v2->id}");

    $this->assertDatabaseMissing('survey_versions', [
        'id' => $v1->id,
    ]);
});

it('blocks deleting active versions', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->post('/admin/surveys', [
            'title' => 'Active Version Guard Survey',
            'description' => 'Cannot delete active',
            'survey_type' => 'multiple_choice',
        ])
        ->assertRedirect();

    $survey = Survey::query()->where('title', 'Active Version Guard Survey')->firstOrFail();
    $v1 = SurveyVersion::query()->where('survey_id', $survey->id)->where('version_number', 1)->firstOrFail();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$v1->id}/questions", [
        'stable_key' => 'q_start',
        'title' => 'Start',
    ])->assertRedirect();

    $this->actingAs($admin)->post("/admin/surveys/{$survey->id}/versions/{$v1->id}/publish")
        ->assertRedirect();

    $this->actingAs($admin)
        ->delete("/admin/surveys/{$survey->id}/versions/{$v1->id}")
        ->assertSessionHasErrors('version_delete');

    $this->assertDatabaseHas('survey_versions', [
        'id' => $v1->id,
    ]);
});

it('allows admin to delete a survey from the architect list', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->post('/admin/surveys', [
            'title' => 'Disposable Survey',
            'description' => 'Will be deleted',
            'survey_type' => 'multiple_choice',
        ])
        ->assertRedirect();

    $survey = Survey::query()->where('title', 'Disposable Survey')->firstOrFail();

    $this->actingAs($admin)
        ->delete("/admin/surveys/{$survey->id}")
        ->assertRedirect('/admin/surveys');

    $this->assertDatabaseMissing('surveys', [
        'id' => $survey->id,
    ]);
});

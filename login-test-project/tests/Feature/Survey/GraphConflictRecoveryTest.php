<?php

use App\Models\QuestionEdge;
use App\Models\QuestionOption;
use App\Models\Survey;
use App\Models\SurveyAnswer;
use App\Models\SurveyQuestion;
use App\Models\SurveySession;
use App\Models\SurveyVersion;
use App\Models\User;
use App\Services\GraphConflictResolver;
use App\Services\SchemaVersioningService;
use App\Services\SessionRecoveryService;
use App\Services\SurveyGraphValidator;
use App\Services\SurveyVisibilityEngine;

function createVersionWithGraph(Survey $survey, int $versionNumber, array $questionDefs, array $edgeDefs): array
{
    $version = SurveyVersion::query()->create([
        'survey_id' => $survey->id,
        'version_number' => $versionNumber,
        'status' => 'draft',
        'is_active' => false,
    ]);

    $questions = collect();

    foreach ($questionDefs as $index => $definition) {
        $question = SurveyQuestion::query()->create([
            'survey_version_id' => $version->id,
            'stable_key' => $definition['stable_key'],
            'title' => $definition['title'] ?? strtoupper($definition['stable_key']),
            'type' => $definition['type'] ?? 'text',
            'is_entry' => $definition['is_entry'] ?? false,
            'order_index' => $definition['order_index'] ?? ($index + 1),
            'metadata' => $definition['metadata'] ?? null,
        ]);

        foreach (($definition['options'] ?? []) as $optionIndex => $option) {
            QuestionOption::query()->create([
                'question_id' => $question->id,
                'value' => $option['value'],
                'label' => $option['label'],
                'order_index' => $option['order_index'] ?? ($optionIndex + 1),
            ]);
        }

        $questions->put($definition['stable_key'], $question);
    }

    foreach ($edgeDefs as $index => $definition) {
        QuestionEdge::query()->create([
            'survey_version_id' => $version->id,
            'from_question_id' => $questions[$definition['from']]->id,
            'to_question_id' => $questions[$definition['to']]->id,
            'condition_type' => 'answer',
            'condition_operator' => $definition['operator'] ?? 'equals',
            'condition_value' => $definition['value'] ?? null,
            'priority' => $definition['priority'] ?? ($index + 1),
        ]);
    }

    return [$version, $questions];
}

it('detects DAG cycles in a survey version', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $survey = Survey::query()->create([
        'title' => 'Cycle Survey',
        'created_by' => $admin->id,
    ]);

    [$version] = createVersionWithGraph($survey, 1, [
        ['stable_key' => 'q1', 'type' => 'boolean', 'is_entry' => true],
        ['stable_key' => 'q2', 'type' => 'boolean'],
    ], [
        ['from' => 'q1', 'to' => 'q2', 'operator' => 'always'],
        ['from' => 'q2', 'to' => 'q1', 'operator' => 'always'],
    ]);

    $result = app(SurveyGraphValidator::class)->validateVersion($version);

    expect($result['is_valid'])->toBeFalse()
        ->and(collect($result['errors'])->pluck('code')->all())->toContain('cycle_detected');
});

it('calculates visibility from conditional DAG edges', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $survey = Survey::query()->create([
        'title' => 'Visibility Survey',
        'created_by' => $admin->id,
    ]);

    [$version] = createVersionWithGraph($survey, 1, [
        ['stable_key' => 'q_people', 'type' => 'boolean', 'is_entry' => true],
        ['stable_key' => 'q_yes_path', 'type' => 'text'],
        ['stable_key' => 'q_no_path', 'type' => 'text'],
    ], [
        ['from' => 'q_people', 'to' => 'q_yes_path', 'operator' => 'equals', 'value' => 'true'],
        ['from' => 'q_people', 'to' => 'q_no_path', 'operator' => 'equals', 'value' => 'false'],
    ]);

    $visibility = app(SurveyVisibilityEngine::class)->calculate($version, [
        'q_people' => 'true',
    ]);

    expect($visibility['visible_stable_keys'])->toContain('q_people')
        ->toContain('q_yes_path')
        ->not->toContain('q_no_path');
});

it('clones a survey version for draft editing', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $survey = Survey::query()->create([
        'title' => 'Clone Survey',
        'created_by' => $admin->id,
    ]);

    [$sourceVersion, $questions] = createVersionWithGraph($survey, 1, [
        ['stable_key' => 'q1', 'type' => 'boolean', 'is_entry' => true],
        [
            'stable_key' => 'q2',
            'type' => 'multiple_choice',
            'options' => [
                ['value' => 'a', 'label' => 'A'],
                ['value' => 'b', 'label' => 'B'],
            ],
        ],
    ], [
        ['from' => 'q1', 'to' => 'q2', 'operator' => 'equals', 'value' => 'true'],
    ]);

    $sourceVersion->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);
    $survey->update(['active_version_id' => $sourceVersion->id]);

    $cloned = app(SchemaVersioningService::class)->cloneDraftFromVersion($sourceVersion);

    expect($cloned->version_number)->toBe(2)
        ->and($cloned->status)->toBe('draft')
        ->and($cloned->base_version_id)->toBe($sourceVersion->id)
        ->and(SurveyQuestion::query()->where('survey_version_id', $cloned->id)->count())->toBe(2)
        ->and(QuestionOption::query()->whereIn('question_id', SurveyQuestion::query()->where('survey_version_id', $cloned->id)->pluck('id'))->count())->toBe(2)
        ->and(QuestionEdge::query()->where('survey_version_id', $cloned->id)->count())->toBe(1)
        ->and(QuestionEdge::query()->where('survey_version_id', $cloned->id)->first()->from_question_id)->not->toBe($questions['q1']->id);
});

it('detects conflict when current question is removed in a new version', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $taker = User::factory()->create();

    $survey = Survey::query()->create([
        'title' => 'Conflict Survey',
        'created_by' => $admin->id,
    ]);

    [$versionOne, $v1Questions] = createVersionWithGraph($survey, 1, [
        ['stable_key' => 'q1', 'type' => 'boolean', 'is_entry' => true],
        ['stable_key' => 'q2', 'type' => 'text'],
    ], [
        ['from' => 'q1', 'to' => 'q2', 'operator' => 'equals', 'value' => 'true'],
    ]);

    $versionOne->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);
    $survey->update(['active_version_id' => $versionOne->id]);

    $session = SurveySession::query()->create([
        'survey_id' => $survey->id,
        'user_id' => $taker->id,
        'started_version_id' => $versionOne->id,
        'current_version_id' => $versionOne->id,
        'current_question_id' => $v1Questions['q2']->id,
        'status' => 'in_progress',
    ]);

    SurveyAnswer::query()->create([
        'session_id' => $session->id,
        'question_stable_key' => 'q1',
        'question_id' => $v1Questions['q1']->id,
        'answer_value' => 'true',
        'valid_under_version_id' => $versionOne->id,
        'is_active' => true,
    ]);

    [$versionTwo] = createVersionWithGraph($survey, 2, [
        ['stable_key' => 'q1', 'type' => 'boolean', 'is_entry' => true],
    ], []);

    $versionTwo->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);
    $survey->update(['active_version_id' => $versionTwo->id]);

    $result = app(GraphConflictResolver::class)->detectConflict($session->fresh(), $versionTwo);

    expect($result['conflict_detected'])->toBeTrue()
        ->and($result['conflict_type'])->toBe('current_node_missing');
});

it('rolls back and replays to the next valid node when a passed node is deleted', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $taker = User::factory()->create();

    $survey = Survey::query()->create([
        'title' => 'Atomic Recovery Survey',
        'created_by' => $admin->id,
    ]);

    [$versionOne, $v1Questions] = createVersionWithGraph($survey, 1, [
        ['stable_key' => 'q1', 'type' => 'boolean', 'is_entry' => true],
        ['stable_key' => 'q_removed', 'type' => 'text'],
        ['stable_key' => 'q_next', 'type' => 'text'],
    ], [
        ['from' => 'q1', 'to' => 'q_removed', 'operator' => 'equals', 'value' => 'false'],
        ['from' => 'q1', 'to' => 'q_next', 'operator' => 'equals', 'value' => 'false'],
    ]);

    $versionOne->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);
    $survey->update(['active_version_id' => $versionOne->id]);

    $session = SurveySession::query()->create([
        'survey_id' => $survey->id,
        'user_id' => $taker->id,
        'started_version_id' => $versionOne->id,
        'current_version_id' => $versionOne->id,
        'current_question_id' => $v1Questions['q_removed']->id,
        'status' => 'in_progress',
    ]);

    SurveyAnswer::query()->create([
        'session_id' => $session->id,
        'question_stable_key' => 'q1',
        'question_id' => $v1Questions['q1']->id,
        'answer_value' => 'false',
        'valid_under_version_id' => $versionOne->id,
        'is_active' => true,
    ]);

    SurveyAnswer::query()->create([
        'session_id' => $session->id,
        'question_stable_key' => 'q_removed',
        'question_id' => $v1Questions['q_removed']->id,
        'answer_value' => 'legacy payload',
        'valid_under_version_id' => $versionOne->id,
        'is_active' => true,
    ]);

    [$versionTwo] = createVersionWithGraph($survey, 2, [
        ['stable_key' => 'q1', 'type' => 'boolean', 'is_entry' => true],
        ['stable_key' => 'q_next', 'type' => 'text'],
    ], [
        ['from' => 'q1', 'to' => 'q_next', 'operator' => 'equals', 'value' => 'false'],
    ]);

    $versionTwo->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);
    $survey->update(['active_version_id' => $versionTwo->id]);

    $result = app(SessionRecoveryService::class)->reconcileSession($session->fresh(), $versionTwo);

    $session->refresh();

    expect($result['recovery_strategy'])->toBe('rollback')
        ->and($result['dropped_answers'])->toContain('q_removed')
        ->and($session->status)->toBe('rolled_back')
        ->and($session->stable_node_key)->toBe('q_next')
        ->and($session->currentQuestion?->stable_key)->toBe('q_next')
        ->and(SurveyAnswer::query()->where('session_id', $session->id)->where('question_stable_key', 'q_removed')->value('is_active'))->toBeFalse();
});

it('drops fallback-node answer when a passed edge no longer matches', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $taker = User::factory()->create();

    $survey = Survey::query()->create([
        'title' => 'Rollback Survey',
        'created_by' => $admin->id,
    ]);

    [$versionOne, $v1Questions] = createVersionWithGraph($survey, 1, [
        ['stable_key' => 'q1', 'type' => 'boolean', 'is_entry' => true],
        ['stable_key' => 'q2', 'type' => 'boolean'],
        ['stable_key' => 'q3', 'type' => 'text'],
    ], [
        ['from' => 'q1', 'to' => 'q2', 'operator' => 'equals', 'value' => 'true'],
        ['from' => 'q2', 'to' => 'q3', 'operator' => 'equals', 'value' => 'yes'],
    ]);

    $versionOne->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);
    $survey->update(['active_version_id' => $versionOne->id]);

    $session = SurveySession::query()->create([
        'survey_id' => $survey->id,
        'user_id' => $taker->id,
        'started_version_id' => $versionOne->id,
        'current_version_id' => $versionOne->id,
        'current_question_id' => $v1Questions['q3']->id,
        'status' => 'in_progress',
    ]);

    SurveyAnswer::query()->create([
        'session_id' => $session->id,
        'question_stable_key' => 'q1',
        'question_id' => $v1Questions['q1']->id,
        'answer_value' => 'true',
        'valid_under_version_id' => $versionOne->id,
        'is_active' => true,
    ]);

    SurveyAnswer::query()->create([
        'session_id' => $session->id,
        'question_stable_key' => 'q2',
        'question_id' => $v1Questions['q2']->id,
        'answer_value' => 'yes',
        'valid_under_version_id' => $versionOne->id,
        'is_active' => true,
    ]);

    SurveyAnswer::query()->create([
        'session_id' => $session->id,
        'question_stable_key' => 'q3',
        'question_id' => $v1Questions['q3']->id,
        'answer_value' => 'already answered',
        'valid_under_version_id' => $versionOne->id,
        'is_active' => true,
    ]);

    [$versionTwo] = createVersionWithGraph($survey, 2, [
        ['stable_key' => 'q1', 'type' => 'boolean', 'is_entry' => true],
        ['stable_key' => 'q2', 'type' => 'boolean'],
        ['stable_key' => 'q3', 'type' => 'text'],
    ], [
        ['from' => 'q1', 'to' => 'q2', 'operator' => 'equals', 'value' => 'false'],
        ['from' => 'q2', 'to' => 'q3', 'operator' => 'equals', 'value' => 'yes'],
    ]);

    $versionTwo->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);
    $survey->update(['active_version_id' => $versionTwo->id]);

    $result = app(SessionRecoveryService::class)->reconcileSession($session->fresh(), $versionTwo);

    $session->refresh();

    expect($result['recovery_strategy'])->toBe('rollback')
        ->and($result['dropped_answers'])->toContain('q1')
        ->and($result['dropped_answers'])->toContain('q2')
        ->toContain('q3')
        ->and($session->status)->toBe('rolled_back')
        ->and($session->stable_node_key)->toBe('q1')
        ->and($session->currentQuestion?->stable_key)->toBe('q1')
        ->and(SurveyAnswer::query()->where('session_id', $session->id)->where('question_stable_key', 'q1')->value('is_active'))->toBeFalse();
});

it('replays to the new branch target when an answered edge changes destination', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $taker = User::factory()->create();

    $survey = Survey::query()->create([
        'title' => 'Edge Target Change Survey',
        'created_by' => $admin->id,
    ]);

    [$versionOne, $v1Questions] = createVersionWithGraph($survey, 1, [
        [
            'stable_key' => 'q1',
            'type' => 'multiple_choice',
            'is_entry' => true,
            'options' => [
                ['value' => 'a', 'label' => 'A'],
            ],
        ],
        [
            'stable_key' => 'q2',
            'type' => 'multiple_choice',
            'options' => [
                ['value' => 'c', 'label' => 'C'],
            ],
        ],
        ['stable_key' => 'q4', 'type' => 'text'],
        ['stable_key' => 'q5', 'type' => 'text'],
    ], [
        ['from' => 'q1', 'to' => 'q2', 'operator' => 'equals', 'value' => 'a'],
        ['from' => 'q2', 'to' => 'q4', 'operator' => 'equals', 'value' => 'c'],
    ]);

    $versionOne->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);
    $survey->update(['active_version_id' => $versionOne->id]);

    $session = SurveySession::query()->create([
        'survey_id' => $survey->id,
        'user_id' => $taker->id,
        'started_version_id' => $versionOne->id,
        'current_version_id' => $versionOne->id,
        'current_question_id' => $v1Questions['q4']->id,
        'status' => 'in_progress',
    ]);

    SurveyAnswer::query()->create([
        'session_id' => $session->id,
        'question_stable_key' => 'q1',
        'question_id' => $v1Questions['q1']->id,
        'answer_value' => 'a',
        'valid_under_version_id' => $versionOne->id,
        'is_active' => true,
    ]);

    SurveyAnswer::query()->create([
        'session_id' => $session->id,
        'question_stable_key' => 'q2',
        'question_id' => $v1Questions['q2']->id,
        'answer_value' => 'c',
        'valid_under_version_id' => $versionOne->id,
        'is_active' => true,
    ]);

    [$versionTwo] = createVersionWithGraph($survey, 2, [
        [
            'stable_key' => 'q1',
            'type' => 'multiple_choice',
            'is_entry' => true,
            'options' => [
                ['value' => 'a', 'label' => 'A'],
            ],
        ],
        [
            'stable_key' => 'q2',
            'type' => 'multiple_choice',
            'options' => [
                ['value' => 'c', 'label' => 'C'],
            ],
        ],
        ['stable_key' => 'q4', 'type' => 'text'],
        ['stable_key' => 'q5', 'type' => 'text'],
    ], [
        ['from' => 'q1', 'to' => 'q2', 'operator' => 'equals', 'value' => 'a'],
        ['from' => 'q2', 'to' => 'q5', 'operator' => 'equals', 'value' => 'c'],
    ]);

    $versionOne->update(['is_active' => false]);
    $versionTwo->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);
    $survey->update(['active_version_id' => $versionTwo->id]);

    $result = app(SessionRecoveryService::class)->reconcileSession($session->fresh(), $versionTwo);
    $session->refresh();

    expect($result['recovery_strategy'])->toBe('rollback')
        ->and($session->status)->toBe('rolled_back')
        ->and($session->stable_node_key)->toBe('q5')
        ->and($session->currentQuestion?->stable_key)->toBe('q5')
        ->and($result['dropped_answers'])->not->toContain('q1')
        ->and($result['dropped_answers'])->not->toContain('q2');
});

it('falls back to the source node when its answered option is removed', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $taker = User::factory()->create();

    $survey = Survey::query()->create([
        'title' => 'Option Removal Fallback Survey',
        'created_by' => $admin->id,
    ]);

    [$versionOne, $v1Questions] = createVersionWithGraph($survey, 1, [
        [
            'stable_key' => 'q1',
            'type' => 'multiple_choice',
            'is_entry' => true,
            'options' => [
                ['value' => 'a', 'label' => 'A'],
            ],
        ],
        [
            'stable_key' => 'q2',
            'type' => 'multiple_choice',
            'options' => [
                ['value' => 'c', 'label' => 'C'],
                ['value' => 'd', 'label' => 'D'],
            ],
        ],
        ['stable_key' => 'q4', 'type' => 'text'],
    ], [
        ['from' => 'q1', 'to' => 'q2', 'operator' => 'equals', 'value' => 'a'],
        ['from' => 'q2', 'to' => 'q4', 'operator' => 'equals', 'value' => 'c'],
    ]);

    $versionOne->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);
    $survey->update(['active_version_id' => $versionOne->id]);

    $session = SurveySession::query()->create([
        'survey_id' => $survey->id,
        'user_id' => $taker->id,
        'started_version_id' => $versionOne->id,
        'current_version_id' => $versionOne->id,
        'current_question_id' => $v1Questions['q4']->id,
        'status' => 'in_progress',
    ]);

    SurveyAnswer::query()->create([
        'session_id' => $session->id,
        'question_stable_key' => 'q1',
        'question_id' => $v1Questions['q1']->id,
        'answer_value' => 'a',
        'valid_under_version_id' => $versionOne->id,
        'is_active' => true,
    ]);

    SurveyAnswer::query()->create([
        'session_id' => $session->id,
        'question_stable_key' => 'q2',
        'question_id' => $v1Questions['q2']->id,
        'answer_value' => 'c',
        'valid_under_version_id' => $versionOne->id,
        'is_active' => true,
    ]);

    [$versionTwo] = createVersionWithGraph($survey, 2, [
        [
            'stable_key' => 'q1',
            'type' => 'multiple_choice',
            'is_entry' => true,
            'options' => [
                ['value' => 'a', 'label' => 'A'],
            ],
        ],
        [
            'stable_key' => 'q2',
            'type' => 'multiple_choice',
            'options' => [
                ['value' => 'd', 'label' => 'D'],
            ],
        ],
        ['stable_key' => 'q4', 'type' => 'text'],
    ], [
        ['from' => 'q1', 'to' => 'q2', 'operator' => 'equals', 'value' => 'a'],
        ['from' => 'q2', 'to' => 'q4', 'operator' => 'equals', 'value' => 'd'],
    ]);

    $versionOne->update(['is_active' => false]);
    $versionTwo->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);
    $survey->update(['active_version_id' => $versionTwo->id]);

    $result = app(SessionRecoveryService::class)->reconcileSession($session->fresh(), $versionTwo);
    $session->refresh();

    expect($result['recovery_strategy'])->toBe('rollback')
        ->and($session->status)->toBe('rolled_back')
        ->and($session->stable_node_key)->toBe('q2')
        ->and($session->currentQuestion?->stable_key)->toBe('q2')
        ->and($result['dropped_answers'])->toContain('q2')
        ->and(SurveyAnswer::query()->where('session_id', $session->id)->where('question_stable_key', 'q1')->value('is_active'))->toBeTrue()
        ->and(SurveyAnswer::query()->where('session_id', $session->id)->where('question_stable_key', 'q2')->value('is_active'))->toBeFalse();
});

it('restarts from the new entry when no stable replay node can be derived', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $taker = User::factory()->create();

    $survey = Survey::query()->create([
        'title' => 'Nuclear Restart Survey',
        'created_by' => $admin->id,
    ]);

    [$versionOne, $v1Questions] = createVersionWithGraph($survey, 1, [
        [
            'stable_key' => 'q1',
            'type' => 'multiple_choice',
            'is_entry' => true,
            'options' => [
                ['value' => 'a', 'label' => 'A'],
            ],
        ],
        ['stable_key' => 'q2', 'type' => 'text'],
    ], [
        ['from' => 'q1', 'to' => 'q2', 'operator' => 'equals', 'value' => 'a'],
    ]);

    $versionOne->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);
    $survey->update(['active_version_id' => $versionOne->id]);

    $session = SurveySession::query()->create([
        'survey_id' => $survey->id,
        'user_id' => $taker->id,
        'started_version_id' => $versionOne->id,
        'current_version_id' => $versionOne->id,
        'current_question_id' => $v1Questions['q2']->id,
        'status' => 'in_progress',
    ]);

    SurveyAnswer::query()->create([
        'session_id' => $session->id,
        'question_stable_key' => 'q1',
        'question_id' => $v1Questions['q1']->id,
        'answer_value' => 'a',
        'valid_under_version_id' => $versionOne->id,
        'is_active' => true,
    ]);

    [$versionTwo] = createVersionWithGraph($survey, 2, [
        [
            'stable_key' => 'q_new',
            'type' => 'multiple_choice',
            'is_entry' => true,
            'options' => [
                ['value' => 'x', 'label' => 'X'],
            ],
        ],
        ['stable_key' => 'q_new_next', 'type' => 'text'],
    ], [
        ['from' => 'q_new', 'to' => 'q_new_next', 'operator' => 'equals', 'value' => 'x'],
    ]);

    $versionOne->update(['is_active' => false]);
    $versionTwo->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);
    $survey->update(['active_version_id' => $versionTwo->id]);

    $result = app(SessionRecoveryService::class)->reconcileSession($session->fresh(), $versionTwo);
    $session->refresh();

    expect($result['recovery_strategy'])->toBe('rollback')
        ->and($session->status)->toBe('rolled_back')
        ->and($session->stable_node_key)->toBe('q_new')
        ->and($session->currentQuestion?->stable_key)->toBe('q_new')
        ->and($result['dropped_answers'])->toContain('q1')
        ->and(SurveyAnswer::query()->where('session_id', $session->id)->where('is_active', true)->count())->toBe(0);
});

it('does not flag conflicts for text or option-label edits when DAG logic is unchanged', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $taker = User::factory()->create();

    $survey = Survey::query()->create([
        'title' => 'Content Edit No-Conflict Survey',
        'created_by' => $admin->id,
    ]);

    [$versionOne, $v1Questions] = createVersionWithGraph($survey, 1, [
        [
            'stable_key' => 'q1',
            'type' => 'multiple_choice',
            'title' => 'Choose path',
            'is_entry' => true,
            'options' => [
                ['value' => 'yes', 'label' => 'Yes'],
                ['value' => 'no', 'label' => 'No'],
            ],
        ],
        ['stable_key' => 'q2', 'type' => 'text', 'title' => 'Follow-up'],
    ], [
        ['from' => 'q1', 'to' => 'q2', 'operator' => 'equals', 'value' => 'yes'],
    ]);

    $versionOne->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);
    $survey->update(['active_version_id' => $versionOne->id]);

    $session = SurveySession::query()->create([
        'survey_id' => $survey->id,
        'user_id' => $taker->id,
        'started_version_id' => $versionOne->id,
        'current_version_id' => $versionOne->id,
        'current_question_id' => $v1Questions['q2']->id,
        'status' => 'in_progress',
    ]);

    SurveyAnswer::query()->create([
        'session_id' => $session->id,
        'question_stable_key' => 'q1',
        'question_id' => $v1Questions['q1']->id,
        'answer_value' => 'yes',
        'valid_under_version_id' => $versionOne->id,
        'is_active' => true,
    ]);

    [$versionTwo] = createVersionWithGraph($survey, 2, [
        [
            'stable_key' => 'q1',
            'type' => 'multiple_choice',
            'title' => 'Choose your updated path',
            'is_entry' => true,
            'options' => [
                ['value' => 'yes', 'label' => 'Absolutely'],
                ['value' => 'no', 'label' => 'No'],
                ['value' => 'maybe', 'label' => 'Maybe'],
            ],
        ],
        ['stable_key' => 'q2', 'type' => 'text', 'title' => 'Follow-up'],
    ], [
        ['from' => 'q1', 'to' => 'q2', 'operator' => 'equals', 'value' => 'yes'],
    ]);

    $versionOne->update(['is_active' => false]);
    $versionTwo->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);
    $survey->update(['active_version_id' => $versionTwo->id]);

    $analysis = app(GraphConflictResolver::class)->detectConflict($session->fresh(), $versionTwo);
    expect($analysis['conflict_detected'])->toBeFalse()
        ->and($analysis['conflict_type'])->toBeNull()
        ->and($analysis['can_atomic_recovery'])->toBeTrue();

    $result = app(SessionRecoveryService::class)->reconcileSession($session->fresh(), $versionTwo);
    $session->refresh();

    expect($result['recovery_strategy'])->toBe('atomic_recovery')
        ->and($result['conflict_detected'])->toBeFalse()
        ->and($session->status)->toBe('in_progress')
        ->and($session->currentQuestion?->stable_key)->toBe('q2');
});

it('prevents zombie questions in recovered session state', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $taker = User::factory()->create();

    $survey = Survey::query()->create([
        'title' => 'Zombie Guard Survey',
        'created_by' => $admin->id,
    ]);

    [$versionOne, $v1Questions] = createVersionWithGraph($survey, 1, [
        ['stable_key' => 'q1', 'type' => 'boolean', 'is_entry' => true],
        ['stable_key' => 'q2', 'type' => 'text'],
    ], [
        ['from' => 'q1', 'to' => 'q2', 'operator' => 'equals', 'value' => 'true'],
    ]);

    $versionOne->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);
    $survey->update(['active_version_id' => $versionOne->id]);

    $session = SurveySession::query()->create([
        'survey_id' => $survey->id,
        'user_id' => $taker->id,
        'started_version_id' => $versionOne->id,
        'current_version_id' => $versionOne->id,
        'current_question_id' => $v1Questions['q2']->id,
        'status' => 'in_progress',
    ]);

    SurveyAnswer::query()->create([
        'session_id' => $session->id,
        'question_stable_key' => 'q1',
        'question_id' => $v1Questions['q1']->id,
        'answer_value' => 'false',
        'valid_under_version_id' => $versionOne->id,
        'is_active' => true,
    ]);

    [$versionTwo] = createVersionWithGraph($survey, 2, [
        ['stable_key' => 'q1', 'type' => 'boolean', 'is_entry' => true],
        ['stable_key' => 'q2', 'type' => 'text'],
    ], [
        ['from' => 'q1', 'to' => 'q2', 'operator' => 'equals', 'value' => 'true'],
    ]);

    $versionTwo->update(['status' => 'published', 'is_active' => true, 'published_at' => now()]);
    $survey->update(['active_version_id' => $versionTwo->id]);

    $result = app(SessionRecoveryService::class)->reconcileSession($session->fresh(), $versionTwo);

    expect($result['visible_questions'])->toContain('q1')
        ->not->toContain('q2')
        ->and($result['current_question']['stable_key'] ?? null)->toBe('q1');
});

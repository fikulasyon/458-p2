<?php

use App\Models\QuestionEdge;
use App\Models\QuestionOption;
use App\Models\Survey;
use App\Models\SurveyAnswer;
use App\Models\SurveyConflictLog;
use App\Models\SurveyQuestion;
use App\Models\SurveySession;
use App\Models\SurveyVersion;
use App\Models\User;

function createMonitoringVersion(Survey $survey, int $versionNumber, array $questionDefs, array $edgeDefs): array
{
    $version = SurveyVersion::query()->create([
        'survey_id' => $survey->id,
        'version_number' => $versionNumber,
        'status' => 'published',
        'is_active' => true,
        'published_at' => now(),
    ]);

    $questions = collect();
    foreach ($questionDefs as $index => $definition) {
        $question = SurveyQuestion::query()->create([
            'survey_version_id' => $version->id,
            'stable_key' => $definition['stable_key'],
            'title' => $definition['title'] ?? strtoupper($definition['stable_key']),
            'type' => $definition['type'] ?? 'multiple_choice',
            'is_entry' => $definition['is_entry'] ?? false,
            'order_index' => $definition['order_index'] ?? ($index + 1),
        ]);

        foreach (($definition['options'] ?? []) as $optionIndex => $option) {
            QuestionOption::query()->create([
                'question_id' => $question->id,
                'value' => (string) $option['value'],
                'label' => (string) $option['label'],
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

it('blocks non-admin users from survey monitoring endpoints', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get('/admin/surveys/monitor/conflicts')
        ->assertForbidden();
});

it('returns conflict feed for admins', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $taker = User::factory()->create();

    $survey = Survey::query()->create([
        'title' => 'Monitoring Survey',
        'survey_type' => 'multiple_choice',
        'created_by' => $admin->id,
    ]);

    [$v1] = createMonitoringVersion($survey, 1, [
        ['stable_key' => 'q1', 'is_entry' => true, 'options' => [['value' => 'a', 'label' => 'A']]],
    ], []);
    [$v2] = createMonitoringVersion($survey, 2, [
        ['stable_key' => 'q1', 'is_entry' => true, 'options' => [['value' => 'a', 'label' => 'A']]],
    ], []);

    $survey->update(['active_version_id' => $v2->id]);

    $session = SurveySession::query()->create([
        'survey_id' => $survey->id,
        'user_id' => $taker->id,
        'started_version_id' => $v1->id,
        'current_version_id' => $v2->id,
        'status' => 'conflict_recovered',
    ]);

    SurveyConflictLog::query()->create([
        'session_id' => $session->id,
        'old_version_id' => $v1->id,
        'new_version_id' => $v2->id,
        'conflict_type' => 'missing_answer_nodes',
        'recovery_strategy' => 'atomic_recovery',
        'details' => ['test' => true],
    ]);

    $this->actingAs($admin)
        ->get('/admin/surveys/monitor/conflicts')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.session_id', $session->id)
        ->assertJsonPath('data.0.conflict_type', 'missing_answer_nodes')
        ->assertJsonPath('data.0.recovery_strategy', 'atomic_recovery');
});

it('returns session mismatch diagnostics for admins', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $taker = User::factory()->create();

    $survey = Survey::query()->create([
        'title' => 'Mismatch Diagnostics Survey',
        'survey_type' => 'multiple_choice',
        'created_by' => $admin->id,
    ]);

    [$v1, $qV1] = createMonitoringVersion($survey, 1, [
        [
            'stable_key' => 'q_start',
            'is_entry' => true,
            'options' => [
                ['value' => 'yes', 'label' => 'Yes'],
                ['value' => 'no', 'label' => 'No'],
            ],
        ],
        ['stable_key' => 'q_next'],
    ], [
        ['from' => 'q_start', 'to' => 'q_next', 'value' => 'yes'],
    ]);

    [$v2] = createMonitoringVersion($survey, 2, [
        [
            'stable_key' => 'q_start',
            'is_entry' => true,
            'options' => [
                ['value' => 'yes', 'label' => 'Yes'],
                ['value' => 'no', 'label' => 'No'],
            ],
        ],
        ['stable_key' => 'q_next'],
    ], [
        ['from' => 'q_start', 'to' => 'q_next', 'value' => 'yes'],
    ]);

    $v1->update(['is_active' => false]);
    $survey->update(['active_version_id' => $v2->id]);

    $session = SurveySession::query()->create([
        'survey_id' => $survey->id,
        'user_id' => $taker->id,
        'started_version_id' => $v1->id,
        'current_version_id' => $v1->id,
        'current_question_id' => $qV1['q_next']->id,
        'status' => 'in_progress',
    ]);

    SurveyAnswer::query()->create([
        'session_id' => $session->id,
        'question_stable_key' => 'q_start',
        'question_id' => $qV1['q_start']->id,
        'answer_value' => 'yes',
        'valid_under_version_id' => $v1->id,
        'is_active' => true,
    ]);

    $this->actingAs($admin)
        ->get("/admin/surveys/monitor/sessions/{$session->id}")
        ->assertOk()
        ->assertJsonPath('session.id', $session->id)
        ->assertJsonPath('mismatch.detected', true)
        ->assertJsonPath('mismatch.predicted_recovery_strategy', 'atomic_recovery')
        ->assertJsonPath('invariants.current_node_visible_under_active', true);
});

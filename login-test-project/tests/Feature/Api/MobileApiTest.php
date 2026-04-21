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

function createMobilePublishedVersion(Survey $survey, int $versionNumber, array $questionDefs, array $edgeDefs): array
{
    $version = SurveyVersion::query()->create([
        'survey_id' => $survey->id,
        'version_number' => $versionNumber,
        'status' => 'published',
        'is_active' => true,
        'published_at' => now(),
        'schema_meta' => ['created_in_mobile_api_test' => true],
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
            'metadata' => $definition['metadata'] ?? null,
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

function mobileAuthHeader($testCase, User $user, string $password = 'password'): array
{
    $response = $testCase->postJson('/api/mobile/login', [
        'email' => $user->email,
        'password' => $password,
        'device_name' => 'android-emulator',
    ])->assertOk();

    $token = $response->json('access_token');

    expect($token)->toBeString()->not->toBeEmpty();

    return [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ];
}

/**
 * @param  array<int, string>  $values
 * @return array<int, string>
 */
function mobileSortedValues(array $values): array
{
    $sorted = array_values($values);
    sort($sorted);

    return $sorted;
}

/**
 * @param  array<int, string>  $expectedDroppedAnswers
 */
function assertMobileVersionSyncEnvelope(
    $response,
    int $fromVersionId,
    int $toVersionId,
    bool $conflictDetected,
    string $recoveryStrategy,
    array $expectedDroppedAnswers = [],
): void {
    $response
        ->assertJsonPath('version_sync.mismatch_detected', true)
        ->assertJsonPath('version_sync.from_version_id', $fromVersionId)
        ->assertJsonPath('version_sync.to_version_id', $toVersionId)
        ->assertJsonPath('version_sync.conflict_detected', $conflictDetected)
        ->assertJsonPath('version_sync.recovery_strategy', $recoveryStrategy);

    $actualDropped = mobileSortedValues((array) $response->json('version_sync.dropped_answers', []));
    $expectedDropped = mobileSortedValues($expectedDroppedAnswers);
    expect($actualDropped)->toBe($expectedDropped);
}

/**
 * @param  array<int, string>  $expectedDroppedAnswers
 */
function assertMobileConflictLogIntegrity(
    int $sessionId,
    int $oldVersionId,
    int $newVersionId,
    string $recoveryStrategy,
    array $expectedDroppedAnswers = [],
    ?string $expectedFallbackStableKey = null,
): SurveyConflictLog {
    $logs = SurveyConflictLog::query()
        ->where('session_id', $sessionId)
        ->orderBy('id')
        ->get();

    expect($logs)->toHaveCount(1);

    /** @var SurveyConflictLog $log */
    $log = $logs->last();
    $details = (array) ($log->details ?? []);

    expect($log->old_version_id)->toBe($oldVersionId)
        ->and($log->new_version_id)->toBe($newVersionId)
        ->and($log->recovery_strategy)->toBe($recoveryStrategy)
        ->and($log->conflict_type)->not->toBeNull();

    $actualDropped = mobileSortedValues((array) ($details['dropped_answers'] ?? []));
    $expectedDropped = mobileSortedValues($expectedDroppedAnswers);
    expect($actualDropped)->toBe($expectedDropped);

    if ($recoveryStrategy === 'rollback') {
        expect($details['replay_reason'] ?? null)->not->toBeNull();
        if ($expectedFallbackStableKey !== null) {
            expect($details['fallback_node_key'] ?? null)->toBe($expectedFallbackStableKey);
        }
    }

    return $log;
}

function mobileNormalizeValue(mixed $value): mixed
{
    if (is_bool($value) || is_int($value) || is_float($value)) {
        return $value;
    }

    if ($value === null) {
        return null;
    }

    if (is_array($value)) {
        return array_map(fn ($item) => mobileNormalizeValue($item), $value);
    }

    $stringValue = trim((string) $value);
    $lower = strtolower($stringValue);

    if ($lower === 'true') {
        return true;
    }

    if ($lower === 'false') {
        return false;
    }

    if (is_numeric($stringValue)) {
        return str_contains($stringValue, '.') ? (float) $stringValue : (int) $stringValue;
    }

    return $stringValue;
}

function mobileEdgeConditionPasses(string $operator, mixed $conditionValue, mixed $parentAnswer): bool
{
    if ($operator === 'always') {
        return true;
    }

    if ($parentAnswer === null) {
        return false;
    }

    $actual = mobileNormalizeValue($parentAnswer);
    $expected = mobileNormalizeValue($conditionValue);

    return match ($operator) {
        'equals' => $actual === $expected,
        'not_equals' => $actual !== $expected,
        default => false,
    };
}

/**
 * @param  array<string, mixed>  $answersByStable
 * @param  array<int, string>  $visibleStableKeys
 */
function assertNoZombieVisibleQuestionsForMultipleChoice(
    SurveyVersion $version,
    array $answersByStable,
    array $visibleStableKeys,
): void {
    $version->loadMissing(['questions', 'edges']);
    if (($version->survey->survey_type ?? 'multiple_choice') !== 'multiple_choice') {
        return;
    }

    $questionsById = $version->questions->keyBy('id');
    $reachable = [];

    $entryQuestions = $version->questions
        ->where('is_entry', true)
        ->values();

    if ($entryQuestions->isEmpty()) {
        $fallbackEntry = $version->questions->sortBy([['order_index', 'asc'], ['id', 'asc']])->first();
        if ($fallbackEntry) {
            $entryQuestions = collect([$fallbackEntry]);
        }
    }

    foreach ($entryQuestions as $entryQuestion) {
        $reachable[$entryQuestion->stable_key] = true;
    }

    $edges = $version->edges
        ->sortBy([['priority', 'asc'], ['id', 'asc']])
        ->values();

    $changed = true;
    while ($changed) {
        $changed = false;

        foreach ($edges as $edge) {
            $from = $questionsById->get($edge->from_question_id);
            $to = $questionsById->get($edge->to_question_id);

            if (! $from || ! $to) {
                continue;
            }

            if (! isset($reachable[$from->stable_key])) {
                continue;
            }

            $parentAnswer = $answersByStable[$from->stable_key] ?? null;
            if (! mobileEdgeConditionPasses(
                (string) ($edge->condition_operator ?? 'equals'),
                $edge->condition_value,
                $parentAnswer
            )) {
                continue;
            }

            if (! isset($reachable[$to->stable_key])) {
                $reachable[$to->stable_key] = true;
                $changed = true;
            }
        }
    }

    foreach ($visibleStableKeys as $stableKey) {
        expect(isset($reachable[$stableKey]))->toBeTrue();
    }
}

it('authenticates mobile users with bearer tokens and supports logout', function () {
    $user = User::factory()->create([
        'password' => 'password',
        'account_state' => 'Active',
    ]);

    $login = $this->postJson('/api/mobile/login', [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'pixel-8',
    ]);

    $login->assertOk()
        ->assertJsonStructure([
            'token_type',
            'access_token',
            'user' => ['id', 'email', 'name', 'is_admin'],
        ]);

    $authHeaders = [
        'Authorization' => 'Bearer '.$login->json('access_token'),
        'Accept' => 'application/json',
    ];

    $this->getJson('/api/mobile/me', $authHeaders)
        ->assertOk()
        ->assertJsonPath('user.id', $user->id);

    $this->postJson('/api/mobile/logout', [], $authHeaders)
        ->assertOk()
        ->assertJsonPath('status', 'ok');

    $this->getJson('/api/mobile/me', $authHeaders)
        ->assertUnauthorized();
});

it('lists published surveys and returns published schema', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();

    $publishedSurvey = Survey::query()->create([
        'title' => 'Which LoL Champion Are You?',
        'description' => 'Adaptive champion path',
        'survey_type' => 'multiple_choice',
        'created_by' => $admin->id,
    ]);

    [$publishedVersion] = createMobilePublishedVersion($publishedSurvey, 1, [
        [
            'stable_key' => 'q_role',
            'title' => 'Choose your lane',
            'type' => 'multiple_choice',
            'is_entry' => true,
            'options' => [
                ['value' => 'top', 'label' => 'Top'],
                ['value' => 'mid', 'label' => 'Mid'],
            ],
        ],
        [
            'stable_key' => 'r_garen',
            'title' => 'You are Garen',
            'type' => 'result',
        ],
        [
            'stable_key' => 'r_ahri',
            'title' => 'You are Ahri',
            'type' => 'result',
        ],
    ], [
        ['from' => 'q_role', 'to' => 'r_garen', 'value' => 'top'],
        ['from' => 'q_role', 'to' => 'r_ahri', 'value' => 'mid'],
    ]);

    $publishedSurvey->update(['active_version_id' => $publishedVersion->id]);

    $draftSurvey = Survey::query()->create([
        'title' => 'Draft only survey',
        'survey_type' => 'multiple_choice',
        'created_by' => $admin->id,
    ]);

    $draftVersion = SurveyVersion::query()->create([
        'survey_id' => $draftSurvey->id,
        'version_number' => 1,
        'status' => 'draft',
        'is_active' => false,
    ]);
    $draftSurvey->update(['active_version_id' => $draftVersion->id]);

    $headers = mobileAuthHeader($this, $user);

    $this->getJson('/api/mobile/surveys', $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $publishedSurvey->id)
        ->assertJsonPath('data.0.survey_type', 'multiple_choice');

    $this->getJson("/api/mobile/surveys/{$publishedSurvey->id}/schema", $headers)
        ->assertOk()
        ->assertJsonPath('survey.id', $publishedSurvey->id)
        ->assertJsonPath('version.id', $publishedVersion->id)
        ->assertJsonPath('version.status', 'published')
        ->assertJsonCount(3, 'schema.questions')
        ->assertJsonCount(2, 'schema.edges');
});

it('starts sessions, advances answers, and supports linear rating flow', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $headers = mobileAuthHeader($this, $user);

    $mcSurvey = Survey::query()->create([
        'title' => 'Champion Path',
        'survey_type' => 'multiple_choice',
        'created_by' => $admin->id,
    ]);

    [$mcVersion] = createMobilePublishedVersion($mcSurvey, 1, [
        [
            'stable_key' => 'q_role',
            'title' => 'Role?',
            'type' => 'multiple_choice',
            'is_entry' => true,
            'options' => [
                ['value' => 'jungle', 'label' => 'Jungle'],
                ['value' => 'support', 'label' => 'Support'],
            ],
        ],
        ['stable_key' => 'r_master_yi', 'title' => 'You are Master Yi', 'type' => 'result'],
        ['stable_key' => 'r_lulu', 'title' => 'You are Lulu', 'type' => 'result'],
    ], [
        ['from' => 'q_role', 'to' => 'r_master_yi', 'value' => 'jungle'],
        ['from' => 'q_role', 'to' => 'r_lulu', 'value' => 'support'],
    ]);
    $mcSurvey->update(['active_version_id' => $mcVersion->id]);

    $start = $this->postJson("/api/mobile/surveys/{$mcSurvey->id}/sessions/start", [], $headers)
        ->assertOk();

    $sessionId = $start->json('session.id');
    expect($sessionId)->toBeInt();

    $start->assertJsonPath('state.current_question.stable_key', 'q_role')
        ->assertJsonPath('state.can_complete', false);

    $decodedStart = json_decode($start->getContent(), false, 512, JSON_THROW_ON_ERROR);
    expect($decodedStart->state->answers)->toBeObject();

    $answer = $this->postJson("/api/mobile/sessions/{$sessionId}/answers", [
        'question_stable_key' => 'q_role',
        'answer_value' => 'support',
    ], $headers)->assertOk();

    $answer->assertJsonPath('state.current_question.stable_key', 'r_lulu')
        ->assertJsonPath('state.current_question.type', 'result')
        ->assertJsonPath('state.can_complete', true);

    $this->postJson("/api/mobile/sessions/{$sessionId}/complete", [], $headers)
        ->assertOk()
        ->assertJsonPath('session.status', 'completed')
        ->assertJsonPath('answer_summary.0.question_stable_key', 'q_role')
        ->assertJsonPath('answer_summary.0.answer_value', 'support');

    $ratingSurvey = Survey::query()->create([
        'title' => 'Rating survey',
        'survey_type' => 'rating',
        'created_by' => $admin->id,
    ]);

    [$ratingVersion] = createMobilePublishedVersion($ratingSurvey, 1, [
        [
            'stable_key' => 'q_fun',
            'title' => 'How fun is your main?',
            'type' => 'rating',
            'is_entry' => true,
            'order_index' => 1,
        ],
        [
            'stable_key' => 'q_skill',
            'title' => 'How hard is your champion?',
            'type' => 'rating',
            'order_index' => 2,
        ],
    ], []);
    $ratingVersion->update([
        'schema_meta' => [
            'rating_scale' => [
                'count' => 5,
                'labels' => ['Very Low', 'Low', 'Medium', 'High', 'Very High'],
            ],
        ],
    ]);
    $ratingSurvey->update(['active_version_id' => $ratingVersion->id]);

    $ratingSessionId = $this->postJson("/api/mobile/surveys/{$ratingSurvey->id}/sessions/start", [], $headers)
        ->assertOk()
        ->json('session.id');

    $this->postJson("/api/mobile/sessions/{$ratingSessionId}/answers", [
        'question_stable_key' => 'q_fun',
        'answer_value' => 4,
    ], $headers)
        ->assertOk()
        ->assertJsonPath('state.current_question.stable_key', 'q_skill')
        ->assertJsonPath('state.visible_questions.0', 'q_fun')
        ->assertJsonPath('state.visible_questions.1', 'q_skill');

    $this->postJson("/api/mobile/sessions/{$ratingSessionId}/answers", [
        'question_stable_key' => 'q_skill',
        'answer_value' => 5,
    ], $headers)->assertOk()
        ->assertJsonPath('state.can_complete', true);

    $this->postJson("/api/mobile/sessions/{$ratingSessionId}/complete", [], $headers)
        ->assertOk()
        ->assertJsonPath('session.status', 'completed')
        ->assertJsonPath('answer_summary.0.question_stable_key', 'q_fun')
        ->assertJsonPath('answer_summary.0.answer_value', 4)
        ->assertJsonPath('answer_summary.1.question_stable_key', 'q_skill')
        ->assertJsonPath('answer_summary.1.answer_value', 5);
});

it('returns conflict-aware version sync payload on schema mismatch', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $headers = mobileAuthHeader($this, $user);

    $survey = Survey::query()->create([
        'title' => 'Conflict survey',
        'survey_type' => 'multiple_choice',
        'created_by' => $admin->id,
    ]);

    [$versionOne, $v1Questions] = createMobilePublishedVersion($survey, 1, [
        [
            'stable_key' => 'q_start',
            'title' => 'Entry',
            'type' => 'multiple_choice',
            'is_entry' => true,
            'options' => [
                ['value' => 'yes', 'label' => 'Yes'],
                ['value' => 'no', 'label' => 'No'],
            ],
        ],
        ['stable_key' => 'q_branch', 'title' => 'Branch', 'type' => 'multiple_choice'],
    ], [
        ['from' => 'q_start', 'to' => 'q_branch', 'value' => 'yes'],
    ]);
    $survey->update(['active_version_id' => $versionOne->id]);

    $session = SurveySession::query()->create([
        'survey_id' => $survey->id,
        'user_id' => $user->id,
        'started_version_id' => $versionOne->id,
        'current_version_id' => $versionOne->id,
        'current_question_id' => $v1Questions['q_branch']->id,
        'status' => 'in_progress',
        'stable_node_key' => 'q_branch',
    ]);

    SurveyAnswer::query()->create([
        'session_id' => $session->id,
        'question_stable_key' => 'q_start',
        'question_id' => $v1Questions['q_start']->id,
        'answer_value' => 'yes',
        'valid_under_version_id' => $versionOne->id,
        'is_active' => true,
    ]);

    [$versionTwo] = createMobilePublishedVersion($survey, 2, [
        [
            'stable_key' => 'q_start',
            'title' => 'Entry',
            'type' => 'multiple_choice',
            'is_entry' => true,
            'options' => [
                ['value' => 'yes', 'label' => 'Yes'],
                ['value' => 'no', 'label' => 'No'],
            ],
        ],
        ['stable_key' => 'q_branch', 'title' => 'Branch', 'type' => 'multiple_choice'],
    ], [
        ['from' => 'q_start', 'to' => 'q_branch', 'value' => 'no'],
    ]);

    $versionOne->update(['is_active' => false]);
    $survey->update(['active_version_id' => $versionTwo->id]);

    $response = $this->getJson("/api/mobile/sessions/{$session->id}/state", $headers)
        ->assertOk()
        ->assertJsonPath('version_sync.conflict_type', 'current_node_unreachable')
        ->assertJsonPath('state.session_status', 'rolled_back')
        ->assertJsonPath('state.visible_questions.0', 'q_start')
        ->assertJsonMissingPath('state.visible_questions.1');

    assertMobileVersionSyncEnvelope($response, $versionOne->id, $versionTwo->id, true, 'rollback', ['q_start']);
    assertMobileConflictLogIntegrity($session->id, $versionOne->id, $versionTwo->id, 'rollback', ['q_start'], 'q_start');

    $session->refresh();
    expect($session->current_version_id)->toBe($versionTwo->id)
        ->and($session->currentQuestion?->stable_key)->toBe('q_start')
        ->and(SurveyAnswer::query()->where('session_id', $session->id)->where('is_active', true)->count())->toBe(0);

    assertNoZombieVisibleQuestionsForMultipleChoice(
        $versionTwo->fresh('survey'),
        (array) $response->json('state.answers', []),
        (array) $response->json('state.visible_questions', []),
    );
});

it('keeps session valid when only question text or option labels change', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $headers = mobileAuthHeader($this, $user);

    $survey = Survey::query()->create([
        'title' => 'Content Edit Sync Survey',
        'survey_type' => 'multiple_choice',
        'created_by' => $admin->id,
    ]);

    [$versionOne, $v1Questions] = createMobilePublishedVersion($survey, 1, [
        [
            'stable_key' => 'q_start',
            'title' => 'Pick one',
            'type' => 'multiple_choice',
            'is_entry' => true,
            'options' => [
                ['value' => 'yes', 'label' => 'Yes'],
                ['value' => 'no', 'label' => 'No'],
            ],
        ],
        ['stable_key' => 'q_next', 'title' => 'Next', 'type' => 'multiple_choice'],
    ], [
        ['from' => 'q_start', 'to' => 'q_next', 'value' => 'yes'],
    ]);
    $survey->update(['active_version_id' => $versionOne->id]);

    $session = SurveySession::query()->create([
        'survey_id' => $survey->id,
        'user_id' => $user->id,
        'started_version_id' => $versionOne->id,
        'current_version_id' => $versionOne->id,
        'current_question_id' => $v1Questions['q_next']->id,
        'status' => 'in_progress',
        'stable_node_key' => 'q_start',
    ]);

    SurveyAnswer::query()->create([
        'session_id' => $session->id,
        'question_stable_key' => 'q_start',
        'question_id' => $v1Questions['q_start']->id,
        'answer_value' => 'yes',
        'valid_under_version_id' => $versionOne->id,
        'is_active' => true,
    ]);

    [$versionTwo] = createMobilePublishedVersion($survey, 2, [
        [
            'stable_key' => 'q_start',
            'title' => 'Pick one (updated wording)',
            'type' => 'multiple_choice',
            'is_entry' => true,
            'options' => [
                ['value' => 'yes', 'label' => 'Absolutely'],
                ['value' => 'no', 'label' => 'No'],
                ['value' => 'maybe', 'label' => 'Maybe'],
            ],
        ],
        ['stable_key' => 'q_next', 'title' => 'Next', 'type' => 'multiple_choice'],
    ], [
        ['from' => 'q_start', 'to' => 'q_next', 'value' => 'yes'],
    ]);
    $versionOne->update(['is_active' => false]);
    $survey->update(['active_version_id' => $versionTwo->id]);

    $response = $this->getJson("/api/mobile/sessions/{$session->id}/state", $headers)
        ->assertOk()
        ->assertJsonPath('version_sync.conflict_type', null)
        ->assertJsonPath('state.session_status', 'in_progress')
        ->assertJsonPath('state.current_question.stable_key', 'q_next');

    assertMobileVersionSyncEnvelope($response, $versionOne->id, $versionTwo->id, false, 'atomic_recovery', []);
    expect(SurveyConflictLog::query()->where('session_id', $session->id)->count())->toBe(0)
        ->and(
            SurveyAnswer::query()
                ->where('session_id', $session->id)
                ->where('question_stable_key', 'q_start')
                ->value('valid_under_version_id')
        )->toBe($versionTwo->id);

    assertNoZombieVisibleQuestionsForMultipleChoice(
        $versionTwo->fresh('survey'),
        (array) $response->json('state.answers', []),
        (array) $response->json('state.visible_questions', []),
    );
});

it('reconciles safely on submit answer when schema mismatch exists', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $headers = mobileAuthHeader($this, $user);

    $survey = Survey::query()->create([
        'title' => 'Answer Reconcile Survey',
        'survey_type' => 'multiple_choice',
        'created_by' => $admin->id,
    ]);

    [$versionOne, $v1Questions] = createMobilePublishedVersion($survey, 1, [
        [
            'stable_key' => 'q1',
            'title' => 'Entry',
            'type' => 'multiple_choice',
            'is_entry' => true,
            'options' => [
                ['value' => 'a', 'label' => 'A'],
            ],
        ],
        [
            'stable_key' => 'q2',
            'title' => 'Branch',
            'type' => 'multiple_choice',
            'options' => [
                ['value' => 'c', 'label' => 'C'],
                ['value' => 'd', 'label' => 'D'],
            ],
        ],
        ['stable_key' => 'r_done', 'title' => 'Done', 'type' => 'result'],
    ], [
        ['from' => 'q1', 'to' => 'q2', 'value' => 'a'],
        ['from' => 'q2', 'to' => 'r_done', 'value' => 'c'],
    ]);
    $survey->update(['active_version_id' => $versionOne->id]);

    $session = SurveySession::query()->create([
        'survey_id' => $survey->id,
        'user_id' => $user->id,
        'started_version_id' => $versionOne->id,
        'current_version_id' => $versionOne->id,
        'current_question_id' => $v1Questions['r_done']->id,
        'status' => 'in_progress',
        'stable_node_key' => 'r_done',
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

    [$versionTwo] = createMobilePublishedVersion($survey, 2, [
        [
            'stable_key' => 'q1',
            'title' => 'Entry',
            'type' => 'multiple_choice',
            'is_entry' => true,
            'options' => [
                ['value' => 'a', 'label' => 'A'],
            ],
        ],
        [
            'stable_key' => 'q2',
            'title' => 'Branch',
            'type' => 'multiple_choice',
            'options' => [
                ['value' => 'd', 'label' => 'D'],
            ],
        ],
        ['stable_key' => 'r_done', 'title' => 'Done', 'type' => 'result'],
    ], [
        ['from' => 'q1', 'to' => 'q2', 'value' => 'a'],
        ['from' => 'q2', 'to' => 'r_done', 'value' => 'd'],
    ]);

    $versionOne->update(['is_active' => false]);
    $survey->update(['active_version_id' => $versionTwo->id]);

    $response = $this->postJson("/api/mobile/sessions/{$session->id}/answers", [
        'question_stable_key' => 'q2',
        'answer_value' => 'd',
    ], $headers)
        ->assertOk()
        ->assertJsonPath('state.session_status', 'in_progress')
        ->assertJsonPath('state.current_question.stable_key', 'r_done')
        ->assertJsonPath('state.can_complete', true);

    assertMobileVersionSyncEnvelope($response, $versionOne->id, $versionTwo->id, true, 'rollback', ['q2']);
    assertMobileConflictLogIntegrity($session->id, $versionOne->id, $versionTwo->id, 'rollback', ['q2'], 'q2');

    $q1Active = SurveyAnswer::query()
        ->where('session_id', $session->id)
        ->where('question_stable_key', 'q1')
        ->where('is_active', true)
        ->value('answer_value');

    $q2Answers = SurveyAnswer::query()
        ->where('session_id', $session->id)
        ->where('question_stable_key', 'q2')
        ->orderBy('id')
        ->get();

    expect($q1Active)->toBe('a')
        ->and($q2Answers)->toHaveCount(2)
        ->and((bool) $q2Answers[0]->is_active)->toBeFalse()
        ->and($q2Answers[0]->answer_value)->toBe('c')
        ->and((bool) $q2Answers[1]->is_active)->toBeTrue()
        ->and($q2Answers[1]->answer_value)->toBe('d');

    assertNoZombieVisibleQuestionsForMultipleChoice(
        $versionTwo->fresh('survey'),
        (array) $response->json('state.answers', []),
        (array) $response->json('state.visible_questions', []),
    );
});

it('reconciles safely on complete when schema mismatch exists', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create();
    $headers = mobileAuthHeader($this, $user);

    $survey = Survey::query()->create([
        'title' => 'Complete Reconcile Survey',
        'survey_type' => 'multiple_choice',
        'created_by' => $admin->id,
    ]);

    [$versionOne, $v1Questions] = createMobilePublishedVersion($survey, 1, [
        [
            'stable_key' => 'q1',
            'title' => 'Entry',
            'type' => 'multiple_choice',
            'is_entry' => true,
            'options' => [
                ['value' => 'yes', 'label' => 'Yes'],
            ],
        ],
        ['stable_key' => 'r_old', 'title' => 'Old result', 'type' => 'result'],
        ['stable_key' => 'r_new', 'title' => 'New result', 'type' => 'result'],
    ], [
        ['from' => 'q1', 'to' => 'r_old', 'value' => 'yes'],
    ]);
    $survey->update(['active_version_id' => $versionOne->id]);

    $session = SurveySession::query()->create([
        'survey_id' => $survey->id,
        'user_id' => $user->id,
        'started_version_id' => $versionOne->id,
        'current_version_id' => $versionOne->id,
        'current_question_id' => $v1Questions['r_old']->id,
        'status' => 'in_progress',
        'stable_node_key' => 'r_old',
    ]);

    SurveyAnswer::query()->create([
        'session_id' => $session->id,
        'question_stable_key' => 'q1',
        'question_id' => $v1Questions['q1']->id,
        'answer_value' => 'yes',
        'valid_under_version_id' => $versionOne->id,
        'is_active' => true,
    ]);

    [$versionTwo] = createMobilePublishedVersion($survey, 2, [
        [
            'stable_key' => 'q1',
            'title' => 'Entry',
            'type' => 'multiple_choice',
            'is_entry' => true,
            'options' => [
                ['value' => 'yes', 'label' => 'Yes'],
            ],
        ],
        ['stable_key' => 'r_old', 'title' => 'Old result', 'type' => 'result'],
        ['stable_key' => 'r_new', 'title' => 'New result', 'type' => 'result'],
    ], [
        ['from' => 'q1', 'to' => 'r_new', 'value' => 'yes'],
    ]);

    $versionOne->update(['is_active' => false]);
    $survey->update(['active_version_id' => $versionTwo->id]);

    $response = $this->postJson("/api/mobile/sessions/{$session->id}/complete", [], $headers)
        ->assertOk()
        ->assertJsonPath('session.status', 'completed')
        ->assertJsonPath('result.stable_key', 'r_new')
        ->assertJsonPath('answer_summary.0.question_stable_key', 'q1')
        ->assertJsonPath('answer_summary.0.answer_value', 'yes')
        ->assertJsonMissingPath('answer_summary.1')
        ->assertJsonPath('state.visible_questions.0', 'q1')
        ->assertJsonPath('state.visible_questions.1', 'r_new');

    assertMobileVersionSyncEnvelope($response, $versionOne->id, $versionTwo->id, true, 'rollback', []);
    assertMobileConflictLogIntegrity($session->id, $versionOne->id, $versionTwo->id, 'rollback', [], 'r_new');

    $q1Answer = SurveyAnswer::query()
        ->where('session_id', $session->id)
        ->where('question_stable_key', 'q1')
        ->where('is_active', true)
        ->first();

    expect($q1Answer)->not->toBeNull()
        ->and($q1Answer->valid_under_version_id)->toBe($versionTwo->id);

    assertNoZombieVisibleQuestionsForMultipleChoice(
        $versionTwo->fresh('survey'),
        (array) $response->json('state.answers', []),
        (array) $response->json('state.visible_questions', []),
    );
});

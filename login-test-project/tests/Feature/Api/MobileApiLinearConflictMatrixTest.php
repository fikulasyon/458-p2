<?php

use App\Models\Survey;
use App\Models\SurveyAnswer;
use App\Models\SurveyConflictLog;
use App\Models\SurveyQuestion;
use App\Models\SurveySession;
use App\Models\SurveyVersion;
use App\Models\User;
use Database\Seeders\ConflictPolicyMatrixSeeder;
use Illuminate\Support\Arr;

function linearConflictMatrixDefinition(): array
{
    /** @var array<string, mixed> $matrix */
    $matrix = require base_path('tests/Support/ConflictPolicyMatrix.php');

    return $matrix;
}

function linearMobileAuthHeader($testCase, User $user, string $password = 'password'): array
{
    $response = $testCase->postJson('/api/mobile/login', [
        'email' => $user->email,
        'password' => $password,
        'device_name' => 'android-emulator-linear-matrix',
    ])->assertOk();

    $token = $response->json('access_token');

    expect($token)->toBeString()->not->toBeEmpty();

    return [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json',
    ];
}

/**
 * @return array{
 *   survey: Survey,
 *   base_version: SurveyVersion,
 *   scenario_version: SurveyVersion,
 *   scenario: array<string, mixed>,
 *   checkpoint: array<string, mixed>,
 *   session: SurveySession
 * }
 */
function linearPrepareScenarioSession(string $surveyType, string $scenarioId, int $userId, bool $fillRemainingAnswers = false): array
{
    $matrix = linearConflictMatrixDefinition();
    $definition = $matrix[$surveyType] ?? null;
    if (! is_array($definition)) {
        throw new RuntimeException("Missing matrix definition for {$surveyType}");
    }

    $surveyTitle = (string) ($definition['seed_survey_title'] ?? '');
    $survey = Survey::query()
        ->with(['versions.questions.options'])
        ->where('title', $surveyTitle)
        ->firstOrFail();

    $baseVersion = $survey->versions->firstWhere('version_number', 1);
    if (! $baseVersion) {
        throw new RuntimeException("Base version not found for survey {$surveyTitle}");
    }

    $scenarioVersion = $survey->versions
        ->first(fn (SurveyVersion $version): bool => data_get($version->schema_meta, 'scenario_id') === $scenarioId);
    if (! $scenarioVersion) {
        throw new RuntimeException("Scenario version {$scenarioId} not found for survey {$surveyTitle}");
    }

    $scenario = collect($definition['scenarios'] ?? [])
        ->first(fn ($item): bool => is_array($item) && ($item['id'] ?? null) === $scenarioId);
    if (! is_array($scenario)) {
        throw new RuntimeException("Scenario {$scenarioId} not found in matrix definition");
    }

    $checkpointRef = $scenario['checkpoint'] ?? null;
    if (is_string($checkpointRef)) {
        $checkpoint = data_get($definition, "checkpoints.{$checkpointRef}") ?? data_get($definition, 'common_checkpoint');
    } else {
        $checkpoint = $checkpointRef;
    }

    if (! is_array($checkpoint)) {
        throw new RuntimeException("Checkpoint resolution failed for scenario {$scenarioId}");
    }

    $baseQuestionsByStable = $baseVersion->questions->keyBy('stable_key');
    $currentStableKey = (string) ($checkpoint['current_stable_key'] ?? '');
    $currentQuestion = $baseQuestionsByStable->get($currentStableKey);
    if (! $currentQuestion) {
        throw new RuntimeException("Current question {$currentStableKey} not found in base version");
    }

    $session = SurveySession::query()->create([
        'survey_id' => $survey->id,
        'user_id' => $userId,
        'started_version_id' => $baseVersion->id,
        'current_version_id' => $baseVersion->id,
        'current_question_id' => $currentQuestion->id,
        'status' => 'in_progress',
        'stable_node_key' => $currentStableKey,
    ]);

    /** @var array<string, mixed> $answers */
    $answers = (array) ($checkpoint['answers'] ?? []);

    if ($fillRemainingAnswers) {
        $deletedNodeKeys = collect((array) ($scenario['mutation'] ?? []))
            ->filter(fn ($mutation): bool => is_array($mutation) && ($mutation['op'] ?? null) === 'delete_node')
            ->pluck('stable_key')
            ->filter(fn ($stableKey): bool => is_string($stableKey) && $stableKey !== '')
            ->values()
            ->all();

        $orderedBaseQuestions = $baseVersion->questions
            ->sortBy([['order_index', 'asc'], ['id', 'asc']])
            ->values();

        foreach ($orderedBaseQuestions as $question) {
            if (in_array($question->stable_key, $deletedNodeKeys, true)) {
                continue;
            }

            if (array_key_exists($question->stable_key, $answers)) {
                continue;
            }

            $answers[$question->stable_key] = $question->type === 'rating'
                ? 3
                : "Autofill for {$question->stable_key}";
        }
    }

    foreach ($answers as $stableKey => $value) {
        /** @var SurveyQuestion|null $answerQuestion */
        $answerQuestion = $baseQuestionsByStable->get((string) $stableKey);
        if (! $answerQuestion) {
            continue;
        }

        SurveyAnswer::query()->create([
            'session_id' => $session->id,
            'question_stable_key' => $stableKey,
            'question_id' => $answerQuestion->id,
            'answer_value' => is_scalar($value) ? (string) $value : json_encode($value),
            'valid_under_version_id' => $baseVersion->id,
            'is_active' => true,
        ]);
    }

    SurveyVersion::query()
        ->where('survey_id', $survey->id)
        ->update(['is_active' => false]);

    $scenarioVersion->forceFill([
        'status' => 'published',
        'is_active' => true,
        'published_at' => now(),
    ])->save();

    $survey->forceFill(['active_version_id' => $scenarioVersion->id])->save();

    return [
        'survey' => $survey,
        'base_version' => $baseVersion,
        'scenario_version' => $scenarioVersion->fresh(['questions.options']),
        'scenario' => $scenario,
        'checkpoint' => $checkpoint,
        'session' => $session->fresh(),
    ];
}

/**
 * @return array<int, array{0:string,1:string}>
 */
function linearStateDatasetCases(): array
{
    return [
        ['rating', 'RT_ATOMIC_01'],
        ['rating', 'RT_RB_01'],
        ['rating', 'RT_RB_02'],
        ['rating', 'RT_RB_03'],
        ['rating', 'RT_NUCLEAR_01'],
        ['open_ended', 'OE_ATOMIC_01'],
        ['open_ended', 'OE_RB_01'],
        ['open_ended', 'OE_RB_02'],
        ['open_ended', 'OE_RB_03'],
        ['open_ended', 'OE_NUCLEAR_01'],
    ];
}

/**
 * @return array<int, array{0:string,1:string}>
 */
function linearCompleteDatasetCases(): array
{
    return [
        ['rating', 'RT_ATOMIC_01'],
        ['rating', 'RT_RB_01'],
        ['rating', 'RT_NUCLEAR_01'],
        ['open_ended', 'OE_ATOMIC_01'],
        ['open_ended', 'OE_RB_01'],
        ['open_ended', 'OE_NUCLEAR_01'],
    ];
}

/**
 * @return int|string
 */
function linearSubmissionValue(string $surveyType, string $stableKey)
{
    if ($surveyType === 'rating') {
        return 4;
    }

    return "Submitted answer for {$stableKey}";
}

it('reconciles rating/open-ended matrix scenarios on /state', function (string $surveyType, string $scenarioId) {
    $this->seed(ConflictPolicyMatrixSeeder::class);

    $user = User::factory()->create(['password' => 'password']);
    $headers = linearMobileAuthHeader($this, $user);
    $prepared = linearPrepareScenarioSession($surveyType, $scenarioId, $user->id);

    $expected = (array) data_get($prepared, 'scenario.expected', []);
    $expectedDropped = array_values((array) ($expected['drop_answers'] ?? []));
    sort($expectedDropped);

    $response = $this->getJson("/api/mobile/sessions/{$prepared['session']->id}/state", $headers)
        ->assertOk()
        ->assertJsonPath('version_sync.mismatch_detected', true)
        ->assertJsonPath('version_sync.from_version_id', $prepared['base_version']->id)
        ->assertJsonPath('version_sync.to_version_id', $prepared['scenario_version']->id)
        ->assertJsonPath('version_sync.recovery_strategy', $expected['recovery_strategy'])
        ->assertJsonPath('version_sync.conflict_detected', (bool) ($expected['conflict_detected'] ?? false))
        ->assertJsonPath('state.current_question.stable_key', $expected['continue_from']);

    $actualDropped = array_values((array) $response->json('version_sync.dropped_answers', []));
    sort($actualDropped);
    expect($actualDropped)->toBe($expectedDropped);

    $visible = (array) $response->json('state.visible_questions', []);
    expect($visible)->toContain($expected['continue_from']);
    foreach ((array) ($expected['must_not_show_unreachable'] ?? []) as $forbidden) {
        expect($visible)->not->toContain($forbidden);
    }

    if ((bool) ($expected['conflict_detected'] ?? false)) {
        expect(SurveyConflictLog::query()->where('session_id', $prepared['session']->id)->exists())->toBeTrue();
    } else {
        expect(SurveyConflictLog::query()->where('session_id', $prepared['session']->id)->exists())->toBeFalse();
    }
})->with('linear_state_cases');

it('reconciles rating/open-ended matrix scenarios on /answers before accepting new input', function (string $surveyType, string $scenarioId) {
    $this->seed(ConflictPolicyMatrixSeeder::class);

    $user = User::factory()->create(['password' => 'password']);
    $headers = linearMobileAuthHeader($this, $user);
    $prepared = linearPrepareScenarioSession($surveyType, $scenarioId, $user->id);

    $expected = (array) data_get($prepared, 'scenario.expected', []);
    $expectedDropped = array_values((array) ($expected['drop_answers'] ?? []));
    sort($expectedDropped);

    $targetStableKey = (string) ($expected['continue_from'] ?? '');
    $answerValue = linearSubmissionValue($surveyType, $targetStableKey);

    $response = $this->postJson("/api/mobile/sessions/{$prepared['session']->id}/answers", [
        'question_stable_key' => $targetStableKey,
        'answer_value' => $answerValue,
    ], $headers)
        ->assertOk()
        ->assertJsonPath('version_sync.mismatch_detected', true)
        ->assertJsonPath('version_sync.recovery_strategy', $expected['recovery_strategy'])
        ->assertJsonPath('version_sync.conflict_detected', (bool) ($expected['conflict_detected'] ?? false))
        ->assertJsonPath("state.answers.{$targetStableKey}", $answerValue);

    $actualDropped = array_values((array) $response->json('version_sync.dropped_answers', []));
    sort($actualDropped);
    expect($actualDropped)->toBe($expectedDropped);

    $visible = (array) $response->json('state.visible_questions', []);
    foreach ((array) ($expected['must_not_show_unreachable'] ?? []) as $forbidden) {
        expect($visible)->not->toContain($forbidden);
    }

    $currentAfterSubmit = Arr::get((array) $response->json('state.current_question'), 'stable_key');
    if ($currentAfterSubmit !== null) {
        expect($visible)->toContain($currentAfterSubmit);
    }

    if ((bool) ($expected['conflict_detected'] ?? false)) {
        expect(SurveyConflictLog::query()->where('session_id', $prepared['session']->id)->exists())->toBeTrue();
    }
})->with('linear_state_cases');

it('reconciles rating/open-ended matrix scenarios on /complete', function (string $surveyType, string $scenarioId) {
    $this->seed(ConflictPolicyMatrixSeeder::class);

    $user = User::factory()->create(['password' => 'password']);
    $headers = linearMobileAuthHeader($this, $user);
    $prepared = linearPrepareScenarioSession($surveyType, $scenarioId, $user->id, true);

    $expected = (array) data_get($prepared, 'scenario.expected', []);

    $this->postJson("/api/mobile/sessions/{$prepared['session']->id}/complete", [], $headers)
        ->assertOk()
        ->assertJsonPath('version_sync.mismatch_detected', true)
        ->assertJsonPath('version_sync.recovery_strategy', $expected['recovery_strategy'])
        ->assertJsonPath('version_sync.conflict_detected', (bool) ($expected['conflict_detected'] ?? false))
        ->assertJsonPath('session.status', 'completed')
        ->assertJsonPath('result', null);

    if ((bool) ($expected['conflict_detected'] ?? false)) {
        expect(SurveyConflictLog::query()->where('session_id', $prepared['session']->id)->exists())->toBeTrue();
    }
})->with('linear_complete_cases');

dataset('linear_state_cases', fn () => linearStateDatasetCases());
dataset('linear_complete_cases', fn () => linearCompleteDatasetCases());

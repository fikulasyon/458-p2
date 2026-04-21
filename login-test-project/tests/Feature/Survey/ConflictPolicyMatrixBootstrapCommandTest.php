<?php

use App\Models\Survey;
use App\Models\SurveyAnswer;
use App\Models\SurveySession;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

it('bootstraps a matrix scenario session via artisan command', function () {
    $status = Artisan::call('survey:matrix-bootstrap', [
        'scenario_id' => 'MC_RB_03',
        '--user-email' => 'matrix.runner@example.com',
        '--user-password' => 'matrix-pass-123',
        '--user-name' => 'Matrix Runner',
        '--json' => true,
    ]);

    expect($status)->toBe(0);

    $rawOutput = trim(Artisan::output());
    expect($rawOutput)->not->toBe('');

    /** @var array<string, mixed>|null $payload */
    $payload = json_decode($rawOutput, true);
    expect($payload)->toBeArray();
    expect($payload['scenario_id'] ?? null)->toBe('MC_RB_03')
        ->and($payload['survey_type'] ?? null)->toBe('multiple_choice');

    $user = User::query()->where('email', 'matrix.runner@example.com')->first();
    expect($user)->not->toBeNull()
        ->and(Hash::check('matrix-pass-123', $user->password))->toBeTrue();

    $survey = Survey::query()->find($payload['survey_id']);
    expect($survey)->not->toBeNull()
        ->and($survey->active_version_id)->toBe($payload['scenario_version_id']);

    $scenarioVersion = SurveyVersion::query()->find($payload['scenario_version_id']);
    expect($scenarioVersion)->not->toBeNull()
        ->and($scenarioVersion->status)->toBe('published')
        ->and((bool) $scenarioVersion->is_active)->toBeTrue()
        ->and(data_get($scenarioVersion->schema_meta, 'scenario_id'))->toBe('MC_RB_03');

    $session = SurveySession::query()->find($payload['session_id']);
    expect($session)->not->toBeNull()
        ->and($session->started_version_id)->toBe($payload['base_version_id'])
        ->and($session->current_version_id)->toBe($payload['base_version_id'])
        ->and($session->status)->toBe('in_progress')
        ->and($session->stable_node_key)->toBe('Q7');

    $answers = SurveyAnswer::query()
        ->where('session_id', $session->id)
        ->where('is_active', true)
        ->orderBy('id')
        ->get()
        ->pluck('answer_value', 'question_stable_key')
        ->all();

    expect($answers)->toBe([
        'Q1' => 'A',
        'Q2' => 'B',
    ]);
});

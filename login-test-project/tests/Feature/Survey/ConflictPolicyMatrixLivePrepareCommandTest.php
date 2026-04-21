<?php

use App\Models\Survey;
use App\Models\SurveySession;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

it('prepares a live sync-conflict scenario with base active and scenario draft', function () {
    $status = Artisan::call('survey:matrix-live-prepare', [
        'scenario_id' => 'MC_RB_03',
        '--user-email' => 'live.runner@example.com',
        '--user-password' => 'live-pass-123',
        '--user-name' => 'Live Runner',
        '--admin-email' => 'live.admin@example.com',
        '--admin-password' => 'live-admin-pass-123',
        '--admin-name' => 'Live Admin',
        '--json' => true,
    ]);

    expect($status)->toBe(0);

    $rawOutput = trim(Artisan::output());
    expect($rawOutput)->not->toBe('');

    /** @var array<string, mixed>|null $payload */
    $payload = json_decode($rawOutput, true);
    expect($payload)->toBeArray();
    expect($payload['mode'] ?? null)->toBe('live_sync_conflict_prepare')
        ->and($payload['scenario_id'] ?? null)->toBe('MC_RB_03')
        ->and($payload['survey_type'] ?? null)->toBe('multiple_choice');

    $mobileUser = User::query()->where('email', 'live.runner@example.com')->first();
    expect($mobileUser)->not->toBeNull()
        ->and(Hash::check('live-pass-123', $mobileUser->password))->toBeTrue()
        ->and((bool) $mobileUser->is_admin)->toBeFalse();

    $adminUser = User::query()->where('email', 'live.admin@example.com')->first();
    expect($adminUser)->not->toBeNull()
        ->and(Hash::check('live-admin-pass-123', $adminUser->password))->toBeTrue()
        ->and((bool) $adminUser->is_admin)->toBeTrue();

    $survey = Survey::query()->find($payload['survey_id']);
    expect($survey)->not->toBeNull()
        ->and($survey->active_version_id)->toBe($payload['base_version_id']);

    $baseVersion = SurveyVersion::query()->find($payload['base_version_id']);
    expect($baseVersion)->not->toBeNull()
        ->and($baseVersion->status)->toBe('published')
        ->and((bool) $baseVersion->is_active)->toBeTrue();

    $scenarioVersion = SurveyVersion::query()->find($payload['scenario_version_id']);
    expect($scenarioVersion)->not->toBeNull()
        ->and($scenarioVersion->status)->toBe('draft')
        ->and((bool) $scenarioVersion->is_active)->toBeFalse()
        ->and($scenarioVersion->published_at)->toBeNull();

    $sessionCount = SurveySession::query()
        ->where('survey_id', $survey->id)
        ->where('user_id', $mobileUser->id)
        ->count();

    expect($sessionCount)->toBe(0);
});


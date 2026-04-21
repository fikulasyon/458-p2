<?php

use App\Support\ConflictPolicyMatrixBootstrapper;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Throwable;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'survey:matrix-bootstrap
    {scenario_id : Matrix scenario id (e.g., MC_RB_03)}
    {--user-email=t1@g.com : Mobile login email}
    {--user-password=123123123 : Mobile login password}
    {--user-name=Mobile Matrix User : Display name for bootstrap user}
    {--skip-seed : Skip reseeding the conflict matrix before bootstrap}
    {--json : Print JSON payload only}',
    function (ConflictPolicyMatrixBootstrapper $bootstrapper): int {
        $scenarioId = (string) $this->argument('scenario_id');
        $userEmail = (string) $this->option('user-email');
        $userPassword = (string) $this->option('user-password');
        $userName = (string) $this->option('user-name');
        $seed = ! (bool) $this->option('skip-seed');

        try {
            $payload = $bootstrapper->bootstrap(
                scenarioId: $scenarioId,
                userEmail: $userEmail,
                userPassword: $userPassword,
                userName: $userName,
                seed: $seed,
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Unexpected bootstrap failure: '.$exception->getMessage());

            return Command::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $this->info('Conflict matrix scenario bootstrapped.');
        $this->table(['Field', 'Value'], [
            ['scenario_id', (string) ($payload['scenario_id'] ?? '')],
            ['survey_type', (string) ($payload['survey_type'] ?? '')],
            ['survey_id', (string) ($payload['survey_id'] ?? '')],
            ['session_id', (string) ($payload['session_id'] ?? '')],
            ['base_version_id', (string) ($payload['base_version_id'] ?? '')],
            ['scenario_version_id', (string) ($payload['scenario_version_id'] ?? '')],
            ['expected_continue_from', (string) data_get($payload, 'expected.continue_from', '')],
            ['expected_recovery_strategy', (string) data_get($payload, 'expected.recovery_strategy', '')],
            ['user_email', (string) data_get($payload, 'user.email', '')],
        ]);

        return Command::SUCCESS;
    }
)->purpose('Seed and bootstrap a conflict-policy matrix scenario for mobile automation');

Artisan::command(
    'survey:matrix-live-prepare
    {scenario_id : Matrix scenario id (e.g., MC_RB_03)}
    {--user-email=t1@g.com : Mobile login email}
    {--user-password=123123123 : Mobile login password}
    {--user-name=Mobile Matrix User : Display name for mobile user}
    {--admin-email=admin@example.com : Web architect admin email}
    {--admin-password=password : Web architect admin password}
    {--admin-name=Survey Admin : Display name for admin user}
    {--skip-seed : Skip reseeding the conflict matrix before preparing}
    {--json : Print JSON payload only}',
    function (ConflictPolicyMatrixBootstrapper $bootstrapper): int {
        $scenarioId = (string) $this->argument('scenario_id');
        $userEmail = (string) $this->option('user-email');
        $userPassword = (string) $this->option('user-password');
        $userName = (string) $this->option('user-name');
        $adminEmail = (string) $this->option('admin-email');
        $adminPassword = (string) $this->option('admin-password');
        $adminName = (string) $this->option('admin-name');
        $seed = ! (bool) $this->option('skip-seed');

        try {
            $payload = $bootstrapper->prepareLiveScenario(
                scenarioId: $scenarioId,
                userEmail: $userEmail,
                userPassword: $userPassword,
                userName: $userName,
                seed: $seed,
                adminEmail: $adminEmail,
                adminPassword: $adminPassword,
                adminName: $adminName,
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return Command::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Unexpected live prepare failure: '.$exception->getMessage());

            return Command::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $this->info('Live sync-conflict scenario prepared.');
        $this->table(['Field', 'Value'], [
            ['scenario_id', (string) ($payload['scenario_id'] ?? '')],
            ['survey_type', (string) ($payload['survey_type'] ?? '')],
            ['survey_id', (string) ($payload['survey_id'] ?? '')],
            ['base_version_id', (string) ($payload['base_version_id'] ?? '')],
            ['scenario_version_id', (string) ($payload['scenario_version_id'] ?? '')],
            ['expected_continue_from', (string) data_get($payload, 'expected.continue_from', '')],
            ['expected_recovery_strategy', (string) data_get($payload, 'expected.recovery_strategy', '')],
            ['mobile_user_email', (string) data_get($payload, 'mobile_user.email', '')],
            ['admin_user_email', (string) data_get($payload, 'admin_user.email', '')],
        ]);

        return Command::SUCCESS;
    }
)->purpose('Prepare a live sync-conflict scenario (base active, scenario draft, clean session)');

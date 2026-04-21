<?php

namespace App\Support;

use App\Models\Survey;
use App\Models\SurveyAnswer;
use App\Models\SurveyConflictLog;
use App\Models\SurveyQuestion;
use App\Models\SurveySession;
use App\Models\SurveyVersion;
use App\Models\User;
use Database\Seeders\ConflictPolicyMatrixSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConflictPolicyMatrixBootstrapper
{
    /**
     * @return array<string, mixed>
     */
    public function bootstrap(
        string $scenarioId,
        string $userEmail,
        string $userPassword,
        string $userName = 'Mobile Matrix User',
        bool $seed = true,
    ): array {
        $scenarioId = strtoupper(trim($scenarioId));
        if ($scenarioId === '') {
            throw new RuntimeException('Scenario id is required.');
        }

        if ($seed) {
            app(ConflictPolicyMatrixSeeder::class)->run();
        }

        $matrix = $this->matrixDefinition();
        [$surveyType, $definition, $scenario] = $this->resolveScenario($matrix, $scenarioId);
        $checkpoint = $this->resolveCheckpoint($definition, $scenario);

        $surveyTitle = (string) ($definition['seed_survey_title'] ?? '');
        if ($surveyTitle === '') {
            throw new RuntimeException("Missing seed_survey_title for survey type {$surveyType}.");
        }

        $survey = Survey::query()
            ->with(['versions.questions.options'])
            ->where('title', $surveyTitle)
            ->first();
        if (! $survey) {
            throw new RuntimeException("Seeded survey not found for title: {$surveyTitle}");
        }

        /** @var SurveyVersion|null $baseVersion */
        $baseVersion = $survey->versions->firstWhere('version_number', 1);
        if (! $baseVersion) {
            throw new RuntimeException("Base version (v1) missing for survey: {$surveyTitle}");
        }

        /** @var SurveyVersion|null $scenarioVersion */
        $scenarioVersion = $survey->versions->first(
            fn (SurveyVersion $version): bool => data_get($version->schema_meta, 'scenario_id') === $scenarioId
        );
        if (! $scenarioVersion) {
            throw new RuntimeException("Scenario version {$scenarioId} not found for survey: {$surveyTitle}");
        }

        $user = $this->upsertMobileUser($userEmail, $userPassword, $userName);

        $session = DB::transaction(function () use (
            $survey,
            $baseVersion,
            $scenarioVersion,
            $checkpoint,
            $user
        ): SurveySession {
            $baseVersion->loadMissing('questions');
            $baseQuestionsByStable = $baseVersion->questions->keyBy('stable_key');

            $currentStableKey = (string) ($checkpoint['current_stable_key'] ?? '');
            if ($currentStableKey === '') {
                throw new RuntimeException('Checkpoint current_stable_key is required.');
            }

            /** @var SurveyQuestion|null $currentQuestion */
            $currentQuestion = $baseQuestionsByStable->get($currentStableKey);
            if (! $currentQuestion) {
                throw new RuntimeException("Checkpoint current_stable_key {$currentStableKey} not found in base version.");
            }

            $session = SurveySession::query()->create([
                'survey_id' => $survey->id,
                'user_id' => $user->id,
                'started_version_id' => $baseVersion->id,
                'current_version_id' => $baseVersion->id,
                'current_question_id' => $currentQuestion->id,
                'status' => 'in_progress',
                'stable_node_key' => $currentStableKey,
                'last_synced_at' => now(),
            ]);

            /** @var array<string, mixed> $answers */
            $answers = (array) ($checkpoint['answers'] ?? []);
            foreach ($answers as $stableKey => $value) {
                /** @var SurveyQuestion|null $answerQuestion */
                $answerQuestion = $baseQuestionsByStable->get((string) $stableKey);
                if (! $answerQuestion) {
                    continue;
                }

                SurveyAnswer::query()->create([
                    'session_id' => $session->id,
                    'question_stable_key' => (string) $stableKey,
                    'question_id' => $answerQuestion->id,
                    'answer_value' => $this->storeValue($value),
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

            return $session;
        });

        return [
            'scenario_id' => $scenarioId,
            'survey_type' => $surveyType,
            'survey_id' => $survey->id,
            'survey_title' => $survey->title,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'password' => $userPassword,
            ],
            'session_id' => $session->id,
            'base_version_id' => $baseVersion->id,
            'scenario_version_id' => $scenarioVersion->id,
            'checkpoint' => $checkpoint,
            'expected' => (array) ($scenario['expected'] ?? []),
            'policy_class' => (string) ($scenario['policy_class'] ?? ''),
            'title' => (string) ($scenario['title'] ?? ''),
            'mutation' => (array) ($scenario['mutation'] ?? []),
        ];
    }

    /**
     * Prepare a live sync-conflict run:
     * - keep base version active
     * - keep scenario version as draft (to be published from web architect)
     * - clear prior runtime sessions for this user+survey so mobile starts from entry
     *
     * @return array<string, mixed>
     */
    public function prepareLiveScenario(
        string $scenarioId,
        string $userEmail,
        string $userPassword,
        string $userName = 'Mobile Matrix User',
        bool $seed = true,
        string $adminEmail = 'admin@example.com',
        string $adminPassword = 'password',
        string $adminName = 'Survey Admin',
    ): array {
        $scenarioId = strtoupper(trim($scenarioId));
        if ($scenarioId === '') {
            throw new RuntimeException('Scenario id is required.');
        }

        if ($seed) {
            app(ConflictPolicyMatrixSeeder::class)->run();
        }

        $matrix = $this->matrixDefinition();
        [$surveyType, $definition, $scenario] = $this->resolveScenario($matrix, $scenarioId);

        $surveyTitle = (string) ($definition['seed_survey_title'] ?? '');
        if ($surveyTitle === '') {
            throw new RuntimeException("Missing seed_survey_title for survey type {$surveyType}.");
        }

        $survey = Survey::query()
            ->with(['versions.questions.options'])
            ->where('title', $surveyTitle)
            ->first();
        if (! $survey) {
            throw new RuntimeException("Seeded survey not found for title: {$surveyTitle}");
        }

        /** @var SurveyVersion|null $baseVersion */
        $baseVersion = $survey->versions->firstWhere('version_number', 1);
        if (! $baseVersion) {
            throw new RuntimeException("Base version (v1) missing for survey: {$surveyTitle}");
        }

        /** @var SurveyVersion|null $scenarioVersion */
        $scenarioVersion = $survey->versions->first(
            fn (SurveyVersion $version): bool => data_get($version->schema_meta, 'scenario_id') === $scenarioId
        );
        if (! $scenarioVersion) {
            throw new RuntimeException("Scenario version {$scenarioId} not found for survey: {$surveyTitle}");
        }

        $checkpoint = $this->resolveCheckpoint($definition, $scenario);
        $mobileUser = $this->upsertMobileUser($userEmail, $userPassword, $userName);
        $adminUser = $this->upsertAdminUser($adminEmail, $adminPassword, $adminName);

        DB::transaction(function () use ($survey, $baseVersion, $scenarioVersion, $mobileUser): void {
            SurveyVersion::query()
                ->where('survey_id', $survey->id)
                ->update(['is_active' => false]);

            SurveyVersion::query()
                ->whereKey($baseVersion->id)
                ->update([
                'status' => 'published',
                'is_active' => true,
                'published_at' => $baseVersion->published_at ?? now(),
                ]);

            SurveyVersion::query()
                ->whereKey($scenarioVersion->id)
                ->update([
                'status' => 'draft',
                'is_active' => false,
                'published_at' => null,
                ]);

            $survey->forceFill([
                'active_version_id' => $baseVersion->id,
            ])->save();

            $sessionIds = SurveySession::query()
                ->where('survey_id', $survey->id)
                ->where('user_id', $mobileUser->id)
                ->pluck('id');

            if ($sessionIds->isNotEmpty()) {
                SurveyAnswer::query()->whereIn('session_id', $sessionIds)->delete();
                SurveyConflictLog::query()->whereIn('session_id', $sessionIds)->delete();
                SurveySession::query()->whereIn('id', $sessionIds)->delete();
            }
        });

        return [
            'mode' => 'live_sync_conflict_prepare',
            'scenario_id' => $scenarioId,
            'survey_type' => $surveyType,
            'survey_id' => $survey->id,
            'survey_title' => $survey->title,
            'base_version_id' => $baseVersion->id,
            'scenario_version_id' => $scenarioVersion->id,
            'checkpoint' => $checkpoint,
            'expected' => (array) ($scenario['expected'] ?? []),
            'policy_class' => (string) ($scenario['policy_class'] ?? ''),
            'title' => (string) ($scenario['title'] ?? ''),
            'mutation' => (array) ($scenario['mutation'] ?? []),
            'mobile_user' => [
                'id' => $mobileUser->id,
                'email' => $mobileUser->email,
                'password' => $userPassword,
            ],
            'admin_user' => [
                'id' => $adminUser->id,
                'email' => $adminUser->email,
                'password' => $adminPassword,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $matrix
     * @return array{0:string,1:array<string, mixed>,2:array<string, mixed>}
     */
    protected function resolveScenario(array $matrix, string $scenarioId): array
    {
        foreach ($matrix as $surveyType => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            foreach ((array) ($definition['scenarios'] ?? []) as $scenario) {
                if (! is_array($scenario)) {
                    continue;
                }

                if (strtoupper((string) ($scenario['id'] ?? '')) !== $scenarioId) {
                    continue;
                }

                return [(string) $surveyType, $definition, $scenario];
            }
        }

        throw new RuntimeException("Scenario id {$scenarioId} not found in conflict matrix.");
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $scenario
     * @return array<string, mixed>
     */
    protected function resolveCheckpoint(array $definition, array $scenario): array
    {
        $checkpointRef = $scenario['checkpoint'] ?? null;

        if (is_array($checkpointRef)) {
            return $checkpointRef;
        }

        if (is_string($checkpointRef)) {
            if ($checkpointRef === 'common') {
                $common = $definition['common_checkpoint'] ?? null;
                if (is_array($common)) {
                    return $common;
                }
            }

            $resolved = data_get($definition, "checkpoints.{$checkpointRef}");
            if (is_array($resolved)) {
                return $resolved;
            }
        }

        $fallback = $definition['common_checkpoint'] ?? null;
        if (is_array($fallback)) {
            return $fallback;
        }

        $firstCheckpoint = collect((array) ($definition['checkpoints'] ?? []))
            ->first(fn ($checkpoint): bool => is_array($checkpoint));
        if (is_array($firstCheckpoint)) {
            return $firstCheckpoint;
        }

        throw new RuntimeException('Unable to resolve checkpoint for scenario bootstrap.');
    }

    protected function upsertMobileUser(string $email, string $password, string $name): User
    {
        $email = trim($email);
        $name = trim($name);

        if ($email === '') {
            throw new RuntimeException('User email cannot be empty.');
        }

        if ($password === '') {
            throw new RuntimeException('User password cannot be empty.');
        }

        /** @var User $user */
        $user = User::query()->firstOrNew([
            'email' => $email,
        ]);

        $user->name = $name !== '' ? $name : 'Mobile Matrix User';
        $user->password = $password;
        $user->is_admin = false;
        $user->email_verified_at = $user->email_verified_at ?? now();
        $user->forceFill([
            'account_state' => 'Active',
            'locked_until' => null,
            'challenge_locked_until' => null,
        ]);
        $user->save();

        return $user;
    }

    protected function upsertAdminUser(string $email, string $password, string $name): User
    {
        $email = trim($email);
        $name = trim($name);

        if ($email === '') {
            throw new RuntimeException('Admin email cannot be empty.');
        }

        if ($password === '') {
            throw new RuntimeException('Admin password cannot be empty.');
        }

        /** @var User $user */
        $user = User::query()->firstOrNew([
            'email' => $email,
        ]);

        $user->name = $name !== '' ? $name : 'Survey Admin';
        $user->password = $password;
        $user->is_admin = true;
        $user->email_verified_at = $user->email_verified_at ?? now();
        $user->forceFill([
            'account_state' => 'Active',
            'locked_until' => null,
            'challenge_locked_until' => null,
        ]);
        $user->save();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    protected function matrixDefinition(): array
    {
        $matrix = require base_path('tests/Support/ConflictPolicyMatrix.php');
        if (! is_array($matrix) || empty($matrix)) {
            throw new RuntimeException('Conflict policy matrix definition missing or invalid.');
        }

        return $matrix;
    }

    protected function storeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}

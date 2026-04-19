<?php

namespace App\Services;

use App\Models\SurveyConflictLog;
use App\Models\SurveySession;
use App\Models\SurveyVersion;
use Illuminate\Support\Facades\DB;

class SessionRecoveryService
{
    public function __construct(
        protected GraphConflictResolver $conflictResolver,
        protected SurveyGraphBuilder $graphBuilder,
        protected SurveyVisibilityEngine $visibilityEngine,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function reconcileSession(SurveySession $session, SurveyVersion $newVersion): array
    {
        $analysis = $this->conflictResolver->detectConflict($session, $newVersion);

        if ($analysis['conflict_detected'] && ! $analysis['can_atomic_recovery']) {
            return $this->rollbackToLastStableNode($session, $newVersion, $analysis);
        }

        return $this->performAtomicRecovery($session, $newVersion, $analysis);
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    protected function performAtomicRecovery(SurveySession $session, SurveyVersion $newVersion, array $analysis): array
    {
        $graph = $this->graphBuilder->build($newVersion);
        $newQuestionsByStable = $graph['questions_by_stable_key'];
        $oldVersionId = $session->current_version_id ?: $session->started_version_id;
        $droppedAnswers = [];

        DB::transaction(function () use ($session, $newVersion, $newQuestionsByStable, $analysis, &$droppedAnswers, $oldVersionId): void {
            $answers = $session->answers()->orderBy('id')->get();
            $activeAnswersByStable = [];

            foreach ($answers as $answer) {
                if (! $answer->is_active) {
                    continue;
                }

                $stableKey = $answer->question_stable_key;
                $newQuestion = $newQuestionsByStable[$stableKey] ?? null;

                if (! $newQuestion) {
                    $answer->forceFill([
                        'is_active' => false,
                        'question_id' => null,
                    ])->save();

                    $droppedAnswers[] = $stableKey;
                    continue;
                }

                $answer->forceFill([
                    'question_id' => $newQuestion->id,
                    'valid_under_version_id' => $newVersion->id,
                    'is_active' => true,
                ])->save();

                $activeAnswersByStable[$stableKey] = $answer->answer_value;
            }

            $visibility = $this->visibilityEngine->calculate($newVersion, $activeAnswersByStable);
            $visibleStableKeys = $visibility['visible_stable_keys'];

            $currentStableKey = $analysis['details']['current_stable_key'] ?? null;
            $currentQuestionId = $this->resolveCurrentQuestionId(
                $currentStableKey,
                $newQuestionsByStable,
                $visibleStableKeys,
                $activeAnswersByStable,
            );

            $session->forceFill([
                'current_version_id' => $newVersion->id,
                'current_question_id' => $currentQuestionId,
                'status' => $analysis['conflict_detected'] ? 'conflict_recovered' : 'in_progress',
                'stable_node_key' => $analysis['conflict_detected'] ? ($currentStableKey ?? $session->stable_node_key) : $session->stable_node_key,
                'last_synced_at' => now(),
            ])->save();

            if ($analysis['conflict_detected']) {
                $this->createConflictLog(
                    $session->id,
                    $oldVersionId,
                    $newVersion->id,
                    $analysis['conflict_type'] ?? 'version_conflict',
                    'atomic_recovery',
                    [
                        ...($analysis['details'] ?? []),
                        'dropped_answers' => array_values(array_unique($droppedAnswers)),
                    ],
                );
            }
        });

        return $this->buildSessionStateResponse(
            $session->fresh(['currentQuestion', 'answers']),
            $newVersion,
            $analysis,
            'atomic_recovery',
            array_values(array_unique($droppedAnswers)),
        );
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @return array<string, mixed>
     */
    protected function rollbackToLastStableNode(SurveySession $session, SurveyVersion $newVersion, array $analysis): array
    {
        $graph = $this->graphBuilder->build($newVersion);
        $newQuestionsByStable = $graph['questions_by_stable_key'];
        $oldVersionId = $session->current_version_id ?: $session->started_version_id;
        $droppedAnswers = [];
        $lastStableKey = null;

        DB::transaction(function () use ($session, $newVersion, $newQuestionsByStable, $analysis, $oldVersionId, &$droppedAnswers, &$lastStableKey): void {
            $answers = $session->answers()->where('is_active', true)->orderBy('id')->get();
            $keptAnswersByStable = [];

            foreach ($answers as $answer) {
                $stableKey = $answer->question_stable_key;
                $newQuestion = $newQuestionsByStable[$stableKey] ?? null;

                if (! $newQuestion) {
                    $answer->forceFill([
                        'is_active' => false,
                        'question_id' => null,
                    ])->save();
                    $droppedAnswers[] = $stableKey;
                    continue;
                }

                $candidateAnswers = [...$keptAnswersByStable, $stableKey => $answer->answer_value];
                $visibility = $this->visibilityEngine->calculate($newVersion, $candidateAnswers);
                $visibleStableKeys = $visibility['visible_stable_keys'];

                if (! in_array($stableKey, $visibleStableKeys, true)) {
                    $answer->forceFill([
                        'is_active' => false,
                        'question_id' => null,
                    ])->save();
                    $droppedAnswers[] = $stableKey;
                    continue;
                }

                $answer->forceFill([
                    'question_id' => $newQuestion->id,
                    'valid_under_version_id' => $newVersion->id,
                    'is_active' => true,
                ])->save();

                $keptAnswersByStable[$stableKey] = $answer->answer_value;
                $lastStableKey = $stableKey;
            }

            $visibilityFinal = $this->visibilityEngine->calculate($newVersion, $keptAnswersByStable);
            $currentQuestionId = $this->resolveCurrentQuestionId(
                $lastStableKey,
                $newQuestionsByStable,
                $visibilityFinal['visible_stable_keys'],
                $keptAnswersByStable,
            );

            $session->forceFill([
                'current_version_id' => $newVersion->id,
                'current_question_id' => $currentQuestionId,
                'status' => 'rolled_back',
                'stable_node_key' => $lastStableKey,
                'last_synced_at' => now(),
            ])->save();

            $this->createConflictLog(
                $session->id,
                $oldVersionId,
                $newVersion->id,
                $analysis['conflict_type'] ?? 'answer_path_inconsistent',
                'rollback',
                [
                    ...($analysis['details'] ?? []),
                    'stable_node_key' => $lastStableKey,
                    'dropped_answers' => array_values(array_unique($droppedAnswers)),
                ],
            );
        });

        return $this->buildSessionStateResponse(
            $session->fresh(['currentQuestion', 'answers']),
            $newVersion,
            $analysis,
            'rollback',
            array_values(array_unique($droppedAnswers)),
        );
    }

    /**
     * @param  array<string, mixed>  $questionsByStable
     * @param  array<int, string>  $visibleStableKeys
     * @param  array<string, mixed>  $activeAnswersByStable
     */
    protected function resolveCurrentQuestionId(
        ?string $preferredStableKey,
        array $questionsByStable,
        array $visibleStableKeys,
        array $activeAnswersByStable,
    ): ?int {
        if ($preferredStableKey !== null
            && isset($questionsByStable[$preferredStableKey])
            && in_array($preferredStableKey, $visibleStableKeys, true)) {
            return $questionsByStable[$preferredStableKey]->id;
        }

        $answeredStableKeys = array_keys($activeAnswersByStable);

        foreach ($visibleStableKeys as $stableKey) {
            if (! in_array($stableKey, $answeredStableKeys, true) && isset($questionsByStable[$stableKey])) {
                return $questionsByStable[$stableKey]->id;
            }
        }

        $fallbackStableKey = $visibleStableKeys[0] ?? null;
        return $fallbackStableKey !== null && isset($questionsByStable[$fallbackStableKey])
            ? $questionsByStable[$fallbackStableKey]->id
            : null;
    }

    /**
     * @param  array<string, mixed>  $analysis
     * @param  array<int, string>  $droppedAnswers
     * @return array<string, mixed>
     */
    protected function buildSessionStateResponse(
        SurveySession $session,
        SurveyVersion $newVersion,
        array $analysis,
        string $recoveryStrategy,
        array $droppedAnswers,
    ): array {
        $activeAnswersByStable = $session->answers
            ->where('is_active', true)
            ->pluck('answer_value', 'question_stable_key')
            ->all();

        $visibility = $this->visibilityEngine->calculate($newVersion, $activeAnswersByStable);

        return [
            'status' => 'ok',
            'session_status' => $session->status,
            'version_status' => 'updated',
            'conflict_detected' => (bool) ($analysis['conflict_detected'] ?? false),
            'conflict_type' => $analysis['conflict_type'] ?? null,
            'recovery_strategy' => $recoveryStrategy,
            'current_question' => $session->currentQuestion
                ? [
                    'id' => $session->currentQuestion->id,
                    'stable_key' => $session->currentQuestion->stable_key,
                ]
                : null,
            'visible_questions' => $visibility['visible_stable_keys'],
            'dropped_answers' => $droppedAnswers,
            'message' => $recoveryStrategy === 'rollback'
                ? 'Session rolled back to last stable node.'
                : 'Session recovered and mapped to latest schema.',
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    protected function createConflictLog(
        int $sessionId,
        int $oldVersionId,
        int $newVersionId,
        string $conflictType,
        string $recoveryStrategy,
        array $details,
    ): void {
        SurveyConflictLog::query()->create([
            'session_id' => $sessionId,
            'old_version_id' => $oldVersionId,
            'new_version_id' => $newVersionId,
            'conflict_type' => $conflictType,
            'recovery_strategy' => $recoveryStrategy,
            'details' => $details,
        ]);
    }
}

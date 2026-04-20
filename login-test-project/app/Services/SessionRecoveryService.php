<?php

namespace App\Services;

use App\Models\SurveyConflictLog;
use App\Models\SurveyQuestion;
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
        $fallbackStableKey = null;
        $replayReason = null;
        $keptStableKeys = [];

        DB::transaction(function () use (
            $session,
            $newVersion,
            $graph,
            $newQuestionsByStable,
            $analysis,
            $oldVersionId,
            &$droppedAnswers,
            &$fallbackStableKey,
            &$replayReason,
            &$keptStableKeys
        ): void {
            $answers = $session->answers()->where('is_active', true)->orderBy('id')->get();
            $plan = $this->buildRollbackReplayPlan($newVersion, $graph, $answers);

            $fallbackStableKey = $plan['fallback_stable_key'];
            $replayReason = $plan['replay_reason'];
            $keptStableKeys = $plan['kept_stable_keys'];

            $keepLookup = array_fill_keys($keptStableKeys, true);
            $dropLookup = array_fill_keys($plan['dropped_stable_keys'], true);
            $latestAnswerIdByStable = [];

            foreach ($answers as $answer) {
                $latestAnswerIdByStable[$answer->question_stable_key] = $answer->id;
            }

            foreach ($answers as $answer) {
                $stableKey = $answer->question_stable_key;
                $isLatestForStable = ($latestAnswerIdByStable[$stableKey] ?? null) === $answer->id;
                $shouldKeep = isset($keepLookup[$stableKey]) && $isLatestForStable && isset($newQuestionsByStable[$stableKey]);

                if (! $shouldKeep) {
                    $answer->forceFill([
                        'is_active' => false,
                        'question_id' => null,
                    ])->save();
                    $dropLookup[$stableKey] = true;
                    continue;
                }

                $answer->forceFill([
                    'question_id' => $newQuestionsByStable[$stableKey]->id,
                    'valid_under_version_id' => $newVersion->id,
                    'is_active' => true,
                ])->save();
            }

            $droppedAnswers = array_values(array_unique(array_keys($dropLookup)));
            $currentQuestionId = ($fallbackStableKey !== null && isset($newQuestionsByStable[$fallbackStableKey]))
                ? $newQuestionsByStable[$fallbackStableKey]->id
                : null;
            $stableNodeKey = $fallbackStableKey ?? ($keptStableKeys[count($keptStableKeys) - 1] ?? null);

            $session->forceFill([
                'current_version_id' => $newVersion->id,
                'current_question_id' => $currentQuestionId,
                'status' => 'rolled_back',
                'stable_node_key' => $stableNodeKey,
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
                    'fallback_node_key' => $fallbackStableKey,
                    'kept_answers' => $keptStableKeys,
                    'replay_reason' => $replayReason,
                    'dropped_answers' => $droppedAnswers,
                ],
            );
        });

        return $this->buildSessionStateResponse(
            $session->fresh(['currentQuestion', 'answers']),
            $newVersion,
            $analysis,
            'rollback',
            $droppedAnswers,
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\SurveyAnswer>  $answers
     * @param  array{
     *   questions: \Illuminate\Support\Collection<int, \App\Models\SurveyQuestion>,
     *   questions_by_stable_key: array<string, \App\Models\SurveyQuestion>,
     *   adjacency: array<int, array<int, array<string, mixed>>>
     * }  $graph
     * @return array{
     *   fallback_stable_key: ?string,
     *   kept_stable_keys: array<int, string>,
     *   dropped_stable_keys: array<int, string>,
     *   replay_reason: string
     * }
     */
    protected function buildRollbackReplayPlan(SurveyVersion $newVersion, array $graph, $answers): array
    {
        $latestAnswersByStable = [];
        foreach ($answers as $answer) {
            $latestAnswersByStable[$answer->question_stable_key] = $answer;
        }

        $surveyType = $newVersion->survey->survey_type ?? 'multiple_choice';
        $plan = $surveyType === 'multiple_choice'
            ? $this->buildMultipleChoiceReplayPlan($newVersion, $graph, $latestAnswersByStable)
            : $this->buildLinearReplayPlan($newVersion, $graph, $latestAnswersByStable);

        $fallbackStableKey = $plan['fallback_stable_key'];
        $keptStableKeys = $plan['kept_stable_keys'];
        $replayReason = $plan['replay_reason'];

        // Nuclear rule: if no stable fallback can be computed, restart from entry with no answers.
        if ($fallbackStableKey === null && empty($keptStableKeys)) {
            $restartStableKey = $this->resolveEntryFallbackStableKey($newVersion, $graph);
            if ($restartStableKey !== null) {
                $fallbackStableKey = $restartStableKey;
                $replayReason = 'restart_from_entry';
            }
        }

        $droppedStableKeys = [];
        foreach (array_keys($latestAnswersByStable) as $stableKey) {
            if (! in_array($stableKey, $keptStableKeys, true)) {
                $droppedStableKeys[] = $stableKey;
            }
        }

        return [
            'fallback_stable_key' => $fallbackStableKey,
            'kept_stable_keys' => $keptStableKeys,
            'dropped_stable_keys' => $droppedStableKeys,
            'replay_reason' => $replayReason,
        ];
    }

    /**
     * @param  array{
     *   questions: \Illuminate\Support\Collection<int, \App\Models\SurveyQuestion>,
     *   questions_by_stable_key: array<string, \App\Models\SurveyQuestion>,
     *   adjacency: array<int, array<int, array<string, mixed>>>
     * }  $graph
     * @param  array<string, \App\Models\SurveyAnswer>  $latestAnswersByStable
     * @return array{
     *   fallback_stable_key: ?string,
     *   kept_stable_keys: array<int, string>,
     *   replay_reason: string
     * }
     */
    protected function buildMultipleChoiceReplayPlan(
        SurveyVersion $newVersion,
        array $graph,
        array $latestAnswersByStable,
    ): array {
        $replayedAnswers = [];
        $keptStableKeys = [];
        $fallbackStableKey = null;
        $replayReason = 'all_answers_replayed';
        $questionCount = max(1, count($graph['questions_by_stable_key']));
        $guard = $questionCount + 2;

        for ($iteration = 0; $iteration < $guard; $iteration++) {
            $visibility = $this->visibilityEngine->calculate($newVersion, $replayedAnswers);
            $nextStableKey = null;

            foreach ($visibility['visible_stable_keys'] as $stableKey) {
                if (! array_key_exists($stableKey, $replayedAnswers)) {
                    $nextStableKey = $stableKey;
                    break;
                }
            }

            if ($nextStableKey === null) {
                break;
            }

            $question = $graph['questions_by_stable_key'][$nextStableKey] ?? null;
            if (! $question) {
                $replayReason = 'next_question_missing_in_schema';
                break;
            }

            if ($question->type === 'result') {
                $fallbackStableKey = $nextStableKey;
                $replayReason = 'result_node_reached';
                break;
            }

            $storedAnswer = $latestAnswersByStable[$nextStableKey] ?? null;
            if (! $storedAnswer) {
                $fallbackStableKey = $nextStableKey;
                $replayReason = 'missing_answer_for_visible_node';
                break;
            }

            $validated = $this->validateStoredAnswerForReplay($newVersion, $question, $storedAnswer->answer_value);
            if (! $validated['valid']) {
                $fallbackStableKey = $nextStableKey;
                $replayReason = $validated['reason'] ?? 'invalid_answer';
                break;
            }

            $normalizedAnswer = $validated['value'];
            if (! $this->canTraverseFromAnswer($question, $normalizedAnswer, $graph)) {
                $fallbackStableKey = $nextStableKey;
                $replayReason = 'no_matching_outgoing_edge';
                break;
            }

            $replayedAnswers[$nextStableKey] = $normalizedAnswer;
            $keptStableKeys[] = $nextStableKey;
        }

        return [
            'fallback_stable_key' => $fallbackStableKey,
            'kept_stable_keys' => $keptStableKeys,
            'replay_reason' => $replayReason,
        ];
    }

    /**
     * @param  array{
     *   questions: \Illuminate\Support\Collection<int, \App\Models\SurveyQuestion>
     * }  $graph
     * @param  array<string, \App\Models\SurveyAnswer>  $latestAnswersByStable
     * @return array{
     *   fallback_stable_key: ?string,
     *   kept_stable_keys: array<int, string>,
     *   replay_reason: string
     * }
     */
    protected function buildLinearReplayPlan(
        SurveyVersion $newVersion,
        array $graph,
        array $latestAnswersByStable,
    ): array {
        $keptStableKeys = [];
        $fallbackStableKey = null;
        $replayReason = 'all_answers_replayed';

        $orderedQuestions = $graph['questions']
            ->sortBy([['order_index', 'asc'], ['id', 'asc']])
            ->values();

        foreach ($orderedQuestions as $question) {
            if ($question->type === 'result') {
                $fallbackStableKey = $question->stable_key;
                $replayReason = 'result_node_reached';
                break;
            }

            $storedAnswer = $latestAnswersByStable[$question->stable_key] ?? null;
            if (! $storedAnswer) {
                $fallbackStableKey = $question->stable_key;
                $replayReason = 'missing_answer_for_visible_node';
                break;
            }

            $validated = $this->validateStoredAnswerForReplay($newVersion, $question, $storedAnswer->answer_value);
            if (! $validated['valid']) {
                $fallbackStableKey = $question->stable_key;
                $replayReason = $validated['reason'] ?? 'invalid_answer';
                break;
            }

            $keptStableKeys[] = $question->stable_key;
        }

        return [
            'fallback_stable_key' => $fallbackStableKey,
            'kept_stable_keys' => $keptStableKeys,
            'replay_reason' => $replayReason,
        ];
    }

    /**
     * @param  array{
     *   questions: \Illuminate\Support\Collection<int, \App\Models\SurveyQuestion>
     * }  $graph
     */
    protected function resolveEntryFallbackStableKey(SurveyVersion $newVersion, array $graph): ?string
    {
        $questions = $graph['questions'];

        if (($newVersion->survey->survey_type ?? 'multiple_choice') === 'multiple_choice') {
            $entry = $questions
                ->where('is_entry', true)
                ->sortBy([['order_index', 'asc'], ['id', 'asc']])
                ->first();

            if ($entry) {
                return $entry->stable_key;
            }
        }

        $first = $questions
            ->sortBy([['order_index', 'asc'], ['id', 'asc']])
            ->first();

        return $first?->stable_key;
    }

    /**
     * @return array{
     *   valid: bool,
     *   value: mixed,
     *   reason: ?string
     * }
     */
    protected function validateStoredAnswerForReplay(
        SurveyVersion $newVersion,
        SurveyQuestion $question,
        mixed $rawStoredValue,
    ): array {
        if (($newVersion->survey->survey_type ?? null) === 'rating' || $question->type === 'rating') {
            if (! is_numeric($rawStoredValue)) {
                return ['valid' => false, 'value' => null, 'reason' => 'invalid_rating_answer'];
            }

            $value = (int) $rawStoredValue;
            $count = (int) (($newVersion->schema_meta['rating_scale']['count'] ?? 5));
            $count = max(2, min(10, $count));

            if ($value < 1 || $value > $count) {
                return ['valid' => false, 'value' => null, 'reason' => 'invalid_rating_answer'];
            }

            return ['valid' => true, 'value' => $value, 'reason' => null];
        }

        if (($newVersion->survey->survey_type ?? null) === 'open_ended'
            || in_array($question->type, ['text', 'open_ended'], true)) {
            $text = trim((string) $rawStoredValue);
            if ($text === '' || mb_strlen($text) > 5000) {
                return ['valid' => false, 'value' => null, 'reason' => 'invalid_open_ended_answer'];
            }

            return ['valid' => true, 'value' => $text, 'reason' => null];
        }

        $stringValue = is_scalar($rawStoredValue) ? trim((string) $rawStoredValue) : '';
        if ($stringValue === '') {
            return ['valid' => false, 'value' => null, 'reason' => 'invalid_multiple_choice_answer'];
        }

        if ($question->options->isNotEmpty()) {
            $optionExists = $question->options->contains(
                fn ($option) => (string) $option->value === $stringValue
            );

            if (! $optionExists) {
                return ['valid' => false, 'value' => null, 'reason' => 'option_removed'];
            }

            return ['valid' => true, 'value' => $stringValue, 'reason' => null];
        }

        return [
            'valid' => true,
            'value' => $this->normalizeValue($rawStoredValue),
            'reason' => null,
        ];
    }

    /**
     * @param  array{
     *   adjacency: array<int, array<int, array<string, mixed>>>
     * }  $graph
     */
    protected function canTraverseFromAnswer(SurveyQuestion $question, mixed $answerValue, array $graph): bool
    {
        $outgoing = $graph['adjacency'][$question->id] ?? [];
        if (empty($outgoing)) {
            return false;
        }

        foreach ($outgoing as $edge) {
            if ($this->edgeConditionPasses(
                (string) ($edge['condition_operator'] ?? 'equals'),
                $edge['condition_value'] ?? null,
                $answerValue
            )) {
                return true;
            }
        }

        return false;
    }

    protected function edgeConditionPasses(string $operator, mixed $conditionValue, mixed $parentAnswer): bool
    {
        if ($operator === 'always') {
            return true;
        }

        if ($parentAnswer === null) {
            return false;
        }

        $actual = $this->normalizeValue($parentAnswer);
        $expected = $this->normalizeValue($conditionValue);

        return match ($operator) {
            'equals' => $actual === $expected,
            'not_equals' => $actual !== $expected,
            'in' => $this->inConditionPasses($actual, $conditionValue),
            'contains' => $this->containsConditionPasses($actual, $expected),
            'gt' => is_numeric($actual) && is_numeric($expected) && $actual > $expected,
            'gte' => is_numeric($actual) && is_numeric($expected) && $actual >= $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && $actual < $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && $actual <= $expected,
            default => false,
        };
    }

    protected function inConditionPasses(mixed $actual, mixed $rawExpected): bool
    {
        $expectedValues = $this->normalizeList($rawExpected);

        if (is_array($actual)) {
            foreach ($actual as $value) {
                if (in_array($this->normalizeValue($value), $expectedValues, true)) {
                    return true;
                }
            }

            return false;
        }

        return in_array($actual, $expectedValues, true);
    }

    protected function containsConditionPasses(mixed $actual, mixed $expected): bool
    {
        if (is_array($actual)) {
            return in_array($expected, array_map(fn ($value) => $this->normalizeValue($value), $actual), true);
        }

        return str_contains((string) $actual, (string) $expected);
    }

    protected function normalizeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_map(fn ($item) => $this->normalizeValue($item), $value);
        }

        if (! is_string($value)) {
            return [$this->normalizeValue($value)];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_map(fn ($item) => $this->normalizeValue($item), $decoded);
        }

        if (str_contains($value, ',')) {
            return array_map(fn ($item) => $this->normalizeValue(trim($item)), explode(',', $value));
        }

        return [$this->normalizeValue($value)];
    }

    protected function normalizeValue(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return array_map(fn ($item) => $this->normalizeValue($item), $value);
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

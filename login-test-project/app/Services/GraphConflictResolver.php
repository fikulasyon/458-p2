<?php

namespace App\Services;

use App\Models\SurveyQuestion;
use App\Models\SurveySession;
use App\Models\SurveyVersion;

class GraphConflictResolver
{
    public function __construct(
        protected SurveyGraphBuilder $graphBuilder,
        protected SurveyVisibilityEngine $visibilityEngine,
    ) {}

    /**
     * @return array{
     *   conflict_detected:bool,
     *   conflict_type:?string,
     *   can_atomic_recovery:bool,
     *   details:array<string, mixed>,
     *   answers_by_stable_key:array<string, mixed>,
     *   visible_questions:array<int, string>
     * }
     */
    public function detectConflict(SurveySession $session, SurveyVersion $newVersion): array
    {
        $session->loadMissing([
            'answers' => fn ($query) => $query->where('is_active', true)->orderBy('id'),
            'currentQuestion',
            'currentVersion.questions.options',
            'startedVersion.questions.options',
        ]);

        $graph = $this->graphBuilder->build($newVersion);
        $newQuestionsByStableKey = $graph['questions_by_stable_key'];
        $surveyType = $newVersion->survey->survey_type ?? 'multiple_choice';

        $answersByStableKey = [];
        foreach ($session->answers as $answer) {
            $answersByStableKey[$answer->question_stable_key] = $answer->answer_value;
        }

        $missingAnswerNodes = [];
        foreach ($answersByStableKey as $stableKey => $answerValue) {
            if (! isset($newQuestionsByStableKey[$stableKey])) {
                $missingAnswerNodes[] = $stableKey;
            }
        }

        $mappedAnswers = array_diff_key($answersByStableKey, array_flip($missingAnswerNodes));

        if ($surveyType !== 'multiple_choice') {
            return $this->detectLinearConflict(
                $session,
                $graph,
                $newQuestionsByStableKey,
                $mappedAnswers,
                $missingAnswerNodes,
            );
        }

        $visibility = $this->visibilityEngine->calculate($newVersion, $mappedAnswers);
        $visibleQuestions = $visibility['visible_stable_keys'];

        $unreachableAnswerNodes = [];
        foreach (array_keys($mappedAnswers) as $stableKey) {
            if (! in_array($stableKey, $visibleQuestions, true)) {
                $unreachableAnswerNodes[] = $stableKey;
            }
        }

        $currentStableKey = $this->resolveCurrentStableKey($session);
        $currentNodeMissing = $currentStableKey !== null && ! isset($newQuestionsByStableKey[$currentStableKey]);
        $currentNodeUnreachable = $currentStableKey !== null
            && isset($newQuestionsByStableKey[$currentStableKey])
            && ! in_array($currentStableKey, $visibleQuestions, true);

        $conflictType = null;
        if ($currentNodeMissing) {
            $conflictType = 'current_node_missing';
        } elseif ($currentNodeUnreachable) {
            $conflictType = 'current_node_unreachable';
        } elseif (! empty($unreachableAnswerNodes)) {
            $conflictType = 'answer_path_inconsistent';
        } elseif (! empty($missingAnswerNodes)) {
            $conflictType = 'missing_answer_nodes';
        }

        return [
            'conflict_detected' => $conflictType !== null,
            'conflict_type' => $conflictType,
            'can_atomic_recovery' => $conflictType === null,
            'details' => [
                'current_stable_key' => $currentStableKey,
                'missing_answer_nodes' => $missingAnswerNodes,
                'unreachable_answer_nodes' => $unreachableAnswerNodes,
                'visible_questions' => $visibleQuestions,
                'content_changes' => [],
            ],
            'answers_by_stable_key' => $mappedAnswers,
            'visible_questions' => $visibleQuestions,
        ];
    }

    /**
     * @param  array{
     *   questions: \Illuminate\Support\Collection<int, \App\Models\SurveyQuestion>,
     *   questions_by_stable_key: array<string, \App\Models\SurveyQuestion>
     * }  $graph
     * @param  array<string, mixed>  $newQuestionsByStableKey
     * @param  array<string, mixed>  $mappedAnswers
     * @param  array<int, string>  $missingAnswerNodes
     * @return array{
     *   conflict_detected:bool,
     *   conflict_type:?string,
     *   can_atomic_recovery:bool,
     *   details:array<string, mixed>,
     *   answers_by_stable_key:array<string, mixed>,
     *   visible_questions:array<int, string>
     * }
     */
    protected function detectLinearConflict(
        SurveySession $session,
        array $graph,
        array $newQuestionsByStableKey,
        array $mappedAnswers,
        array $missingAnswerNodes,
    ): array {
        $orderedStableKeys = $graph['questions']
            ->sortBy([['order_index', 'asc'], ['id', 'asc']])
            ->pluck('stable_key')
            ->values()
            ->all();

        $sequence = $this->linearSequenceDiagnostics($orderedStableKeys, $mappedAnswers);
        $unreachableAnswerNodes = $sequence['answered_after_gap'];

        $currentStableKey = $this->resolveCurrentStableKey($session);
        $currentNodeMissing = $currentStableKey !== null && ! isset($newQuestionsByStableKey[$currentStableKey]);
        $currentNodeOutOfSequence = $this->linearCurrentNodeOutOfSequence(
            $currentStableKey,
            $sequence['first_unanswered_stable_key'],
        );

        $conflictType = null;
        if ($currentNodeMissing) {
            $conflictType = 'current_node_missing';
        } elseif (! empty($missingAnswerNodes)) {
            $conflictType = 'missing_answer_nodes';
        } elseif (! empty($unreachableAnswerNodes)) {
            $conflictType = 'answer_path_inconsistent';
        } elseif ($currentNodeOutOfSequence) {
            $conflictType = 'current_node_unreachable';
        }

        return [
            'conflict_detected' => $conflictType !== null,
            'conflict_type' => $conflictType,
            'can_atomic_recovery' => $conflictType === null,
            'details' => [
                'current_stable_key' => $currentStableKey,
                'missing_answer_nodes' => $missingAnswerNodes,
                'unreachable_answer_nodes' => $unreachableAnswerNodes,
                'first_unanswered_stable_key' => $sequence['first_unanswered_stable_key'],
                'visible_questions' => $orderedStableKeys,
                'content_changes' => [],
            ],
            'answers_by_stable_key' => $mappedAnswers,
            'visible_questions' => $orderedStableKeys,
        ];
    }

    /**
     * @param  array<int, string>  $orderedStableKeys
     * @param  array<string, mixed>  $mappedAnswers
     * @return array{
     *   first_unanswered_stable_key: ?string,
     *   answered_after_gap: array<int, string>
     * }
     */
    protected function linearSequenceDiagnostics(array $orderedStableKeys, array $mappedAnswers): array
    {
        $answeredLookup = array_fill_keys(array_keys($mappedAnswers), true);
        $firstUnansweredStableKey = null;
        $answeredAfterGap = [];
        $gapStarted = false;

        foreach ($orderedStableKeys as $stableKey) {
            $isAnswered = isset($answeredLookup[$stableKey]);

            if (! $isAnswered) {
                if (! $gapStarted) {
                    $gapStarted = true;
                    $firstUnansweredStableKey = $stableKey;
                }

                continue;
            }

            if ($gapStarted) {
                $answeredAfterGap[] = $stableKey;
            }
        }

        return [
            'first_unanswered_stable_key' => $firstUnansweredStableKey,
            'answered_after_gap' => array_values(array_unique($answeredAfterGap)),
        ];
    }

    /**
     * @param  array<int, string>  $answeredStableKeys
     */
    protected function linearCurrentNodeOutOfSequence(
        ?string $currentStableKey,
        ?string $firstUnansweredStableKey,
    ): bool {
        if ($currentStableKey === null || $firstUnansweredStableKey === null) {
            return false;
        }

        return $currentStableKey !== $firstUnansweredStableKey;
    }

    protected function resolveCurrentStableKey(SurveySession $session): ?string
    {
        if ($session->currentQuestion) {
            return $session->currentQuestion->stable_key;
        }

        if (! $session->current_question_id) {
            return null;
        }

        return SurveyQuestion::query()->whereKey($session->current_question_id)->value('stable_key');
    }
}

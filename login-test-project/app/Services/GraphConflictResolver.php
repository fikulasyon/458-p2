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
    ) {
    }

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
        ]);

        $graph = $this->graphBuilder->build($newVersion);
        $newQuestionsByStableKey = $graph['questions_by_stable_key'];

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
            'can_atomic_recovery' => ! $currentNodeUnreachable && empty($unreachableAnswerNodes),
            'details' => [
                'current_stable_key' => $currentStableKey,
                'missing_answer_nodes' => $missingAnswerNodes,
                'unreachable_answer_nodes' => $unreachableAnswerNodes,
                'visible_questions' => $visibleQuestions,
            ],
            'answers_by_stable_key' => $mappedAnswers,
            'visible_questions' => $visibleQuestions,
        ];
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

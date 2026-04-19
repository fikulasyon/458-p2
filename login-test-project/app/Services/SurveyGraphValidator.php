<?php

namespace App\Services;

use App\Models\SurveyVersion;

class SurveyGraphValidator
{
    public function __construct(
        protected SurveyGraphBuilder $graphBuilder,
    ) {
    }

    /**
     * @param  SurveyVersion|int  $version
     * @return array{
     *   is_valid:bool,
     *   errors:array<int, array{code:string, message:string}>,
     *   topological_order:array<int, int>
     * }
     */
    public function validateVersion(SurveyVersion|int $version): array
    {
        return $this->validateGraph($this->graphBuilder->build($version));
    }

    /**
     * @param  array<string, mixed>  $graph
     * @return array{
     *   is_valid:bool,
     *   errors:array<int, array{code:string, message:string}>,
     *   topological_order:array<int, int>
     * }
     */
    public function validateGraph(array $graph): array
    {
        $errors = [];
        $questionsById = $graph['questions_by_id'];
        $incoming = $graph['incoming'];
        $adjacency = $graph['adjacency'];

        if (empty($questionsById)) {
            $errors[] = [
                'code' => 'no_questions',
                'message' => 'Survey version must contain at least one question.',
            ];
        }

        if (empty($graph['entry_question_ids'])) {
            $errors[] = [
                'code' => 'missing_entry_node',
                'message' => 'Survey version must define at least one entry question.',
            ];
        }

        foreach ($adjacency as $fromQuestionId => $edges) {
            if (! isset($questionsById[$fromQuestionId])) {
                $errors[] = [
                    'code' => 'invalid_parent_reference',
                    'message' => "Edge parent question [{$fromQuestionId}] does not exist in this version.",
                ];
            }

            foreach ($edges as $edge) {
                if (! isset($questionsById[$edge['to_question_id']])) {
                    $errors[] = [
                        'code' => 'invalid_child_reference',
                        'message' => "Edge child question [{$edge['to_question_id']}] does not exist in this version.",
                    ];
                }
            }
        }

        $topologicalOrder = $this->topologicalOrder($questionsById, $incoming, $adjacency);

        if (count($topologicalOrder) !== count($questionsById)) {
            $errors[] = [
                'code' => 'cycle_detected',
                'message' => 'Survey graph must be acyclic (DAG); a cycle was detected.',
            ];
        }

        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'topological_order' => $topologicalOrder,
        ];
    }

    /**
     * @param  array<int, mixed>  $questionsById
     * @param  array<int, array<int, array<string, mixed>>>  $incoming
     * @param  array<int, array<int, array<string, mixed>>>  $adjacency
     * @return array<int, int>
     */
    protected function topologicalOrder(array $questionsById, array $incoming, array $adjacency): array
    {
        $inDegree = [];
        foreach ($questionsById as $questionId => $question) {
            $inDegree[$questionId] = count($incoming[$questionId] ?? []);
        }

        $queue = [];
        foreach ($inDegree as $questionId => $count) {
            if ($count === 0) {
                $queue[] = $questionId;
            }
        }

        $order = [];
        while (! empty($queue)) {
            $current = array_shift($queue);
            $order[] = $current;

            foreach ($adjacency[$current] ?? [] as $edge) {
                $child = $edge['to_question_id'];
                $inDegree[$child]--;
                if ($inDegree[$child] === 0) {
                    $queue[] = $child;
                }
            }
        }

        return $order;
    }
}

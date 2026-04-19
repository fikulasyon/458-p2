<?php

namespace App\Services;

use App\Models\SurveyQuestion;
use App\Models\SurveyVersion;
use Illuminate\Support\Collection;

class SurveyGraphBuilder
{
    /**
     * @param  SurveyVersion|int  $version
     * @return array{
     *   version:SurveyVersion,
     *   questions:Collection<int, SurveyQuestion>,
     *   questions_by_id:array<int, SurveyQuestion>,
     *   questions_by_stable_key:array<string, SurveyQuestion>,
     *   adjacency:array<int, array<int, array<string, mixed>>>,
     *   incoming:array<int, array<int, array<string, mixed>>>,
     *   entry_question_ids:array<int, int>
     * }
     */
    public function build(SurveyVersion|int $version): array
    {
        $versionModel = $version instanceof SurveyVersion
            ? $version->loadMissing(['survey'])
            : SurveyVersion::query()->findOrFail($version);

        $questions = $versionModel->questions()
            ->with('options')
            ->orderByRaw('coalesce(order_index, 1000000)')
            ->orderBy('id')
            ->get();

        $edges = $versionModel->edges()
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $questionsById = $questions->keyBy('id')->all();
        $questionsByStableKey = $questions->keyBy('stable_key')->all();

        $adjacency = [];
        $incoming = [];

        foreach ($questions as $question) {
            $adjacency[$question->id] = [];
            $incoming[$question->id] = [];
        }

        foreach ($edges as $edge) {
            $payload = [
                'id' => $edge->id,
                'from_question_id' => $edge->from_question_id,
                'to_question_id' => $edge->to_question_id,
                'condition_type' => $edge->condition_type,
                'condition_operator' => $edge->condition_operator,
                'condition_value' => $edge->condition_value,
                'priority' => $edge->priority,
            ];

            $adjacency[$edge->from_question_id][] = $payload;
            $incoming[$edge->to_question_id][] = $payload;
        }

        $entryQuestionIds = $questions
            ->where('is_entry', true)
            ->pluck('id')
            ->values()
            ->all();

        return [
            'version' => $versionModel,
            'questions' => $questions,
            'questions_by_id' => $questionsById,
            'questions_by_stable_key' => $questionsByStableKey,
            'adjacency' => $adjacency,
            'incoming' => $incoming,
            'entry_question_ids' => $entryQuestionIds,
        ];
    }
}

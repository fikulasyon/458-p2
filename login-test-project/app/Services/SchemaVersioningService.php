<?php

namespace App\Services;

use App\Models\QuestionEdge;
use App\Models\QuestionOption;
use App\Models\SurveyQuestion;
use App\Models\SurveyVersion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SchemaVersioningService
{
    public function __construct(
        protected SurveyGraphValidator $graphValidator,
    ) {
    }

    public function cloneDraftFromVersion(SurveyVersion $baseVersion): SurveyVersion
    {
        return DB::transaction(function () use ($baseVersion) {
            $baseVersion->loadMissing(['questions.options', 'edges']);

            $nextVersionNumber = (int) SurveyVersion::query()
                ->where('survey_id', $baseVersion->survey_id)
                ->max('version_number') + 1;

            $draftVersion = SurveyVersion::query()->create([
                'survey_id' => $baseVersion->survey_id,
                'version_number' => $nextVersionNumber,
                'status' => 'draft',
                'base_version_id' => $baseVersion->id,
                'is_active' => false,
                'schema_meta' => array_merge(
                    $baseVersion->schema_meta ?? [],
                    ['cloned_from_version_id' => $baseVersion->id],
                ),
            ]);

            $questionIdMap = [];

            $sourceQuestions = $baseVersion->questions()
                ->with('options')
                ->orderByRaw('coalesce(order_index, 1000000)')
                ->orderBy('id')
                ->get();

            foreach ($sourceQuestions as $question) {
                $newQuestion = SurveyQuestion::query()->create([
                    'survey_version_id' => $draftVersion->id,
                    'stable_key' => $question->stable_key,
                    'title' => $question->title,
                    'type' => $question->type,
                    'is_entry' => $question->is_entry,
                    'order_index' => $question->order_index,
                    'metadata' => $question->metadata,
                ]);

                $questionIdMap[$question->id] = $newQuestion->id;

                foreach ($question->options as $option) {
                    QuestionOption::query()->create([
                        'question_id' => $newQuestion->id,
                        'value' => $option->value,
                        'label' => $option->label,
                        'order_index' => $option->order_index,
                        'metadata' => $option->metadata,
                    ]);
                }
            }

            $sourceEdges = $baseVersion->edges()
                ->orderBy('priority')
                ->orderBy('id')
                ->get();

            foreach ($sourceEdges as $edge) {
                QuestionEdge::query()->create([
                    'survey_version_id' => $draftVersion->id,
                    'from_question_id' => $questionIdMap[$edge->from_question_id],
                    'to_question_id' => $questionIdMap[$edge->to_question_id],
                    'condition_type' => $edge->condition_type,
                    'condition_operator' => $edge->condition_operator,
                    'condition_value' => $edge->condition_value,
                    'priority' => $edge->priority,
                ]);
            }

            return $draftVersion->fresh();
        });
    }

    public function publishVersion(SurveyVersion $version): SurveyVersion
    {
        $validation = $this->graphValidator->validateVersion($version);
        if (! $validation['is_valid']) {
            $firstError = $validation['errors'][0]['message'] ?? 'Unknown DAG validation error.';
            throw new RuntimeException("Cannot publish invalid survey version: {$firstError}");
        }

        return DB::transaction(function () use ($version) {
            SurveyVersion::query()
                ->where('survey_id', $version->survey_id)
                ->update(['is_active' => false]);

            $version->forceFill([
                'status' => 'published',
                'is_active' => true,
                'published_at' => now(),
            ])->save();

            $version->survey()->update([
                'active_version_id' => $version->id,
            ]);

            return $version->fresh();
        });
    }
}

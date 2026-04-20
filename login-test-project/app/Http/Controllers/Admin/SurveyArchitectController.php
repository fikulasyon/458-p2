<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\QuestionEdge;
use App\Models\QuestionOption;
use App\Models\Survey;
use App\Models\SurveyQuestion;
use App\Models\SurveyVersion;
use App\Services\SchemaVersioningService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class SurveyArchitectController extends Controller
{
    public function index(): Response
    {
        $surveys = Survey::query()
            ->with(['activeVersion:id,survey_id,version_number,status,is_active,published_at', 'versions:id,survey_id,version_number,status,is_active,published_at'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function (Survey $survey) {
                $latestDraft = $survey->versions
                    ->where('status', 'draft')
                    ->sortByDesc('version_number')
                    ->first();

                return [
                    'id' => $survey->id,
                    'title' => $survey->title,
                    'description' => $survey->description,
                    'survey_type' => $survey->survey_type,
                    'active_version_id' => $survey->active_version_id,
                    'active_version' => $survey->activeVersion ? [
                        'id' => $survey->activeVersion->id,
                        'version_number' => $survey->activeVersion->version_number,
                        'status' => $survey->activeVersion->status,
                        'published_at' => optional($survey->activeVersion->published_at)->toIso8601String(),
                    ] : null,
                    'latest_draft' => $latestDraft ? [
                        'id' => $latestDraft->id,
                        'version_number' => $latestDraft->version_number,
                    ] : null,
                    'versions' => $survey->versions
                        ->sortByDesc('version_number')
                        ->values()
                        ->map(fn (SurveyVersion $version) => [
                            'id' => $version->id,
                            'version_number' => $version->version_number,
                            'status' => $version->status,
                            'is_active' => (bool) $version->is_active,
                            'published_at' => optional($version->published_at)->toIso8601String(),
                        ]),
                ];
            });

        return Inertia::render('admin/surveys/index', [
            'surveys' => $surveys,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/surveys/create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'survey_type' => ['nullable', Rule::in(['multiple_choice', 'rating', 'open_ended'])],
        ]);

        $survey = Survey::query()->create([
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'survey_type' => $data['survey_type'] ?? 'multiple_choice',
            'created_by' => $request->user()->id,
        ]);

        $version = SurveyVersion::query()->create([
            'survey_id' => $survey->id,
            'version_number' => 1,
            'status' => 'draft',
            'is_active' => false,
            'schema_meta' => ['created_in_architect' => true],
        ]);

        return redirect()->route('admin.surveys.versions.edit', [$survey, $version])
            ->with('status', 'Survey created with draft version v1.');
    }

    public function destroy(Survey $survey): RedirectResponse
    {
        $survey->delete();

        return redirect()->route('admin.surveys.index')
            ->with('status', 'Survey deleted.');
    }

    public function editVersion(Survey $survey, SurveyVersion $version): Response
    {
        $this->ensureVersionBelongsToSurvey($survey, $version);

        $version->load(['questions.options', 'edges.fromQuestion.options', 'edges.toQuestion']);

        $versions = SurveyVersion::query()
            ->where('survey_id', $survey->id)
            ->orderByDesc('version_number')
            ->get();

        $surveyPayload = [
            'id' => $survey->id,
            'title' => $survey->title,
            'description' => $survey->description,
            'survey_type' => $survey->survey_type,
            'active_version_id' => $survey->active_version_id,
        ];

        $versionPayload = [
            'id' => $version->id,
            'version_number' => $version->version_number,
            'status' => $version->status,
            'is_active' => (bool) $version->is_active,
            'base_version_id' => $version->base_version_id,
            'published_at' => optional($version->published_at)->toIso8601String(),
        ];

        $versionsPayload = $versions->map(fn (SurveyVersion $item) => [
            'id' => $item->id,
            'version_number' => $item->version_number,
            'status' => $item->status,
            'is_active' => (bool) $item->is_active,
            'published_at' => optional($item->published_at)->toIso8601String(),
        ]);

        $questionsPayload = $version->questions
            ->sortBy([['order_index', 'asc'], ['id', 'asc']])
            ->values()
            ->map(fn (SurveyQuestion $question) => [
                'id' => $question->id,
                'stable_key' => $question->stable_key,
                'title' => $question->title,
                'type' => $question->type,
                'is_result' => $question->type === 'result',
                'is_entry' => (bool) $question->is_entry,
                'order_index' => $question->order_index,
                'metadata' => $question->metadata,
                'options' => $question->options
                    ->sortBy([['order_index', 'asc'], ['id', 'asc']])
                    ->values()
                    ->map(fn (QuestionOption $option) => [
                        'id' => $option->id,
                        'value' => $option->value,
                        'label' => $option->label,
                    ]),
            ]);

        if ($survey->survey_type !== 'multiple_choice') {
            $ratingScale = null;
            if ($survey->survey_type === 'rating') {
                $meta = $version->schema_meta ?? [];
                $storedScale = $meta['rating_scale'] ?? null;
                $defaultLabels = ['Very Bad', 'Bad', 'Neutral', 'Good', 'Excellent'];
                $count = (int) ($storedScale['count'] ?? 5);
                $count = max(2, min(10, $count));
                $labels = is_array($storedScale['labels'] ?? null)
                    ? array_values(array_slice($storedScale['labels'], 0, $count))
                    : array_slice($defaultLabels, 0, $count);

                while (count($labels) < $count) {
                    $labels[] = (string) (count($labels) + 1);
                }

                $ratingScale = [
                    'count' => $count,
                    'labels' => $labels,
                ];
            }

            return Inertia::render('admin/surveys/edit-linear-version', [
                'survey' => $surveyPayload,
                'version' => $versionPayload,
                'versions' => $versionsPayload,
                'rating_scale' => $ratingScale,
                'questions' => $questionsPayload
                    ->values()
                    ->map(fn (array $question, int $index) => [
                        'id' => $question['id'],
                        'stable_key' => $question['stable_key'],
                        'title' => $question['title'],
                        'type' => $question['type'],
                        'position' => $index + 1,
                    ]),
            ]);
        }

        return Inertia::render('admin/surveys/edit-version', [
            'survey' => $surveyPayload,
            'version' => $versionPayload,
            'versions' => $versionsPayload,
            'questions' => $questionsPayload,
            'edges' => $version->edges
                ->sortBy([['priority', 'asc'], ['id', 'asc']])
                ->values()
                ->map(function (QuestionEdge $edge) {
                    $fromOption = $edge->fromQuestion?->options
                        ?->firstWhere('value', $edge->condition_value);

                    return [
                        'id' => $edge->id,
                        'from_question_id' => $edge->from_question_id,
                        'from_stable_key' => $edge->fromQuestion?->stable_key,
                        'from_option_id' => $fromOption?->id,
                        'from_option_value' => $edge->condition_value,
                        'to_question_id' => $edge->to_question_id,
                        'to_stable_key' => $edge->toQuestion?->stable_key,
                        'condition_type' => $edge->condition_type,
                        'condition_operator' => $edge->condition_operator,
                        'priority' => $edge->priority,
                    ];
                }),
        ]);
    }

    public function cloneVersion(Survey $survey, SurveyVersion $version, SchemaVersioningService $versioningService): RedirectResponse
    {
        $this->ensureVersionBelongsToSurvey($survey, $version);

        $cloned = $versioningService->cloneDraftFromVersion($version);

        return redirect()->route('admin.surveys.versions.edit', [$survey, $cloned])
            ->with('status', "Draft version v{$cloned->version_number} created.");
    }

    public function publishVersion(Survey $survey, SurveyVersion $version, SchemaVersioningService $versioningService): RedirectResponse
    {
        $this->ensureVersionBelongsToSurvey($survey, $version);

        try {
            $published = $versioningService->publishVersion($version);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'publish' => $exception->getMessage(),
            ]);
        }

        return redirect()->route('admin.surveys.versions.edit', [$survey, $published])
            ->with('status', "Published version v{$published->version_number}.");
    }

    public function destroyVersion(Survey $survey, SurveyVersion $version): RedirectResponse
    {
        $this->ensureVersionBelongsToSurvey($survey, $version);
        $this->ensureVersionIsNotActive($version);

        $versionCount = SurveyVersion::query()
            ->where('survey_id', $survey->id)
            ->count();

        if ($versionCount <= 1) {
            throw ValidationException::withMessages([
                'version_delete' => 'Cannot delete the only version. Delete the survey instead.',
            ]);
        }

        $nextVersion = SurveyVersion::query()
            ->where('survey_id', $survey->id)
            ->whereKeyNot($version->id)
            ->orderByRaw("case when status = 'draft' then 0 else 1 end")
            ->orderByDesc('version_number')
            ->first();

        $version->delete();

        if ($nextVersion) {
            return redirect()->route('admin.surveys.versions.edit', [$survey, $nextVersion])
                ->with('status', 'Draft version deleted.');
        }

        return redirect()->route('admin.surveys.index')
            ->with('status', 'Draft version deleted.');
    }

    public function storeQuestion(Request $request, Survey $survey, SurveyVersion $version): RedirectResponse
    {
        $this->ensureVersionBelongsToSurvey($survey, $version);
        $this->ensureDraftVersion($version);

        $data = $request->validate([
            'stable_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9_\-]+$/',
                Rule::unique('survey_questions', 'stable_key')->where(
                    fn ($query) => $query->where('survey_version_id', $version->id),
                ),
            ],
            'title' => ['required', 'string', 'max:500'],
            'node_kind' => ['nullable', Rule::in(['question', 'result'])],
        ]);

        $isFirstQuestion = ! SurveyQuestion::query()
            ->where('survey_version_id', $version->id)
            ->exists();

        $nextOrderIndex = (int) SurveyQuestion::query()
            ->where('survey_version_id', $version->id)
            ->max('order_index') + 1;

        $type = $this->determineQuestionTypeForSurvey($survey, $data['node_kind'] ?? 'question');

        SurveyQuestion::query()->create([
            'survey_version_id' => $version->id,
            'stable_key' => $data['stable_key'],
            'title' => $data['title'],
            'type' => $type,
            'is_entry' => $isFirstQuestion,
            'order_index' => $nextOrderIndex,
        ]);

        return back()->with('status', 'Question added.');
    }

    public function updateQuestion(Request $request, Survey $survey, SurveyVersion $version, SurveyQuestion $question): RedirectResponse
    {
        $this->ensureVersionBelongsToSurvey($survey, $version);
        $this->ensureDraftVersion($version);
        $this->ensureQuestionBelongsToVersion($question, $version);

        $data = $request->validate([
            'stable_key' => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-zA-Z0-9_\-]+$/',
                Rule::unique('survey_questions', 'stable_key')
                    ->where(fn ($query) => $query->where('survey_version_id', $version->id))
                    ->ignore($question->id),
            ],
            'title' => ['required', 'string', 'max:500'],
            'node_kind' => ['nullable', Rule::in(['question', 'result'])],
        ]);

        $type = $this->determineQuestionTypeForSurvey($survey, $data['node_kind'] ?? 'question');

        $question->update([
            'stable_key' => $data['stable_key'],
            'title' => $data['title'],
            'type' => $type,
        ]);

        if ($type !== 'multiple_choice') {
            $question->options()->delete();
            $this->cleanupInvalidEdgesForQuestion($version, $question);
        }

        return back()->with('status', 'Question updated.');
    }

    public function moveQuestion(Request $request, Survey $survey, SurveyVersion $version, SurveyQuestion $question): RedirectResponse
    {
        $this->ensureVersionBelongsToSurvey($survey, $version);
        $this->ensureDraftVersion($version);
        $this->ensureQuestionBelongsToVersion($question, $version);

        $data = $request->validate([
            'direction' => ['required', Rule::in(['up', 'down'])],
        ]);

        $this->normalizeQuestionOrder($version);

        $orderedIds = SurveyQuestion::query()
            ->where('survey_version_id', $version->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->pluck('id')
            ->values();

        $currentIndex = $orderedIds->search($question->id);
        if ($currentIndex === false) {
            return back();
        }

        $targetIndex = $data['direction'] === 'up'
            ? $currentIndex - 1
            : $currentIndex + 1;

        if ($targetIndex < 0 || $targetIndex >= $orderedIds->count()) {
            return back();
        }

        $targetQuestion = SurveyQuestion::query()->findOrFail((int) $orderedIds[$targetIndex]);
        $currentQuestion = SurveyQuestion::query()->findOrFail((int) $question->id);

        DB::transaction(function () use ($currentQuestion, $targetQuestion): void {
            $currentOrder = (int) $currentQuestion->order_index;
            $targetOrder = (int) $targetQuestion->order_index;

            $currentQuestion->update(['order_index' => $targetOrder]);
            $targetQuestion->update(['order_index' => $currentOrder]);
        });

        $this->normalizeQuestionOrder($version);
        $this->syncEntryNode($version);

        return back()->with('status', 'Question order updated.');
    }

    public function reorderQuestions(Request $request, Survey $survey, SurveyVersion $version): RedirectResponse
    {
        $this->ensureVersionBelongsToSurvey($survey, $version);
        $this->ensureDraftVersion($version);

        $data = $request->validate([
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['integer', Rule::exists('survey_questions', 'id')->where('survey_version_id', $version->id)],
        ]);

        $incoming = array_values(array_map('intval', $data['question_ids']));
        $existing = SurveyQuestion::query()
            ->where('survey_version_id', $version->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        sort($incoming);
        $sortedExisting = $existing;
        sort($sortedExisting);

        if ($incoming !== $sortedExisting) {
            throw ValidationException::withMessages([
                'question_ids' => 'Question reorder payload does not match the current version nodes.',
            ]);
        }

        $orderedIds = array_values(array_map('intval', $data['question_ids']));

        DB::transaction(function () use ($orderedIds): void {
            foreach ($orderedIds as $index => $questionId) {
                SurveyQuestion::query()
                    ->whereKey($questionId)
                    ->update([
                        'order_index' => $index + 1,
                    ]);
            }
        });

        $this->syncEntryNode($version);

        return back()->with('status', 'Question order updated.');
    }

    public function updateRatingScale(Request $request, Survey $survey, SurveyVersion $version): RedirectResponse
    {
        $this->ensureVersionBelongsToSurvey($survey, $version);
        $this->ensureDraftVersion($version);

        if ($survey->survey_type !== 'rating') {
            throw ValidationException::withMessages([
                'survey' => 'Rating scale settings are only available for rating surveys.',
            ]);
        }

        $data = $request->validate([
            'count' => ['required', 'integer', 'min:2', 'max:10'],
            'labels' => ['required', 'array'],
            'labels.*' => ['required', 'string', 'max:80'],
        ]);

        $count = (int) $data['count'];
        $labels = array_values(array_map(
            fn (string $label) => trim($label),
            array_slice($data['labels'], 0, $count),
        ));

        while (count($labels) < $count) {
            $labels[] = (string) (count($labels) + 1);
        }

        $schemaMeta = $version->schema_meta ?? [];
        $schemaMeta['rating_scale'] = [
            'count' => $count,
            'labels' => $labels,
        ];

        $version->update(['schema_meta' => $schemaMeta]);

        return back()->with('status', 'Rating scale updated.');
    }

    public function destroyQuestion(Survey $survey, SurveyVersion $version, SurveyQuestion $question): RedirectResponse
    {
        $this->ensureVersionBelongsToSurvey($survey, $version);
        $this->ensureDraftVersion($version);
        $this->ensureQuestionBelongsToVersion($question, $version);

        DB::transaction(function () use ($question, $version): void {
            $question->delete();
            $this->normalizeQuestionOrder($version);
            $this->syncEntryNode($version);
        });

        return back()->with('status', 'Question deleted.');
    }

    public function storeOption(Request $request, Survey $survey, SurveyVersion $version, SurveyQuestion $question): RedirectResponse
    {
        $this->ensureVersionBelongsToSurvey($survey, $version);
        $this->ensureDraftVersion($version);
        $this->ensureMultipleChoiceSurvey($survey);
        $this->ensureQuestionBelongsToVersion($question, $version);
        $this->ensureQuestionAcceptsOptions($question);

        $data = $request->validate([
            'value' => [
                'required',
                'string',
                'max:100',
                Rule::unique('question_options', 'value')->where(
                    fn ($query) => $query->where('question_id', $question->id),
                ),
            ],
            'label' => ['required', 'string', 'max:255'],
        ]);

        $nextOrderIndex = (int) QuestionOption::query()
            ->where('question_id', $question->id)
            ->max('order_index') + 1;

        QuestionOption::query()->create([
            'question_id' => $question->id,
            'value' => $data['value'],
            'label' => $data['label'],
            'order_index' => $nextOrderIndex,
        ]);

        return back()->with('status', 'Option added.');
    }

    public function updateOption(Request $request, Survey $survey, SurveyVersion $version, SurveyQuestion $question, QuestionOption $option): RedirectResponse
    {
        $this->ensureVersionBelongsToSurvey($survey, $version);
        $this->ensureDraftVersion($version);
        $this->ensureMultipleChoiceSurvey($survey);
        $this->ensureQuestionBelongsToVersion($question, $version);
        $this->ensureQuestionAcceptsOptions($question);
        $this->ensureOptionBelongsToQuestion($option, $question);

        $data = $request->validate([
            'value' => [
                'required',
                'string',
                'max:100',
                Rule::unique('question_options', 'value')
                    ->where(fn ($query) => $query->where('question_id', $question->id))
                    ->ignore($option->id),
            ],
            'label' => ['required', 'string', 'max:255'],
        ]);

        $oldValue = $option->value;

        $option->update([
            'value' => $data['value'],
            'label' => $data['label'],
        ]);

        if ($oldValue !== $data['value']) {
            QuestionEdge::query()
                ->where('survey_version_id', $version->id)
                ->where('from_question_id', $question->id)
                ->where('condition_operator', 'equals')
                ->where('condition_value', $oldValue)
                ->delete();
        }

        $this->cleanupInvalidEdgesForQuestion($version, $question);

        return back()->with('status', 'Option updated.');
    }

    public function destroyOption(Survey $survey, SurveyVersion $version, SurveyQuestion $question, QuestionOption $option): RedirectResponse
    {
        $this->ensureVersionBelongsToSurvey($survey, $version);
        $this->ensureDraftVersion($version);
        $this->ensureMultipleChoiceSurvey($survey);
        $this->ensureQuestionBelongsToVersion($question, $version);
        $this->ensureQuestionAcceptsOptions($question);
        $this->ensureOptionBelongsToQuestion($option, $question);

        $removedValue = $option->value;
        $option->delete();

        QuestionEdge::query()
            ->where('survey_version_id', $version->id)
            ->where('from_question_id', $question->id)
            ->where('condition_operator', 'equals')
            ->where('condition_value', $removedValue)
            ->delete();

        $this->cleanupInvalidEdgesForQuestion($version, $question);

        return back()->with('status', 'Option removed.');
    }

    public function storeEdge(Request $request, Survey $survey, SurveyVersion $version): RedirectResponse
    {
        $this->ensureVersionBelongsToSurvey($survey, $version);
        $this->ensureDraftVersion($version);
        $this->ensureMultipleChoiceSurvey($survey);

        $data = $request->validate([
            'from_option_id' => ['required', 'integer', Rule::exists('question_options', 'id')],
            'to_question_id' => ['required', Rule::exists('survey_questions', 'id')->where('survey_version_id', $version->id)],
        ]);

        $fromOption = QuestionOption::query()->findOrFail((int) $data['from_option_id']);
        $fromQuestion = SurveyQuestion::query()->findOrFail($fromOption->question_id);

        abort_unless($fromQuestion->survey_version_id === $version->id, 422);

        $nextPriority = (int) QuestionEdge::query()
            ->where('survey_version_id', $version->id)
            ->max('priority') + 1;

        QuestionEdge::query()
            ->where('survey_version_id', $version->id)
            ->where('from_question_id', (int) $fromQuestion->id)
            ->where('condition_operator', 'equals')
            ->where('condition_value', $fromOption->value)
            ->delete();

        QuestionEdge::query()->create([
            'survey_version_id' => $version->id,
            'from_question_id' => (int) $fromQuestion->id,
            'to_question_id' => (int) $data['to_question_id'],
            'condition_type' => 'answer',
            'condition_operator' => 'equals',
            'condition_value' => $fromOption->value,
            'priority' => $nextPriority,
        ]);

        return back()->with('status', 'Edge added.');
    }

    public function updateEdge(Request $request, Survey $survey, SurveyVersion $version, QuestionEdge $edge): RedirectResponse
    {
        $this->ensureVersionBelongsToSurvey($survey, $version);
        $this->ensureDraftVersion($version);
        $this->ensureMultipleChoiceSurvey($survey);
        $this->ensureEdgeBelongsToVersion($edge, $version);

        $data = $request->validate([
            'from_option_id' => ['required', 'integer', Rule::exists('question_options', 'id')],
            'to_question_id' => ['required', Rule::exists('survey_questions', 'id')->where('survey_version_id', $version->id)],
        ]);

        $fromOption = QuestionOption::query()->findOrFail((int) $data['from_option_id']);
        $fromQuestion = SurveyQuestion::query()->findOrFail($fromOption->question_id);

        abort_unless($fromQuestion->survey_version_id === $version->id, 422);

        QuestionEdge::query()
            ->where('survey_version_id', $version->id)
            ->where('from_question_id', (int) $fromQuestion->id)
            ->where('condition_operator', 'equals')
            ->where('condition_value', $fromOption->value)
            ->where('id', '!=', $edge->id)
            ->delete();

        $edge->update([
            'from_question_id' => (int) $fromQuestion->id,
            'to_question_id' => (int) $data['to_question_id'],
            'condition_operator' => 'equals',
            'condition_value' => $fromOption->value,
        ]);

        return back()->with('status', 'Edge updated.');
    }

    public function destroyEdge(Survey $survey, SurveyVersion $version, QuestionEdge $edge): RedirectResponse
    {
        $this->ensureVersionBelongsToSurvey($survey, $version);
        $this->ensureDraftVersion($version);
        $this->ensureMultipleChoiceSurvey($survey);
        $this->ensureEdgeBelongsToVersion($edge, $version);

        $edge->delete();

        return back()->with('status', 'Edge removed.');
    }

    protected function ensureVersionBelongsToSurvey(Survey $survey, SurveyVersion $version): void
    {
        abort_unless($version->survey_id === $survey->id, 404);
    }

    protected function ensureQuestionBelongsToVersion(SurveyQuestion $question, SurveyVersion $version): void
    {
        abort_unless($question->survey_version_id === $version->id, 404);
    }

    protected function ensureOptionBelongsToQuestion(QuestionOption $option, SurveyQuestion $question): void
    {
        abort_unless($option->question_id === $question->id, 404);
    }

    protected function ensureEdgeBelongsToVersion(QuestionEdge $edge, SurveyVersion $version): void
    {
        abort_unless($edge->survey_version_id === $version->id, 404);
    }

    protected function ensureDraftVersion(SurveyVersion $version): void
    {
        if ($version->status !== 'draft') {
            throw ValidationException::withMessages([
                'version' => 'Only draft versions can be edited.',
            ]);
        }
    }

    protected function ensureVersionIsNotActive(SurveyVersion $version): void
    {
        if ($version->is_active) {
            throw ValidationException::withMessages([
                'version_delete' => 'Active version cannot be deleted.',
            ]);
        }
    }

    protected function ensureQuestionAcceptsOptions(SurveyQuestion $question): void
    {
        if ($question->type === 'result') {
            throw ValidationException::withMessages([
                'option' => 'Result nodes cannot have answer options.',
            ]);
        }
    }

    protected function ensureMultipleChoiceSurvey(Survey $survey): void
    {
        if ($survey->survey_type !== 'multiple_choice') {
            throw ValidationException::withMessages([
                'survey' => 'Options and edges are only available for multiple-choice surveys.',
            ]);
        }
    }

    protected function determineQuestionTypeForSurvey(Survey $survey, string $nodeKind): string
    {
        return match ($survey->survey_type) {
            'rating' => 'rating',
            'open_ended' => 'text',
            default => $nodeKind === 'result' ? 'result' : 'multiple_choice',
        };
    }

    protected function normalizeQuestionOrder(SurveyVersion $version): void
    {
        $orderedQuestions = SurveyQuestion::query()
            ->where('survey_version_id', $version->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        foreach ($orderedQuestions as $index => $question) {
            $expected = $index + 1;
            if ((int) $question->order_index === $expected) {
                continue;
            }

            $question->update(['order_index' => $expected]);
        }
    }

    protected function syncEntryNode(SurveyVersion $version): void
    {
        $orderedQuestions = SurveyQuestion::query()
            ->where('survey_version_id', $version->id)
            ->orderBy('order_index')
            ->orderBy('id')
            ->get();

        foreach ($orderedQuestions as $index => $question) {
            $isEntry = $index === 0;
            if ((bool) $question->is_entry === $isEntry) {
                continue;
            }

            $question->update(['is_entry' => $isEntry]);
        }
    }

    protected function cleanupInvalidEdgesForQuestion(SurveyVersion $version, SurveyQuestion $question): void
    {
        $validOptionValues = $question->options()->pluck('value')->all();

        $edgesQuery = QuestionEdge::query()
            ->where('survey_version_id', $version->id)
            ->where('from_question_id', $question->id)
            ->where('condition_operator', 'equals');

        if (empty($validOptionValues)) {
            $edgesQuery->delete();
            return;
        }

        $edgesQuery
            ->whereNotIn('condition_value', $validOptionValues)
            ->delete();
    }
}

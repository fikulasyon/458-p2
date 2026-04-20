<?php

namespace App\Services;

use App\Models\QuestionOption;
use App\Models\Survey;
use App\Models\SurveyAnswer;
use App\Models\SurveyQuestion;
use App\Models\SurveySession;
use App\Models\SurveyVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MobileSurveyRuntimeService
{
    public function __construct(
        protected SurveyGraphBuilder $graphBuilder,
        protected SurveyVisibilityEngine $visibilityEngine,
        protected SessionRecoveryService $sessionRecoveryService,
    ) {}

    /**
     * @return Collection<int, Survey>
     */
    public function listPublishedSurveys(): Collection
    {
        return Survey::query()
            ->whereNotNull('active_version_id')
            ->whereHas('activeVersion', function ($query): void {
                $query->where('status', 'published')->where('is_active', true);
            })
            ->with(['activeVersion' => function ($query): void {
                $query->select('id', 'survey_id', 'version_number', 'status', 'published_at', 'schema_meta');
            }])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function resolvePublishedVersion(Survey $survey): SurveyVersion
    {
        $version = SurveyVersion::query()
            ->whereKey($survey->active_version_id)
            ->where('survey_id', $survey->id)
            ->where('status', 'published')
            ->where('is_active', true)
            ->first();

        if (! $version) {
            abort(404, 'Published version not found for survey.');
        }

        return $version->loadMissing(['survey', 'questions.options', 'edges']);
    }

    public function startOrResumeSession(Survey $survey, User $user): SurveySession
    {
        $existing = SurveySession::query()
            ->where('survey_id', $survey->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['in_progress', 'conflict_recovered', 'rolled_back'])
            ->latest('id')
            ->first();

        if ($existing) {
            return $existing->loadMissing(['survey', 'answers', 'currentQuestion', 'currentVersion', 'startedVersion']);
        }

        $version = $this->resolvePublishedVersion($survey);
        $entryQuestion = $this->resolveEntryQuestion($version);

        return SurveySession::query()->create([
            'survey_id' => $survey->id,
            'user_id' => $user->id,
            'started_version_id' => $version->id,
            'current_version_id' => $version->id,
            'current_question_id' => $entryQuestion?->id,
            'status' => 'in_progress',
            'stable_node_key' => $entryQuestion?->stable_key,
            'last_synced_at' => now(),
        ])->loadMissing(['survey', 'answers', 'currentQuestion', 'currentVersion', 'startedVersion']);
    }

    /**
     * @return array{session:SurveySession, active_version:SurveyVersion, version_sync:array<string, mixed>}
     */
    public function syncSessionToActiveVersion(SurveySession $session): array
    {
        $session->loadMissing(['survey', 'answers', 'currentQuestion', 'currentVersion', 'startedVersion']);

        $activeVersion = $this->resolvePublishedVersion($session->survey);
        $currentVersionId = (int) ($session->current_version_id ?: $session->started_version_id);

        if ($currentVersionId === $activeVersion->id) {
            return [
                'session' => $session,
                'active_version' => $activeVersion,
                'version_sync' => [
                    'mismatch_detected' => false,
                    'from_version_id' => $currentVersionId,
                    'to_version_id' => $activeVersion->id,
                    'conflict_detected' => false,
                    'conflict_type' => null,
                    'recovery_strategy' => null,
                    'dropped_answers' => [],
                    'message' => 'Session already matches the active schema.',
                ],
            ];
        }

        $recovery = $this->sessionRecoveryService->reconcileSession($session, $activeVersion);
        Log::info('mobile_session_schema_mismatch_reconciled', [
            'session_id' => $session->id,
            'survey_id' => $session->survey_id,
            'user_id' => $session->user_id,
            'from_version_id' => $currentVersionId,
            'to_version_id' => $activeVersion->id,
            'conflict_detected' => (bool) ($recovery['conflict_detected'] ?? false),
            'conflict_type' => $recovery['conflict_type'] ?? null,
            'recovery_strategy' => $recovery['recovery_strategy'] ?? null,
            'dropped_answers' => $recovery['dropped_answers'] ?? [],
        ]);

        $refreshed = $session->fresh(['survey', 'answers', 'currentQuestion', 'currentVersion', 'startedVersion']);

        return [
            'session' => $refreshed,
            'active_version' => $activeVersion,
            'version_sync' => [
                'mismatch_detected' => true,
                'from_version_id' => $currentVersionId,
                'to_version_id' => $activeVersion->id,
                'conflict_detected' => (bool) ($recovery['conflict_detected'] ?? false),
                'conflict_type' => $recovery['conflict_type'] ?? null,
                'recovery_strategy' => $recovery['recovery_strategy'] ?? null,
                'dropped_answers' => $recovery['dropped_answers'] ?? [],
                'message' => $recovery['message'] ?? 'Session remapped to active schema.',
            ],
        ];
    }

    public function ensureCurrentCursor(SurveySession $session): SurveySession
    {
        $session->loadMissing(['answers', 'currentQuestion', 'currentVersion', 'startedVersion']);

        if ($session->status === 'completed') {
            return $session;
        }

        $version = $session->currentVersion ?: $session->startedVersion;
        if (! $version) {
            return $session;
        }

        $answersByStable = $this->activeAnswerMap($session);
        $visibleStableKeys = $this->resolveVisibleStableKeys($version, $answersByStable);

        $preferred = $session->currentQuestion;
        $nextQuestion = null;

        if ($preferred
            && in_array($preferred->stable_key, $visibleStableKeys, true)
            && ($preferred->type === 'result' || ! array_key_exists($preferred->stable_key, $answersByStable))) {
            $nextQuestion = $preferred;
        }

        if (! $nextQuestion) {
            foreach ($visibleStableKeys as $stableKey) {
                if (! array_key_exists($stableKey, $answersByStable)) {
                    $nextQuestion = $version->questions->firstWhere('stable_key', $stableKey);
                    break;
                }
            }
        }

        $currentQuestionId = $nextQuestion?->id;

        if ((int) ($session->current_question_id ?? 0) !== (int) ($currentQuestionId ?? 0)) {
            $session->forceFill([
                'current_question_id' => $currentQuestionId,
                'last_synced_at' => now(),
            ])->save();

            $session = $session->fresh(['answers', 'currentQuestion', 'currentVersion', 'startedVersion']);
        }

        return $session;
    }

    public function submitAnswer(SurveySession $session, string $questionStableKey, mixed $answerValue): SurveySession
    {
        $session->loadMissing(['answers', 'currentQuestion', 'currentVersion', 'startedVersion']);
        if ($session->status === 'completed') {
            throw ValidationException::withMessages([
                'session' => 'Completed sessions cannot accept new answers.',
            ]);
        }

        $session = $this->ensureCurrentCursor($session);
        $version = $session->currentVersion ?: $session->startedVersion;

        if (! $version) {
            throw ValidationException::withMessages([
                'session' => 'Session has no active schema version.',
            ]);
        }

        $question = $version->questions->firstWhere('stable_key', $questionStableKey);
        if (! $question) {
            throw ValidationException::withMessages([
                'question_stable_key' => 'Question is not part of this session version.',
            ]);
        }

        if (! $session->currentQuestion || $session->currentQuestion->stable_key !== $questionStableKey) {
            throw ValidationException::withMessages([
                'question_stable_key' => 'Answer must target the current question.',
            ]);
        }

        if ($question->type === 'result') {
            throw ValidationException::withMessages([
                'question_stable_key' => 'Result nodes do not accept answers.',
            ]);
        }

        $normalizedAnswer = $this->validateAndNormalizeAnswer($version, $question, $answerValue);
        $storedValue = $this->storeValue($normalizedAnswer);

        DB::transaction(function () use ($session, $question, $version, $questionStableKey, $storedValue): void {
            SurveyAnswer::query()
                ->where('session_id', $session->id)
                ->where('question_stable_key', $questionStableKey)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            SurveyAnswer::query()->create([
                'session_id' => $session->id,
                'question_stable_key' => $questionStableKey,
                'question_id' => $question->id,
                'answer_value' => $storedValue,
                'valid_under_version_id' => $version->id,
                'is_active' => true,
            ]);

            $session->forceFill([
                'stable_node_key' => $questionStableKey,
                'status' => 'in_progress',
                'last_synced_at' => now(),
            ])->save();
        });

        $session = $session->fresh(['answers', 'currentQuestion', 'currentVersion', 'startedVersion']);

        return $this->ensureCurrentCursor($session);
    }

    public function completeSession(SurveySession $session): SurveySession
    {
        $session->loadMissing(['answers', 'currentQuestion', 'currentVersion', 'startedVersion']);
        $session = $this->ensureCurrentCursor($session);

        $state = $this->buildSessionState($session);

        if (! ($state['can_complete'] ?? false)) {
            throw ValidationException::withMessages([
                'session' => 'Session cannot be completed before reaching a valid terminal state.',
            ]);
        }

        $finalStableKey = $state['result']['stable_key'] ?? $session->stable_node_key;

        $session->forceFill([
            'status' => 'completed',
            'current_question_id' => null,
            'stable_node_key' => $finalStableKey,
            'last_synced_at' => now(),
        ])->save();

        return $session->fresh(['answers', 'currentQuestion', 'currentVersion', 'startedVersion']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildAnswerSummary(SurveySession $session): array
    {
        $session->loadMissing(['answers.question', 'currentVersion.questions', 'startedVersion.questions']);
        $version = $session->currentVersion ?: $session->startedVersion;
        $questionsByStable = $version?->questions?->keyBy('stable_key');

        return $session->answers
            ->where('is_active', true)
            ->sortBy('id')
            ->values()
            ->map(function (SurveyAnswer $answer) use ($questionsByStable): array {
                $fallbackQuestion = $questionsByStable?->get($answer->question_stable_key);
                $questionTitle = $answer->question?->title
                    ?? $fallbackQuestion?->title
                    ?? $answer->question_stable_key;
                $questionType = $answer->question?->type
                    ?? $fallbackQuestion?->type
                    ?? 'unknown';

                return [
                    'question_stable_key' => $answer->question_stable_key,
                    'question_title' => $questionTitle,
                    'question_type' => $questionType,
                    'answer_value' => $this->parseStoredValue($answer->answer_value),
                ];
            })
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSessionState(SurveySession $session): array
    {
        $session->loadMissing(['answers', 'currentQuestion', 'currentVersion', 'startedVersion']);
        $version = $session->currentVersion ?: $session->startedVersion;

        if (! $version) {
            return [
                'session_status' => $session->status,
                'current_question' => null,
                'visible_questions' => [],
                'answers' => (object) [],
                'can_complete' => false,
                'result' => null,
            ];
        }

        $answersByStable = $this->activeAnswerMap($session);
        $visibleStableKeys = $this->resolveVisibleStableKeys($version, $answersByStable);

        $currentQuestion = $session->currentQuestion;
        if ($currentQuestion && ! in_array($currentQuestion->stable_key, $visibleStableKeys, true)) {
            $currentQuestion = null;
        }

        $resultQuestion = null;
        if ($currentQuestion && $currentQuestion->type === 'result') {
            $resultQuestion = $currentQuestion;
        } elseif ($session->stable_node_key) {
            $candidate = $version->questions->firstWhere('stable_key', $session->stable_node_key);
            if ($candidate && $candidate->type === 'result') {
                $resultQuestion = $candidate;
            }
        }

        $canComplete = $this->canComplete($version, $currentQuestion, $answersByStable, $session->status);

        return [
            'session_status' => $session->status,
            'current_question' => $currentQuestion ? $this->questionPayload($currentQuestion) : null,
            'visible_questions' => $visibleStableKeys,
            // Force object shape so Android map deserialization stays stable even when empty.
            'answers' => (object) $answersByStable,
            'can_complete' => $canComplete,
            'result' => $resultQuestion ? [
                'stable_key' => $resultQuestion->stable_key,
                'title' => $resultQuestion->title,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schemaPayload(SurveyVersion $version): array
    {
        $version->loadMissing(['survey', 'questions.options', 'edges']);

        $questions = $version->questions
            ->sortBy([['order_index', 'asc'], ['id', 'asc']])
            ->values()
            ->map(fn (SurveyQuestion $question) => [
                'id' => $question->id,
                'stable_key' => $question->stable_key,
                'title' => $question->title,
                'type' => $question->type,
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
                        'order_index' => $option->order_index,
                    ]),
            ]);

        $questionsById = $version->questions->keyBy('id');
        $edges = $version->edges
            ->sortBy([['priority', 'asc'], ['id', 'asc']])
            ->values()
            ->map(fn ($edge) => [
                'id' => $edge->id,
                'from_question_id' => $edge->from_question_id,
                'from_stable_key' => $questionsById[$edge->from_question_id]->stable_key ?? null,
                'to_question_id' => $edge->to_question_id,
                'to_stable_key' => $questionsById[$edge->to_question_id]->stable_key ?? null,
                'condition_operator' => $edge->condition_operator,
                'condition_value' => $edge->condition_value,
                'priority' => $edge->priority,
            ]);

        return [
            'survey' => [
                'id' => $version->survey->id,
                'title' => $version->survey->title,
                'description' => $version->survey->description,
                'survey_type' => $version->survey->survey_type,
            ],
            'version' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'status' => $version->status,
                'published_at' => optional($version->published_at)->toIso8601String(),
                'schema_meta' => $version->schema_meta,
            ],
            'schema' => [
                'questions' => $questions,
                'edges' => $edges,
            ],
        ];
    }

    protected function resolveEntryQuestion(SurveyVersion $version): ?SurveyQuestion
    {
        $version->loadMissing(['survey', 'questions']);

        if ($version->survey->survey_type === 'multiple_choice') {
            return $version->questions
                ->sortBy([['order_index', 'asc'], ['id', 'asc']])
                ->firstWhere('is_entry', true)
                ?: $version->questions->sortBy([['order_index', 'asc'], ['id', 'asc']])->first();
        }

        return $version->questions
            ->sortBy([['order_index', 'asc'], ['id', 'asc']])
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function activeAnswerMap(SurveySession $session): array
    {
        $activeAnswers = $session->answers
            ->where('is_active', true)
            ->sortBy('id')
            ->pluck('answer_value', 'question_stable_key')
            ->all();

        $normalized = [];
        foreach ($activeAnswers as $stableKey => $rawValue) {
            $normalized[$stableKey] = $this->parseStoredValue($rawValue);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $answersByStable
     * @return array<int, string>
     */
    protected function resolveVisibleStableKeys(SurveyVersion $version, array $answersByStable): array
    {
        $version->loadMissing(['survey', 'questions.options', 'edges']);

        if ($version->survey->survey_type === 'multiple_choice') {
            $visibility = $this->visibilityEngine->calculate($version, $answersByStable);

            return $visibility['visible_stable_keys'];
        }

        return $version->questions
            ->sortBy([['order_index', 'asc'], ['id', 'asc']])
            ->pluck('stable_key')
            ->values()
            ->all();
    }

    protected function canComplete(
        SurveyVersion $version,
        ?SurveyQuestion $currentQuestion,
        array $answersByStable,
        string $sessionStatus,
    ): bool {
        if ($sessionStatus === 'completed') {
            return true;
        }

        if ($version->survey->survey_type === 'multiple_choice') {
            if ($currentQuestion && $currentQuestion->type === 'result') {
                return true;
            }

            return $currentQuestion === null;
        }

        $allStableKeys = $version->questions
            ->sortBy([['order_index', 'asc'], ['id', 'asc']])
            ->pluck('stable_key')
            ->values()
            ->all();

        foreach ($allStableKeys as $stableKey) {
            if (! array_key_exists($stableKey, $answersByStable)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function questionPayload(SurveyQuestion $question): array
    {
        $question->loadMissing('options');

        return [
            'id' => $question->id,
            'stable_key' => $question->stable_key,
            'title' => $question->title,
            'type' => $question->type,
            'is_entry' => (bool) $question->is_entry,
            'metadata' => $question->metadata,
            'options' => $question->options
                ->sortBy([['order_index', 'asc'], ['id', 'asc']])
                ->values()
                ->map(fn (QuestionOption $option) => [
                    'id' => $option->id,
                    'value' => $option->value,
                    'label' => $option->label,
                ]),
        ];
    }

    protected function validateAndNormalizeAnswer(SurveyVersion $version, SurveyQuestion $question, mixed $answerValue): mixed
    {
        if ($version->survey->survey_type === 'rating' || $question->type === 'rating') {
            if (! is_numeric($answerValue)) {
                throw ValidationException::withMessages([
                    'answer_value' => 'Rating answer must be numeric.',
                ]);
            }

            $value = (int) $answerValue;
            $count = (int) (($version->schema_meta['rating_scale']['count'] ?? 5));
            $count = max(2, min(10, $count));

            if ($value < 1 || $value > $count) {
                throw ValidationException::withMessages([
                    'answer_value' => "Rating answer must be between 1 and {$count}.",
                ]);
            }

            return $value;
        }

        if ($version->survey->survey_type === 'open_ended' || $question->type === 'text') {
            if (! is_string($answerValue) || trim($answerValue) === '') {
                throw ValidationException::withMessages([
                    'answer_value' => 'Open-ended answer must be a non-empty string.',
                ]);
            }

            if (mb_strlen($answerValue) > 5000) {
                throw ValidationException::withMessages([
                    'answer_value' => 'Open-ended answer is too long.',
                ]);
            }

            return trim($answerValue);
        }

        $stringValue = is_scalar($answerValue) ? (string) $answerValue : null;
        if ($stringValue === null || trim($stringValue) === '') {
            throw ValidationException::withMessages([
                'answer_value' => 'Multiple-choice answer must be a valid option value.',
            ]);
        }

        $optionExists = $question->options()->where('value', $stringValue)->exists();
        if (! $optionExists) {
            throw ValidationException::withMessages([
                'answer_value' => 'Selected option does not exist on the current schema.',
            ]);
        }

        return $stringValue;
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

    protected function parseStoredValue(mixed $raw): mixed
    {
        if (! is_string($raw)) {
            return $raw;
        }

        $trimmed = trim($raw);

        if ($trimmed === '') {
            return '';
        }

        $json = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $json;
        }

        $lower = strtolower($trimmed);
        if ($lower === 'true') {
            return true;
        }

        if ($lower === 'false') {
            return false;
        }

        if (is_numeric($trimmed)) {
            return str_contains($trimmed, '.') ? (float) $trimmed : (int) $trimmed;
        }

        return $trimmed;
    }
}

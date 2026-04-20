<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SurveyConflictLog;
use App\Models\SurveySession;
use App\Services\GraphConflictResolver;
use App\Services\SurveyVisibilityEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SurveyMonitoringController extends Controller
{
    public function conflicts(Request $request): JsonResponse
    {
        $limit = max(1, min((int) $request->integer('limit', 50), 200));

        $baseQuery = SurveyConflictLog::query()
            ->with([
                'session.survey:id,title,survey_type',
                'session.user:id,name,email',
                'oldVersion:id,survey_id,version_number,status',
                'newVersion:id,survey_id,version_number,status',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('survey_id')) {
            $surveyId = (int) $request->integer('survey_id');
            $baseQuery->whereHas('session', fn ($query) => $query->where('survey_id', $surveyId));
        }

        if ($request->filled('session_id')) {
            $baseQuery->where('session_id', (int) $request->integer('session_id'));
        }

        if ($request->filled('conflict_type')) {
            $baseQuery->where('conflict_type', (string) $request->string('conflict_type'));
        }

        if ($request->filled('recovery_strategy')) {
            $baseQuery->where('recovery_strategy', (string) $request->string('recovery_strategy'));
        }

        if ($request->filled('since_minutes')) {
            $minutes = max(1, min((int) $request->integer('since_minutes'), 60 * 24 * 14));
            $baseQuery->where('created_at', '>=', now()->subMinutes($minutes));
        }

        $total = (clone $baseQuery)->count();
        $byStrategy = (clone $baseQuery)
            ->selectRaw('recovery_strategy, count(*) as aggregate')
            ->groupBy('recovery_strategy')
            ->pluck('aggregate', 'recovery_strategy')
            ->all();
        $byType = (clone $baseQuery)
            ->selectRaw('conflict_type, count(*) as aggregate')
            ->groupBy('conflict_type')
            ->pluck('aggregate', 'conflict_type')
            ->all();

        $rows = $baseQuery
            ->limit($limit)
            ->get()
            ->map(fn (SurveyConflictLog $log) => [
                'id' => $log->id,
                'created_at' => optional($log->created_at)->toIso8601String(),
                'session_id' => $log->session_id,
                'survey' => $log->session?->survey ? [
                    'id' => $log->session->survey->id,
                    'title' => $log->session->survey->title,
                    'survey_type' => $log->session->survey->survey_type,
                ] : null,
                'user' => $log->session?->user ? [
                    'id' => $log->session->user->id,
                    'name' => $log->session->user->name,
                    'email' => $log->session->user->email,
                ] : null,
                'old_version' => $log->oldVersion ? [
                    'id' => $log->oldVersion->id,
                    'version_number' => $log->oldVersion->version_number,
                    'status' => $log->oldVersion->status,
                ] : null,
                'new_version' => $log->newVersion ? [
                    'id' => $log->newVersion->id,
                    'version_number' => $log->newVersion->version_number,
                    'status' => $log->newVersion->status,
                ] : null,
                'conflict_type' => $log->conflict_type,
                'recovery_strategy' => $log->recovery_strategy,
                'details' => $log->details,
            ])
            ->values();

        return response()->json([
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'counts' => [
                    'by_recovery_strategy' => $byStrategy,
                    'by_conflict_type' => $byType,
                ],
            ],
            'data' => $rows,
        ]);
    }

    public function session(
        SurveySession $session,
        GraphConflictResolver $conflictResolver,
        SurveyVisibilityEngine $visibilityEngine,
    ): JsonResponse {
        $session->loadMissing([
            'survey.activeVersion',
            'user',
            'startedVersion',
            'currentVersion',
            'currentQuestion',
            'answers.question',
            'conflictLogs' => fn ($query) => $query->latest()->limit(10),
        ]);

        $currentVersion = $session->currentVersion ?: $session->startedVersion;
        $activeVersion = $session->survey?->activeVersion;
        $answersByStable = collect($session->answers)
            ->where('is_active', true)
            ->sortBy('id')
            ->mapWithKeys(fn ($answer) => [
                $answer->question_stable_key => $this->parseAnswerValue($answer->answer_value),
            ])
            ->all();

        $currentVisibility = $currentVersion
            ? $visibilityEngine->calculate($currentVersion, $answersByStable)
            : ['visible_stable_keys' => []];
        $activeVisibility = $activeVersion
            ? $visibilityEngine->calculate($activeVersion, $answersByStable)
            : ['visible_stable_keys' => []];

        $mismatchDetected = $activeVersion !== null
            && $currentVersion !== null
            && $activeVersion->id !== $currentVersion->id;

        $analysis = null;
        $predictedRecovery = null;
        if ($mismatchDetected && $activeVersion) {
            $analysis = $conflictResolver->detectConflict($session, $activeVersion);
            $predictedRecovery = (($analysis['conflict_detected'] ?? false) && ! ($analysis['can_atomic_recovery'] ?? false))
                ? 'rollback'
                : 'atomic_recovery';
        }

        $currentStable = $session->currentQuestion?->stable_key;
        $isZombieCandidate = $currentStable !== null
            && ! in_array($currentStable, $activeVisibility['visible_stable_keys'], true);

        return response()->json([
            'session' => [
                'id' => $session->id,
                'status' => $session->status,
                'survey_id' => $session->survey_id,
                'survey_title' => $session->survey?->title,
                'survey_type' => $session->survey?->survey_type,
                'user' => $session->user ? [
                    'id' => $session->user->id,
                    'name' => $session->user->name,
                    'email' => $session->user->email,
                ] : null,
                'started_version_id' => $session->started_version_id,
                'current_version_id' => $session->current_version_id,
                'active_version_id' => $activeVersion?->id,
                'current_question' => $session->currentQuestion ? [
                    'id' => $session->currentQuestion->id,
                    'stable_key' => $session->currentQuestion->stable_key,
                    'title' => $session->currentQuestion->title,
                    'type' => $session->currentQuestion->type,
                ] : null,
                'stable_node_key' => $session->stable_node_key,
                'last_synced_at' => optional($session->last_synced_at)->toIso8601String(),
            ],
            'mismatch' => [
                'detected' => $mismatchDetected,
                'predicted_recovery_strategy' => $predictedRecovery,
                'analysis' => $analysis,
            ],
            'invariants' => [
                'current_node_visible_under_active' => ! $isZombieCandidate,
                'visible_under_current_version' => $currentVisibility['visible_stable_keys'],
                'visible_under_active_version' => $activeVisibility['visible_stable_keys'],
            ],
            'answers' => collect($session->answers)
                ->sortBy('id')
                ->values()
                ->map(fn ($answer) => [
                    'id' => $answer->id,
                    'is_active' => (bool) $answer->is_active,
                    'question_stable_key' => $answer->question_stable_key,
                    'question_id' => $answer->question_id,
                    'question_title' => $answer->question?->title,
                    'answer_value' => $this->parseAnswerValue($answer->answer_value),
                    'valid_under_version_id' => $answer->valid_under_version_id,
                    'created_at' => optional($answer->created_at)->toIso8601String(),
                ]),
            'recent_conflicts' => $session->conflictLogs
                ->map(fn ($log) => [
                    'id' => $log->id,
                    'created_at' => optional($log->created_at)->toIso8601String(),
                    'old_version_id' => $log->old_version_id,
                    'new_version_id' => $log->new_version_id,
                    'conflict_type' => $log->conflict_type,
                    'recovery_strategy' => $log->recovery_strategy,
                    'details' => $log->details,
                ])
                ->values(),
        ]);
    }

    protected function parseAnswerValue(mixed $raw): mixed
    {
        if (! is_string($raw)) {
            return $raw;
        }

        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }

        $decoded = json_decode($trimmed, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
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

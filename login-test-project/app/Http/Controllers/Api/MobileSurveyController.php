<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Models\SurveySession;
use App\Models\User;
use App\Services\MobileSurveyRuntimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileSurveyController extends Controller
{
    public function __construct(
        protected MobileSurveyRuntimeService $runtimeService,
    ) {}

    public function index(): JsonResponse
    {
        $surveys = $this->runtimeService->listPublishedSurveys()
            ->map(fn (Survey $survey) => [
                'id' => $survey->id,
                'title' => $survey->title,
                'description' => $survey->description,
                'survey_type' => $survey->survey_type,
                'active_version' => [
                    'id' => $survey->activeVersion->id,
                    'version_number' => $survey->activeVersion->version_number,
                    'published_at' => optional($survey->activeVersion->published_at)->toIso8601String(),
                ],
            ])
            ->values();

        return response()->json([
            'data' => $surveys,
        ]);
    }

    public function schema(Survey $survey): JsonResponse
    {
        $version = $this->runtimeService->resolvePublishedVersion($survey);

        return response()->json($this->runtimeService->schemaPayload($version));
    }

    public function start(Request $request, Survey $survey): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $session = $this->runtimeService->startOrResumeSession($survey, $user);
        $sync = $this->runtimeService->syncSessionToActiveVersion($session);

        /** @var SurveySession $syncedSession */
        $syncedSession = $sync['session'];
        $syncedSession = $this->runtimeService->ensureCurrentCursor($syncedSession);

        return response()->json([
            'session' => $this->sessionPayload($syncedSession),
            'state' => $this->runtimeService->buildSessionState($syncedSession),
            'version_sync' => $sync['version_sync'],
        ]);
    }

    public function state(Request $request, SurveySession $session): JsonResponse
    {
        $this->ensureSessionOwnership($request, $session);

        $sync = $this->runtimeService->syncSessionToActiveVersion($session);

        /** @var SurveySession $syncedSession */
        $syncedSession = $sync['session'];
        $syncedSession = $this->runtimeService->ensureCurrentCursor($syncedSession);

        return response()->json([
            'session' => $this->sessionPayload($syncedSession),
            'state' => $this->runtimeService->buildSessionState($syncedSession),
            'version_sync' => $sync['version_sync'],
        ]);
    }

    public function submitAnswer(Request $request, SurveySession $session): JsonResponse
    {
        $this->ensureSessionOwnership($request, $session);

        $data = $request->validate([
            'question_stable_key' => ['required', 'string', 'max:100'],
            'answer_value' => ['required'],
        ]);

        $sync = $this->runtimeService->syncSessionToActiveVersion($session);

        /** @var SurveySession $syncedSession */
        $syncedSession = $sync['session'];
        $updated = $this->runtimeService->submitAnswer(
            $syncedSession,
            $data['question_stable_key'],
            $data['answer_value'],
        );

        return response()->json([
            'session' => $this->sessionPayload($updated),
            'state' => $this->runtimeService->buildSessionState($updated),
            'version_sync' => $sync['version_sync'],
        ]);
    }

    public function complete(Request $request, SurveySession $session): JsonResponse
    {
        $this->ensureSessionOwnership($request, $session);

        $sync = $this->runtimeService->syncSessionToActiveVersion($session);

        /** @var SurveySession $syncedSession */
        $syncedSession = $sync['session'];
        $completed = $this->runtimeService->completeSession($syncedSession);
        $state = $this->runtimeService->buildSessionState($completed);

        return response()->json([
            'session' => $this->sessionPayload($completed),
            'state' => $state,
            'result' => $state['result'],
            'answer_summary' => $this->runtimeService->buildAnswerSummary($completed),
            'version_sync' => $sync['version_sync'],
        ]);
    }

    protected function ensureSessionOwnership(Request $request, SurveySession $session): void
    {
        /** @var User|null $user */
        $user = $request->user();
        abort_if(! $user || $session->user_id !== $user->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    protected function sessionPayload(SurveySession $session): array
    {
        return [
            'id' => $session->id,
            'survey_id' => $session->survey_id,
            'started_version_id' => $session->started_version_id,
            'current_version_id' => $session->current_version_id,
            'current_question_id' => $session->current_question_id,
            'stable_node_key' => $session->stable_node_key,
            'status' => $session->status,
            'last_synced_at' => optional($session->last_synced_at)->toIso8601String(),
        ];
    }
}

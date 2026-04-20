package com.lolsurvey.mobile.api

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonObject

@Serializable
data class LoginRequest(
    val email: String,
    val password: String,
    @SerialName("device_name") val deviceName: String,
)

@Serializable
data class LoginResponse(
    @SerialName("token_type") val tokenType: String,
    @SerialName("access_token") val accessToken: String,
    val user: MobileUser,
)

@Serializable
data class MobileUser(
    val id: Long,
    val name: String,
    val email: String,
    @SerialName("is_admin") val isAdmin: Boolean,
    @SerialName("account_state") val accountState: String? = null,
)

@Serializable
data class MobileMeResponse(
    val user: MobileUser,
)

@Serializable
data class MobileOkResponse(
    val status: String,
)

@Serializable
data class SurveyListResponse(
    val data: List<SurveySummary>,
)

@Serializable
data class SurveySummary(
    val id: Long,
    val title: String,
    val description: String? = null,
    @SerialName("survey_type") val surveyType: String,
    @SerialName("active_version") val activeVersion: ActiveVersionSummary,
)

@Serializable
data class ActiveVersionSummary(
    val id: Long,
    @SerialName("version_number") val versionNumber: Int,
    @SerialName("published_at") val publishedAt: String? = null,
)

@Serializable
data class SurveySchemaResponse(
    val survey: SurveyMeta,
    val version: SurveyVersionMeta,
    val schema: SurveySchema,
)

@Serializable
data class SurveyMeta(
    val id: Long,
    val title: String,
    val description: String? = null,
    @SerialName("survey_type") val surveyType: String,
)

@Serializable
data class SurveyVersionMeta(
    val id: Long,
    @SerialName("version_number") val versionNumber: Int,
    val status: String,
    @SerialName("published_at") val publishedAt: String? = null,
    @SerialName("schema_meta") val schemaMeta: JsonObject? = null,
)

@Serializable
data class SurveySchema(
    val questions: List<SurveyQuestion>,
    val edges: List<SurveyEdge>,
)

@Serializable
data class SurveyQuestion(
    val id: Long,
    @SerialName("stable_key") val stableKey: String,
    val title: String,
    val type: String,
    @SerialName("is_entry") val isEntry: Boolean = false,
    val metadata: JsonObject? = null,
    val options: List<QuestionOption> = emptyList(),
)

@Serializable
data class QuestionOption(
    val id: Long,
    val value: String,
    val label: String,
)

@Serializable
data class SurveyEdge(
    val id: Long,
    @SerialName("from_stable_key") val fromStableKey: String? = null,
    @SerialName("to_stable_key") val toStableKey: String? = null,
    @SerialName("condition_operator") val conditionOperator: String,
    @SerialName("condition_value") val conditionValue: String? = null,
)

@Serializable
data class SessionEnvelope(
    val session: SurveySession,
    val state: SessionState,
    @SerialName("version_sync") val versionSync: VersionSync,
)

@Serializable
data class CompleteEnvelope(
    val session: SurveySession,
    val state: SessionState,
    val result: ResultNode? = null,
    @SerialName("answer_summary") val answerSummary: List<AnswerSummaryItem> = emptyList(),
    @SerialName("version_sync") val versionSync: VersionSync,
)

@Serializable
data class AnswerSummaryItem(
    @SerialName("question_stable_key") val questionStableKey: String,
    @SerialName("question_title") val questionTitle: String,
    @SerialName("question_type") val questionType: String,
    @SerialName("answer_value") val answerValue: JsonElement,
)

@Serializable
data class SurveySession(
    val id: Long,
    @SerialName("survey_id") val surveyId: Long,
    @SerialName("started_version_id") val startedVersionId: Long,
    @SerialName("current_version_id") val currentVersionId: Long? = null,
    @SerialName("current_question_id") val currentQuestionId: Long? = null,
    @SerialName("stable_node_key") val stableNodeKey: String? = null,
    val status: String,
    @SerialName("last_synced_at") val lastSyncedAt: String? = null,
)

@Serializable
data class SessionState(
    @SerialName("session_status") val sessionStatus: String,
    @SerialName("current_question") val currentQuestion: SurveyQuestion? = null,
    @SerialName("visible_questions") val visibleQuestions: List<String> = emptyList(),
    val answers: Map<String, JsonElement> = emptyMap(),
    @SerialName("can_complete") val canComplete: Boolean = false,
    val result: ResultNode? = null,
)

@Serializable
data class ResultNode(
    @SerialName("stable_key") val stableKey: String,
    val title: String,
)

@Serializable
data class VersionSync(
    @SerialName("mismatch_detected") val mismatchDetected: Boolean,
    @SerialName("from_version_id") val fromVersionId: Long,
    @SerialName("to_version_id") val toVersionId: Long,
    @SerialName("conflict_detected") val conflictDetected: Boolean,
    @SerialName("conflict_type") val conflictType: String? = null,
    @SerialName("recovery_strategy") val recoveryStrategy: String? = null,
    @SerialName("dropped_answers") val droppedAnswers: List<String> = emptyList(),
    val message: String? = null,
)

@Serializable
data class AnswerRequest(
    @SerialName("question_stable_key") val questionStableKey: String,
    @SerialName("answer_value") val answerValue: JsonElement,
)

package com.lolsurvey.mobile.data

import com.lolsurvey.mobile.api.AnswerRequest
import com.lolsurvey.mobile.api.CompleteEnvelope
import com.lolsurvey.mobile.api.LoginRequest
import com.lolsurvey.mobile.api.LoginResponse
import com.lolsurvey.mobile.api.MobileApiService
import com.lolsurvey.mobile.api.MobileMeResponse
import com.lolsurvey.mobile.api.MobileOkResponse
import com.lolsurvey.mobile.api.SessionEnvelope
import com.lolsurvey.mobile.api.SurveyListResponse
import com.lolsurvey.mobile.api.SurveySchemaResponse
import kotlinx.serialization.json.JsonElement

class MobileSurveyRepository(
    private val api: MobileApiService,
) {
    suspend fun login(email: String, password: String): LoginResponse =
        api.login(LoginRequest(email = email, password = password, deviceName = "android-compose-client"))

    suspend fun logout(): MobileOkResponse = api.logout()

    suspend fun me(): MobileMeResponse = api.me()

    suspend fun surveys(): SurveyListResponse = api.surveys()

    suspend fun schema(surveyId: Long): SurveySchemaResponse = api.schema(surveyId)

    suspend fun startSession(surveyId: Long): SessionEnvelope = api.startSession(surveyId)

    suspend fun sessionState(sessionId: Long): SessionEnvelope = api.sessionState(sessionId)

    suspend fun submitAnswer(
        sessionId: Long,
        questionStableKey: String,
        answerValue: JsonElement,
    ): SessionEnvelope = api.submitAnswer(
        sessionId = sessionId,
        request = AnswerRequest(questionStableKey = questionStableKey, answerValue = answerValue),
    )

    suspend fun complete(sessionId: Long): CompleteEnvelope = api.complete(sessionId)
}

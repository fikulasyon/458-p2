package com.lolsurvey.mobile.api

import retrofit2.http.Body
import retrofit2.http.GET
import retrofit2.http.POST
import retrofit2.http.Path

interface MobileApiService {
    @POST("api/mobile/login")
    suspend fun login(@Body request: LoginRequest): LoginResponse

    @POST("api/mobile/logout")
    suspend fun logout(): MobileOkResponse

    @GET("api/mobile/me")
    suspend fun me(): MobileMeResponse

    @GET("api/mobile/surveys")
    suspend fun surveys(): SurveyListResponse

    @GET("api/mobile/surveys/{surveyId}/schema")
    suspend fun schema(@Path("surveyId") surveyId: Long): SurveySchemaResponse

    @POST("api/mobile/surveys/{surveyId}/sessions/start")
    suspend fun startSession(@Path("surveyId") surveyId: Long): SessionEnvelope

    @GET("api/mobile/sessions/{sessionId}/state")
    suspend fun sessionState(@Path("sessionId") sessionId: Long): SessionEnvelope

    @POST("api/mobile/sessions/{sessionId}/answers")
    suspend fun submitAnswer(
        @Path("sessionId") sessionId: Long,
        @Body request: AnswerRequest,
    ): SessionEnvelope

    @POST("api/mobile/sessions/{sessionId}/complete")
    suspend fun complete(@Path("sessionId") sessionId: Long): CompleteEnvelope
}

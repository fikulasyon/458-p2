package com.lolsurvey.mobile.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.ViewModelProvider
import androidx.lifecycle.viewModelScope
import com.lolsurvey.mobile.api.CompleteEnvelope
import com.lolsurvey.mobile.api.MobileUser
import com.lolsurvey.mobile.api.SessionEnvelope
import com.lolsurvey.mobile.api.SurveySchemaResponse
import com.lolsurvey.mobile.api.SurveySummary
import com.lolsurvey.mobile.data.MobileSurveyRepository
import com.lolsurvey.mobile.data.SessionStore
import kotlinx.coroutines.launch
import kotlinx.serialization.json.JsonElement
import retrofit2.HttpException
import java.io.IOException

data class RunnerUiModel(
    val envelope: SessionEnvelope,
    val schema: SurveySchemaResponse?,
)

data class AppUiState(
    val isBootstrapping: Boolean = true,
    val isLoading: Boolean = false,
    val errorMessage: String? = null,
    val user: MobileUser? = null,
    val surveys: List<SurveySummary> = emptyList(),
    val runner: RunnerUiModel? = null,
    val completion: CompleteEnvelope? = null,
)

class AppViewModel(
    private val repository: MobileSurveyRepository,
    private val sessionStore: SessionStore,
) : ViewModel() {
    private var _uiState = androidx.compose.runtime.mutableStateOf(AppUiState())
    val uiState: androidx.compose.runtime.State<AppUiState> = _uiState
    private var silentSessionRefreshInProgress: Boolean = false

    init {
        bootstrap()
    }

    fun clearError() {
        _uiState.value = _uiState.value.copy(errorMessage = null)
    }

    fun bootstrap() {
        val token = sessionStore.accessToken()
        if (token.isNullOrBlank()) {
            _uiState.value = _uiState.value.copy(isBootstrapping = false, user = null)
            return
        }

        viewModelScope.launch {
            _uiState.value = _uiState.value.copy(isBootstrapping = false, isLoading = true, errorMessage = null)
            try {
                val me = repository.me()
                val surveys = repository.surveys()
                _uiState.value = _uiState.value.copy(
                    isLoading = false,
                    user = me.user,
                    surveys = surveys.data,
                )
            } catch (throwable: Throwable) {
                sessionStore.clear()
                _uiState.value = _uiState.value.copy(
                    isLoading = false,
                    user = null,
                    surveys = emptyList(),
                    runner = null,
                    completion = null,
                    errorMessage = toReadableError(throwable),
                )
            }
        }
    }

    fun login(email: String, password: String) {
        viewModelScope.launch {
            _uiState.value = _uiState.value.copy(isLoading = true, errorMessage = null)
            try {
                val login = repository.login(email.trim(), password)
                sessionStore.saveAccessToken(login.accessToken)
                val surveys = repository.surveys()
                _uiState.value = _uiState.value.copy(
                    isLoading = false,
                    user = login.user,
                    surveys = surveys.data,
                    runner = null,
                    completion = null,
                )
            } catch (throwable: Throwable) {
                _uiState.value = _uiState.value.copy(
                    isLoading = false,
                    errorMessage = toReadableError(throwable),
                )
            }
        }
    }

    fun logout() {
        viewModelScope.launch {
            _uiState.value = _uiState.value.copy(isLoading = true, errorMessage = null)
            try {
                repository.logout()
            } catch (_: Throwable) {
                // Best-effort revoke, local token will still be cleared.
            } finally {
                sessionStore.clear()
                _uiState.value = AppUiState(isBootstrapping = false)
            }
        }
    }

    fun refreshSurveys() {
        if (_uiState.value.user == null) {
            return
        }
        viewModelScope.launch {
            _uiState.value = _uiState.value.copy(isLoading = true, errorMessage = null)
            try {
                val surveys = repository.surveys()
                _uiState.value = _uiState.value.copy(
                    isLoading = false,
                    surveys = surveys.data,
                )
            } catch (throwable: Throwable) {
                _uiState.value = _uiState.value.copy(
                    isLoading = false,
                    errorMessage = toReadableError(throwable),
                )
            }
        }
    }

    fun startSurvey(survey: SurveySummary) {
        viewModelScope.launch {
            _uiState.value = _uiState.value.copy(isLoading = true, errorMessage = null)
            try {
                val sessionEnvelope = repository.startSession(survey.id)
                val schema = repository.schema(survey.id)
                _uiState.value = _uiState.value.copy(
                    isLoading = false,
                    runner = RunnerUiModel(sessionEnvelope, schema),
                    completion = null,
                )
            } catch (throwable: Throwable) {
                _uiState.value = _uiState.value.copy(
                    isLoading = false,
                    errorMessage = toReadableError(throwable),
                )
            }
        }
    }

    fun refreshSessionState() {
        refreshSessionState(silent = false)
    }

    fun refreshSessionState(silent: Boolean) {
        if (silent && silentSessionRefreshInProgress) {
            return
        }

        val runner = _uiState.value.runner ?: return
        val sessionId = runner.envelope.session.id

        viewModelScope.launch {
            if (silent) {
                silentSessionRefreshInProgress = true
            }

            if (!silent) {
                _uiState.value = _uiState.value.copy(isLoading = true, errorMessage = null)
            }

            try {
                val nextEnvelope = repository.sessionState(sessionId)
                val latestRunner = _uiState.value.runner
                if (latestRunner == null || latestRunner.envelope.session.id != sessionId) {
                    return@launch
                }

                _uiState.value = _uiState.value.copy(
                    isLoading = if (silent) _uiState.value.isLoading else false,
                    runner = latestRunner.copy(envelope = nextEnvelope),
                    errorMessage = if (silent) null else _uiState.value.errorMessage,
                )
            } catch (throwable: Throwable) {
                if (!silent) {
                    _uiState.value = _uiState.value.copy(
                        isLoading = false,
                        errorMessage = toReadableError(throwable),
                    )
                }
            } finally {
                if (silent) {
                    silentSessionRefreshInProgress = false
                }
            }
        }
    }

    fun submitAnswer(questionStableKey: String, answerValue: JsonElement) {
        val runner = _uiState.value.runner ?: return
        viewModelScope.launch {
            _uiState.value = _uiState.value.copy(isLoading = true, errorMessage = null)
            try {
                val nextEnvelope = repository.submitAnswer(
                    sessionId = runner.envelope.session.id,
                    questionStableKey = questionStableKey,
                    answerValue = answerValue,
                )
                _uiState.value = _uiState.value.copy(
                    isLoading = false,
                    runner = runner.copy(envelope = nextEnvelope),
                )
            } catch (throwable: Throwable) {
                if (throwable is HttpException && throwable.code() == 422) {
                    _uiState.value = _uiState.value.copy(
                        isLoading = false,
                        errorMessage = "Session changed on server. Auto-syncing to latest stable node.",
                    )
                    refreshSessionState(silent = true)
                    return@launch
                }

                _uiState.value = _uiState.value.copy(
                    isLoading = false,
                    errorMessage = toReadableError(throwable),
                )
            }
        }
    }

    fun completeSession() {
        val runner = _uiState.value.runner ?: return
        viewModelScope.launch {
            _uiState.value = _uiState.value.copy(isLoading = true, errorMessage = null)
            try {
                val completion = repository.complete(runner.envelope.session.id)
                _uiState.value = _uiState.value.copy(
                    isLoading = false,
                    completion = completion,
                    runner = null,
                )
            } catch (throwable: Throwable) {
                _uiState.value = _uiState.value.copy(
                    isLoading = false,
                    errorMessage = toReadableError(throwable),
                )
            }
        }
    }

    fun closeCompletionAndReturnToList() {
        _uiState.value = _uiState.value.copy(completion = null, runner = null)
        refreshSurveys()
    }

    fun leaveRunner() {
        _uiState.value = _uiState.value.copy(runner = null)
    }

    private fun toReadableError(throwable: Throwable): String = when (throwable) {
        is IOException -> "Cannot reach backend. Check API base URL and network."
        is HttpException -> "Request failed (${throwable.code()})."
        else -> throwable.message ?: "Unexpected error."
    }
}

class AppViewModelFactory(
    private val repository: MobileSurveyRepository,
    private val sessionStore: SessionStore,
) : ViewModelProvider.Factory {
    @Suppress("UNCHECKED_CAST")
    override fun <T : ViewModel> create(modelClass: Class<T>): T {
        if (modelClass.isAssignableFrom(AppViewModel::class.java)) {
            return AppViewModel(repository, sessionStore) as T
        }
        throw IllegalArgumentException("Unknown ViewModel class: ${modelClass.name}")
    }
}

package com.lolsurvey.mobile

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import com.lolsurvey.mobile.api.ApiClient
import com.lolsurvey.mobile.data.MobileSurveyRepository
import com.lolsurvey.mobile.data.SessionStore
import com.lolsurvey.mobile.ui.AppViewModel
import com.lolsurvey.mobile.ui.AppViewModelFactory
import com.lolsurvey.mobile.ui.screen.CompletionScreen
import com.lolsurvey.mobile.ui.screen.LoginScreen
import com.lolsurvey.mobile.ui.screen.SurveyListScreen
import com.lolsurvey.mobile.ui.screen.SurveyRunnerScreen
import com.lolsurvey.mobile.ui.theme.LoLSurveyMobileTheme

class MainActivity : ComponentActivity() {
    private val sessionStore by lazy { SessionStore(applicationContext) }
    private val apiService by lazy { ApiClient.create(BuildConfig.API_BASE_URL) { sessionStore.accessToken() } }
    private val repository by lazy { MobileSurveyRepository(apiService) }
    private val viewModel: AppViewModel by viewModels {
        AppViewModelFactory(repository, sessionStore)
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()

        setContent {
            LoLSurveyMobileTheme {
                Surface(
                    modifier = Modifier.fillMaxSize(),
                    color = MaterialTheme.colorScheme.background,
                ) {
                    val state = viewModel.uiState.value

                    when {
                        state.isBootstrapping -> {
                            Box(modifier = Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
                                CircularProgressIndicator()
                            }
                        }

                        state.user == null -> {
                            LoginScreen(
                                isLoading = state.isLoading,
                                errorMessage = state.errorMessage,
                                onClearError = viewModel::clearError,
                                onLogin = viewModel::login,
                            )
                        }

                        state.completion != null -> {
                            CompletionScreen(
                                completion = state.completion,
                                onBackToSurveys = viewModel::closeCompletionAndReturnToList,
                            )
                        }

                        state.runner != null -> {
                            SurveyRunnerScreen(
                                runner = state.runner,
                                isLoading = state.isLoading,
                                errorMessage = state.errorMessage,
                                onClearError = viewModel::clearError,
                                onRefresh = viewModel::refreshSessionState,
                                onAutoRefresh = { viewModel.refreshSessionState(silent = true) },
                                onSubmitAnswer = viewModel::submitAnswer,
                                onComplete = viewModel::completeSession,
                                onLeave = viewModel::leaveRunner,
                            )
                        }

                        else -> {
                            SurveyListScreen(
                                user = state.user,
                                surveys = state.surveys,
                                isLoading = state.isLoading,
                                errorMessage = state.errorMessage,
                                onClearError = viewModel::clearError,
                                onRefresh = viewModel::refreshSurveys,
                                onLogout = viewModel::logout,
                                onStartSurvey = viewModel::startSurvey,
                            )
                        }
                    }
                }
            }
        }
    }
}

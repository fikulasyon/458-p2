package com.lolsurvey.mobile.ui.screen

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.ui.ExperimentalComposeUiApi
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.testTagsAsResourceId
import androidx.compose.ui.unit.dp
import com.lolsurvey.mobile.api.MobileUser
import com.lolsurvey.mobile.api.SurveySummary
import com.lolsurvey.mobile.ui.AutomationTags

@OptIn(ExperimentalMaterial3Api::class, ExperimentalComposeUiApi::class)
@Composable
fun SurveyListScreen(
    user: MobileUser?,
    surveys: List<SurveySummary>,
    isLoading: Boolean,
    errorMessage: String?,
    onClearError: () -> Unit,
    onRefresh: () -> Unit,
    onLogout: () -> Unit,
    onStartSurvey: (SurveySummary) -> Unit,
) {
    Scaffold(
        modifier = Modifier
            .semantics { testTagsAsResourceId = true }
            .testTag(AutomationTags.SCREEN_SURVEY_LIST),
        topBar = {
            TopAppBar(
                title = { Text("Survey List") },
                actions = {
                    TextButton(
                        onClick = onRefresh,
                        enabled = !isLoading,
                        modifier = Modifier.testTag(AutomationTags.SURVEY_LIST_REFRESH_BUTTON),
                    ) { Text("Refresh") }
                    TextButton(
                        onClick = onLogout,
                        enabled = !isLoading,
                        modifier = Modifier.testTag(AutomationTags.SURVEY_LIST_LOGOUT_BUTTON),
                    ) { Text("Logout") }
                },
            )
        },
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            if (user != null) {
                Text(text = "Signed in as ${user.email}", style = MaterialTheme.typography.bodySmall)
            }

            if (errorMessage != null) {
                Card(
                    modifier = Modifier
                        .fillMaxWidth()
                        .testTag(AutomationTags.SURVEY_LIST_ERROR_CARD),
                ) {
                    Column(modifier = Modifier.padding(12.dp)) {
                        Text(text = errorMessage, color = MaterialTheme.colorScheme.error)
                        TextButton(
                            onClick = onClearError,
                            modifier = Modifier.testTag(AutomationTags.SURVEY_LIST_ERROR_DISMISS_BUTTON),
                        ) { Text("Dismiss") }
                    }
                }
            }

            if (isLoading) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    CircularProgressIndicator(modifier = Modifier.testTag(AutomationTags.SURVEY_LIST_LOADING_INDICATOR))
                    Text(text = "Loading surveys...")
                }
            }

            LazyColumn(
                modifier = Modifier.testTag(AutomationTags.SURVEY_LIST_ITEMS),
                verticalArrangement = Arrangement.spacedBy(10.dp),
            ) {
                items(surveys, key = { it.id }) { survey ->
                    Card(
                        modifier = Modifier
                            .fillMaxWidth()
                            .testTag(AutomationTags.surveyCard(survey.id)),
                    ) {
                        Column(
                            modifier = Modifier
                                .fillMaxWidth()
                                .padding(12.dp),
                            verticalArrangement = Arrangement.spacedBy(6.dp),
                        ) {
                            Text(text = survey.title, style = MaterialTheme.typography.titleMedium)
                            if (!survey.description.isNullOrBlank()) {
                                Text(text = survey.description, style = MaterialTheme.typography.bodySmall)
                            }
                            Text(text = "Type: ${survey.surveyType}")
                            Text(text = "Version: v${survey.activeVersion.versionNumber}")
                            Button(
                                onClick = { onStartSurvey(survey) },
                                enabled = !isLoading,
                                modifier = Modifier.testTag(AutomationTags.surveyStartButton(survey.id)),
                            ) {
                                Text("Start / Continue")
                            }
                        }
                    }
                }
            }
        }
    }
}

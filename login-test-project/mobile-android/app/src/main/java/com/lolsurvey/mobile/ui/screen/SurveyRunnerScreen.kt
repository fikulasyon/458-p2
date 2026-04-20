package com.lolsurvey.mobile.ui.screen

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.FilterChip
import androidx.compose.material3.FilterChipDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberUpdatedState
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.lolsurvey.mobile.ui.RunnerUiModel
import kotlinx.coroutines.delay
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive

@OptIn(ExperimentalMaterial3Api::class, ExperimentalLayoutApi::class)
@Composable
fun SurveyRunnerScreen(
    runner: RunnerUiModel,
    isLoading: Boolean,
    errorMessage: String?,
    onClearError: () -> Unit,
    onRefresh: () -> Unit,
    onAutoRefresh: () -> Unit,
    onSubmitAnswer: (questionStableKey: String, answerValue: JsonElement) -> Unit,
    onComplete: () -> Unit,
    onLeave: () -> Unit,
) {
    val envelope = runner.envelope
    val currentQuestion = envelope.state.currentQuestion
    val ratingScale = remember(runner.schema) { ratingScaleFromSchema(runner) }
    val questionKey = currentQuestion?.stableKey ?: "none"
    var openEndedText by remember(questionKey) { mutableStateOf("") }
    var selectedRating by remember(questionKey) { mutableIntStateOf(0) }
    val latestLoading by rememberUpdatedState(isLoading)
    val latestAutoRefresh by rememberUpdatedState(onAutoRefresh)

    LaunchedEffect(envelope.session.id) {
        while (true) {
            delay(3500)
            if (!latestLoading) {
                latestAutoRefresh()
            }
        }
    }

    Scaffold(
        topBar = {
            TopAppBar(
                title = {
                    Text("Survey Runner #${envelope.session.id}")
                },
                actions = {
                    TextButton(onClick = onRefresh, enabled = !isLoading) { Text("Sync") }
                    TextButton(onClick = onLeave, enabled = !isLoading) { Text("Back") }
                },
            )
        },
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(16.dp)
                .verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            if (isLoading) {
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    CircularProgressIndicator()
                    Text("Applying request...")
                }
            }

            if (errorMessage != null) {
                Card(modifier = Modifier.fillMaxWidth()) {
                    Column(modifier = Modifier.padding(12.dp)) {
                        Text(text = errorMessage, color = MaterialTheme.colorScheme.error)
                        TextButton(onClick = onClearError) { Text("Dismiss") }
                    }
                }
            }

            if (envelope.versionSync.mismatchDetected) {
                Card(modifier = Modifier.fillMaxWidth()) {
                    Column(
                        modifier = Modifier.padding(12.dp),
                        verticalArrangement = Arrangement.spacedBy(6.dp),
                    ) {
                        Text("Schema updated while session was in progress.")
                        Text("Recovery: ${envelope.versionSync.recoveryStrategy ?: "none"}")
                        if (envelope.versionSync.conflictDetected) {
                            Text("Conflict type: ${envelope.versionSync.conflictType ?: "unknown"}")
                        }
                        if (envelope.versionSync.droppedAnswers.isNotEmpty()) {
                            Text("Dropped answers: ${envelope.versionSync.droppedAnswers.joinToString(", ")}")
                        }
                    }
                }
            }

            Card(modifier = Modifier.fillMaxWidth()) {
                Column(modifier = Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    Text("Session status: ${envelope.state.sessionStatus}")
                    Text("Visible nodes: ${envelope.state.visibleQuestions.joinToString(", ")}")
                    if (envelope.state.answers.isNotEmpty()) {
                        Text("Answer count: ${envelope.state.answers.size}")
                    }
                }
            }

            if (currentQuestion != null) {
                Card(modifier = Modifier.fillMaxWidth()) {
                    Column(
                        modifier = Modifier.padding(12.dp),
                        verticalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        Text(currentQuestion.title, style = MaterialTheme.typography.titleMedium)
                        Text("Node: ${currentQuestion.stableKey} (${currentQuestion.type})")

                        when (currentQuestion.type) {
                            "multiple_choice" -> {
                                currentQuestion.options.forEach { option ->
                                    Button(
                                        onClick = {
                                            onSubmitAnswer(currentQuestion.stableKey, JsonPrimitive(option.value))
                                        },
                                        enabled = !isLoading,
                                        modifier = Modifier.fillMaxWidth(),
                                    ) {
                                        Text(option.label)
                                    }
                                }
                            }

                            "rating" -> {
                                val (count, labels) = ratingScale
                                FlowRow(
                                    horizontalArrangement = Arrangement.spacedBy(8.dp),
                                    verticalArrangement = Arrangement.spacedBy(8.dp),
                                ) {
                                    (1..count).forEach { value ->
                                        FilterChip(
                                            selected = selectedRating == value,
                                            onClick = { selectedRating = value },
                                            label = {
                                                Text(if (selectedRating == value) "Selected: $value" else value.toString())
                                            },
                                            colors = FilterChipDefaults.filterChipColors(
                                                selectedContainerColor = MaterialTheme.colorScheme.primaryContainer,
                                                selectedLabelColor = MaterialTheme.colorScheme.onPrimaryContainer,
                                            ),
                                        )
                                    }
                                }

                                if (labels.isNotEmpty()) {
                                    Text(labels.joinToString(" | "))
                                }

                                Button(
                                    onClick = {
                                        if (selectedRating > 0) {
                                            onSubmitAnswer(currentQuestion.stableKey, JsonPrimitive(selectedRating))
                                        }
                                    },
                                    enabled = !isLoading && selectedRating > 0,
                                ) {
                                    Text("Submit Rating")
                                }
                            }

                            "text" -> {
                                OutlinedTextField(
                                    value = openEndedText,
                                    onValueChange = { openEndedText = it },
                                    label = { Text("Your answer") },
                                    modifier = Modifier.fillMaxWidth(),
                                )
                                Button(
                                    onClick = {
                                        onSubmitAnswer(currentQuestion.stableKey, JsonPrimitive(openEndedText.trim()))
                                    },
                                    enabled = !isLoading && openEndedText.isNotBlank(),
                                ) {
                                    Text("Submit Text")
                                }
                            }

                            "result" -> {
                                Text("Result node reached. You can complete the survey now.")
                            }

                            else -> {
                                Text("Unsupported node type: ${currentQuestion.type}")
                            }
                        }
                    }
                }
            } else {
                Card(modifier = Modifier.fillMaxWidth()) {
                    Text(
                        text = "No active question. If all required answers are present, complete the session.",
                        modifier = Modifier.padding(12.dp),
                    )
                }
            }

            Button(
                onClick = onComplete,
                enabled = envelope.state.canComplete && !isLoading,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text("Complete Session")
            }
        }
    }
}

private fun ratingScaleFromSchema(runner: RunnerUiModel): Pair<Int, List<String>> {
    val schemaMeta = runner.schema?.version?.schemaMeta ?: return 5 to emptyList()
    val ratingScale = schemaMeta["rating_scale"]?.jsonObject ?: return 5 to emptyList()
    val count = ratingScale["count"]?.jsonPrimitive?.content?.toIntOrNull()?.coerceIn(2, 10) ?: 5
    val labels = ratingScale["labels"]
        ?.jsonArray
        ?.mapNotNull { element ->
            val raw = element.jsonPrimitive.content
            if (raw.isBlank() || raw == "null") null else raw
        }
        ?: emptyList()
    return count to labels
}

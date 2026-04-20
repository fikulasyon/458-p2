package com.lolsurvey.mobile.ui.screen

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import com.lolsurvey.mobile.api.CompleteEnvelope
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject

@Composable
fun CompletionScreen(
    completion: CompleteEnvelope,
    onBackToSurveys: () -> Unit,
) {
    Scaffold { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(16.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text("Survey Completed", style = MaterialTheme.typography.headlineSmall)

            Card(modifier = Modifier.fillMaxWidth()) {
                Column(
                    modifier = Modifier.padding(12.dp),
                    verticalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    Text("Session #${completion.session.id}")
                    Text("Status: ${completion.session.status}")
                    val result = completion.result ?: completion.state.result
                    if (result != null) {
                        Text("Result: ${result.title}")
                        Text("Key: ${result.stableKey}")
                    } else {
                        Text("No explicit result node.")
                    }
                }
            }

            if (completion.versionSync.mismatchDetected) {
                Card(modifier = Modifier.fillMaxWidth()) {
                    Column(
                        modifier = Modifier.padding(12.dp),
                        verticalArrangement = Arrangement.spacedBy(6.dp),
                    ) {
                        Text("Schema changed during session.")
                        Text("Recovery: ${completion.versionSync.recoveryStrategy ?: "none"}")
                    }
                }
            }

            if (completion.answerSummary.isNotEmpty()) {
                Card(modifier = Modifier.fillMaxWidth()) {
                    Column(
                        modifier = Modifier.padding(12.dp),
                        verticalArrangement = Arrangement.spacedBy(8.dp),
                    ) {
                        Text("Submitted Answers", style = MaterialTheme.typography.titleMedium)
                        completion.answerSummary.forEachIndexed { index, item ->
                            Text("${index + 1}. ${item.questionTitle} (${item.questionType})")
                            Text(
                                "Answer: ${toReadableAnswer(item.answerValue)}",
                                style = MaterialTheme.typography.bodySmall,
                            )
                            Text(
                                "Key: ${item.questionStableKey}",
                                style = MaterialTheme.typography.bodySmall,
                            )
                        }
                    }
                }
            }

            Button(
                onClick = onBackToSurveys,
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text("Back to Surveys")
            }
        }
    }
}

private fun toReadableAnswer(answer: JsonElement): String {
    if (answer is JsonNull) {
        return "null"
    }

    if (answer is JsonPrimitive) {
        return answer.content
    }

    if (answer.jsonArrayOrNull != null) {
        return answer.jsonArray.joinToString(", ") { element -> toReadableAnswer(element) }
    }

    if (answer.jsonObjectOrNull != null) {
        return answer.jsonObject.entries.joinToString(", ") { (key, value) ->
            "$key=${toReadableAnswer(value)}"
        }
    }

    return answer.toString()
}

private val JsonElement.jsonArrayOrNull
    get() = runCatching { this.jsonArray }.getOrNull()

private val JsonElement.jsonObjectOrNull
    get() = runCatching { this.jsonObject }.getOrNull()

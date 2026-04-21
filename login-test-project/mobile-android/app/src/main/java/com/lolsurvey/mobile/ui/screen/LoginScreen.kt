package com.lolsurvey.mobile.ui.screen

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.ExperimentalComposeUiApi
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.testTag
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.semantics.testTagsAsResourceId
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import com.lolsurvey.mobile.ui.AutomationTags

@OptIn(ExperimentalComposeUiApi::class)
@Composable
fun LoginScreen(
    isLoading: Boolean,
    errorMessage: String?,
    onClearError: () -> Unit,
    onLogin: (email: String, password: String) -> Unit,
) {
    var email by remember { mutableStateOf("t1@g.com") }
    var password by remember { mutableStateOf("123123123") }

    Scaffold(
        modifier = Modifier
            .semantics { testTagsAsResourceId = true }
            .testTag(AutomationTags.SCREEN_LOGIN),
    ) { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(20.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text(
                text = "Mobile survey runner",
                style = MaterialTheme.typography.bodyMedium,
            )

            if (errorMessage != null) {
                Card(
                    modifier = Modifier
                        .fillMaxWidth()
                        .testTag(AutomationTags.LOGIN_ERROR_CARD),
                ) {
                    Column(modifier = Modifier.padding(12.dp)) {
                        Text(text = errorMessage, color = MaterialTheme.colorScheme.error)
                        TextButton(
                            contentPadding = PaddingValues(0.dp),
                            onClick = onClearError,
                            modifier = Modifier.testTag(AutomationTags.LOGIN_ERROR_DISMISS_BUTTON),
                        ) {
                            Text("Dismiss")
                        }
                    }
                }
            }

            OutlinedTextField(
                value = email,
                onValueChange = { email = it },
                label = { Text("Email") },
                modifier = Modifier
                    .fillMaxWidth()
                    .testTag(AutomationTags.LOGIN_EMAIL_INPUT),
                singleLine = true,
                enabled = !isLoading,
            )

            OutlinedTextField(
                value = password,
                onValueChange = { password = it },
                label = { Text("Password") },
                visualTransformation = PasswordVisualTransformation(),
                modifier = Modifier
                    .fillMaxWidth()
                    .testTag(AutomationTags.LOGIN_PASSWORD_INPUT),
                singleLine = true,
                enabled = !isLoading,
            )

            Button(
                onClick = { onLogin(email, password) },
                enabled = !isLoading && email.isNotBlank() && password.isNotBlank(),
                modifier = Modifier
                    .fillMaxWidth()
                    .testTag(AutomationTags.LOGIN_SUBMIT_BUTTON),
            ) {
                if (isLoading) {
                    CircularProgressIndicator(
                        modifier = Modifier
                            .padding(2.dp)
                            .testTag(AutomationTags.LOGIN_LOADING_INDICATOR),
                    )
                } else {
                    Text("Login")
                }
            }
        }
    }
}

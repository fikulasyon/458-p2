package com.lolsurvey.mobile.ui.theme

import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable

private val LightColors = lightColorScheme(
    primary = DeepBlue,
    secondary = Gold,
    tertiary = Steel,
    background = SurfaceLight,
)

private val DarkColors = darkColorScheme(
    primary = Gold,
    secondary = Steel,
    tertiary = DeepBlue,
)

@Composable
fun LoLSurveyMobileTheme(
    darkTheme: Boolean = isSystemInDarkTheme(),
    content: @Composable () -> Unit,
) {
    MaterialTheme(
        colorScheme = if (darkTheme) DarkColors else LightColors,
        typography = AppTypography,
        content = content,
    )
}

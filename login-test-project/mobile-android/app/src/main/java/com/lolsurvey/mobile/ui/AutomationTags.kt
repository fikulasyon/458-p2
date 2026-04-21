package com.lolsurvey.mobile.ui

object AutomationTags {
    const val SCREEN_LOGIN = "screen_login"
    const val LOGIN_EMAIL_INPUT = "login_email_input"
    const val LOGIN_PASSWORD_INPUT = "login_password_input"
    const val LOGIN_SUBMIT_BUTTON = "login_submit_button"
    const val LOGIN_LOADING_INDICATOR = "login_loading_indicator"
    const val LOGIN_ERROR_CARD = "login_error_card"
    const val LOGIN_ERROR_DISMISS_BUTTON = "login_error_dismiss_button"

    const val SCREEN_SURVEY_LIST = "screen_survey_list"
    const val SURVEY_LIST_REFRESH_BUTTON = "survey_list_refresh_button"
    const val SURVEY_LIST_LOGOUT_BUTTON = "survey_list_logout_button"
    const val SURVEY_LIST_LOADING_INDICATOR = "survey_list_loading_indicator"
    const val SURVEY_LIST_ERROR_CARD = "survey_list_error_card"
    const val SURVEY_LIST_ERROR_DISMISS_BUTTON = "survey_list_error_dismiss_button"
    const val SURVEY_LIST_ITEMS = "survey_list_items"

    const val SCREEN_SURVEY_RUNNER = "screen_survey_runner"
    const val RUNNER_SYNC_BUTTON = "runner_sync_button"
    const val RUNNER_BACK_BUTTON = "runner_back_button"
    const val RUNNER_LOADING_INDICATOR = "runner_loading_indicator"
    const val RUNNER_ERROR_CARD = "runner_error_card"
    const val RUNNER_ERROR_DISMISS_BUTTON = "runner_error_dismiss_button"
    const val RUNNER_CONFLICT_CARD = "runner_conflict_card"
    const val RUNNER_CONFLICT_STRATEGY_TEXT = "runner_conflict_strategy_text"
    const val RUNNER_CONFLICT_TYPE_TEXT = "runner_conflict_type_text"
    const val RUNNER_STATE_CARD = "runner_state_card"
    const val RUNNER_CURRENT_QUESTION_CARD = "runner_current_question_card"
    const val RUNNER_RATING_SUBMIT_BUTTON = "runner_rating_submit_button"
    const val RUNNER_OPEN_ENDED_INPUT = "runner_open_ended_input"
    const val RUNNER_OPEN_ENDED_SUBMIT_BUTTON = "runner_open_ended_submit_button"
    const val RUNNER_RESULT_HINT = "runner_result_hint"
    const val RUNNER_NO_QUESTION_CARD = "runner_no_question_card"
    const val RUNNER_COMPLETE_BUTTON = "runner_complete_button"

    const val SCREEN_COMPLETION = "screen_completion"
    const val COMPLETION_HEADER = "completion_header"
    const val COMPLETION_SESSION_CARD = "completion_session_card"
    const val COMPLETION_RESULT_TITLE = "completion_result_title"
    const val COMPLETION_RESULT_KEY = "completion_result_key"
    const val COMPLETION_VERSION_SYNC_CARD = "completion_version_sync_card"
    const val COMPLETION_VERSION_SYNC_STRATEGY = "completion_version_sync_strategy"
    const val COMPLETION_ANSWER_SUMMARY_CARD = "completion_answer_summary_card"
    const val COMPLETION_BACK_BUTTON = "completion_back_button"

    fun surveyCard(surveyId: Long): String = "survey_card_${sanitize(surveyId.toString())}"

    fun surveyStartButton(surveyId: Long): String = "survey_start_button_${sanitize(surveyId.toString())}"

    fun runnerCurrentQuestionKey(stableKey: String): String =
        "runner_current_question_${sanitize(stableKey)}"

    fun runnerMultipleChoiceOption(questionStableKey: String, optionValue: String): String =
        "runner_option_${sanitize(questionStableKey)}_${sanitize(optionValue)}"

    fun runnerRatingChip(value: Int): String = "runner_rating_chip_${sanitize(value.toString())}"

    fun completionAnswerItem(index: Int): String =
        "completion_answer_item_${sanitize(index.toString())}"

    private fun sanitize(value: String): String = value
        .lowercase()
        .replace(Regex("[^a-z0-9]+"), "_")
        .trim('_')
        .ifBlank { "empty" }
}

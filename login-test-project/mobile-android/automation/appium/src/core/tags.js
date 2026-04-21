const sanitize = (value) => {
  const normalized = String(value)
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "");

  return normalized.length > 0 ? normalized : "empty";
};

export const Tags = Object.freeze({
  SCREEN_LOGIN: "screen_login",
  LOGIN_EMAIL_INPUT: "login_email_input",
  LOGIN_PASSWORD_INPUT: "login_password_input",
  LOGIN_SUBMIT_BUTTON: "login_submit_button",
  SCREEN_SURVEY_LIST: "screen_survey_list",
  SURVEY_LIST_REFRESH_BUTTON: "survey_list_refresh_button",
  SURVEY_LIST_LOGOUT_BUTTON: "survey_list_logout_button",
  SCREEN_SURVEY_RUNNER: "screen_survey_runner",
  RUNNER_SYNC_BUTTON: "runner_sync_button",
  RUNNER_BACK_BUTTON: "runner_back_button",
  RUNNER_COMPLETE_BUTTON: "runner_complete_button",
  RUNNER_RATING_SUBMIT_BUTTON: "runner_rating_submit_button",
  RUNNER_OPEN_ENDED_INPUT: "runner_open_ended_input",
  RUNNER_OPEN_ENDED_SUBMIT_BUTTON: "runner_open_ended_submit_button",
  SCREEN_COMPLETION: "screen_completion",
  COMPLETION_BACK_BUTTON: "completion_back_button",
  RUNNER_CONFLICT_CARD: "runner_conflict_card",
  RUNNER_CONFLICT_STRATEGY_TEXT: "runner_conflict_strategy_text",
  RUNNER_CONFLICT_TYPE_TEXT: "runner_conflict_type_text",
});

export const surveyCardTag = (surveyId) => `survey_card_${sanitize(surveyId)}`;
export const surveyStartButtonTag = (surveyId) => `survey_start_button_${sanitize(surveyId)}`;
export const runnerCurrentQuestionTag = (stableKey) => `runner_current_question_${sanitize(stableKey)}`;
export const runnerMultipleChoiceOptionTag = (questionStableKey, optionValue) =>
  `runner_option_${sanitize(questionStableKey)}_${sanitize(optionValue)}`;
export const runnerRatingChipTag = (value) => `runner_rating_chip_${sanitize(value)}`;

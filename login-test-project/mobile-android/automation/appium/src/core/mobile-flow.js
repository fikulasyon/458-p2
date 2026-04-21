import { byTag, waitForAnyVisible, waitForVisible } from "./selectors.js";
import {
  runnerCurrentQuestionTag,
  runnerMultipleChoiceOptionTag,
  runnerRatingChipTag,
  surveyStartButtonTag,
  Tags,
} from "./tags.js";

export const waitForLoginScreen = (driver, timeoutMs = 15000) =>
  waitForVisible(driver, Tags.SCREEN_LOGIN, timeoutMs);

export const waitForSurveyListScreen = (driver, timeoutMs = 15000) =>
  waitForVisible(driver, Tags.SCREEN_SURVEY_LIST, timeoutMs);

export const waitForRunnerScreen = (driver, timeoutMs = 15000) =>
  waitForVisible(driver, Tags.SCREEN_SURVEY_RUNNER, timeoutMs);

export const waitForCompletionScreen = (driver, timeoutMs = 15000) =>
  waitForVisible(driver, Tags.SCREEN_COMPLETION, timeoutMs);

export async function login(driver, { email, password, timeoutMs = 15000 }) {
  const initialScreen = await waitForAnyVisible(
    driver,
    [Tags.SCREEN_LOGIN, Tags.SCREEN_SURVEY_LIST],
    timeoutMs,
  );

  if (initialScreen.tag === Tags.SCREEN_SURVEY_LIST) {
    return;
  }

  const emailInput = await waitForVisible(driver, Tags.LOGIN_EMAIL_INPUT, timeoutMs);
  const passwordInput = await waitForVisible(driver, Tags.LOGIN_PASSWORD_INPUT, timeoutMs);

  await emailInput.click();
  await emailInput.setValue(String(email));
  await passwordInput.click();
  await passwordInput.setValue(String(password));

  const submit = await waitForVisible(driver, Tags.LOGIN_SUBMIT_BUTTON, timeoutMs);
  await submit.click();
  await waitForSurveyListScreen(driver, timeoutMs);
}

export async function startSurvey(driver, { surveyId, timeoutMs = 15000 }) {
  const initialScreen = await waitForAnyVisible(
    driver,
    [Tags.SCREEN_SURVEY_LIST, Tags.SCREEN_SURVEY_RUNNER],
    timeoutMs,
  );
  if (initialScreen.tag === Tags.SCREEN_SURVEY_RUNNER) {
    return;
  }
  const startButton = await waitForVisible(driver, surveyStartButtonTag(surveyId), timeoutMs);
  await startButton.click();
  await waitForRunnerScreen(driver, timeoutMs);
}

export async function assertCurrentQuestion(driver, { stableKey, timeoutMs = 15000 }) {
  await waitForRunnerScreen(driver, timeoutMs);
  await waitForVisible(driver, runnerCurrentQuestionTag(stableKey), timeoutMs);
}

export async function answer(driver, payload) {
  if (!payload || typeof payload !== "object") {
    throw new Error("answer payload is required.");
  }

  const { type } = payload;

  if (type === "multiple_choice") {
    const { questionStableKey, optionValue, timeoutMs = 15000 } = payload;
    const tag = runnerMultipleChoiceOptionTag(questionStableKey, optionValue);
    const optionButton = await waitForVisible(driver, tag, timeoutMs);
    await optionButton.click();
    return;
  }

  if (type === "rating") {
    const { value, timeoutMs = 15000 } = payload;
    const chip = await waitForVisible(driver, runnerRatingChipTag(value), timeoutMs);
    await chip.click();
    const submit = await waitForVisible(driver, Tags.RUNNER_RATING_SUBMIT_BUTTON, timeoutMs);
    await submit.click();
    return;
  }

  if (type === "open_ended") {
    const { text, timeoutMs = 15000 } = payload;
    const input = await waitForVisible(driver, Tags.RUNNER_OPEN_ENDED_INPUT, timeoutMs);
    await input.click();
    await input.setValue(String(text));
    const submit = await waitForVisible(driver, Tags.RUNNER_OPEN_ENDED_SUBMIT_BUTTON, timeoutMs);
    await submit.click();
    return;
  }

  throw new Error(`Unsupported answer type: ${type}`);
}

export async function complete(driver, { timeoutMs = 15000 } = {}) {
  const completeButton = await waitForVisible(driver, Tags.RUNNER_COMPLETE_BUTTON, timeoutMs);
  await completeButton.click();
  await waitForCompletionScreen(driver, timeoutMs);
}

export async function syncRunnerState(driver, { timeoutMs = 15000 } = {}) {
  const syncButton = await waitForVisible(driver, Tags.RUNNER_SYNC_BUTTON, timeoutMs);
  await syncButton.click();
  await waitForRunnerScreen(driver, timeoutMs);
}

export async function readConflictStrategy(driver) {
  const strategyNode = await driver.$(byTag(Tags.RUNNER_CONFLICT_STRATEGY_TEXT));
  if (!(await strategyNode.isExisting()) || !(await strategyNode.isDisplayed())) {
    return null;
  }
  return strategyNode.getText();
}

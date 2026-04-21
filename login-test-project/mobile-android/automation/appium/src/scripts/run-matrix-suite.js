import assert from "node:assert/strict";
import {
  answer,
  assertCurrentQuestion,
  complete,
  login,
  readConflictStrategy,
  startSurvey,
} from "../core/mobile-flow.js";
import { maybeElement } from "../core/selectors.js";
import { Tags } from "../core/tags.js";
import { bootstrapMatrixScenario } from "../matrix/bootstrap.js";
import { fetchSessionState, loginMobileApi, startSurveySession } from "../matrix/mobile-api.js";
import {
  DEFAULT_MATRIX_SUITE_SCENARIOS,
  FULL_MATRIX_SUITE_SCENARIOS,
} from "../matrix/suite-scenarios.js";
import { printSuiteReport } from "../reporting/suite-report.js";
import { createDriverSession } from "../runtime/create-driver-session.js";

const parseBooleanEnv = (name, fallback = false) => {
  const value = process.env[name];
  if (value === undefined) {
    return fallback;
  }
  const normalized = String(value).trim().toLowerCase();
  return normalized === "1" || normalized === "true" || normalized === "yes";
};

const parseScenarioListFromEnv = () => {
  const raw = String(process.env.MATRIX_SUITE_SCENARIOS ?? "").trim();
  if (!raw) {
    return null;
  }

  return raw
    .split(/[,\s]+/)
    .map((item) => item.trim().toUpperCase())
    .filter(Boolean);
};

const asObject = (value) => (value && typeof value === "object" ? value : {});

const asStringArray = (value) =>
  Array.isArray(value) ? value.map((item) => String(item)) : [];

const responseCurrentStableKey = (stateResponse) =>
  stateResponse?.state?.current_question?.stable_key ?? null;

const responseAnswers = (stateResponse) => asObject(stateResponse?.state?.answers);

const responseVisible = (stateResponse) => asStringArray(stateResponse?.state?.visible_questions);

const resolveSuiteScenarioIds = () => {
  const explicit = parseScenarioListFromEnv();
  if (explicit && explicit.length > 0) {
    return explicit;
  }

  const runFull = parseBooleanEnv("MATRIX_SUITE_FULL", false);
  return runFull ? [...FULL_MATRIX_SUITE_SCENARIOS] : [...DEFAULT_MATRIX_SUITE_SCENARIOS];
};

const valueEquals = (left, right) => {
  try {
    assert.deepStrictEqual(left, right);
    return true;
  } catch {
    return false;
  }
};

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function waitForSessionState({
  token,
  sessionId,
  predicate,
  timeoutMs = 12000,
  intervalMs = 300,
  description = "session condition",
}) {
  const deadline = Date.now() + timeoutMs;
  let lastState = null;

  while (Date.now() < deadline) {
    const state = await fetchSessionState({ token, sessionId });
    lastState = state;

    if (predicate(state)) {
      return state;
    }

    await sleep(intervalMs);
  }

  const currentStable = lastState?.state?.current_question?.stable_key ?? null;
  const answerKeys = Object.keys(responseAnswers(lastState));
  throw new Error(
    `Timed out waiting for ${description}. Last state => current: ${currentStable ?? "null"}, answers: [${answerKeys.join(", ")}]`,
  );
}

async function assertNoRunnerErrorCard(driver, contextLabel) {
  const card = await maybeElement(driver, Tags.RUNNER_ERROR_CARD);
  const exists = await card.isExisting();
  if (!exists) {
    return;
  }

  const displayed = await card.isDisplayed();
  if (!displayed) {
    return;
  }

  let details = "Runner error card is visible.";
  try {
    const text = await card.getText();
    if (String(text).trim()) {
      details = `Runner error card is visible: ${text}`;
    }
  } catch {
    // Keep fallback message.
  }

  throw new Error(`${contextLabel}: ${details}`);
}

function assertInitialStateAgainstScenario({
  payload,
  stateResponse,
  observedStrategyBanner,
  strictStrategyBanner,
}) {
  const expected = asObject(payload?.expected);
  const expectedContinue = expected.continue_from ? String(expected.continue_from) : null;
  const expectedStrategy = expected.recovery_strategy ? String(expected.recovery_strategy) : null;
  const expectedConflict = Boolean(expected.conflict_detected);
  const expectedDropped = asStringArray(expected.drop_answers);
  const mustNotShowUnreachable = asStringArray(expected.must_not_show_unreachable);
  const checkpointAnswers = asObject(payload?.checkpoint?.answers);

  const currentStable = responseCurrentStableKey(stateResponse);
  const answers = responseAnswers(stateResponse);
  const visible = responseVisible(stateResponse);
  const sessionStatus = String(stateResponse?.state?.session_status ?? "");

  if (expectedContinue && currentStable !== expectedContinue) {
    throw new Error(
      `Expected current question "${expectedContinue}" but got "${currentStable ?? "null"}".`,
    );
  }

  if (expectedContinue && Object.prototype.hasOwnProperty.call(answers, expectedContinue)) {
    throw new Error(
      `Fresh-answer boundary violated: current question "${expectedContinue}" already has an answer.`,
    );
  }

  for (const stableKey of expectedDropped) {
    if (Object.prototype.hasOwnProperty.call(answers, stableKey)) {
      throw new Error(`Dropped answer "${stableKey}" is still active in state.answers.`);
    }
  }

  for (const [stableKey, value] of Object.entries(checkpointAnswers)) {
    if (expectedDropped.includes(String(stableKey))) {
      continue;
    }

    if (!Object.prototype.hasOwnProperty.call(answers, stableKey)) {
      throw new Error(`Expected kept answer "${stableKey}" missing from state.answers.`);
    }

    if (!valueEquals(answers[stableKey], value)) {
      throw new Error(
        `Expected kept answer "${stableKey}" value ${JSON.stringify(value)} but got ${JSON.stringify(answers[stableKey])}.`,
      );
    }
  }

  if (currentStable && !visible.includes(currentStable)) {
    throw new Error(
      `Zombie invariant failed: current node "${currentStable}" is not in visible_questions.`,
    );
  }

  for (const unreachable of mustNotShowUnreachable) {
    if (visible.includes(unreachable)) {
      throw new Error(
        `Zombie/unreachable invariant failed: "${unreachable}" is still visible in state.`,
      );
    }
  }

  if (expectedConflict) {
    if (expectedStrategy === "rollback" && sessionStatus !== "rolled_back") {
      throw new Error(
        `Expected rollback session status "rolled_back" but got "${sessionStatus || "null"}".`,
      );
    }
    if (expectedStrategy === "atomic_recovery" && sessionStatus !== "conflict_recovered") {
      throw new Error(
        `Expected atomic conflict recovery session status "conflict_recovered" but got "${sessionStatus || "null"}".`,
      );
    }
  } else if (sessionStatus === "rolled_back") {
    throw new Error("Expected non-conflict session, but runtime status is rolled_back.");
  }

  if (!expectedStrategy) {
    return;
  }

  if (strictStrategyBanner) {
    if (!observedStrategyBanner || !observedStrategyBanner.includes(expectedStrategy)) {
      throw new Error(
        `Expected visible strategy banner "${expectedStrategy}", got "${observedStrategyBanner ?? "null"}".`,
      );
    }
    return;
  }

  if (
    expectedConflict &&
    observedStrategyBanner &&
    !observedStrategyBanner.includes(expectedStrategy)
  ) {
    throw new Error(
      `Visible strategy banner mismatch. Expected "${expectedStrategy}", got "${observedStrategyBanner}".`,
    );
  }
}

function buildAnswerPayload(currentQuestion, scenarioId) {
  if (!currentQuestion || typeof currentQuestion !== "object") {
    return null;
  }

  const stableKey = String(currentQuestion.stable_key ?? "");
  const type = String(currentQuestion.type ?? "");

  if (!stableKey || !type) {
    return null;
  }

  if (type === "multiple_choice") {
    const options = Array.isArray(currentQuestion.options) ? currentQuestion.options : [];
    const optionValue = options[0]?.value;
    if (optionValue === undefined || optionValue === null || `${optionValue}`.trim() === "") {
      return null;
    }

    return {
      type: "multiple_choice",
      questionStableKey: stableKey,
      optionValue: String(optionValue),
    };
  }

  if (type === "rating") {
    const desired = Number.parseInt(process.env.MATRIX_RATING_VALUE ?? "3", 10);
    const value = Number.isFinite(desired) ? Math.max(1, Math.min(10, desired)) : 3;
    return {
      type: "rating",
      value,
    };
  }

  if (type === "text" || type === "open_ended") {
    return {
      type: "open_ended",
      text:
        process.env.MATRIX_OPEN_ENDED_TEXT ??
        `Automation answer for ${scenarioId} at ${new Date().toISOString()}`,
    };
  }

  return null;
}

async function runProgressionAndCompletion({
  driver,
  sessionId,
  apiToken,
  scenarioId,
  initialStateResponse,
}) {
  const initialCurrent = initialStateResponse?.state?.current_question ?? null;
  const answerPayload = buildAnswerPayload(initialCurrent, scenarioId);

  if (!answerPayload) {
    if (Boolean(initialStateResponse?.state?.can_complete)) {
      await complete(driver);
      const postComplete = await fetchSessionState({
        token: apiToken,
        sessionId,
      });
      if (postComplete?.state?.session_status !== "completed") {
        throw new Error(
          `Expected completed session after completion click, got "${postComplete?.state?.session_status ?? "null"}".`,
        );
      }

      return {
        answered: false,
        completed: true,
        postStateResponse: postComplete,
      };
    }

    return {
      answered: false,
      completed: false,
      postStateResponse: initialStateResponse,
    };
  }

  const answeredStable = String(initialCurrent?.stable_key ?? "");
  await answer(driver, answerPayload);
  await assertNoRunnerErrorCard(driver, `${scenarioId} after answer`);

  const afterAnswer = await waitForSessionState({
    token: apiToken,
    sessionId,
    description: `submitted answer for "${answeredStable}" to persist`,
    predicate: (state) =>
      Object.prototype.hasOwnProperty.call(responseAnswers(state), answeredStable),
  });

  const answers = responseAnswers(afterAnswer);
  if (!Object.prototype.hasOwnProperty.call(answers, answeredStable)) {
    throw new Error(`Submitted answer for "${answeredStable}" was not persisted.`);
  }

  const nextStable = responseCurrentStableKey(afterAnswer);
  if (!afterAnswer?.state?.can_complete && nextStable === answeredStable) {
    throw new Error(
      `Expected progression after answering "${answeredStable}", but current question stayed unchanged.`,
    );
  }

  if (nextStable) {
    await assertCurrentQuestion(driver, { stableKey: nextStable });
  }

  if (!afterAnswer?.state?.can_complete) {
    return {
      answered: true,
      completed: false,
      postStateResponse: afterAnswer,
    };
  }

  await complete(driver);
  const postComplete = await fetchSessionState({
    token: apiToken,
    sessionId,
  });
  if (postComplete?.state?.session_status !== "completed") {
    throw new Error(
      `Expected completed session after completion click, got "${postComplete?.state?.session_status ?? "null"}".`,
    );
  }

  return {
    answered: true,
    completed: true,
    postStateResponse: postComplete,
  };
}

async function runScenarioCase({ scenarioId, skipSeed, strictStrategyBanner }) {
  const payload = bootstrapMatrixScenario({
    scenarioId,
    skipSeed,
  });

  const surveyId = payload?.survey_id;
  const sessionId = payload?.session_id;
  const email = payload?.user?.email ?? process.env.MOBILE_EMAIL ?? "t1@g.com";
  const password = payload?.user?.password ?? process.env.MOBILE_PASSWORD ?? "123123123";

  if (!surveyId || !sessionId) {
    throw new Error(`Bootstrap payload missing survey/session id: ${JSON.stringify(payload)}`);
  }

  const apiToken = await loginMobileApi({
    email,
    password,
    deviceName: `appium-matrix-${scenarioId}`,
  });

  const apiStarted = await startSurveySession({
    token: apiToken,
    surveyId,
  });
  const activeSessionId = apiStarted?.session?.id ?? sessionId;

  const driver = await createDriverSession();
  try {
    await login(driver, { email, password });
    await startSurvey(driver, { surveyId });
    await assertNoRunnerErrorCard(driver, `${scenarioId} initial`);

    const observedStrategyBanner = await readConflictStrategy(driver);
    const initialStateResponse = await fetchSessionState({
      token: apiToken,
      sessionId: activeSessionId,
    });

    assertInitialStateAgainstScenario({
      payload,
      stateResponse: initialStateResponse,
      observedStrategyBanner,
      strictStrategyBanner,
    });

    const progression = await runProgressionAndCompletion({
      driver,
      sessionId: activeSessionId,
      apiToken,
      scenarioId,
      initialStateResponse,
    });

    return {
      scenario_id: scenarioId,
      survey_id: surveyId,
      session_id: activeSessionId,
      bootstrapped_session_id: sessionId,
      expected_continue_from: payload?.expected?.continue_from ?? null,
      expected_recovery_strategy: payload?.expected?.recovery_strategy ?? null,
      observed_strategy_banner: observedStrategyBanner,
      strict_strategy_banner_assertion: strictStrategyBanner,
      initial_current_question: responseCurrentStableKey(initialStateResponse),
      final_current_question: responseCurrentStableKey(progression.postStateResponse),
      final_session_status: progression?.postStateResponse?.state?.session_status ?? null,
      answered_one_step: progression.answered,
      completed: progression.completed,
    };
  } finally {
    await driver.deleteSession();
  }
}

async function run() {
  const scenarioIds = resolveSuiteScenarioIds();
  if (scenarioIds.length === 0) {
    throw new Error("No matrix suite scenarios resolved.");
  }

  const strictStrategyBanner = parseBooleanEnv("MATRIX_ASSERT_STRATEGY_BANNER", false);
  const reseedEach = parseBooleanEnv("MATRIX_SUITE_RESEED_EACH", false);
  const failFast = parseBooleanEnv("MATRIX_SUITE_FAIL_FAST", false);
  const printJson = parseBooleanEnv("MATRIX_SUITE_PRINT_JSON", true);
  const results = [];

  for (let index = 0; index < scenarioIds.length; index += 1) {
    const scenarioId = scenarioIds[index];
    const skipSeed = reseedEach ? false : index > 0;

    try {
      const caseResult = await runScenarioCase({
        scenarioId,
        skipSeed,
        strictStrategyBanner,
      });
      results.push({
        status: "passed",
        ...caseResult,
      });
      console.log(`[PASS] ${scenarioId}`);
    } catch (error) {
      results.push({
        status: "failed",
        scenario_id: scenarioId,
        error: error instanceof Error ? error.message : String(error),
      });
      console.error(`[FAIL] ${scenarioId}: ${error instanceof Error ? error.message : String(error)}`);
      if (failFast) {
        break;
      }
    }
  }

  const output = printSuiteReport({
    suiteName: "Matrix Mobile Suite",
    plannedScenarioIds: scenarioIds,
    results,
    printJson,
  });

  if (output.status !== "ok") {
    process.exit(1);
  }
}

run().catch((error) => {
  console.error(error);
  process.exit(1);
});

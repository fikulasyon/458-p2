import assert from "node:assert/strict";
import { pathToFileURL } from "node:url";
import {
  answer,
  assertCurrentQuestion,
  login,
  readConflictStrategy,
  startSurvey,
  syncRunnerState,
} from "../core/mobile-flow.js";
import { maybeElement } from "../core/selectors.js";
import { Tags } from "../core/tags.js";
import { prepareLiveMatrixScenario } from "../matrix/bootstrap.js";
import {
  fetchSessionState,
  loginMobileApi,
  startSurveySession,
} from "../matrix/mobile-api.js";
import { createDriverSession } from "../runtime/create-driver-session.js";
import { createWebDriverSession } from "../runtime/create-web-driver-session.js";
import {
  loginAsArchitectAdmin,
  publishVersionFromArchitect,
} from "../web/architect-flow.js";

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

const parseBooleanEnv = (name, fallback = false) => {
  const value = process.env[name];
  if (value === undefined) {
    return fallback;
  }
  const normalized = String(value).trim().toLowerCase();
  return normalized === "1" || normalized === "true" || normalized === "yes";
};

const parseIntEnv = (name, fallback) => {
  const value = process.env[name];
  if (!value) {
    return fallback;
  }
  const parsed = Number.parseInt(value, 10);
  return Number.isFinite(parsed) ? parsed : fallback;
};

const asObject = (value) => (value && typeof value === "object" ? value : {});
const asStringArray = (value) =>
  Array.isArray(value) ? value.map((item) => String(item)) : [];
const asSurveyType = (value) => String(value ?? "").trim().toLowerCase();

const parseRatingValue = (value, fallback = 3) => {
  const parsed = Number.parseInt(String(value), 10);
  const numeric = Number.isFinite(parsed) ? parsed : fallback;
  return Math.max(1, Math.min(10, numeric));
};

const parseOpenEndedValue = (value, fallback) => {
  const text = value === null || value === undefined ? "" : String(value).trim();
  if (text !== "") {
    return text;
  }
  return fallback;
};

async function waitForSessionState({
  token,
  sessionId,
  predicate,
  timeoutMs = 15000,
  intervalMs = 300,
  description = "session state condition",
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
  const sessionStatus = lastState?.state?.session_status ?? null;
  const answerKeys = Object.keys(asObject(lastState?.state?.answers));
  throw new Error(
    `Timed out waiting for ${description}. Last state => current: ${currentStable ?? "null"}, status: ${sessionStatus ?? "null"}, answers: [${answerKeys.join(", ")}]`,
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
    // Keep fallback.
  }

  throw new Error(`${contextLabel}: ${details}`);
}

function assertConflictStateAgainstExpected(payload, stateResponse) {
  const expected = asObject(payload?.expected);
  const expectedContinue = expected.continue_from ? String(expected.continue_from) : null;
  const expectedStrategy = expected.recovery_strategy ? String(expected.recovery_strategy) : null;
  const expectedConflict = Boolean(expected.conflict_detected);
  const expectedDropped = asStringArray(expected.drop_answers);
  const mustNotShowUnreachable = asStringArray(expected.must_not_show_unreachable);
  const checkpointAnswers = asObject(payload?.checkpoint?.answers);

  const sync = asObject(stateResponse?.version_sync);
  const state = asObject(stateResponse?.state);
  const currentStable = state?.current_question?.stable_key ?? null;
  const answers = asObject(state?.answers);
  const visible = asStringArray(state?.visible_questions);
  const sessionStatus = String(state?.session_status ?? "");
  const mismatchDetected = Boolean(sync.mismatch_detected);

  // mismatch_detected can be consumed by a previous state sync (manual or auto-refresh).
  // When present, validate version_sync fields strictly; when absent, validate from session state.
  if (mismatchDetected) {
    if (Boolean(sync.conflict_detected) !== expectedConflict) {
      throw new Error(
        `Expected conflict_detected=${expectedConflict}, got ${Boolean(sync.conflict_detected)}.`,
      );
    }

    if (expectedStrategy && sync.recovery_strategy !== expectedStrategy) {
      throw new Error(
        `Expected recovery_strategy="${expectedStrategy}" but got "${sync.recovery_strategy ?? "null"}".`,
      );
    }
  } else if (expectedConflict) {
    if (expectedStrategy === "rollback" && sessionStatus !== "rolled_back") {
      throw new Error(
        `Expected rolled_back state after conflict when mismatch flag is consumed, got "${sessionStatus || "null"}".`,
      );
    }
    if (expectedStrategy === "atomic_recovery" && sessionStatus !== "conflict_recovered") {
      throw new Error(
        `Expected conflict_recovered state after conflict when mismatch flag is consumed, got "${sessionStatus || "null"}".`,
      );
    }
  }

  if (expectedContinue && currentStable !== expectedContinue) {
    throw new Error(
      `Expected current question "${expectedContinue}" but got "${currentStable ?? "null"}".`,
    );
  }

  if (expectedContinue && Object.prototype.hasOwnProperty.call(answers, expectedContinue)) {
    throw new Error(
      `Fallback fresh-answer boundary violated: "${expectedContinue}" already has an answer.`,
    );
  }

  const droppedFromSync = asStringArray(sync.dropped_answers);
  for (const stableKey of expectedDropped) {
    if (mismatchDetected && !droppedFromSync.includes(stableKey)) {
      throw new Error(`Expected dropped answer "${stableKey}" missing from version_sync.`);
    }
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
    assert.deepStrictEqual(
      answers[stableKey],
      value,
      `Expected kept answer "${stableKey}" to stay as ${JSON.stringify(value)}.`,
    );
  }

  if (currentStable && !visible.includes(currentStable)) {
    throw new Error(
      `Zombie invariant failed: current node "${currentStable}" is not visible.`,
    );
  }

  for (const unreachable of mustNotShowUnreachable) {
    if (visible.includes(unreachable)) {
      throw new Error(
        `Zombie/unreachable invariant failed: node "${unreachable}" is still visible.`,
      );
    }
  }
}

async function answerCheckpointPath({ driver, payload }) {
  const checkpoint = asObject(payload?.checkpoint);
  const checkpointAnswers = asObject(checkpoint?.answers);
  const entries = Object.entries(checkpointAnswers);
  const surveyType = asSurveyType(payload?.survey_type);

  for (const [questionStableKey, answerValue] of entries) {
    await assertCurrentQuestion(driver, { stableKey: String(questionStableKey) });

    if (surveyType === "multiple_choice") {
      await answer(driver, {
        type: "multiple_choice",
        questionStableKey: String(questionStableKey),
        optionValue: String(answerValue),
      });
      continue;
    }

    if (surveyType === "rating") {
      await answer(driver, {
        type: "rating",
        value: parseRatingValue(answerValue),
      });
      continue;
    }

    if (surveyType === "open_ended" || surveyType === "text") {
      await answer(driver, {
        type: "open_ended",
        text: parseOpenEndedValue(
          answerValue,
          `Sync checkpoint answer for ${String(questionStableKey)}`,
        ),
      });
      continue;
    }

    throw new Error(`Unsupported survey type for checkpoint replay: "${surveyType || "unknown"}".`);
  }

  const checkpointCurrent = checkpoint?.current_stable_key
    ? String(checkpoint.current_stable_key)
    : null;

  if (checkpointCurrent) {
    await assertCurrentQuestion(driver, { stableKey: checkpointCurrent });
  }
}

export async function executeSyncConflictScenario({
  scenarioId = "MC_RB_03",
  skipSeed = false,
  strictStrategyBanner = parseBooleanEnv("MATRIX_ASSERT_STRATEGY_BANNER", false),
  verifyFallbackFreshAnswer = parseBooleanEnv("SYNC_VERIFY_FRESH_ANSWER", true),
  publishDelayMs = parseIntEnv("SYNC_PUBLISH_DELAY_MS", 0),
  baseUrl = (process.env.BACKEND_BASE_URL ?? "http://127.0.0.1:8000").replace(/\/+$/, ""),
  fallbackAnswerValue = process.env.SYNC_FALLBACK_ANSWER_VALUE ?? "A",
} = {}) {
  const normalizedScenarioId = String(scenarioId).trim().toUpperCase();
  if (!normalizedScenarioId) {
    throw new Error("scenarioId is required.");
  }

  const payload = prepareLiveMatrixScenario({
    scenarioId: normalizedScenarioId,
    skipSeed,
  });
  const surveyType = asSurveyType(payload?.survey_type);
  if (!["multiple_choice", "rating", "open_ended", "text"].includes(surveyType)) {
    throw new Error(
      `executeSyncConflictScenario does not support survey_type="${payload?.survey_type ?? "unknown"}".`,
    );
  }

  const surveyId = payload?.survey_id;
  const scenarioVersionId = payload?.scenario_version_id;
  const mobileEmail = payload?.mobile_user?.email ?? process.env.MOBILE_EMAIL ?? "t1@g.com";
  const mobilePassword =
    payload?.mobile_user?.password ?? process.env.MOBILE_PASSWORD ?? "123123123";
  const adminEmail = payload?.admin_user?.email ?? process.env.ADMIN_EMAIL ?? "admin@example.com";
  const adminPassword =
    payload?.admin_user?.password ?? process.env.ADMIN_PASSWORD ?? "password";
  const expectedContinueFrom = payload?.expected?.continue_from ?? null;
  const expectedStrategy = payload?.expected?.recovery_strategy ?? null;

  if (!surveyId || !scenarioVersionId) {
    throw new Error(`Live prepare payload missing survey/scenario version: ${JSON.stringify(payload)}`);
  }

  const apiToken = await loginMobileApi({
    email: mobileEmail,
    password: mobilePassword,
    deviceName: `appium-sync-conflict-${normalizedScenarioId.toLowerCase()}`,
  });

  const sessionStart = await startSurveySession({
    token: apiToken,
    surveyId,
  });

  const sessionId = sessionStart?.session?.id;
  if (!sessionId) {
    throw new Error(`Start session payload missing session id: ${JSON.stringify(sessionStart)}`);
  }

  const initialFromApi = sessionStart?.state?.current_question?.stable_key ?? null;
  if (!initialFromApi) {
    throw new Error(
      "Start session response missing current question stable key.",
    );
  }

  let mobileDriver = null;
  let webDriver = null;

  try {
    mobileDriver = await createDriverSession();
    await login(mobileDriver, { email: mobileEmail, password: mobilePassword });
    await startSurvey(mobileDriver, { surveyId });
    await answerCheckpointPath({
      driver: mobileDriver,
      payload,
    });

    if (publishDelayMs > 0) {
      await sleep(publishDelayMs);
    }

    webDriver = await createWebDriverSession();
    await loginAsArchitectAdmin(webDriver, {
      baseUrl,
      email: adminEmail,
      password: adminPassword,
    });
    await publishVersionFromArchitect(webDriver, {
      baseUrl,
      surveyId,
      versionId: scenarioVersionId,
    });

    await syncRunnerState(mobileDriver);
    await assertNoRunnerErrorCard(mobileDriver, `${normalizedScenarioId} after sync`);
    if (expectedContinueFrom) {
      await assertCurrentQuestion(mobileDriver, {
        stableKey: String(expectedContinueFrom),
        timeoutMs: 20000,
      });
    }

    const observedStrategyBanner = await readConflictStrategy(mobileDriver);
    if (expectedStrategy) {
      if (strictStrategyBanner) {
        if (
          !observedStrategyBanner ||
          !observedStrategyBanner.includes(String(expectedStrategy))
        ) {
          throw new Error(
            `Expected strategy banner "${expectedStrategy}" after sync. Actual: ${observedStrategyBanner ?? "null"}`,
          );
        }
      } else if (
        observedStrategyBanner &&
        !observedStrategyBanner.includes(String(expectedStrategy))
      ) {
        throw new Error(
          `Visible strategy banner mismatch. Expected "${expectedStrategy}", got "${observedStrategyBanner}".`,
        );
      }
    }

    const conflictState = await fetchSessionState({
      token: apiToken,
      sessionId,
    });
    assertConflictStateAgainstExpected(payload, conflictState);

    let postFreshState = null;
    let freshAnswerUsed = null;
    if (verifyFallbackFreshAnswer && expectedContinueFrom) {
      if (surveyType === "multiple_choice") {
        const options = Array.isArray(conflictState?.state?.current_question?.options)
          ? conflictState.state.current_question.options
          : [];
        const candidateValues = options
          .map((option) => option?.value)
          .filter((value) => value !== null && value !== undefined)
          .map((value) => String(value));
        const preferredValue = String(fallbackAnswerValue);
        const optionValue = candidateValues.includes(preferredValue)
          ? preferredValue
          : (candidateValues[0] ?? preferredValue);

        freshAnswerUsed = optionValue;
        await answer(mobileDriver, {
          type: "multiple_choice",
          questionStableKey: String(expectedContinueFrom),
          optionValue,
        });
      } else if (surveyType === "rating") {
        const fallbackRating = parseRatingValue(
          process.env.SYNC_FALLBACK_RATING_VALUE ?? "3",
        );
        freshAnswerUsed = String(fallbackRating);
        await answer(mobileDriver, {
          type: "rating",
          value: fallbackRating,
        });
      } else {
        const fallbackText = parseOpenEndedValue(
          process.env.SYNC_FALLBACK_TEXT ?? "",
          `sync-fallback-${normalizedScenarioId.toLowerCase()}-${Date.now()}`,
        );
        freshAnswerUsed = fallbackText;
        await answer(mobileDriver, {
          type: "open_ended",
          text: fallbackText,
        });
      }

      await assertNoRunnerErrorCard(
        mobileDriver,
        `${normalizedScenarioId} after fallback re-answer`,
      );

      postFreshState = await waitForSessionState({
        token: apiToken,
        sessionId,
        description: `fresh fallback answer for "${expectedContinueFrom}" to be stored`,
        predicate: (state) => {
          const answers = asObject(state?.state?.answers);
          return Object.prototype.hasOwnProperty.call(
            answers,
            String(expectedContinueFrom),
          );
        },
      });
    }

    return {
      status: "ok",
      scenario_id: normalizedScenarioId,
      survey_id: surveyId,
      session_id: sessionId,
      scenario_version_id: scenarioVersionId,
      expected_continue_from: expectedContinueFrom,
      expected_recovery_strategy: expectedStrategy,
      mismatch_detected_in_asserted_state: Boolean(conflictState?.version_sync?.mismatch_detected),
      observed_strategy_banner: observedStrategyBanner,
      strict_strategy_banner_assertion: strictStrategyBanner,
      fresh_fallback_answer_verified: verifyFallbackFreshAnswer,
      fresh_fallback_answer_used: freshAnswerUsed,
      final_current_question:
        postFreshState?.state?.current_question?.stable_key ??
        conflictState?.state?.current_question?.stable_key ??
        null,
      final_session_status:
        postFreshState?.state?.session_status ??
        conflictState?.state?.session_status ??
        null,
    };
  } finally {
    if (webDriver) {
      await webDriver.deleteSession();
    }
    if (mobileDriver) {
      await mobileDriver.deleteSession();
    }
  }
}

async function run() {
  const result = await executeSyncConflictScenario({
    scenarioId: process.env.SYNC_SCENARIO_ID ?? "MC_RB_03",
    skipSeed: (process.env.MATRIX_SKIP_SEED ?? "false") === "true",
  });

  console.log(JSON.stringify(result, null, 2));
}

const isMainModule = process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url;
if (isMainModule) {
  run().catch((error) => {
    console.error(error);
    process.exit(1);
  });
}

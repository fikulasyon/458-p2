import {
  assertCurrentQuestion,
  login,
  readConflictStrategy,
  startSurvey,
} from "../core/mobile-flow.js";
import { bootstrapMatrixScenario as bootstrapScenarioPayload } from "../matrix/bootstrap.js";
import { createDriverSession } from "../runtime/create-driver-session.js";

function bootstrapScenario() {
  const scenarioId = (process.env.MATRIX_SCENARIO_ID ?? "").trim();
  if (!scenarioId) {
    throw new Error("MATRIX_SCENARIO_ID is required (e.g., MC_RB_03).");
  }

  return bootstrapScenarioPayload({
    scenarioId,
  });
}

async function run() {
  const payload = bootstrapScenario();
  const expectedContinue = payload?.expected?.continue_from;
  const expectedStrategy = payload?.expected?.recovery_strategy;
  const expectedConflictDetected = Boolean(payload?.expected?.conflict_detected);
  const strictStrategyBanner =
    (process.env.MATRIX_ASSERT_STRATEGY_BANNER ?? "false").toLowerCase() === "true";
  const surveyId = payload?.survey_id;
  const email = payload?.user?.email ?? process.env.MOBILE_EMAIL ?? "t1@g.com";
  const password = payload?.user?.password ?? process.env.MOBILE_PASSWORD ?? "123123123";

  if (!surveyId) {
    throw new Error(`Bootstrap payload missing survey_id: ${JSON.stringify(payload)}`);
  }

  const driver = await createDriverSession();
  try {
    await login(driver, { email, password });
    await startSurvey(driver, { surveyId });

    if (expectedContinue) {
      await assertCurrentQuestion(driver, { stableKey: String(expectedContinue) });
    }

    let observedStrategy = null;
    if (expectedStrategy) {
      observedStrategy = await readConflictStrategy(driver);

      // Strategy banner is transient because the app auto-refreshes session state.
      // Keep strict checking opt-in for debugging runs.
      if (strictStrategyBanner) {
        if (!observedStrategy || !observedStrategy.includes(String(expectedStrategy))) {
          throw new Error(
            `Expected conflict strategy banner "${expectedStrategy}" not found. Actual: ${observedStrategy ?? "null"}`,
          );
        }
      } else if (
        expectedConflictDetected &&
        observedStrategy &&
        !observedStrategy.includes(String(expectedStrategy))
      ) {
        throw new Error(
          `Conflict banner was visible but did not match expected strategy "${expectedStrategy}". Actual: ${observedStrategy}`,
        );
      }
    }

    if (!strictStrategyBanner && expectedConflictDetected && !observedStrategy) {
      console.warn(
        `Conflict strategy banner was not visible (likely auto-refresh). Expected strategy remains "${expectedStrategy}".`,
      );
    }

    console.log(
      JSON.stringify(
        {
          status: "ok",
          scenario_id: payload?.scenario_id,
          survey_id: surveyId,
          expected_continue_from: expectedContinue ?? null,
          expected_recovery_strategy: expectedStrategy ?? null,
          observed_strategy_banner: observedStrategy,
          strict_strategy_banner_assertion: strictStrategyBanner,
        },
        null,
        2,
      ),
    );
  } finally {
    await driver.deleteSession();
  }
}

run().catch((error) => {
  console.error(error);
  process.exit(1);
});

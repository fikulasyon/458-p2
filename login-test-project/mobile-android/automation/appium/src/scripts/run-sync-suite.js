import { executeSyncConflictScenario } from "./run-sync-conflict.js";
import { FULL_SYNC_SUITE_SCENARIOS } from "../matrix/suite-scenarios.js";
import { printSuiteReport } from "../reporting/suite-report.js";

const parseBooleanEnv = (name, fallback = false) => {
  const value = process.env[name];
  if (value === undefined) {
    return fallback;
  }
  const normalized = String(value).trim().toLowerCase();
  return normalized === "1" || normalized === "true" || normalized === "yes";
};

const parseScenarioListFromEnv = () => {
  const raw = String(process.env.SYNC_SUITE_SCENARIOS ?? "").trim();
  if (!raw) {
    return null;
  }

  const scenarios = raw
    .split(/[,\s]+/)
    .map((item) => item.trim().toUpperCase())
    .filter(Boolean);

  return scenarios.length > 0 ? scenarios : null;
};

async function run() {
  const scenarios = parseScenarioListFromEnv() ?? [...FULL_SYNC_SUITE_SCENARIOS];
  const reseedEach = parseBooleanEnv("SYNC_SUITE_RESEED_EACH", false);
  const failFast = parseBooleanEnv("SYNC_SUITE_FAIL_FAST", false);
  const printJson = parseBooleanEnv("SYNC_SUITE_PRINT_JSON", true);
  const results = [];

  for (let index = 0; index < scenarios.length; index += 1) {
    const scenarioId = scenarios[index];
    const skipSeed = reseedEach ? false : index > 0;

    try {
      const result = await executeSyncConflictScenario({
        scenarioId,
        skipSeed,
      });

      results.push({
        status: "passed",
        ...result,
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
    suiteName: "Sync Conflict Suite",
    plannedScenarioIds: scenarios,
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

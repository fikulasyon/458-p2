const asString = (value) => {
  if (value === null || value === undefined) {
    return "-";
  }
  const text = String(value).trim();
  return text === "" ? "-" : text;
};

const asScenarioId = (result) => asString(result?.scenario_id).toUpperCase();

const formatPassedScenarioLine = (scenarioId, result) => {
  const expectedStrategy = asString(result?.expected_recovery_strategy);
  const expectedContinue = asString(result?.expected_continue_from);
  const finalQuestion = asString(result?.final_current_question);
  const finalStatus = asString(result?.final_session_status);

  const parts = [
    `[PASS] ${scenarioId}`,
    `expected=${expectedStrategy}@${expectedContinue}`,
    `final=${finalQuestion}`,
    `session=${finalStatus}`,
  ];

  if (Object.prototype.hasOwnProperty.call(result, "mismatch_detected_in_asserted_state")) {
    parts.push(`mismatch_flag=${Boolean(result.mismatch_detected_in_asserted_state)}`);
  }

  if (Object.prototype.hasOwnProperty.call(result, "fresh_fallback_answer_verified")) {
    parts.push(`fresh_boundary=${Boolean(result.fresh_fallback_answer_verified)}`);
  }

  return parts.join(" | ");
};

const formatFailedScenarioLine = (scenarioId, result) => {
  const reason = asString(result?.error);
  return `[FAIL] ${scenarioId} | error=${reason}`;
};

export function buildSuiteOutput({ plannedScenarioIds, results }) {
  const planned = Array.isArray(plannedScenarioIds)
    ? plannedScenarioIds.map((id) => asString(id).toUpperCase()).filter(Boolean)
    : [];
  const executed = Array.isArray(results) ? results : [];
  const failed = executed.filter((item) => String(item?.status) === "failed");
  const passed = executed.length - failed.length;

  const executedIds = new Set(
    executed.map((item) => asScenarioId(item)).filter((id) => id && id !== "-"),
  );
  const missingScenarioIds = planned.filter((id) => !executedIds.has(id));

  return {
    status: failed.length === 0 && missingScenarioIds.length === 0 ? "ok" : "failed",
    suite_size: planned.length,
    executed: executed.length,
    passed,
    failed: failed.length,
    missing: missingScenarioIds.length,
    missing_scenarios: missingScenarioIds,
    scenarios: executed,
  };
}

export function printSuiteReport({ suiteName, plannedScenarioIds, results, printJson = true }) {
  const output = buildSuiteOutput({ plannedScenarioIds, results });
  const planned = Array.isArray(plannedScenarioIds)
    ? plannedScenarioIds.map((id) => asString(id).toUpperCase()).filter(Boolean)
    : [];
  const plannedSet = new Set(planned);
  const resultByScenarioId = new Map(
    output.scenarios.map((result) => [asScenarioId(result), result]),
  );

  console.log("=".repeat(72));
  console.log(`${suiteName} Summary`);
  console.log("=".repeat(72));
  console.log(
    `Status: ${output.status.toUpperCase()} | Planned: ${output.suite_size} | Executed: ${output.executed} | Passed: ${output.passed} | Failed: ${output.failed} | Missing: ${output.missing}`,
  );
  console.log("-".repeat(72));
  console.log("Scenario Results:");

  for (const scenarioId of planned) {
    const result = resultByScenarioId.get(scenarioId);
    if (!result) {
      console.log(`[MISS] ${scenarioId} | not executed`);
      continue;
    }

    if (String(result.status) === "failed") {
      console.log(formatFailedScenarioLine(scenarioId, result));
      continue;
    }

    console.log(formatPassedScenarioLine(scenarioId, result));
  }

  const unplannedResults = output.scenarios.filter((result) => {
    const id = asScenarioId(result);
    return id !== "-" && !plannedSet.has(id);
  });
  if (unplannedResults.length > 0) {
    console.log("-".repeat(72));
    console.log("Unplanned Results:");
    for (const result of unplannedResults) {
      const scenarioId = asScenarioId(result);
      if (String(result.status) === "failed") {
        console.log(formatFailedScenarioLine(scenarioId, result));
      } else {
        console.log(formatPassedScenarioLine(scenarioId, result));
      }
    }
  }

  if (output.missing_scenarios.length > 0) {
    console.log("-".repeat(72));
    console.log(`Missing scenarios: ${output.missing_scenarios.join(", ")}`);
  }

  if (printJson) {
    console.log("-".repeat(72));
    console.log(JSON.stringify(output, null, 2));
  }

  return output;
}

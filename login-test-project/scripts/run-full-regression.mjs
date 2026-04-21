import { spawn } from "node:child_process";
import { createWriteStream, mkdirSync, writeFileSync } from "node:fs";
import { join, resolve } from "node:path";

const now = new Date();
const runId = [
  now.getFullYear(),
  String(now.getMonth() + 1).padStart(2, "0"),
  String(now.getDate()).padStart(2, "0"),
].join("") + `-${String(now.getHours()).padStart(2, "0")}${String(now.getMinutes()).padStart(2, "0")}${String(now.getSeconds()).padStart(2, "0")}`;

const repoRoot = process.cwd();
const artifactsDir = resolve(repoRoot, "artifacts", "test-runs", runId);
mkdirSync(artifactsDir, { recursive: true });

const asBool = (value, fallback = false) => {
  if (value === undefined) {
    return fallback;
  }
  const normalized = String(value).trim().toLowerCase();
  return normalized === "1" || normalized === "true" || normalized === "yes";
};

const isWindows = process.platform === "win32";
const npmCommand = isWindows ? "npm.cmd" : "npm";
const gradleCommand = isWindows ? "gradlew.bat" : "./gradlew";

const skipAndroid = asBool(process.env.FULL_REGRESSION_SKIP_ANDROID, false);
const skipMatrix = asBool(process.env.FULL_REGRESSION_SKIP_MATRIX, false);
const skipSync = asBool(process.env.FULL_REGRESSION_SKIP_SYNC, false);

const steps = [
  {
    id: "php-artisan-test",
    label: "Laravel test suite",
    command: "php",
    args: ["artisan", "test"],
    cwd: repoRoot,
    enabled: true,
  },
  {
    id: "android-testDebugUnitTest",
    label: "Android unit tests",
    command: gradleCommand,
    args: ["testDebugUnitTest"],
    cwd: join(repoRoot, "mobile-android"),
    enabled: !skipAndroid,
  },
  {
    id: "appium-matrix-suite",
    label: "Appium matrix suite",
    command: npmCommand,
    args: ["run", "run:matrix-suite"],
    cwd: join(repoRoot, "mobile-android", "automation", "appium"),
    enabled: !skipMatrix,
  },
  {
    id: "appium-sync-suite",
    label: "Appium sync suite",
    command: npmCommand,
    args: ["run", "run:sync-suite"],
    cwd: join(repoRoot, "mobile-android", "automation", "appium"),
    enabled: !skipSync,
  },
];

function unsetKeys(target, keys) {
  for (const key of keys) {
    if (Object.prototype.hasOwnProperty.call(target, key)) {
      delete target[key];
    }
  }
}

function buildStepEnv(step) {
  const env = { ...process.env };

  if (step.id === "appium-matrix-suite") {
    unsetKeys(env, [
      "MATRIX_SCENARIO_ID",
      "MATRIX_SKIP_SEED",
      "MATRIX_SUITE_SCENARIOS",
      "MATRIX_SUITE_FULL",
      "MATRIX_SUITE_RESEED_EACH",
      "MATRIX_SUITE_FAIL_FAST",
    ]);

    const overrideScenarios = String(process.env.FULL_REGRESSION_MATRIX_SCENARIOS ?? "").trim();
    if (overrideScenarios) {
      env.MATRIX_SUITE_SCENARIOS = overrideScenarios;
    }

    if (asBool(process.env.FULL_REGRESSION_MATRIX_SUITE_FULL, false)) {
      env.MATRIX_SUITE_FULL = "true";
    }
  }

  if (step.id === "appium-sync-suite") {
    unsetKeys(env, [
      "SYNC_SCENARIO_ID",
      "SYNC_SUITE_SCENARIOS",
      "SYNC_SUITE_RESEED_EACH",
      "SYNC_SUITE_FAIL_FAST",
    ]);

    const overrideSyncScenarios = String(process.env.FULL_REGRESSION_SYNC_SCENARIOS ?? "").trim();
    if (overrideSyncScenarios) {
      env.SYNC_SUITE_SCENARIOS = overrideSyncScenarios;
    }
  }

  // Avoid npm warning noise from foreign envs in CI/local shells.
  unsetKeys(env, ["npm_config_public_hoist_pattern", "NPM_CONFIG_PUBLIC_HOIST_PATTERN"]);

  return env;
}

function stepBanner(label) {
  const line = "=".repeat(80);
  return `\n${line}\n${label}\n${line}\n`;
}

function runStep(step) {
  return new Promise((resolveStep) => {
    const startedAt = new Date();
    const startedHr = process.hrtime.bigint();
    const logPath = join(artifactsDir, `${step.id}.log`);
    const stream = createWriteStream(logPath, { flags: "w" });

    if (!step.enabled) {
      const skippedMessage = [
        `Step: ${step.label}`,
        `Status: skipped`,
        `Reason: disabled by environment flag`,
        `Started at: ${startedAt.toISOString()}`,
      ].join("\n");
      stream.write(`${skippedMessage}\n`);
      stream.end();
      resolveStep({
        id: step.id,
        label: step.label,
        status: "skipped",
        code: null,
        started_at: startedAt.toISOString(),
        duration_seconds: 0,
        log_path: logPath,
      });
      return;
    }

    const header = [
      `Step: ${step.label}`,
      `Command: ${step.command} ${step.args.join(" ")}`,
      `Working directory: ${step.cwd}`,
      `Started at: ${startedAt.toISOString()}`,
      "",
    ].join("\n");
    stream.write(header);
    process.stdout.write(stepBanner(`Running: ${step.label}`));
    process.stdout.write(`$ ${step.command} ${step.args.join(" ")}\n`);

    const lowerCommand = step.command.toLowerCase();
    const isWindowsBatch = isWindows && (lowerCommand.endsWith(".bat") || lowerCommand.endsWith(".cmd"));
    const spawnCommand = isWindowsBatch ? "cmd.exe" : step.command;
    const spawnArgs = isWindowsBatch
      ? ["/d", "/s", "/c", step.command, ...step.args]
      : step.args;

    const child = spawn(spawnCommand, spawnArgs, {
      cwd: step.cwd,
      env: buildStepEnv(step),
      shell: false,
      stdio: ["ignore", "pipe", "pipe"],
    });

    const writeChunk = (chunk, writer) => {
      if (!chunk) {
        return;
      }
      writer.write(chunk);
      stream.write(chunk);
    };

    child.stdout.on("data", (chunk) => writeChunk(chunk, process.stdout));
    child.stderr.on("data", (chunk) => writeChunk(chunk, process.stderr));

    child.on("error", (error) => {
      const elapsed = Number(process.hrtime.bigint() - startedHr) / 1_000_000_000;
      const message = `\nCommand failed to start: ${error.message}\n`;
      process.stderr.write(message);
      stream.write(message);
      stream.end();
      resolveStep({
        id: step.id,
        label: step.label,
        status: "failed",
        code: null,
        error: error.message,
        started_at: startedAt.toISOString(),
        duration_seconds: Number(elapsed.toFixed(2)),
        log_path: logPath,
      });
    });

    child.on("close", (code) => {
      const elapsed = Number(process.hrtime.bigint() - startedHr) / 1_000_000_000;
      const passed = code === 0;
      const footer = `\nExit code: ${code}\nDuration: ${elapsed.toFixed(2)}s\n`;
      stream.write(footer);
      stream.end();

      resolveStep({
        id: step.id,
        label: step.label,
        status: passed ? "passed" : "failed",
        code,
        started_at: startedAt.toISOString(),
        duration_seconds: Number(elapsed.toFixed(2)),
        log_path: logPath,
      });
    });
  });
}

function buildMarkdownSummary(summary) {
  const lines = [];
  lines.push("# Full Regression Run Summary");
  lines.push("");
  lines.push(`- Run ID: \`${summary.run_id}\``);
  lines.push(`- Started at: \`${summary.started_at}\``);
  lines.push(`- Completed at: \`${summary.completed_at}\``);
  lines.push(`- Overall status: \`${summary.status}\``);
  lines.push(`- Artifacts: \`${summary.artifacts_dir}\``);
  lines.push("");
  lines.push("| Step | Status | Exit Code | Duration (s) | Log |");
  lines.push("| --- | --- | --- | ---: | --- |");
  for (const step of summary.steps) {
    const code = step.code === null || step.code === undefined ? "-" : String(step.code);
    const duration = Number(step.duration_seconds ?? 0).toFixed(2);
    lines.push(
      `| ${step.label} | ${step.status} | ${code} | ${duration} | ${step.log_path.replaceAll("\\", "/")} |`,
    );
  }
  lines.push("");
  return `${lines.join("\n")}\n`;
}

async function main() {
  const startedAt = new Date();
  const results = [];

  for (const step of steps) {
    // eslint-disable-next-line no-await-in-loop
    const result = await runStep(step);
    results.push(result);
  }

  const failedCount = results.filter((step) => step.status === "failed").length;
  const summary = {
    run_id: runId,
    started_at: startedAt.toISOString(),
    completed_at: new Date().toISOString(),
    status: failedCount === 0 ? "passed" : "failed",
    artifacts_dir: artifactsDir,
    failed_steps: failedCount,
    steps: results,
  };

  const jsonPath = join(artifactsDir, "summary.json");
  const markdownPath = join(artifactsDir, "summary.md");
  writeFileSync(jsonPath, `${JSON.stringify(summary, null, 2)}\n`, "utf8");
  writeFileSync(markdownPath, buildMarkdownSummary(summary), "utf8");

  process.stdout.write(stepBanner("Full Regression Summary"));
  process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
  process.stdout.write(`\nSummary files:\n- ${jsonPath}\n- ${markdownPath}\n`);

  if (failedCount > 0) {
    process.exitCode = 1;
  }
}

main().catch((error) => {
  process.stderr.write(`${error instanceof Error ? error.stack ?? error.message : String(error)}\n`);
  process.exitCode = 1;
});

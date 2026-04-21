import { spawnSync } from "node:child_process";
import path from "node:path";
import { fileURLToPath } from "node:url";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const repoRoot = path.resolve(__dirname, "../../../../../");

export const parseBootstrapJson = (stdout) => {
  const lines = String(stdout)
    .split(/\r?\n/)
    .map((line) => line.trim())
    .filter((line) => line.startsWith("{") && line.endsWith("}"));

  if (lines.length === 0) {
    throw new Error(`Bootstrap output did not contain JSON. Raw output:\n${stdout}`);
  }

  const parsed = JSON.parse(lines.at(-1));
  if (!parsed || typeof parsed !== "object") {
    throw new Error("Bootstrap JSON payload was invalid.");
  }

  return parsed;
};

const runArtisanJson = (commandName, args) => {
  const result = spawnSync("php", ["artisan", commandName, ...args, "--json"], {
    cwd: repoRoot,
    encoding: "utf-8",
    env: process.env,
  });

  if (result.status !== 0) {
    throw new Error(
      [
        `Matrix command failed: ${commandName}`,
        `Exit code: ${result.status}`,
        `STDOUT:\n${result.stdout ?? ""}`,
        `STDERR:\n${result.stderr ?? ""}`,
      ].join("\n"),
    );
  }

  return parseBootstrapJson(result.stdout ?? "");
};

export function bootstrapMatrixScenario({
  scenarioId,
  userEmail = process.env.MOBILE_EMAIL ?? "t1@g.com",
  userPassword = process.env.MOBILE_PASSWORD ?? "123123123",
  userName = process.env.MATRIX_USER_NAME ?? "Mobile Matrix User",
  skipSeed = (process.env.MATRIX_SKIP_SEED ?? "false") === "true",
} = {}) {
  const normalizedScenario = String(scenarioId ?? "").trim();
  if (!normalizedScenario) {
    throw new Error("scenarioId is required (e.g., MC_RB_03).");
  }

  const args = [
    normalizedScenario,
    `--user-email=${userEmail}`,
    `--user-password=${userPassword}`,
    `--user-name=${userName}`,
  ];

  if (skipSeed) {
    args.push("--skip-seed");
  }

  return runArtisanJson("survey:matrix-bootstrap", args);
}

export function prepareLiveMatrixScenario({
  scenarioId,
  userEmail = process.env.MOBILE_EMAIL ?? "t1@g.com",
  userPassword = process.env.MOBILE_PASSWORD ?? "123123123",
  userName = process.env.MATRIX_USER_NAME ?? "Mobile Matrix User",
  adminEmail = process.env.ADMIN_EMAIL ?? "admin@example.com",
  adminPassword = process.env.ADMIN_PASSWORD ?? "password",
  adminName = process.env.ADMIN_NAME ?? "Survey Admin",
  skipSeed = (process.env.MATRIX_SKIP_SEED ?? "false") === "true",
} = {}) {
  const normalizedScenario = String(scenarioId ?? "").trim();
  if (!normalizedScenario) {
    throw new Error("scenarioId is required (e.g., MC_RB_03).");
  }

  const args = [
    normalizedScenario,
    `--user-email=${userEmail}`,
    `--user-password=${userPassword}`,
    `--user-name=${userName}`,
    `--admin-email=${adminEmail}`,
    `--admin-password=${adminPassword}`,
    `--admin-name=${adminName}`,
  ];

  if (skipSeed) {
    args.push("--skip-seed");
  }

  return runArtisanJson("survey:matrix-live-prepare", args);
}

# Appium Scaffold (Mobile Conflict Automation)

This folder contains the first automation scaffold for the Android client.

Current scope:
- Stable selector/tag contract usage (Compose `testTag` exposed as Android resource-id)
- Reusable mobile flow helpers:
  - `login`
  - `startSurvey`
  - `answer` (`multiple_choice`, `rating`, `open_ended`)
  - `assertCurrentQuestion`
  - `complete`
- Driver bootstrap for Android + UiAutomator2
- Unit tests for helper behavior with a mock driver

## Install

```bash
cd mobile-android/automation/appium
npm install
```

## Full regression entrypoint (repo root)

For grading/repeatable runs, execute from repo root:

```bash
npm run regression:full
```

Artifacts are written to:

`artifacts/test-runs/<run-id>/`

## Test (no emulator needed)

```bash
npm test
```

## Smoke run (requires Appium + emulator/device + app installed)

```bash
npm run run:smoke
```

## Matrix scenario run (seed + checkpoint + publish + mobile assert)

```bash
MATRIX_SCENARIO_ID=MC_RB_03 npm run run:matrix
```

On PowerShell:

```powershell
$env:MATRIX_SCENARIO_ID='MC_RB_03'
npm run run:matrix
```

## Matrix suite run (10 logic-focused cases, UI + backend assertions)

```bash
npm run run:matrix-suite
```

PowerShell examples:

```powershell
# default 10-case suite
npm run run:matrix-suite

# run specific scenarios only
$env:MATRIX_SUITE_SCENARIOS='MC_RB_03,MC_NUCLEAR_01,RT_NUCLEAR_01'
npm run run:matrix-suite

# run full frozen matrix (all currently defined scenarios)
$env:MATRIX_SUITE_FULL='true'
npm run run:matrix-suite
```

## Sync-conflict live scaffold (Appium + Selenium)

This run starts from base schema at `Q1`, advances in mobile, then publishes the scenario draft from web architect and verifies rollback/recovery.

```bash
npm run run:sync-conflict
```

Defaults:
- `SYNC_SCENARIO_ID=MC_RB_03`
- `BACKEND_BASE_URL=http://127.0.0.1:8000`
- mobile user `t1@g.com / 123123123`
- admin user `admin@example.com / password`

Selenium endpoint defaults:
- `SELENIUM_HOST=127.0.0.1`
- `SELENIUM_PORT=4444`
- `SELENIUM_PATH=/wd/hub`
- `SELENIUM_BROWSER=chrome`

Optional tuning:
- `SELENIUM_HEADLESS=true` to run browser headless
- `SYNC_PUBLISH_DELAY_MS=...` to delay publish after reaching pre-conflict node
- `SYNC_VERIFY_FRESH_ANSWER=false` to skip post-rollback fresh-answer check
- `SYNC_FALLBACK_ANSWER_VALUE=A` fallback answer used for fresh-answer verification

## Sync-conflict suite (Appium + Selenium, multiple scenarios)

```bash
npm run run:sync-suite
```

Default suite:
- full frozen matrix (`19` scenarios): multiple-choice + rating + open-ended

Suite controls:
- `SYNC_SUITE_SCENARIOS` comma/space separated scenario ids
- `SYNC_SUITE_RESEED_EACH=true` reseed each case
- `SYNC_SUITE_FAIL_FAST=true` stop at first failure
- `SYNC_SUITE_PRINT_JSON=false` hide raw JSON and keep readable summary only

PowerShell examples:

```powershell
# run full sync suite (default: all 19 matrix scenarios)
npm run run:sync-suite

# quick multiple-choice-only sync suite
$env:SYNC_SUITE_SCENARIOS='MC_ATOMIC_01,MC_ATOMIC_02,MC_RB_01,MC_ATOMIC_03,MC_RB_02,MC_RB_03,MC_RB_04,MC_NUCLEAR_01,MC_ATOMIC_04'
npm run run:sync-suite
```

Environment variables:
- `MOBILE_EMAIL`, `MOBILE_PASSWORD`
- `SMOKE_SURVEY_ID` (default: `1`)
- `SMOKE_EXPECTED_QUESTION_STABLE_KEY` (optional)
- `SMOKE_COMPLETE=true` to click complete
- `MATRIX_SCENARIO_ID` (required for `run:matrix`)
- `MATRIX_SKIP_SEED=true` to skip reseeding
- `MATRIX_USER_NAME` (optional display name)
- `MATRIX_ASSERT_STRATEGY_BANNER=true` to enforce conflict banner visibility check
- `MATRIX_SUITE_SCENARIOS` comma/space separated scenario ids (overrides default 10-case suite)
- `MATRIX_SUITE_FULL=true` to run all matrix scenarios currently defined
- `MATRIX_SUITE_RESEED_EACH=true` to reseed before every suite case (slower, stricter isolation)
- `MATRIX_SUITE_FAIL_FAST=true` to stop suite at first failure
- `BACKEND_BASE_URL` for backend API assertions (default: `http://127.0.0.1:8000`)
- `MATRIX_RATING_VALUE` rating answer used in progression check (default: `3`)
- `MATRIX_OPEN_ENDED_TEXT` open-ended answer used in progression check
- `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `ADMIN_NAME`
- `SELENIUM_PROTOCOL`, `SELENIUM_HOST`, `SELENIUM_PORT`, `SELENIUM_PATH`
- `SELENIUM_BROWSER`, `SELENIUM_HEADLESS`, `SELENIUM_NO_SANDBOX`, `SELENIUM_LOG_LEVEL`
- `SYNC_SCENARIO_ID`, `SYNC_PUBLISH_DELAY_MS`, `SYNC_VERIFY_FRESH_ANSWER`, `SYNC_FALLBACK_ANSWER_VALUE`
- `SYNC_FALLBACK_RATING_VALUE`, `SYNC_FALLBACK_TEXT`
- `SYNC_SUITE_SCENARIOS`, `SYNC_SUITE_RESEED_EACH`, `SYNC_SUITE_FAIL_FAST`
- `SYNC_SUITE_PRINT_JSON`
- `APPIUM_HOST`, `APPIUM_PORT`, `APPIUM_PATH`
- `APPIUM_DEVICE_NAME`, `APPIUM_APP_PACKAGE`, `APPIUM_APP_ACTIVITY`
- `APPIUM_NO_RESET`, `APPIUM_NEW_COMMAND_TIMEOUT`, `APPIUM_LOG_LEVEL`

Default behavior:
- `APPIUM_NO_RESET=false` (cleaner/repeatable smoke runs)
- `run:matrix-suite` uses the default 10-case set:
  - `MC_ATOMIC_01`, `MC_RB_01`, `MC_RB_03`, `MC_RB_04`, `MC_NUCLEAR_01`, `MC_ATOMIC_04`
  - `RT_ATOMIC_01`, `RT_NUCLEAR_01`
  - `OE_ATOMIC_01`, `OE_NUCLEAR_01`
- `run:sync-suite` uses full frozen matrix by default (19 scenarios). Override with `SYNC_SUITE_SCENARIOS` for smaller subsets.

## Notes

- This is intentionally minimal scaffolding for step-by-step expansion.
- Synchronized Selenium+Appium scaffold is now in place for single and suite runs.

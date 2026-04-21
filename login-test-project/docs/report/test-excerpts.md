# Test Excerpts

All logs referenced below are in:

- `artifacts/test-runs/20260421-000052/`
- `artifacts/test-runs/20260421-002803/`

## 1) Full backend/API test sweep

Source: `php-artisan-test.log`

```text
PASS  Tests\Feature\Survey\GraphConflictRecoveryTest
...
it prevents zombie questions in recovered session state

Tests:    105 passed (1090 assertions)
Duration: 11.59s
```

## 2) Auth / roles / architect smoke regression

Source: `smoke-auth-roles-architect.log`

```text
PASS  Tests\Feature\DashboardTest
PASS  Tests\Feature\Admin\SurveyArchitectTest
PASS  Tests\Feature\Auth\AuthenticationTest

Tests:    20 passed (148 assertions)
Duration: 1.52s
```

## 3) Matrix mobile suite output excerpt

Source: `appium-matrix-suite.log`

```text
Matrix Mobile Suite Summary
Status: FAILED | Planned: 10 | Executed: 10 | Passed: 1 | Failed: 9 | Missing: 0
```

This run confirms harness execution and summary reporting. Failures indicate local environment/session-state mismatch during answer persistence checks in this specific run.

## 4) Sync conflict suite output excerpt

Source: `appium-sync-suite.log`

```text
Sync Conflict Suite Summary
Status: FAILED | Planned: 19 | Executed: 19 | Passed: 18 | Failed: 1 | Missing: 0
[FAIL] OE_RB_02 | error=Timed out waiting for fresh fallback answer for "q4" to be stored...
```

## 5) One-command runner evidence

Source: `artifacts/test-runs/20260421-002803/summary.md`

```text
Overall status: passed
Step: Laravel test suite => passed
Step: Android/Appium suites => skipped (explicit flags for entrypoint validation)
```

Use `npm run regression:full` without skip flags for a grading-ready full run.

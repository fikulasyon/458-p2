# Regression Run Summary

- Run ID: `20260421-000052`
- Type: manual end-to-end sequence

## Results

| Step | Status | Notes | Log |
| --- | --- | --- | --- |
| `php artisan test` | passed | `105` tests passed, `1090` assertions | `php-artisan-test.log` |
| `./gradlew.bat testDebugUnitTest` | failed | `JAVA_HOME` not configured on this machine | `android-testDebugUnitTest.log` |
| `npm run run:matrix-suite` | failed | Suite executed; summary: planned `10`, passed `1`, failed `9` | `appium-matrix-suite.log` |
| `npm run run:sync-suite` | failed | Suite executed; summary: planned `19`, passed `18`, failed `1` (`OE_RB_02`) | `appium-sync-suite.log` |
| Smoke (`dashboard/auth/survey architect`) | passed | `20` tests passed, `148` assertions | `smoke-auth-roles-architect.log` |

## Quick excerpt pointers

- Backend sweep summary near end of `php-artisan-test.log`
- Matrix summary marker: `Matrix Mobile Suite Summary`
- Sync summary marker: `Sync Conflict Suite Summary`

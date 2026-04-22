# Adaptive Survey Ecosystem (LoL Theme)

This repository contains a unified adaptive survey system with:
- Laravel 12 backend + web architect (Inertia.js + React + TypeScript)
- Native Android client (Kotlin + Jetpack Compose)
- Conflict-safe schema version reconciliation for mid-session updates (GBCR/RCLR policy)

Theme used in survey scenarios: **Which LoL Champion Are You?**

## Submission-Oriented Map (Where Source and Tests Are)

### Main source code
- Backend (Laravel): `app/`, `routes/`, `config/`, `database/`
- Web architect UI (Inertia React/TS): `resources/js/`
- Android app: `mobile-android/app/src/main/java/`
- Android UI screens: `mobile-android/app/src/main/java/com/lolsurvey/mobile/ui/screen/`
- Appium automation code: `mobile-android/automation/appium/src/`

### Main test code
- Laravel feature/integration/unit tests: `tests/`
- Conflict matrix definitions used by tests/seeding: `tests/Support/ConflictPolicyMatrix.php`
- Android unit tests: `mobile-android/app/src/test/`
- Appium unit tests (helper-level): `mobile-android/automation/appium/test/`
- Appium matrix/sync executable automation scripts: `mobile-android/automation/appium/src/scripts/`

### Test run artifacts
- Generated regression artifacts: `artifacts/test-runs/<run-id>/`

## Project Structure (Quick)

- `app/Services/`
  - runtime + reconciliation services (`MobileSurveyRuntimeService`, `GraphConflictResolver`, `SessionRecoveryService`)
- `app/Http/Controllers/Api/`
  - mobile API endpoints (`login/me`, survey/session/state/answers/complete)
- `database/seeders/ConflictPolicyMatrixSeeder.php`
  - deterministic scenario seeding for conflict-policy matrix
- `tests/Feature/Api/`
  - API conflict integration tests (`/state`, `/answers`, `/complete`)
- `mobile-android/`
  - native Android app
- `mobile-android/automation/appium/`
  - Appium + Selenium synchronized automation scaffold/suites

## Prerequisites

- PHP 8.2+
- Composer
- Node.js + npm
- SQLite
- Java + Android Studio (for Android module)
- Android SDK / emulator (for mobile runs)
- Appium server (`127.0.0.1:4723`) for Appium runs
- Selenium server (`127.0.0.1:4444`) for sync-conflict web+mobile runs

## Setup

From repository root:

```powershell
composer install
npm install
copy .env.example .env
php artisan key:generate
php artisan migrate
```

## Running the System

### Laravel + web architect

```powershell
composer run dev
```

Or minimal backend only:

```powershell
php artisan serve --host=0.0.0.0 --port=8000
```

### Android app

Open `mobile-android/` in Android Studio and run on emulator/device.

(Default API base URL is configured in Android module; see `mobile-android/README.md`.)

## Recommended 4-Terminal Workflow (Used in This Project)

When running full mobile + sync automation locally, use 4 terminals:

### Terminal 1 (repo root): Laravel + web

```powershell
cd .
composer run dev
```

### Terminal 2 (Appium server)

```powershell
cd mobile-android\automation\appium
appium server --address 127.0.0.1 --port 4723 --base-path /
```

Notes:
- `--base-path /` matches this repo's default Appium client config (`APPIUM_PATH=/`).
- Do not leave `--base-path` empty.

### Terminal 3 (Selenium server)

```powershell
npx selenium-standalone@latest start
```

This starts Selenium on `127.0.0.1:4444` (used by sync-conflict automation).

### Terminal 4 (repo root): regression run

```powershell
cd .
npm run regression:full
```

## Running Tests

### 1) Laravel test suite

```powershell
php artisan test
```

### 2) Android unit tests

```powershell
cd mobile-android
.\gradlew.bat testDebugUnitTest
```

### 3) Appium automation tests

```powershell
cd mobile-android\automation\appium
npm install
npm test
```

### 4) Appium matrix suite (logic-focused mobile conflict cases)

```powershell
cd mobile-android\automation\appium
npm run run:matrix-suite
```

### 5) Sync suite (Selenium + Appium synchronized conflict runs)

Prereqs: Appium server + Selenium server + backend + emulator running.

```powershell
cd mobile-android\automation\appium
npm run run:sync-suite
```

### 6) One-command full regression (recommended for grading)

From repository root:

```powershell
npm run regression:full
```

This writes logs and summaries under:
- `artifacts/test-runs/<run-id>/summary.json`
- `artifacts/test-runs/<run-id>/summary.md`

## Additional Module READMEs

- Android app notes: `mobile-android/README.md`
- Appium automation details + env variables: `mobile-android/automation/appium/README.md`

## LLM vs Authors Contribution Statement

This project was developed collaboratively by the project author and LLM assistance.

### Primarily by project Authors
- Project direction, requirements decisions, and acceptance criteria
- Manual validation decisions and scenario-policy intent
- Iteration decisions for rollback/fallback behavior expectations
- Final report framing/content selection and submission packaging decisions

### Developed with LLM assistance (in this repo)
- Conflict-policy matrix expansion and scenario bootstrapping support
- GBCR/RCLR reconciliation implementation refinements in runtime services
- API conflict integration test depth and matrix coverage
- Appium helper scaffolding, matrix suite, and Selenium+Appium sync-conflict scaffolds
- UML/report technical documentation generation and formatting
- Regression orchestration script and test artifact automation flow

# Survey Ecosystem Report Pack

This folder is the report package for the adaptive survey project.

## Included artifacts

- `uml.md`
  - component view
  - conflict recovery sequence view
  - ER/data view
  - state diagram (session conflict lifecycle)
  - class diagram (domain + reconciliation services)
  - use case diagram (admin/mobile/monitor)
- `uml.html`
  - browser-rendered UML diagrams (Mermaid)
  - includes all diagrams including both activity diagrams and state/class/use case
- `policy-mapping.md`
  - GBCR/RCLR policy-to-implementation mapping
- `test-excerpts.md`
  - backend/API/mobile automation evidence snippets

## One-command regression entrypoint

Run from repo root:

```powershell
npm run regression:full
```

This command writes timestamped artifacts under:

`artifacts/test-runs/<run-id>/`

Each run generates:

- per-suite log files
- `summary.json`
- `summary.md`

## Environment prerequisites for full grading run

- Laravel backend reachable by Android client and automation scripts
- `JAVA_HOME` configured for Android unit tests
- Appium server running (`127.0.0.1:4723`)
- Selenium server running (`127.0.0.1:4444`) for sync suite
- Android emulator/device online with the app installed

## Latest local artifact runs

- `artifacts/test-runs/20260421-000052` (manual full regression attempt)
- `artifacts/test-runs/20260421-002803` (entrypoint validation run with mobile suites intentionally skipped)

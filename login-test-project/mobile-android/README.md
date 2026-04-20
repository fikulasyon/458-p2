# LoL Survey Mobile (Android)

Native Android client (Kotlin + Jetpack Compose) for the Laravel mobile API.

## Implemented flow

- Login (`/api/mobile/login`)
- Survey list (`/api/mobile/surveys`)
- Start/continue session (`/api/mobile/surveys/{id}/sessions/start`)
- Session runner (`/api/mobile/sessions/{id}/state`, `/answers`, `/complete`)
- Completion summary
- Conflict-aware messaging from `version_sync` (mismatch/conflict/recovery strategy)

## Project structure

- `app/src/main/java/com/lolsurvey/mobile/api`: Retrofit service + DTOs
- `app/src/main/java/com/lolsurvey/mobile/data`: repository + token storage
- `app/src/main/java/com/lolsurvey/mobile/ui`: app ViewModel
- `app/src/main/java/com/lolsurvey/mobile/ui/screen`: Login/List/Runner/Completion screens

## Backend URL

Default base URL is set in `app/build.gradle.kts`:

- `BuildConfig.API_BASE_URL = "http://10.0.2.2:8000/"`

For Android Emulator, `10.0.2.2` maps to your host machine.
If your Laravel server is elsewhere, update this value.

## Run

1. Start Laravel backend in repo root:
   - `php artisan serve --host=0.0.0.0 --port=8000`
2. Open `mobile-android/` in Android Studio.
3. Sync Gradle and run on emulator/device.

## Notes

- This is a minimum clean implementation focused on functional correctness.
- The app keeps mobile token in `SharedPreferences` and reuses it on restart.
- On any session call, conflict/recovery information returned by backend is shown in runner/completion UI.
- Login defaults are prefilled in UI as:
  - email: `t1@g.com`
  - password: `123123123`

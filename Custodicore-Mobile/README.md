# Custodicore Mobile

Expo / React Native **visitor** mobile app for CustodiCore (BJMP facility visitation).

This app is for **visitors** (sign in, registration, documents, visits, QR, notifications). It is **not** the staff web dashboards.

## Relationship to Custodicore Web

- Staff dashboards and the Laravel JSON API live in the separate **Custodicore-Web** repository.
- This mobile app calls that API through `src/services/api.js` when not using local mocks.
- API paths in `src/services/api.js` match Custodicore-Web `routes/api.php` (for example `/auth/login`, `/visits/upcoming`, `/notifications`).
- Set `EXPO_PUBLIC_API_URL` in `.env` to point at your running Laravel server (see [API Configuration](#api-configuration)).
- Some flows still use mock data controlled by flags in `src/mock/devFlags.js` (`USE_MOCK_NOTIFICATIONS`, `USE_MOCK_QR`, `USE_MOCK_VISIT_HISTORY`, `USE_MOCK_TIMELINE`). Those flags are currently `true` in the repo — set a flag to `false` when that backend endpoint is ready.

---

## Requirements

| Tool | Notes (from this project) |
|------|---------------------------|
| **Node.js + npm** | Required. This app uses **Expo SDK ~54** (`"expo": "~54.0.35"` in `package.json`). There is no `engines` field in `package.json`; install a current **Node.js LTS** from [nodejs.org](https://nodejs.org). |
| **Git** | To clone the repository |
| **Expo tooling** | Started via `npm start` / `npx expo start` (Expo CLI comes with the project deps) |
| **Android testing** | **Expo Go** on a physical Android phone, and/or an **Android emulator** (Android Studio) if you use `npm run android` |
| **Custodicore-Web (optional for UI-only)** | Needed when you turn off mocks and call the real API (`php artisan serve` on the web project) |
| **EAS account (optional)** | Needed only for cloud Android builds (`eas.json` / `app.json` EAS `projectId`) |

This project’s `package.json` scripts also include `ios` and `web`, but the school workflow below focuses on **Windows + Android**.

---

## Installation

### 1. Clone the repository

```powershell
git clone <your-team-repo-url> Custodicore-Mobile
cd Custodicore-Mobile
```

Use your team’s real Git remote URL.

### 2. Install dependencies

```powershell
npm install
```

### 3. Create `.env` from `.env.example`

```powershell
copy .env.example .env
```

- `.env.example` is the shared template (safe to commit).
- `.env` is local-only and is listed in `.gitignore` — **never commit it**.

Edit `.env` for your machine (see below). **Restart Expo after changing `.env`** — Expo reads these values at startup (noted in `.env.example`).

### 4. Environment variables (from `.env.example`)

| Variable | Required? | Purpose |
|----------|-----------|---------|
| `EXPO_PUBLIC_API_URL` | Yes, when calling the real API | Laravel API base URL. Must include `/api`. No trailing slash after `/api`. |
| `EXPO_PUBLIC_GOOGLE_EXPO_CLIENT_ID` | Optional | Google Sign-In (leave blank to disable) |
| `EXPO_PUBLIC_GOOGLE_IOS_CLIENT_ID` | Optional | Google Sign-In |
| `EXPO_PUBLIC_GOOGLE_ANDROID_CLIENT_ID` | Optional | Google Sign-In |
| `EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID` | Optional | Google Sign-In |

Example template value from `.env.example`:

```env
EXPO_PUBLIC_API_URL=http://YOUR-LAN-IP:8000/api
```

Google client IDs are optional until Google auth is configured (`src/config/authConfig.js` / `src/services/googleAuthService.js`).

If `EXPO_PUBLIC_API_URL` is missing, `src/services/api.js` falls back to `https://api.custodicore.placeholder` (not a real local server).

---

## Running the Mobile App

### Start Expo

From the project root:

```powershell
npm start
```

This runs `expo start` (see `package.json` scripts).

Equivalent:

```powershell
npx expo start
```

Other scripts from `package.json`:

```powershell
npm run android   # expo start --android
npm run ios       # expo start --ios
npm run web       # expo start --web
npm run lint      # expo lint
```

### Open on an Android emulator

1. Install/start **Android Studio** and create/start an Android Virtual Device (AVD) if you do not already have one.
2. With the emulator running, either:
   - start Expo with `npm start`, then press `a` in the Expo terminal, or
   - run `npm run android`.
3. Ensure `EXPO_PUBLIC_API_URL` points at a host/port your emulator can use to reach Custodicore-Web (Laravel must be running). This repo does **not** hardcode an emulator-specific host; configure `.env` for your setup.

### Test on a physical Android phone

1. Install **Expo Go** from the Play Store on your phone.
2. Phone and PC must be on the **same Wi‑Fi / LAN**.
3. In `.env`, set `EXPO_PUBLIC_API_URL` to your **PC’s LAN IP** and Laravel port, including `/api` (as stated in `.env.example` — **do not use `localhost` on a physical device**).
4. Start Expo with `npm start`.
5. Scan the QR code with Expo Go (or follow the URL Expo prints).

### Network / LAN notes

- Laravel in Custodicore-Web is typically served with `php artisan serve` on port **8000**.
- `.env.example` uses port **8000** in the sample API URL.
- Physical devices cannot reach `localhost` / `127.0.0.1` on your PC — use the PC’s LAN IP (documented in `.env.example`).
- Windows Firewall may block phone → PC connections; allow Node/Expo and PHP if the device cannot reach the API.

---

## API Configuration

### How the app connects

- HTTP client: Axios in `src/services/api.js`.
- Base URL: `process.env.EXPO_PUBLIC_API_URL` (trimmed of a trailing `/`).
- Auth: Bearer token from AsyncStorage (`@custodicore/auth_token`) attached as `Authorization: Bearer <token>`.
- Backend: Custodicore-Web Sanctum API under the `/api` prefix.

### Variable for the API base URL

```env
EXPO_PUBLIC_API_URL=http://YOUR-LAN-IP:8000/api
```

Rules from `.env.example`:

- Include `/api`
- No trailing slash after `/api`
- Use LAN IP for physical devices (not `localhost`)

### What URL to use

| Situation | What this project documents |
|-----------|-----------------------------|
| **Physical Android phone** | `http://<YOUR-PC-LAN-IP>:8000/api` (required style in `.env.example`) |
| **Local development (PC + phone/emulator)** | Same pattern: host that can reach Custodicore-Web on port `8000`, path ending in `/api` |
| **Android emulator** | Not given a special hardcoded host in this repo — set `EXPO_PUBLIC_API_URL` to a URL the emulator can use to reach your PC’s Laravel server |

Always keep Custodicore-Web running (`php artisan serve` and MySQL) when testing real API calls.

### Mock flags vs real API

Even with a correct `EXPO_PUBLIC_API_URL`, these flags in `src/mock/devFlags.js` currently force mock data for some features:

- `USE_MOCK_NOTIFICATIONS = true`
- `USE_MOCK_QR = true`
- `USE_MOCK_VISIT_HISTORY = true`
- `USE_MOCK_TIMELINE = true`

Set a flag to `false` only when that backend endpoint is ready (as commented in that file).

---

## Project Structure

| Path | Purpose |
|------|---------|
| `App.js` | App entry: providers, `NavigationContainer`, `AppNavigator` |
| `app.json` | Expo config (name **CustodiCore**, slug `custodicore`, Android package `com.yuttq.custodicore`, splash/icons, plugins, EAS `projectId`) |
| `package.json` | Dependencies and scripts (`start`, `android`, `ios`, `web`, `lint`, icon/splash generators) |
| `eas.json` | EAS Build profiles (`development`, `preview`, `production`) |
| `.env.example` | Env template for local setup |
| `babel.config.js` / `metro.config.js` / `tsconfig.json` | Tooling config |
| `assets/` | App icons, splash, logos (`icon.png`, `splash.png`, `adaptive-icon.png`, …) |
| `scripts/` | PowerShell helpers (`generate-app-icons.ps1`, `generate-splash-assets.ps1`, …) |
| `src/navigation/` | Navigation (`AppNavigator.js`, tab icons) |
| `src/screens/` | Screens (Login, Register, Dashboard, QR, visits, profile, …) |
| `src/components/` | Shared UI components |
| `src/services/` | API client (`api.js`), Google auth helper |
| `src/repositories/` | Data access layer (used with mock flags) |
| `src/mock/` | Mock data + `devFlags.js` |
| `src/hooks/` | e.g. `useAuth.js` |
| `src/context/` | React context (visits, notification badge) |
| `src/config/` | e.g. `authConfig.js` |
| `src/constants/` / `src/designSystem/` | Colors, layout, theme |
| `src/utils/` | Helpers |

---

## Android APK Testing

Configuration comes from **`eas.json`** and **`app.json`** (`extra.eas.projectId`, `owner`).

### Profiles in `eas.json`

| Profile | Settings in this repo |
|---------|------------------------|
| `development` | `"developmentClient": true`, `"distribution": "internal"` |
| `preview` | `"distribution": "internal"` (suitable for teammate test installs) |
| `production` | `"autoIncrement": true` |
| `submit.production` | Present as `{}` (no Play Store steps documented here) |

EAS CLI requirement from `eas.json`: `"cli": { "version": ">= 18.13.0", ... }`.

### Create a preview / internal Android build

Requires an Expo/EAS account linked to this project (`app.json` already includes the EAS `projectId`).

```powershell
npx eas-cli login
npx eas build -p android --profile preview
```

These commands match the previous project README and the `preview` profile in `eas.json`.

- **`preview`**: internal distribution — teammates can install the resulting Android build for testing.
- **`development`**: development client build (`developmentClient: true`) — for custom native-dev-client workflows, not plain Expo Go.
- **`production`**: production profile with `autoIncrement: true`.

This README does **not** document Google Play Store publishing. `eas.json` has an empty `submit.production` object only; there is no Play Store release guide in this repo.

---

## Common Problems

### `npm` not recognized

- Install Node.js LTS and reopen the terminal.
- Confirm with `node -v` and `npm -v`.

### Dependency installation problems

```powershell
npm install
```

If installs fail, delete `node_modules` and retry:

```powershell
Remove-Item -Recurse -Force node_modules
npm install
```

### Expo not starting

```powershell
npm start
```

or:

```powershell
npx expo start
```

Ensure you are in the `Custodicore-Mobile` folder (where `package.json` and `app.json` live).

### Android emulator not detected

- Start an AVD from Android Studio first.
- Then `npm run android` or press `a` in the Expo terminal.
- Confirm Android SDK / emulator tools are installed.

### Physical phone cannot connect

- Same Wi‑Fi as the PC.
- Scan the Expo QR from `npm start`.
- For API calls, set `EXPO_PUBLIC_API_URL` to the PC **LAN IP** (not `localhost`) as in `.env.example`.
- Check Windows Firewall.

### API connection failures / incorrect API URL

- Custodicore-Web must be running (`php artisan serve`, MySQL up).
- `.env` must have `EXPO_PUBLIC_API_URL=http://<reachable-host>:8000/api`.
- Restart Expo after editing `.env`.
- Avoid the placeholder fallback `https://api.custodicore.placeholder`.
- Remember mock flags in `src/mock/devFlags.js` may skip HTTP for some screens even when the URL is correct.

### Environment variable problems

- Copy from `.env.example` if `.env` is missing.
- Use `EXPO_PUBLIC_` prefix (required for Expo public env vars).
- Restart Expo after changes.

### Metro / cache problems

```powershell
npx expo start -c
```

(or stop Expo and start again with cache cleared using Expo’s clear-cache option).

---

## Team Development Workflow

1. **Pull latest changes**
   ```powershell
   git pull
   ```
2. **Install dependencies when `package.json` / lockfile changed**
   ```powershell
   npm install
   ```
3. **Check `.env`** against `.env.example` (API URL / optional Google IDs).
4. **Start Expo**
   ```powershell
   npm start
   ```
5. **Test** on emulator and/or physical phone.
6. If testing the real API, also run **Custodicore-Web** and confirm mock flags.
7. **Test before committing**.
8. **Commit and push** source only — never `.env`, never `node_modules/`.

---

## Environment and Security

| File | Commit? | Role |
|------|---------|------|
| `.env.example` | Yes | Template for teammates |
| `.env` | **No** (gitignored) | Your local URLs and optional Google client IDs |

Rules:

- Never commit secrets, private keys, or production credentials.
- Do not hardcode private API keys in source (use `EXPO_PUBLIC_*` only for values meant for the client, as this project already does).
- Teammates must create their own `.env` and set at least `EXPO_PUBLIC_API_URL` for real API testing.
- Google Sign-In stays disabled until `EXPO_PUBLIC_GOOGLE_*` values are filled (`isGoogleSignInConfigured()` in `src/config/authConfig.js`).

---

## Quick Start

For developers who already have Node.js/npm installed:

```powershell
git clone <your-team-repo-url> Custodicore-Mobile
cd Custodicore-Mobile
npm install
copy .env.example .env
```

Edit `.env` — for a physical phone pointing at local Laravel:

```env
EXPO_PUBLIC_API_URL=http://YOUR-LAN-IP:8000/api
```

Then:

```powershell
npm start
```

Open the app in **Expo Go** (phone) or press `a` for Android emulator.

Optional preview APK for teammates (EAS account required):

```powershell
npx eas-cli login
npx eas build -p android --profile preview
```

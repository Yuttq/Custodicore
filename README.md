# CustodiCore

A BJMP (Bureau of Jail Management and Penology) facility visitation system: three staff web dashboards (Warden/Admin, Records Officer, Front Desk) plus a visitor-facing mobile app, all backed by one shared MySQL database.

Keep this file in sync with reality as the project changes — it's the first thing anyone (jess, eunice, daniel, jan, or a new contributor) should read before touching the code.

## Repo layout

```
Custodicore/
├── backend/     Laravel 12 app — the three web dashboards, the mobile JSON API, and the database
├── app/         Expo Router screens for the mobile app
├── src/         Mobile app source (services, hooks, components, design system, mocks)
└── assets/      Mobile app images/fonts
```

`backend/` and the mobile app (`app/` + `src/`) are two separate projects that talk to each other over HTTP — you need both running to use the mobile app, but the web dashboards work with just `backend/`.

## Prerequisites

- **PHP 8.2+** and **Composer**
- **MySQL** — XAMPP is the easiest way to get this locally (Apache isn't needed, just the MySQL service)
- **Node.js** (for both `backend/`'s frontend assets and the mobile app) and **npm**
- **Expo Go** app on your phone, or an Android/iOS emulator, if you want to test the mobile app

## Backend setup (one-time)

Run these from the repo root unless noted otherwise.

**1. Start MySQL.** Open XAMPP Control Panel → **Start** next to MySQL. `backend/.env` is already pointed at XAMPP's defaults (`127.0.0.1:3306`, database `custodicore`, user `root`, no password) — only edit it if your MySQL setup differs.

**2. Install PHP dependencies, including Sanctum (mobile login):**
```bash
cd backend
composer install
```

**3. Create the database:**
```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS custodicore"
```
(Or create a database named `custodicore` in phpMyAdmin if `mysql` isn't on your PATH.)

**4. Run migrations and seed test data:**
```bash
php artisan migrate:fresh --seed
```
This builds every table and seeds the test accounts listed below. `--fresh` drops existing tables first — only run it on a database you're OK wiping. Later, `php artisan migrate --seed` (no `--fresh`) reseeds without wiping.

**5. Link storage** (so uploaded visitor ID photos are servable):
```bash
php artisan storage:link
```

**6. Build the frontend assets.** The dashboards' CSS/JS are bundled with Vite — either run the dev server in its own terminal (rebuilds on save, useful while developing):
```bash
npm run dev
```
or build once for a static bundle:
```bash
npm run build
```

**7. Start the Laravel server:**
```bash
php artisan serve --host=0.0.0.0 --port=8000
```
`--host=0.0.0.0` matters if you also want to reach this from a phone on the same Wi-Fi — plain `php artisan serve` only binds to localhost. Leave this terminal open.

**8. Open it.** On the same computer, visit **http://localhost:8000/** — you'll land on the sign-in page.

## Web dashboards

All three dashboards require login now and are locked to their own role — a Front Desk account can't open `/admin`, etc. `/` redirects you to the right one automatically based on who's signed in.

| Role | Test login | Lands on |
|---|---|---|
| System Administrator/Warden | `a.domingo@bjmp.gov.ph` | `/admin` |
| Record Officer | `r.salcedo@bjmp.gov.ph` or `g.manalo@bjmp.gov.ph` | `/dashboard` |
| Front Desk Officer | `n.fernandez@bjmp.gov.ph` | `/front-desk` |

Password for every seeded account is **`password`**. `j.reyes@bjmp.gov.ph` is seeded as *inactive* — useful for testing that the "account is inactive" message actually shows up.

Visitor accounts (mobile app only, not the website): `maria.santos@example.com`, `carlo.ramos@example.com`, `liza.aquino@example.com`, `dante.cabrera@example.com`, `fe.lopez@example.com` — same password.

## Mobile app setup

**1. Install dependencies** (from the repo root, not `backend/`):
```bash
npm install
```

**2. Point it at your backend.** Find your computer's LAN IP (`ipconfig` on Windows, `ipconfig getifaddr en0` on Mac) — not `localhost`, since the phone needs to reach your computer over Wi-Fi. Create a `.env` file in the repo root:
```
EXPO_PUBLIC_API_URL=http://YOUR-LAN-IP:8000/api
```
Keep the trailing `/api`. Phone and computer must be on the same Wi-Fi network, and your firewall needs to allow the backend's port 8000 (Windows will usually prompt for this the first time `php artisan serve` runs).

**3. Start Expo:**
```bash
npx expo start
```
Restart this after any `.env` change — environment variables are only read at startup.

**4. Test it.** Log in with a seeded visitor account (see table above). `maria.santos@example.com` has a completed visit on record, so History and Notifications should show real data.

## Design system

The web dashboards use a small shared set of Tailwind tokens (`backend/tailwind.config.js`) so every page looks like one product:

- **`primary-navy`** (`#0F3D7A`) and **`primary-teal`** (`#0DA58A`) are the only two brand colors in the app.
- Status is told apart by shade and label, not by a rainbow of hues — `success`/`warning`/`danger`/`info` still exist as class names (`cc-chip-success`, etc.) so existing views don't need to change, but they now resolve to shades of navy/teal/gray instead of green/amber/red/blue.
- Shared components live in `backend/resources/css/app.css` (`cc-card`, `cc-btn-primary`, `cc-input`, `cc-chip-*`, …) — reuse these instead of one-off Tailwind classes when building a new page.
- The mobile app has its own copy of these tokens (`src/designSystem/tokens/colors.js`) which is **not** currently in sync with the web's trimmed palette — that's a known follow-up, not yet done.

## Known gaps (don't assume these are covered)

- **Records Officer module has no pages yet.** Its controllers and routes exist and are login-protected, but the Blade views they render (`dashboard`, `pdl.*`, `visitor.*`, `eligibility.index`, `custody-history`, `visitation-tracking`) haven't been built — logging in as a Record Officer hits a "view not found" error until someone builds them.
- `/auth/register`'s exact field names (mobile registration) are a best guess — `RegisterScreen.js` wasn't fully audited against the backend contract.
- `/auth/google` (mobile Google Sign-In) intentionally returns HTTP 501 — no OAuth credentials are set up yet.
- `DashboardScreen.js` and `MyAssignedVisitsScreen.js` on mobile still read local mock files directly rather than the real API (only Notifications, QR, Visit History, and the Visit Timeline were rewired).

## Where to look for more detail

Day-to-day setup notes, troubleshooting, and anything not worth keeping in this file long-term live in the **"CustodiCore — Database, Auth & Mobile API"** doc in the shared Claude project — check there if something in this README seems out of date.

# CustodiCore

A BJMP (Bureau of Jail Management and Penology) facility visitation system: three staff web dashboards (Warden/Admin, Records Officer, Front Desk) plus a visitor-facing mobile app, all backed by one shared MySQL database.

Keep this file in sync with reality as the project changes — it's the first thing anyone (jess, eunice, daniel, jan, or a new contributor) should read before touching the code.

## Repo layout

```
Custodicore/
├── Custodicore-Web/      Laravel 12 app — the three web dashboards, the mobile JSON API, and the database
└── Custodicore-Mobile/   Expo / React Native visitor app (src/: screens, services, repositories, hooks, design system)
```

`Custodicore-Web/` and `Custodicore-Mobile/` are two separate projects that talk to each other over HTTP — you need both running to use the mobile app, but the web dashboards work with just `Custodicore-Web/`. Each folder has its own README with more detail.

## Prerequisites

- **PHP 8.2+** and **Composer**
- **MySQL** — XAMPP is the easiest way to get this locally (Apache isn't needed, just the MySQL service)
- **Node.js** (for both `Custodicore-Web/`'s frontend assets and the mobile app) and **npm**
- **Expo Go** app on your phone, or an Android/iOS emulator, if you want to test the mobile app

## Backend setup (one-time)

Run these from `Custodicore-Web/` unless noted otherwise.

**1. Start MySQL.** Open XAMPP Control Panel → **Start** next to MySQL. Create `Custodicore-Web/.env` from the template — `.env.example` already points at XAMPP's defaults (`127.0.0.1:3306`, database `custodicore`, user `root`, no password); only edit it if your MySQL setup differs:
```bash
cd Custodicore-Web
copy .env.example .env
php artisan key:generate
```

**2. Install PHP and Node dependencies, including Sanctum (mobile login):**
```bash
composer install
npm install
```

**3. Create the database:**
```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS custodicore"
```
(Or create a database named `custodicore` in phpMyAdmin if `mysql` isn't on your PATH.)

**4. Run migrations and seed test data:**
```bash
php artisan migrate
php artisan db:seed
```
This builds every table and seeds the test accounts listed below. Both commands are safe to re-run on an existing database. Check what's applied with `php artisan migrate:status`. Do **not** use `migrate:fresh` on a database whose data you want to keep — it drops every table.

Visit schedules are created by the seeder, dated relative to the day you seed (there is no staff page for creating schedules yet). If every seeded schedule date has passed, re-run `php artisan db:seed` to add the next week's slots.

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

**1. Install dependencies** (in `Custodicore-Mobile/`, not `Custodicore-Web/`):
```bash
cd Custodicore-Mobile
npm install
```

**2. Point it at your backend.** Find your computer's LAN IP (`ipconfig` on Windows, `ipconfig getifaddr en0` on Mac) — not `localhost`, since the phone needs to reach your computer over Wi-Fi. Copy `Custodicore-Mobile/.env.example` to `Custodicore-Mobile/.env` and set:
```
EXPO_PUBLIC_API_URL=http://YOUR-LAN-IP:8000/api
```
Keep the trailing `/api`. Phone and computer must be on the same Wi-Fi network, and your firewall needs to allow the backend's port 8000 (Windows will usually prompt for this the first time `php artisan serve` runs).

**3. Start Expo:**
```bash
npx expo start
```
Restart this after any `.env` change — environment variables are only read at startup.

**4. Test it.** Log in with a seeded visitor account (see table above). Every visitor screen now uses the real API (all `USE_MOCK_*` flags in `src/mock/devFlags.js` are `false`). `maria.santos@example.com` has a completed visit on record, so History and Timeline show real data.

## Local end-to-end test

With MySQL, Laravel (`php artisan serve --host=0.0.0.0 --port=8000`), Vite (`npm run dev` in `Custodicore-Web/`) and Expo running:

1. **Record Officer** (`r.salcedo@bjmp.gov.ph`, web) → Visitors → open a visitor → choose a verified relationship and a schedule for **today** → **Assign Visit**. The visit is created as `pending_confirmation` and the visitor gets a notification.
2. **Visitor** (mobile) → Home → **View Visit** → **Confirm Attendance** → status becomes `confirmed`.
3. Visitor → **QR Pass** tab shows the gate QR (only for a confirmed visit, only on the visit day, expires after 180 minutes).
4. **Front Desk** (`n.fernandez@bjmp.gov.ph`, web) → Check-In / Check-Out → **Start QR Scanner** → scan the phone → tick the three checks → **Next** → tick *ID has been physically surrendered* → **Confirm Check-In**. The visit stays `confirmed` while the visitor is inside.
5. Front Desk → **Check-Out** → start the scanner → scan the same QR → **Return ID** → tick *ID returned* → complete check-out. The visit becomes `completed`.
6. Visitor → Profile → **Visitation History** shows the completed visit; **View Visit Timeline** shows assigned → confirmed → QR → check-in → completed; **Notifications** shows the assignment and confirmation.

Automated backend tests: `php vendor/bin/phpunit --testsuite Feature` in `Custodicore-Web/` (runs on in-memory SQLite — it never touches the MySQL database).

## Design system

The web dashboards use a small shared set of Tailwind tokens (`Custodicore-Web/tailwind.config.js`) so every page looks like one product:

- **`primary-navy`** (`#0F3D7A`) and **`primary-teal`** (`#0DA58A`) are the only two brand colors in the app.
- Status is told apart by shade and label, not by a rainbow of hues — `success`/`warning`/`danger`/`info` still exist as class names (`cc-chip-success`, etc.) so existing views don't need to change, but they now resolve to shades of navy/teal/gray instead of green/amber/red/blue.
- Shared components live in `Custodicore-Web/resources/css/app.css` (`cc-card`, `cc-btn-primary`, `cc-input`, `cc-chip-*`, …) — reuse these instead of one-off Tailwind classes when building a new page.
- The mobile app has its own copy of these tokens (`src/designSystem/tokens/colors.js`) which is **not** currently in sync with the web's trimmed palette — that's a known follow-up, not yet done.

## Known gaps (don't assume these are covered)

- **A visitor's overall verification status never changes.** Staff can verify/reject individual IDs and relationships, but nothing sets `visitor_profiles.verification_status`, so a newly registered visitor shows "Under Review" indefinitely.
- **Supporting documents** (marriage certificate, etc.) can only be uploaded after staff link the visitor to a PDL; staff pages don't display uploaded files yet, and staff verify/reject actions don't notify the visitor.
- **No staff page creates visit schedules** — they come from the seeder (see Backend setup step 4).
- `/auth/google` (mobile Google Sign-In) intentionally returns HTTP 501 — no OAuth credentials are set up yet. Forgot Password is not implemented.
- The mobile app has only been tested in the Expo web build and against the API — test on a real Android device (especially camera/gallery ID upload) before relying on it.

## Where to look for more detail

Day-to-day setup notes, troubleshooting, and anything not worth keeping in this file long-term live in the **"CustodiCore — Database, Auth & Mobile API"** doc in the shared Claude project — check there if something in this README seems out of date.

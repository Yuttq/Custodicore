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

Setting everything up on a MacBook (for example for a demo)? Follow [SETUP-MAC.md](SETUP-MAC.md) instead — it covers the files that are not in git and must be copied from a working machine.

## Prerequisites

- **PHP 8.2+** and **Composer**
- **MySQL** — XAMPP is the easiest way to get this locally (Apache isn't needed, just the MySQL service)
- **Node.js** (for both `Custodicore-Web/`'s frontend assets and the mobile app) and **npm**
- **Android Studio** with an Android emulator (or a real Android phone with USB debugging) for the mobile app. The app is a development build because it uses native Google Sign-In, so **Expo Go does not work** — see Mobile app setup step 3.

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

The seeder adds visit schedules dated relative to the day you seed. After that, Record Officers create and adjust dated visiting sessions on **Manage Visitor Slots** (`/visitor-slots`).

**5. Uploaded files are private — no `storage:link` needed.** Government IDs, photos and documents are stored under `storage/app/private` and shown to staff only through signed-in, role-checked pages. Do **not** run `php artisan storage:link`: nothing needs it.

If you are upgrading a copy where IDs were uploaded before they moved to private storage (old files are in `storage/app/public/visitor-ids`), move them with the steps in [Custodicore-Web/README.md → Moving old government ID files](Custodicore-Web/README.md#moving-old-government-id-files-to-private-storage).

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

Password for every seeded account is **`password`** (or `SEED_PASSWORD` from `.env` if set — required on a production server). `j.reyes@bjmp.gov.ph` is seeded as *inactive* — useful for testing that the "account is inactive" message actually shows up.

Visitor accounts (mobile app only, not the website): `maria.santos@example.com`, `carlo.ramos@example.com`, `liza.aquino@example.com`, `dante.cabrera@example.com`, `fe.lopez@example.com` — same password.

## Mobile app setup

**1. Install dependencies** (in `Custodicore-Mobile/`, not `Custodicore-Web/`):
```bash
cd Custodicore-Mobile
npm install
```

**2. Point it at your backend.** Copy `Custodicore-Mobile/.env.example` to `Custodicore-Mobile/.env` and set `EXPO_PUBLIC_API_URL` (keep the trailing `/api`; never `localhost` on a phone or emulator):

| Running the app on | Use |
|---|---|
| Android emulator on the same computer as Laravel | `EXPO_PUBLIC_API_URL=http://10.0.2.2:8000/api` |
| A real phone | `EXPO_PUBLIC_API_URL=http://YOUR-LAN-IP:8000/api` — find the IP with `ipconfig` (Windows) or `ipconfig getifaddr en0` (Mac). Phone and computer must be on the same Wi-Fi, Laravel must run with `--host=0.0.0.0`, and the firewall must allow port 8000. |
| Chrome (`npx expo start --web`) on the same computer as Laravel | Nothing extra: web swaps `10.0.2.2` for `127.0.0.1`. Set `EXPO_PUBLIC_WEB_API_URL` only if Laravel is elsewhere. |

Also set `EXPO_PUBLIC_GOOGLE_WEB_CLIENT_ID` for Google Sign-In (ask the team for the value; email/password works without it).

**3. Build and install the app (first time, ~10–20 min):** with the emulator running,
```bash
npx expo run:android
```
This creates the `android/` folder (git-ignored) and installs the app. Google Sign-In only works when `android/app/debug.keystore` is the team's shared key, so copy it in from a working machine after the first build and run the command again.

**4. Start Expo (every other time):**
```bash
npx expo start
```
then press **a** to open the app on the emulator. Restart this after any `.env` change — environment variables are only read at startup. If the app crashes on launch with *"App react context shouldn't be created before"*, close the app completely on the emulator and press **a** again.

**5. Test it.** Log in with a seeded visitor account (see table above). Every visitor screen now uses the real API (all `USE_MOCK_*` flags in `src/mock/devFlags.js` are `false`). `maria.santos@example.com` has a completed visit on record, so History and Timeline show real data.

## Local end-to-end test

With MySQL, Laravel (`php artisan serve --host=0.0.0.0 --port=8000`), Vite (`npm run dev` in `Custodicore-Web/`) and Expo running:

1. **Record Officer** (`r.salcedo@bjmp.gov.ph`, web) → Visitors → open a visitor → choose a verified relationship and a schedule for **today** → **Assign Visit**. The visit is created as `pending_confirmation` and the visitor gets a notification.
2. **Visitor** (mobile) → Home → **View Visit** → **Confirm Attendance** → status becomes `confirmed`.
3. Visitor → **QR Pass** tab shows the gate QR (only for a confirmed visit, only on the visit day, expires after 180 minutes).
4. **Front Desk** (`n.fernandez@bjmp.gov.ph`, web) → Check-In / Check-Out → **Start QR Scanner** → scan the phone → tick the three checks → **Next** → tick *ID has been physically surrendered* → **Confirm Check-In**. The visit stays `confirmed` while the visitor is inside.
5. Front Desk → **Check-Out** → start the scanner → scan the same QR → **Return ID** → tick *ID returned* → complete check-out. The visit becomes `completed`.
6. Visitor → Profile → **Visitation History** shows the completed visit; **View Visit Timeline** shows assigned → confirmed → QR → check-in → completed; **Notifications** shows the assignment and confirmation.

Automated backend tests: `php vendor/bin/phpunit --testsuite Feature` in `Custodicore-Web/` (runs on in-memory SQLite — it never touches the MySQL database).

## Forgot Password (visitors)

1. App → **Forgot Password?** → `POST /api/auth/password/forgot {email}`. The answer is always the same generic "If an account exists…" message, so it can't be used to find accounts. Limited to 6/min per IP and 3 per 10 min per email address (429 after that).
2. Only an **active Visitor** account with no pending staff invitation gets an email (Google-linked visitors included). Staff, inactive/suspended and unknown addresses get the same response and no email.
3. The email links to `APP_URL/password/reset/{token}?email=…` (route `password.reset`), a web page where the visitor picks a new password: at least 8 characters with a letter and a number, max 72, no leading or trailing spaces, confirmed, not their email address.
4. On success the password is changed, the link stops working, every app session of that account is signed out, and an audit-log entry is written. The visitor then logs in to the app with the new password.

Links use Laravel's password broker (`config/auth.php` → `passwords.accounts`): stored hashed in `password_reset_tokens`, valid for 60 minutes, single use, and a new request replaces the old link. A reset does **not** mark the email as verified — an unverified visitor still has to use the verification link. Code: `App\Services\Auth\PasswordResetService`, `App\Http\Controllers\Auth\PasswordResetController`.

**To test locally:** set `APP_URL` in `Custodicore-Web/.env` to an address your phone can open (e.g. `http://YOUR-PC-LAN-IP:8000`), and `MAIL_MAILER=log` to read the email (with the link) in `storage/logs/laravel.log` instead of sending it — or configure the `MAIL_*` SMTP settings to deliver it. Run `php artisan config:clear` after changing `.env`. Tests: `php artisan test --filter=PasswordResetTest`.

> **Warning:** `MAIL_MAILER=log` writes every email — including working password-reset links and their tokens — into `storage/logs/laravel.log`. Use it for local testing only, never in production. Production must use a properly configured mail transport (the `MAIL_*` SMTP settings or another real mailer).

## Design system

The web dashboards use a small shared set of Tailwind tokens (`Custodicore-Web/tailwind.config.js`) so every page looks like one product:

- **`primary-navy`** (`#0F3D7A`) and **`primary-teal`** (`#0DA58A`) are the only two brand colors in the app.
- Status is told apart by shade and label, not by a rainbow of hues — `success`/`warning`/`danger`/`info` still exist as class names (`cc-chip-success`, etc.) so existing views don't need to change, but they now resolve to shades of navy/teal/gray instead of green/amber/red/blue.
- Shared components live in `Custodicore-Web/resources/css/app.css` (`cc-card`, `cc-btn-primary`, `cc-input`, `cc-chip-*`, …) — reuse these instead of one-off Tailwind classes when building a new page.
- The mobile app has its own copy of these tokens (`src/designSystem/tokens/colors.js`) which is **not** currently in sync with the web's trimmed palette — that's a known follow-up, not yet done.

## Known gaps (don't assume these are covered)

- **Google Sign-In needs setup per machine.** The backend accepts it only when `GOOGLE_CLIENT_IDS` is set in `Custodicore-Web/.env`, and the app only when it is built with the shared `debug.keystore` (Mobile app setup step 3).
- **Forgot Password is visitor-only.** Staff still ask their Warden administrator to reset a password (as the web login page says).
- **No iOS build.** The mobile app has been built and tested on Android only; there is no `ios/` setup or iOS Google client ID.
- **Everything runs locally.** Nothing is hosted online, so a phone can only use the app while it can reach the computer running Laravel (same Wi-Fi, or the emulator on that computer).

## Where to look for more detail

Day-to-day setup notes, troubleshooting, and anything not worth keeping in this file long-term live in the **"CustodiCore — Database, Auth & Mobile API"** doc in the shared Claude project — check there if something in this README seems out of date.

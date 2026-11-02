# Custodicore Web

Laravel staff web application and backend API for **CustodiCore**, a BJMP facility custody and visitor management system.

This repository (`affinitysalestraining/custodicore-backend`) contains:

- Staff web dashboards (Blade + Vite/Tailwind):
  - **System Administrator / Warden** (`/admin`)
  - **Record Officer** (`/dashboard` and related modules)
  - **Front Desk Officer** (`/front-desk`)
- Shared session login for staff (`/login`)
- Visitor **mobile JSON API** (`routes/api.php`) protected with Laravel Sanctum
- MySQL/MariaDB migrations and a `DatabaseSeeder` for local sample data

The companion mobile app lives in a separate repository (**Custodicore-Mobile**). That app talks to this backend’s `/api/*` routes when mock mode is turned off.

---

## Requirements

Install these on a Windows PC before setup:

| Tool | Required version / notes |
|------|--------------------------|
| **PHP** | `^8.2` (project requires PHP 8.2+; verified with PHP 8.2.x) |
| **Composer** | For PHP dependencies (`composer.json`) |
| **Node.js + npm** | For Vite frontend assets (`package.json`) |
| **XAMPP (MySQL/MariaDB)** | MySQL/MariaDB on `127.0.0.1:3306` (Apache is **not** required for local `php artisan serve`) |
| **Git** | To clone the repository |
| **Chrome** (or any browser) | To open the local web UI |

Optional: this repo includes `composer.phar`, so if Composer is not on your PATH you can run `php composer.phar …` instead of `composer …`.

---

## Installation

### 1. Clone the repository

```powershell
git clone <your-team-repo-url> Custodicore-Web
cd Custodicore-Web
```

Use the real Git remote your team uses (GitHub/GitLab/etc.).

### 2. Install PHP dependencies

```powershell
composer install
```

If `composer` is not recognized:

```powershell
php composer.phar install
```

### 3. Install Node dependencies

```powershell
npm install
```

### 4. Create your `.env` file

```powershell
copy .env.example .env
php artisan key:generate
```

- `.env.example` is the shared template (safe to commit).
- `.env` is your **local** config (never commit it — it is listed in `.gitignore`).

Edit `.env` and set database values for your machine (see [Database Setup](#database-setup) and [Environment Variables](#environment-variables)).

### 5. Uploaded files (no `storage:link` needed)

Government IDs, visitor/staff/PDL photos and relationship documents are all stored on the private `local` disk (`storage/app/private`) and served only through signed-in, role-checked routes. Nothing uses the `public` disk, so do **not** run `php artisan storage:link`.

#### Moving old government ID files to private storage

Government IDs uploaded before this change were saved in `storage/app/public/visitor-ids`. Until they are moved, the staff ID viewers still read them from there (a temporary fallback in `App\Models\VisitorId::fileDisk()`). The database does not change: each `file_path` (`visitor-ids/<name>`) is the same on both disks.

1. **Back up first:** copy `storage/app` and export the database.
2. **Dry run** — changes nothing, shows counts only:
   ```powershell
   php artisan visitor-ids:move-to-private
   ```
3. **Copy** — copies each file, checks its SHA-256 checksum, keeps the originals:
   ```powershell
   php artisan visitor-ids:move-to-private --copy
   ```
   The result should show no "checksum mismatch", "copy failed" or "missing" rows. Open a few IDs from Account Review and Front Desk Visitor Lookup to confirm they still display.
4. **Delete the originals** — a separate step; deletes a public file only when its private copy exists and matches:
   ```powershell
   php artisan visitor-ids:move-to-private --delete-originals
   ```
   Run the dry run again: every file should be "already private".
5. **Remove an old storage link, if one exists.** Removing `storage:link` from these instructions does not delete a link created earlier. If `public/storage` exists, delete that link (it is only a shortcut — `storage/app/public` itself is untouched). Until it is gone, old ID files that are still in the public folder can be downloaded by URL.
6. Files the command reports as "not referenced by any visitor_ids row" are left in place; review and remove them by hand.

After step 4 succeeds on every installation, the fallback (`VisitorId::LEGACY_DISK`) can be removed.

#### Azure App Service: keep uploads outside the deployed code

By default uploads live in `Custodicore-Web/storage/app/private`, inside the code folder. On Azure App Service a deployment can replace or lock that folder, so uploads would be lost or could not be saved. Laravel moves its whole `storage` folder (private files, the legacy public disk, logs and compiled views) when the `LARAVEL_STORAGE_PATH` environment variable is set. No code change is needed, and the private files are still served only through the same signed-in, role-checked routes.

1. **Create the folder** on App Service's persistent storage, outside `/home/site/wwwroot` (for example in the startup script, so it exists after every restart):
   ```bash
   mkdir -p /home/custodicore/storage/app/private /home/custodicore/storage/framework/cache /home/custodicore/storage/framework/sessions /home/custodicore/storage/framework/views /home/custodicore/storage/logs
   ```
2. **Add the App Setting** (Configuration → Application settings): `LARAVEL_STORAGE_PATH` = `/home/custodicore/storage`.
   - Set it as an App Setting, not only in `.env`: Laravel binds the storage path when the app starts, before `.env` is read.
   - Never set it to an empty value: an empty value makes the storage path relative.
3. **Check it** from the SSH console: `php artisan tinker --execute="echo storage_path('app/private');"` should print `/home/custodicore/storage/app/private`.
4. **Point the web server at `public/`** (`/home/site/wwwroot/Custodicore-Web/public`, via the startup script's nginx config). App Service's default web root is `/home/site/wwwroot`, which would make the code folder and `.env` downloadable.
5. **Deploy as files, not "run from package"** (`WEBSITE_RUN_FROM_PACKAGE`), which makes the code folder read-only.
6. **Do not run `php artisan storage:link`**, and do not upload `storage/app/public`.
7. **Back up the files with the database:** App Service backups include `/home`; restore files and database from the same point in time.

`/home` is shared by every instance of the app and kept across restarts and deployments, but it is deleted with the App Service and limited by the plan's storage quota.

**Moving existing files to Azure** (only when their database rows are moved too; never copy files for a fresh or demo database):
- first finish [Moving old government ID files to private storage](#moving-old-government-id-files-to-private-storage), so nothing comes from the public disk;
- copy only `storage/app/private` into `/home/custodicore/storage/app/private`, keeping the folder structure (database paths are relative to it);
- use an encrypted transfer (Kudu/SSH), check file counts and checksums, then remove any temporary copies;
- keep the originals until the Azure copies are checked.

#### Azure App Service: logging

**App Settings:**

| Setting | Value |
|---|---|
| `LOG_CHANNEL` | `stack` |
| `LOG_STACK` | `daily` |
| `LOG_LEVEL` | `warning` (never `debug` in production) |
| `LOG_DAILY_DAYS` | `14` |

- **Where:** with `LARAVEL_STORAGE_PATH` set (above), logs are written to `/home/custodicore/storage/logs/laravel-YYYY-MM-DD.log`, so they survive restarts and deployments. Without it they would sit inside the deployed code folder and could be lost or unwritable after a deployment.
- **Retention:** one file per day; files older than `LOG_DAILY_DAYS` are deleted automatically when a new day's file is started. They share the `/home` storage quota with uploads.
- **Reading them:** SSH or Kudu console (`tail -n 50 /home/custodicore/storage/logs/laravel-$(date +%F).log`). These files do not appear in the portal's Log stream or in Azure Monitor; no Azure logging service is set up yet.
- **Privacy:** failed database queries are logged with `?` placeholders instead of their values, and database stack traces are written without call arguments (`App\Logging\RedactQueryBindings`). Connection name, SQL statement, error code and exception class are kept for debugging. Treat the log files as confidential anyway: do not copy them off the server or attach them to tickets without reading them first.

#### Azure App Service: HTTPS and security headers

Every Laravel response (pages, API, files) carries `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `Permissions-Policy` (camera allowed for this site only, for the front desk QR scanner) and a CSP that only blocks framing, `<base>`, off-site form posts and plugins; scripts and styles are not restricted (`App\Http\Middleware\SecurityHeaders`).

1. **Turn on HTTPS Only** (Configuration → General settings, or TLS/SSL settings) and set `TRUSTED_PROXIES` (see `.env.example`). Without `TRUSTED_PROXIES` the app cannot tell a request was HTTPS, and HSTS is never sent.
2. **App Settings:**

   | Setting | Value |
   |---|---|
   | `SESSION_SECURE_COOKIE` | `true` |
   | `HSTS_MAX_AGE` | `0` at first (default: off) |

3. **Check over https:** sign in (with the two-step code), open a visitor's ID or relationship document (PDF and image), scan a QR pass at the front desk, and call the mobile API.
4. **Start HSTS:** set `HSTS_MAX_AGE` = `86400` (1 day). Check the response has `Strict-Transport-Security: max-age=86400` and repeat step 3.
5. **Raise it** to `31536000` (1 year) once that has worked for a while. Browsers then refuse plain http for this site for a year, so only do this when https is settled.

Never add `includeSubDomains` or `preload`: both are hard to undo and can force https on unrelated services under the same domain. To back out, set `HSTS_MAX_AGE` to `0`; browsers that already saw the header keep it until their max-age runs out.

### 6. Email: Azure Communication Services Email (production)

The app sends five emails, each straight away during the request (no queue worker): visitor email verification, password reset, staff account setup links, staff two-step sign-in codes (fallback to the authenticator app), and "account approved". Visit updates are in-app notifications only.

`MAIL_MAILER=log` is for **local development only**. With it nothing is delivered: with `LOG_LEVEL=debug` whole emails, including links and codes, go to `storage/logs/laravel.log`; with a higher level they are dropped. In production with `log` or `array` the app writes a critical log line (at most once an hour) but keeps running.

If a send fails, nothing is bypassed: the visitor or officer can ask again (new verification link, new reset link, "Email me a code", or the Warden resends the setup link, which is also shown to the Warden once to hand over). Failures are logged with the error type and a plain-English reason only, never the raw mail-server message, address, link or code.

**Azure setup** (Communication Services sends through Laravel's built-in `smtp` mailer; no extra package):
1. Create an **Email Communication Services** resource and add a **custom domain**. Add the DNS records Azure shows: the verification TXT, SPF, the two DKIM CNAMEs, and a DMARC record. The Azure-managed `…azurecomm.net` domain is for testing only (about 5 emails/minute and 10/hour per subscription).
2. Create a **Communication Services** resource and connect the verified domain. Use its **MailFrom** address (for example `DoNotReply@your-domain`) as `MAIL_FROM_ADDRESS`; any other sender is rejected.
3. Create a **Microsoft Entra app registration** with a client secret, give it the role the [SMTP authentication guide](https://learn.microsoft.com/en-us/azure/communication-services/quickstarts/email/send-email-smtp/smtp-authentication) lists on the Communication Services resource, and create an **SMTP Username** that links to the app.
4. **Sending limits:** a custom domain starts at about 30 emails/minute and 100/hour per subscription. Ask Azure support for more (Service and subscription limits → Azure Communication Services Email) before many visitors register at once.
5. **Secret rotation:** Entra client secrets expire (24 months at most). Note the expiry date, add the new secret before the old one expires, update `MAIL_PASSWORD`, run the test below, then delete the old secret. An expired secret stops every email; staff can still sign in with their authenticator app.

**App Settings** on the App Service (keep `MAIL_USERNAME` / `MAIL_PASSWORD` in App Settings or Key Vault references, never in git):

| Setting | Value |
|---|---|
| `MAIL_MAILER` | `smtp` |
| `MAIL_HOST` | `smtp.azurecomm.net` |
| `MAIL_PORT` | `587` |
| `MAIL_SCHEME` | leave unset (STARTTLS on 587, not `smtps`) |
| `MAIL_REQUIRE_TLS` | `true` |
| `MAIL_USERNAME` | the SMTP Username from step 3 |
| `MAIL_PASSWORD` | the Entra app's client secret |
| `MAIL_FROM_ADDRESS` | the verified MailFrom address |
| `MAIL_FROM_NAME` | `CustodiCore` |
| `LOG_LEVEL` | `warning` (never `debug` in production) |

**Controlled test** after every deployment that changes mail settings, and after each secret rotation:
1. From the App Service SSH console: `php artisan config:clear` (or `php artisan optimize` again if config is cached).
2. `php artisan cc:mail-test <an inbox the team owns>`. Never use a visitor's or staff member's real address. The command refuses to run with `log`/`array` and prints the mail server's full error on screen only (it is not logged).
3. Check the message arrived (and not in Spam), then check the app log (`logs/laravel.log` under `LARAVEL_STORAGE_PATH`) has no mail warnings (with `LOG_STACK=daily` the file is `logs/laravel-YYYY-MM-DD.log`).
4. With test accounts only, go through one email verification, one password reset, one staff setup link and one "Email me a code".

---

## Database Setup

### Configuration (from `.env.example`)

| Variable | Default in `.env.example` | Meaning |
|----------|---------------------------|---------|
| `DB_CONNECTION` | `mysql` | Use MySQL/MariaDB |
| `DB_HOST` | `127.0.0.1` | Local database host |
| `DB_PORT` | `3306` | Default MySQL/MariaDB port |
| `DB_DATABASE` | `custodicore` | Database name this app expects |
| `DB_USERNAME` | `root` | Typical XAMPP default user |
| `DB_PASSWORD` | *(empty)* | Typical XAMPP default (blank password) |

Also required for local login/sessions:

| Variable | Default | Why it matters |
|----------|---------|----------------|
| `SESSION_DRIVER` | `database` | Sessions are stored in the DB — migrations must run before web login works |
| `CACHE_STORE` | `database` | Cache tables come from migrations |
| `QUEUE_CONNECTION` | `database` | Queue tables come from migrations |

### Create the database (XAMPP)

1. Start **MySQL** in XAMPP Control Panel (Apache can stay stopped).
2. Create an empty database named `custodicore`, for example:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS custodicore CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

If your MySQL `root` password is not empty, set `DB_PASSWORD` in `.env` to match.

### Run migrations (safe)

```powershell
php artisan migrate
```

This creates tables from `database/migrations/` (roles, accounts, PDL/visitor/visit tables, sessions, cache, jobs, Sanctum tokens, etc.).

### Seed sample data (recommended for local testing)

```powershell
php artisan db:seed
```

This runs `database/seeders/DatabaseSeeder.php`.

- Seeds the reference data first (`ReferenceDataSeeder`: roles, modules, permissions, base visiting rules), then the demo people, PDLs and visits.
- Uses `firstOrCreate` for most rows (safe to re-run on a local DB without wiping).
- Default password for every seeded demo account: **`password`**, only when `APP_ENV` is `local` or `testing` and `SEED_PASSWORD` is blank. Any other environment must set `SEED_PASSWORD`.
- With `APP_ENV=production` only the reference data is seeded, unless `SEED_DEMO_DATA=true` (demo servers only).

Example **staff** web logins after seeding:

| Role | Email |
|------|--------|
| System Administrator/Warden | `a.domingo@bjmp.gov.ph` |
| Record Officer | `r.salcedo@bjmp.gov.ph` |
| Record Officer | `g.manalo@bjmp.gov.ph` |
| Front Desk Officer | `n.fernandez@bjmp.gov.ph` |

Visitor accounts are also seeded for the **mobile app** only (they cannot sign in to the staff web UI).

### Production setup: the first Warden

A real installation never gets the demo accounts or a shared password. Set it up in this order (from the server's SSH console):

1. `php artisan migrate --force`
2. `php artisan db:seed --class=ReferenceDataSeeder --force`. Roles, modules, permissions and the base visiting rules. It creates no accounts and is safe to run again.
3. Set up email first and check it with `php artisan cc:mail-test <an inbox the team owns>` (see [Email](#6-email-azure-communication-services-email-production)).
4. Create the first Warden:
   ```bash
   php artisan cc:create-first-warden --email=warden@your-facility.example --first-name=Juan --last-name="Dela Cruz" --contact=09171234567
   ```
   - The account is created **inactive** with a random password nobody knows, and a one-time setup link (valid 72 hours, stored only as a hash) is **emailed** to the Warden. The command prints only the account number, the masked email and whether the email went out, never the link or a password. The same checks as Admin → Users apply (name, mobile number, email not already used).
   - It refuses to run if any Warden account already exists (active, inactive or awaiting setup), or if step 2 was skipped.
   - If email is not working, it refuses to start (mail in `log` mode) or keeps the account and reports the failure. Only when you add `--show-link` does it print the link on screen (never to the log) after a failed delivery. Then hand it to the Warden yourself over a private channel.
5. The Warden opens the link, chooses their own username and password, signs in, and must set up two-step verification.
6. The Warden adds the other staff from **Admin → Users**, including a **second Warden** as a backup: staff passwords cannot be reset by email, so a single locked-out Warden can only be helped from the server console.

`SEED_DEMO_DATA=true` (with a strong `SEED_PASSWORD`) is only for demo servers such as the Droplet script in `deploy/`. Its demo staff use `@bjmp.gov.ph` addresses, so never seed demo data where real email is connected to real users.

### Destructive commands — do not use casually

**Do not run** these unless you intentionally want to wipe a **local** database:

- `php artisan migrate:fresh`
- `php artisan migrate:fresh --seed`
- `php artisan db:wipe`
- Dropping tables/databases manually on a shared team database

Prefer:

```powershell
php artisan migrate
php artisan db:seed
```

---

## Running the Web Application

You need **two terminals** while developing (Laravel + Vite). Keep **XAMPP MySQL** running.

### Terminal 1 — Laravel

```powershell
cd C:\path\to\Custodicore-Web
php artisan serve
```

Expected output includes a server URL similar to:

```text
Server running on [http://127.0.0.1:8000]
```

### Terminal 2 — Vite (CSS/JS for Admin & Front Desk)

```powershell
cd C:\path\to\Custodicore-Web
npm run dev
```

Expected output includes Vite ready (typically on port `5173`) and the Laravel Vite plugin.

Without Vite in development, pages that use `@vite(...)` (Admin/Front Desk/login styling) can fail with:

`Vite manifest not found ... public/build/manifest.json`

### Open in the browser

| Page | URL |
|------|-----|
| Home (redirects to login or role dashboard) | http://127.0.0.1:8000/ |
| Staff login | http://127.0.0.1:8000/login |
| Health check | http://127.0.0.1:8000/up |

Do **not** use the Vite URL (`http://localhost:5173`) as the app URL — that is only the asset server.

### Production-style asset build (optional)

If you are not using `npm run dev`:

```powershell
npm run build
```

This writes compiled assets to `public/build/`.

---

## API

API routes are defined in **`routes/api.php`**.

Laravel registers them under the **`/api`** prefix (confirmed via `php artisan route:list --path=api`).

### Relationship to the mobile app

From comments in `routes/api.php`:

- Paths and HTTP verbs match `src/services/api.js` in the **Custodicore-Mobile** repo.
- Auth uses **Sanctum bearer tokens** (`Authorization: Bearer <token>`).
- Tokens are issued by `POST /api/auth/login` and `POST /api/auth/register`.
- Staff web login and visitor mobile login both use the same `accounts` table (`App\Models\Account`).
- Visitors are mobile-only; staff dashboards refuse Visitor accounts at web login.
- Staff are web-only; `POST /api/auth/login` refuses non-Visitor accounts (no mobile token for staff).

### Google Sign-In (backend phase 1: verification only)

- `POST /api/auth/google` takes `{ "idToken": "<Google ID token>" }` (`id_token` also accepted). Any email/name sent alongside it is ignored.
- The token is verified server-side by `App\Services\Auth\GoogleIdTokenVerifier`: RS256 signature against Google's published keys, issuer, audience, expiry, required claims (`sub`, `email`, `email_verified`, `exp`, `iat`), and `email_verified = true`. Tokens are never logged.
- Phase 1 does **not** create, find or link accounts and does **not** issue a Sanctum token. Responses: `401` invalid token, `422` missing token, `503` not configured / Google unreachable, `501` valid token (sign-in not enabled yet). Throttled to 10 requests/minute.
- Configure with `GOOGLE_CLIENT_IDS` in `.env`: comma-separated OAuth client IDs accepted as the token's `aud`. Leave it empty to disable Google Sign-In. No client secret is needed.
- `accounts.google_id` (nullable, unique) will hold the verified Google `sub`. It is not mass-assignable.

### Endpoints that exist in this project

**Auth (mostly public; logout requires Sanctum):**

| Method | Path |
|--------|------|
| `POST` | `/api/auth/login` |
| `POST` | `/api/auth/google` |
| `POST` | `/api/auth/register` |
| `POST` | `/api/auth/logout` |

**Authenticated (`auth:sanctum`):**

| Method | Path |
|--------|------|
| `GET` | `/api/me` |
| `PATCH` | `/api/me` |
| `GET` | `/api/documents` |
| `POST` | `/api/documents` |
| `POST` | `/api/relationships/{relationship}/supporting-document` |
| `GET` | `/api/visits` |
| `GET` | `/api/visits/upcoming` |
| `GET` | `/api/visits/history` |
| `POST` | `/api/schedules/{visitRequest}/confirm` |
| `POST` | `/api/schedules/{visitRequest}/decline` |
| `GET` | `/api/schedules/{visitRequest}/qr` |
| `GET` | `/api/schedules/{visitRequest}/timeline` |
| `GET` | `/api/notifications` |
| `GET` | `/api/notifications/unread-count` |
| `PATCH` | `/api/notifications/{notification}/read` |

Staff web UI routes live in **`routes/web.php`** (not listed fully here). Controllers are under `app/Http/Controllers/` (`Admin`, `FrontDesk`, `Auth`, `Api`, and Record Officer controllers).

---

## Environment Variables

### `.env.example`

Committed template for the team. Copy it to create your local `.env`.

### `.env`

Your machine-specific file. **Never commit `.env`.** Never paste DB passwords, `APP_KEY`, or API tokens into chat, screenshots, or PRs.

### Variables used by this project’s `.env.example`

| Variable | Purpose |
|----------|---------|
| `APP_NAME` | Application name (`CustodiCore`) |
| `APP_ENV` | Environment (`local` for development) |
| `APP_KEY` | Encryption key — generate with `php artisan key:generate` |
| `APP_DEBUG` | Detailed errors when `true` |
| `APP_TIMEZONE` | `Asia/Manila` |
| `APP_URL` | App URL (`http://localhost` in example; local serve often uses `http://127.0.0.1:8000`) |
| `APP_LOCALE` / `APP_FALLBACK_LOCALE` | Locale settings |
| `LOG_CHANNEL` / `LOG_STACK` / `LOG_LEVEL` / `LOG_DAILY_DAYS` | Logging (production: `daily`, `warning`, `14`; see [Azure App Service: logging](#azure-app-service-logging)) |
| `DB_*` | MySQL/MariaDB connection |
| `SESSION_DRIVER` / `SESSION_LIFETIME` | Session storage (database) |
| `CACHE_STORE` | Cache storage (database) |
| `QUEUE_CONNECTION` | Queue connection (database) |
| `MAIL_MAILER` | Mail driver (`log` for local development only; `smtp` in production — see [Azure Communication Services Email](#6-email-azure-communication-services-email-production)) |
| `MAIL_REQUIRE_TLS` | `true` = refuse to send mail without an encrypted connection (use in production) |

Fill in `DB_*` for your XAMPP MySQL. Leave secrets out of git.

---

## Common Problems

### PHP not recognized

```text
php : The term 'php' is not recognized...
```

- Install PHP 8.2+ or add XAMPP’s PHP folder (e.g. `C:\xampp\php`) to your Windows PATH.
- Confirm with: `php -v`

### Composer not recognized

```text
composer : The term 'composer' is not recognized...
```

- Install Composer, **or** use the bundled phar:

```powershell
php composer.phar install
```

### npm / Node not recognized

- Install Node.js LTS from https://nodejs.org
- Confirm with: `node -v` and `npm -v`

### MySQL/MariaDB not running

- Open XAMPP Control Panel → start **MySQL**.
- Apache is not required for `php artisan serve`.

### Port 3306 already in use

- Another MySQL service may be running.
- Stop the other service, or change MySQL’s port and update `DB_PORT` in `.env` to match.

### Laravel database connection errors

Typical causes:

- MySQL not started
- Database `custodicore` missing
- Wrong `DB_USERNAME` / `DB_PASSWORD`
- Migrations not run (`SESSION_DRIVER=database` needs session tables)

Fix checklist:

1. Start MySQL  
2. Create database `custodicore`  
3. Match `.env` to your MySQL credentials  
4. Run `php artisan migrate`

### Vite not starting / Vite manifest not found

- Run `npm install` then `npm run dev` in a **second** terminal while using `php artisan serve`.
- Or run `npm run build` once if you are not using the Vite dev server.

### Missing `APP_KEY`

```powershell
php artisan key:generate
```

### Permission / storage problems

Uploaded files are private (`storage/app/private`); `storage:link` is not needed. If government IDs uploaded before the move to private storage don't show, see [Moving old government ID files](#moving-old-government-id-files-to-private-storage).

Ensure `storage/` and `bootstrap/cache/` are writable by PHP (on Windows this is usually fine if you own the project folder).

### Staff login works but CSS looks broken

Vite is not running and `public/build` is missing — start `npm run dev` or run `npm run build`.

---

## Project Structure

Folders and files you will work with most often:

| Path | Purpose |
|------|---------|
| `app/Http/Controllers/` | Web + API controllers (`Admin`, `FrontDesk`, `Auth`, `Api`, Record Officer) |
| `app/Http/Middleware/` | e.g. `EnsureRoleAccess` (`role:` middleware) |
| `app/Models/` | Eloquent models (`Account`, `Role`, PDL/visitor/visit models, etc.) |
| `routes/web.php` | Staff web routes + login/logout |
| `routes/api.php` | Mobile Sanctum API routes |
| `resources/views/` | Blade templates (`admin/`, `frontdesk/`, Record Officer views, `auth/login`) |
| `resources/css/`, `resources/js/` | Vite inputs (see `vite.config.js`) |
| `public/` | Web root (`index.php`, built assets, linked storage) |
| `database/migrations/` | Schema |
| `database/seeders/DatabaseSeeder.php` | Local sample data |
| `config/` | Laravel config (`auth.php` uses `accounts` provider + Sanctum) |
| `bootstrap/app.php` | App bootstrap, route registration, middleware aliases |
| `.env.example` | Env template |
| `composer.json` | PHP deps (Laravel 12, Sanctum 4, …) |
| `package.json` | Node deps (Vite 5, Tailwind, Chart.js, …) |
| `artisan` | Laravel CLI entry point |

---

## Development Workflow

1. **Pull latest changes**
   ```powershell
   git pull
   ```
2. **Install dependencies if lockfiles changed**
   ```powershell
   composer install
   npm install
   ```
3. **Check `.env`** still matches your local MySQL (never overwrite teammates’ secrets into git).
4. **Run new migrations when necessary**
   ```powershell
   php artisan migrate
   ```
   Use `php artisan db:seed` only when you need/refresh local sample data.
5. **Start MySQL**, then **Laravel** + **Vite** (two terminals).
6. **Test** the pages/API you changed (login as the correct role).
7. **Commit and push** only source files — not `.env`, `vendor/`, or `node_modules/`.

---

## Safety / Team Rules

- **Never commit `.env`** (or real passwords, API keys, or `APP_KEY` from a shared machine).
- **Never expose database credentials** in chats, tickets, or screenshots.
- **Never run destructive DB commands** (`migrate:fresh`, `db:wipe`, dropping DBs) on shared or production databases.
- **Do not modify another team’s module** unless coordinated (Admin / Record Officer / Front Desk / Mobile API).
- **Do not commit `vendor/` or `node_modules/`** — both are ignored in `.gitignore`.
- Prefer `php artisan migrate` + `php artisan db:seed` over `migrate:fresh --seed` for routine local setup.

---

## Troubleshooting

Useful diagnostic commands (run from the project root):

```powershell
php -v
composer -V
node -v
npm -v
php artisan --version
php artisan about
php artisan migrate:status
php artisan route:list --path=api
php artisan route:list --path=login
```

Test MySQL (XAMPP default empty root password):

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "SHOW DATABASES LIKE 'custodicore';"
```

Clear caches if config looks stale after `.env` edits:

```powershell
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

Check Laravel logs:

```powershell
Get-Content storage\logs\laravel.log -Tail 50
```

---

## Quick Start

For developers who already have PHP 8.2+, Composer, Node/npm, and XAMPP MySQL installed:

```powershell
git clone <your-team-repo-url> Custodicore-Web
cd Custodicore-Web
composer install
npm install
copy .env.example .env
php artisan key:generate
```

Create DB `custodicore` in MySQL, confirm `.env` `DB_*` values, then:

```powershell
php artisan migrate
php artisan db:seed
```

**Terminal 1:**

```powershell
php artisan serve
```

**Terminal 2:**

```powershell
npm run dev
```

Open: **http://127.0.0.1:8000/login**

Seeded Warden login (local only): `a.domingo@bjmp.gov.ph` / `password`

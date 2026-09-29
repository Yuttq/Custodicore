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

### 5. Link public storage (needed for visitor ID uploads via API)

```powershell
php artisan storage:link
```

This creates `public/storage` → `storage/app/public`. The API stores uploaded visitor IDs under the `public` disk (`visitor-ids/`).

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

- Uses `firstOrCreate` for most rows (safe to re-run on a local DB without wiping).
- Default password for every seeded account: **`password`** (documented in the seeder).

Example **staff** web logins after seeding:

| Role | Email |
|------|--------|
| System Administrator/Warden | `a.domingo@bjmp.gov.ph` |
| Record Officer | `r.salcedo@bjmp.gov.ph` |
| Record Officer | `g.manalo@bjmp.gov.ph` |
| Front Desk Officer | `n.fernandez@bjmp.gov.ph` |

Visitor accounts are also seeded for the **mobile app** only (they cannot sign in to the staff web UI).

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
| `POST` | `/api/documents` |
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
| `LOG_CHANNEL` / `LOG_STACK` / `LOG_LEVEL` | Logging |
| `DB_*` | MySQL/MariaDB connection |
| `SESSION_DRIVER` / `SESSION_LIFETIME` | Session storage (database) |
| `CACHE_STORE` | Cache storage (database) |
| `QUEUE_CONNECTION` | Queue connection (database) |
| `MAIL_MAILER` | Mail driver (`log` for local) |

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

```powershell
php artisan storage:link
```

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
php artisan storage:link
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

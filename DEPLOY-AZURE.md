# Custodicore on Azure — deployment status & runbook

A record of how Custodicore is deployed on Azure, what is done, and what is
left. Update the checkboxes as you go.

> **Never put passwords, app passwords, keys or `.env` contents in this file.**
> The repository is public. Secrets live only in Azure App Service → App settings.

## Setup at a glance

| Part | Value |
|---|---|
| Subscription | Azure for Students |
| Resource group | `rg-custodicore-demo` |
| Region | Malaysia West |
| Web app | Azure App Service (Linux, PHP 8.3, Nginx) |
| Database | Azure Database for MySQL Flexible Server `custodicore-mysql-demo` (MySQL 8.4, Burstable B1ms), database `custodicore`, private access via VNet |
| Deploys | GitHub Actions → App Service (OIDC) |
| Startup script | `Custodicore-Web/deploy/azure-startup.sh` (creates `/home/custodicore/storage`, installs the Nginx config) |
| Settings | App Service → **Settings → Environment variables → App settings** (no secrets in the server `.env`) |
| Email | Gmail SMTP with a Gmail **app password** (demo). Long term: Azure Communication Services, see `Custodicore-Web/README.md` §6 |

## Progress

### Phase 1 — Azure infrastructure ✅
Subscription, resource group, region, resources.

### Phase 2 — Deployment authentication and CI/CD ✅
GitHub Actions, Azure OIDC, automatic App Service deploys.

### Phase 3 — Private networking ✅
VNet, subnets, private DNS, MySQL private access, App Service VNet integration.

### Phase 4 — Laravel on App Service ✅
Laravel deployed, Nginx configured, `APP_KEY` set, login page returns HTTP 200.

### Phase 5 — Production database ✅ (fresh install, no import)
Decision (2026-11-03): **fresh install instead of importing the local database.**
The local database is test/demo data, and its uploaded files exist only on the
laptop. If specific real records are needed later, copy only those — do not
import the whole local database over the live one.

- [x] MySQL connection over TLS works (`config/database.php` uses `PDO::MYSQL_ATTR_SSL_CA`, PHP 8.3 compatible)
- [x] `php artisan migrate --force` — all migrations ran
- [x] `php artisan db:seed --class=ReferenceDataSeeder --force` — roles, permissions, base visiting rules (no demo accounts)
- [x] First Warden created with `php artisan cc:create-first-warden` (setup link sent by email)

### Phase 6 — Production configuration and security ⏳
- [x] Email: Gmail SMTP set in App settings; `php artisan cc:mail-test <email>` succeeded
- [ ] **`APP_URL`** = `https://<app-name>.azurewebsites.net` (https, no trailing `/`). Until this is set, email links point to `http://localhost`.
- [ ] First Warden uses the setup link, sets a password, signs in (and two-step verification)
- [ ] Fix the first Warden's mobile number (it was created with the example `09171234567`)
- [ ] Create a **second Warden** (Admin → Users) — staff cannot reset their own passwords
- [ ] Storage: `LARAVEL_STORAGE_PATH=/home/custodicore/storage` so uploads survive restarts (anything outside `/home` is wiped)
- [ ] Check sessions / cache settings
- [ ] Production settings: `APP_ENV=production`, `APP_DEBUG=false`, `SEED_DEMO_DATA` **not** set
- [ ] Security check: HTTPS only, security headers, trusted proxies (see `Custodicore-Web/README.md`)

### Phase 7 — Final testing and go-live ⏳
- [ ] Staff sign-in, visitor registration (email verification link opens the Azure site), visit request → approval → QR → check-in
- [ ] Upload a visitor ID, restart the app, confirm the file is still there
- [ ] Mobile app pointed at the Azure URL

## Runbook: SSH console

Open: Azure Portal → App Service → **Development Tools → SSH → Go**.

- The session starts in `/home`. **Always run `cd /home/site/wwwroot` first**, or `php artisan` says `Could not open input file: artisan`.
- Run **one command at a time**. Pasting several lines can add junk like `^[[200~` and break the first command.
- After changing any App setting: click **Apply**, wait for the restart (~1 min), open a **new** SSH session, then run `php artisan config:clear`.

Useful commands:

```bash
cd /home/site/wwwroot
php artisan config:clear              # after changing App settings
php artisan migrate:status            # quick database connection check
php artisan migrate --force           # after deploying new migrations
php artisan cc:mail-test you@example.com
php artisan cc:mfa-reset              # remove a staff member's two-step verification
```

**Never run on production:** plain `php artisan db:seed`, `migrate:fresh`, `migrate:refresh`, `migrate:reset`, or `SEED_DEMO_DATA=true`.

## Problems we hit (and the fix)

| Symptom | Cause | Fix |
|---|---|---|
| `Migration table not found` | Empty new database | `migrate --force`, then the reference seeder |
| `cc:mail-test` says mailer is `log` | `MAIL_MAILER` not set | Add `MAIL_*` App settings, `config:clear` |
| Gmail `534 Application-specific password required` | `MAIL_PASSWORD` was not a valid Gmail app password | Make a new one at myaccount.google.com/apppasswords, paste without spaces or quotes |
| `SQLSTATE[HY000] [1045] Access denied for user 'custodicoreadmin'` | `DB_PASSWORD` was changed by mistake while editing the mail settings | MySQL server → Overview → **Reset password**, put the same value in `DB_PASSWORD`, `config:clear`, `migrate:status` |
| Email link opens `localhost` (`ERR_CONNECTION_REFUSED`) | `APP_URL` still `http://localhost` | Set `APP_URL` in App settings. For a link already sent, replace `http://localhost` with the Azure address and keep the rest |
| `Enter a valid Philippine mobile number` | Placeholder used | 11 digits starting with `09`, no spaces |

**Tip:** when editing App settings, change only the one setting you mean to. The password settings sit close together in the list.

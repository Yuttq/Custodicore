# CustodiCore — Web Admin (Laravel)

The System Administrator/Warden dashboard for CustodiCore, the BJMP visitor
and custody management system. This sits alongside the Expo mobile app in
this repo and is built to match it — same color palette, type scale,
spacing and radii as `src/designSystem/tokens/*` in the mobile app, and the
same field names/enum values as the `CustodiCore Database Design` project
doc and `custodicore_schema.sql`.

**Status: UI only, not connected to a database yet.** Every page renders
from static sample arrays in its controller (`app/Http/Controllers/Admin/`).
Nothing here runs a query. The 23 migrations under `database/migrations/`
mirror `custodicore_schema.sql` exactly (same tables, columns, enums, FKs)
so the schema is ready to go the moment you want to wire this up — just
don't run `php artisan migrate` until you're ready for that step.

## Setup

This was scaffolded by hand in a sandboxed environment with no access to
Packagist, so `vendor/` isn't included — install it here:

```bash
cd backend
composer install
npm install
cp .env.example .env
php artisan key:generate
npm run dev      # Vite dev server, in one terminal
php artisan serve # in another terminal — visit http://127.0.0.1:8000
```

## What's here

- `app/Http/Controllers/Admin/` — one controller per module (Dashboard,
  Users, PDLs, Visitors, Schedules, Check-ins, Eligibility, Audit,
  Settings), each returning sample data shaped like the matching table(s)
  in the database design.
- `resources/views/layouts/admin.blade.php` — sidebar + topbar shell every
  page extends.
- `resources/views/admin/*/index.blade.php` — one page per module.
- `resources/views/admin/partials/status-chip.blade.php` — shared status
  pill, color-mapped to the same status vocabulary used across the schema
  (`active`, `pending`, `verified`, `flagged_for_review`, …).
- `tailwind.config.js` / `resources/css/app.css` — the mobile app's design
  tokens (`colors.js`, `typography.js`, `spacing.js`, `shadows.js`)
  translated into a Tailwind theme + a small set of reusable component
  classes (`cc-card`, `cc-btn-primary`, `cc-chip-*`).
- `database/migrations/2024_01_02_*` — the 23 CustodiCore tables, in the
  same dependency order and with the same names/types as
  `custodicore_schema.sql`.

## Next steps (when you're ready to connect the database)

1. Fill in `DB_*` in `.env` (matches the connection this schema was
   verified against: MySQL 8, InnoDB, `utf8mb4`).
2. `php artisan migrate` — or run `custodicore_schema.sql` directly if you
   want the seed data (roles, modules, permissions, visitation rules) that
   ships with it.
3. Swap each controller's static arrays for real Eloquent queries against
   the new tables.
4. Add real authentication (the `users` table/migration here is Laravel's
   default — decide whether staff logins move onto `accounts` +
   `staff_profiles` from the design doc instead, to match the mobile app's
   `accounts` table exactly).

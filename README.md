# TeleCRM

A single-company lead and call management application for a 15–22 person team in India. Built with Laravel 12, Blade, Tailwind CSS, and a database-backed queue. MySQL is the deployment target; local development uses SQLite so Apache and MySQL do not need to run.

## Try the local application

Open **http://127.0.0.1:8000** while the Laravel server is running.

| Role | Email | Password |
| --- | --- | --- |
| Owner | owner@telecrm.test | DemoOwner!2026 |
| Employee | anjali@telecrm.test | DemoEmployee!2026 |

These accounts and the sample contacts are **local demo data only**. Demo calls do not contact any telephone numbers. Do not reuse the demo database for live operations.

- Owner: dashboard, add/import/assign leads, manage employees, check call history, follow-ups, and operational settings.
- Employee: assigned leads, Call button, simulated call result, notes, stage updates, and follow-ups.
- In demo mode, click **Start demo call**, choose a result and duration, then **Finish demo call**. Demo timing is synthetic.

## Requirements

- PHP 8.2+ with PDO SQLite for local use, PDO MySQL for deployment, and the standard Laravel extensions.
- Composer 2.
- Node.js LTS and npm for compiling assets. Node is not needed to serve a production build.

## Start development

From this folder, in one terminal:

```sh
php artisan serve --host=127.0.0.1 --port=8000
```

In another terminal, use the existing NVM installation:

```sh
source ~/.nvm/nvm.sh
nvm use --lts
npm run dev -- --host 127.0.0.1
```

On this machine, XAMPP can put a different `head` program ahead of the macOS command and make NVM report `Unknown option: n`. In that terminal only, run `export PATH="/usr/bin:/bin:$PATH"` before sourcing NVM. No global shell changes are necessary.

Visit **port 8000**, not port 5173. Vite on 5173 only serves frontend assets.

Alternatively, after enabling Node in your terminal, `composer run dev` starts Laravel, Vite, the queue worker, and the scheduler together. Stop any individually started servers first to avoid port conflicts.

To use compiled assets without a Vite server: stop Vite and run `npm run build`. Laravel's `public/hot` file must not remain if Vite has been forcibly terminated.

## Install on a fresh checkout

```sh
composer install
cp .env.example .env
php artisan key:generate
php -r "file_exists('database/database.sqlite') || touch('database/database.sqlite');"
php artisan migrate
php artisan db:seed
npm ci
npm run build
php artisan serve
```

Do not replace an existing `.env` or regenerate an existing application key. Seeding is refused outside local/testing demo mode and is skipped if users already exist.

## Implemented

- Session login, throttling, password changes, owner-managed employee accounts and deactivation.
- Server-side authorization across lead details, mutations, calls, and follow-ups.
- India-only number normalization, unique lead phones, CSV validation and row-level import results.
- Manual assignments; lead timelines with notes, stage changes, and reassignment history.
- Scheduled, overdue, rescheduled, and completed follow-ups in IST.
- Owner dashboard, date filters, lead pipeline, employee activity, never-called leads, and call history.
- Exotel Connect Two Numbers adapter, recording explicitly off, guarded live activation.
- Idempotent call initiation, per-employee call locking, uncertain outcome handling, durable callback hints, queue retries, provider API verification, reconciliation, and owner recovery tools.
- Private database backup command and scheduled daily backup; see deployment guide for retention and restore instructions.
- Responsive interface and local demonstration data.

## Live Exotel setup

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). Real calling requires an approved Exotel account/number, correct account region, a public HTTPS callback URL, and running background services. No live calls were made during development.

The callback URL uses a random per-call token. **Callback contents do not directly change call outcomes.** The worker fetches call details with server-held Exotel credentials and checks the account, call ID, phone numbers, and initiation time before applying results. This is application-side verification; it does not claim that Exotel provides a webhook signature.

Live call metrics include only Exotel records with a provider reference and a successful authenticated read-back. Clicking Call alone does not earn call activity credit. Demo records are excluded from live histories and totals. Employees cannot submit or edit call timing, duration, customer outcome, or provider identity; server-side validation rejects these fields. Notes and stages remain employee-reported and never count as call evidence. The simulator is restricted to local/testing environments, including direct service-level checks.

Customer connection is based on second-leg details or positive conversation duration. Overall call completion or employee pickup alone does not count as a customer connection. Exact connection timestamps remain unknown when the provider does not supply them. Total/billed duration is never used as talk time.

If initiation times out, there is no automatic redial. An owner checks the provider log, supplies the call SID to reconcile if needed, or releases the lock with a recorded reason after verifying the call is not active. Historical outcomes remain unknown unless verified.

## Checks

```sh
php artisan test
vendor/bin/pint --test
npm run build
```

The application tests use an isolated in-memory SQLite database and fake provider HTTP responses. They do not place calls or modify your demo database. A MySQL production deployment and real provider integration still require an environment-specific pilot.

Browser automation was not completed because Chrome is restricted on this machine. Follow [docs/MANUAL_TESTING.md](docs/MANUAL_TESTING.md) for manual testing. Optional Playwright scripts are included for a future environment with browser permission; they are not required to run the app.

## Project map

- `app/Http/Controllers`: web workflows and callback entry point.
- `app/Services`: lead assignment/history and Exotel call lifecycle.
- `app/Jobs/ProcessCallEvent.php`: authenticated provider read-back.
- `app/Console/Commands`: create owner, reconcile calls, back up database.
- `database/migrations`: database schema.
- `resources/views`: Blade screens; `resources/css/app.css`: Tailwind and interface styling.
- `tests/Feature/CrmTest.php`: permissions, workflows, reporting, and provider failure cases.

Scope intentionally excludes call recording, native phone tracking, WhatsApp/SMS, inbound calls, multi-company billing, and automatic dialing. Ordinary calls made outside the CRM cannot be tracked by this web application.
# crm-telecalling

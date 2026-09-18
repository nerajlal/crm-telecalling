# Deployment and operations

## 1. Host and database

Use PHP 8.2+ (Laravel 12), MySQL 8+ or compatible MariaDB, HTTPS, scheduled tasks, and a supervised queue worker. Confirm these capabilities before selecting a shared hosting plan. Use a VPS or managed Laravel host if persistent workers are unavailable.

Point Apache/Nginx document root at **tele-crm/public**, never the repository root. The root `.htaccess` is additional protection for local Apache subfolder use, not a replacement for the correct document root. Allow the application to write to `storage` and `bootstrap/cache`; do not make the whole project world-writable.

Create a **new, empty production database** and a dedicated database user. Do not import the local demo database. Set:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://crm.your-company.in
SESSION_SECURE_COOKIE=true
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=tele_crm
DB_USERNAME=tele_crm_app
DB_PASSWORD=your-private-database-password
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
TELEPHONY_DRIVER=exotel
TELEPHONY_LIVE_ENABLED=false
```

Keep secrets in `.env`, outside source control. On the first deployment only, generate an application key. Preserve that key on subsequent deployments.

```sh
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan crm:create-owner
npm ci
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

`crm:create-owner` asks for the initial owner's credentials privately and refuses if an owner already exists. Create employee accounts from the interface. There is no public registration page. Do not seed production. Remove any stale `public/hot` file before deployment; the Vite dev server is not used in production.

## 2. Configure domestic calling

Provision the company's approved India domestic calling setup with Exotel. Confirm account KYC/onboarding, permitted calling use, caller ID, both-leg charges, and simultaneous-call capacity with the provider.

```dotenv
EXOTEL_HOST=api.in.exotel.com
EXOTEL_ACCOUNT_SID=your-account-sid
EXOTEL_API_KEY=your-api-key
EXOTEL_API_TOKEN=your-api-token
EXOTEL_CALLER_ID=your-approved-exophone
EXOTEL_CALLBACK_BASE_URL=https://crm.your-company.in
EXOTEL_TIMEZONE=Asia/Kolkata
```

Use `api.exotel.com` instead if that is your account's assigned region. Confirm the timezone of the account's timestamp strings; an incorrect timezone will intentionally fail call identity checks.

The adapter uses the V1 Connect Two Numbers API: employee number as `From`, customer as `To`, your provisioned ExoPhone as `CallerId`, `Record=false`, answered/terminal callbacks, JSON responses, and call detail retrieval with `details=true`.

The application passes a per-call callback URL automatically:

```text
https://crm.your-company.in/webhooks/exotel/{call_id}/{random_call_token}
```

Make that POST endpoint publicly reachable over HTTPS. Exclude its full URL/token from access logs or redact it. The route is CSRF-exempt and protected by an unguessable per-call token. The worker treats callback data only as a hint and independently verifies call details through authenticated Exotel API requests. There is no unsupported claim of a provider HMAC signature.

Live initiation remains disabled until `TELEPHONY_LIVE_ENABLED=true`. Run `php artisan config:cache` and restart workers after configuration changes. No automatic test calls are made by setup or health checks.

## 3. Worker and scheduler

Run a supervised worker with your deployment process manager:

```sh
php artisan queue:work database --sleep=2 --tries=4 --timeout=60 --max-time=3600
```

Example Supervisor configuration (adjust paths and user):

```ini
[program:tele-crm-worker]
command=/usr/bin/php /srv/tele-crm/artisan queue:work database --sleep=2 --tries=4 --timeout=60 --max-time=3600
directory=/srv/tele-crm
user=www-data
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs=70
redirect_stderr=true
stdout_logfile=/srv/tele-crm/storage/logs/worker.log
```

Cron, once per minute:

```cron
* * * * * cd /srv/tele-crm && /usr/bin/php artisan schedule:run >> /srv/tele-crm/storage/logs/scheduler.log 2>&1
```

The scheduler reconciles recent incomplete calls every five minutes and creates a private database backup at 02:00 IST. It never retries call initiation. If a missing callback leaves no provider SID, an owner must find the reference in the Exotel portal and enter it in Settings. Automatic reconciliation looks back 24 hours; older calls can be manually reconciled from their lead detail page.

Operational commands:

```sh
php artisan calls:reconcile
php artisan queue:failed
php artisan queue:retry all
php artisan queue:restart
php artisan schedule:list
```

Only callback verification is queued, so retrying failed jobs cannot initiate a second call. Settings displays queue counts, verification errors, and stuck calls. Monitor failed jobs and scheduler output; zero queued jobs by itself does not prove the worker is healthy.

## 4. Backup and restore

```sh
php artisan crm:backup
```

Backups are written to `storage/app/private/backups` with restrictive permissions. SQLite uses `VACUUM INTO` for a consistent snapshot. MySQL/MariaDB requires a compatible `mysqldump` executable (set `MYSQLDUMP_BINARY` when it is not on PATH). The command uses argument arrays and passes the database password through a process environment variable, not the command line. Match any database TLS settings in the dump client's configuration before using a remote database.

Copy backups to encrypted storage outside the application host. Backups contain lead data and credentials stored as password hashes; keep them private. Retention is an operator-managed policy: monitor disk use and remove expired backups after verifying off-host copies. Back up `.env` and the application key separately in a secure secret store.

Restore rehearsal **into a separate database**, never over a running production database:

- SQLite: copy a snapshot to a new local path, point a separate test application's `DB_DATABASE` at it, and verify login, lead counts, call history, and follow-ups.
- MySQL: create an empty restore-test database and use `mysql --user=... --password restore_test < backup.sql`. Enter the password at the prompt. Point a separate application instance at that database and check the same workflows.
- Keep calling disabled during restore tests (`TELEPHONY_LIVE_ENABLED=false`); do not start restored queued jobs until reviewed.
- Confirm the expected migration version and sample record counts. Record the successful rehearsal date before launch.

No automatic destructive restore command is provided.

## 5. Two-person pilot before rollout

1. Enable live calling only after onboarding and a public HTTPS endpoint are ready.
2. With permission from both participants, test a controlled India-to-India call.
3. Confirm employee pickup alone does not count as a customer connection.
4. Verify customer connection and talk time against the Exotel portal; check no-answer and busy cases.
5. Verify webhook processing and scheduled reconciliation, including a delayed callback.
6. Check timezone, reassignment, permissions, backup restore, and employee login from their actual devices.
7. Add the rest of the team after the pilot passes.

The code has automated coverage with mocked provider responses. Real Exotel account payloads, domestic routing, capacity, and MySQL deployment behavior require this pilot. Recording is disabled. Ordinary SIM calls outside the CRM remain untracked.

## Reference documentation

- [Exotel Connect Two Numbers](https://developer.exotel.com/docs/voice-v1/api-reference/connect-two-numbers)
- [Exotel call details](https://developer.exotel.com/docs/voice-v1/api-reference/call-details)
- [Exotel status callbacks](https://developer.exotel.com/docs/voice-v1/api-reference/status-callback)
- [Laravel deployment](https://laravel.com/framework/docs/12.x/deployment)

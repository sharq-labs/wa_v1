# Deployment

## Requirements

- PHP 8.3+ with `pdo_mysql`, `redis` (or predis), `intl`, `pcntl`+`posix` (for Horizon)
- MySQL 8, Redis 7
- Node 20+ (build only)

## Docker Compose (reference topology)

`docker compose up` starts: **app** (php-fpm) + **nginx** (:8000) + **mysql** + **redis** + **horizon** + **reverb** (:8080) + **scheduler**. See `docker-compose.yml` and `docker/`.

## Manual deployment checklist

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan key:generate            # first deploy only
php artisan migrate --force
php artisan db:seed --class=PlanSeeder   # plans catalogue
php artisan storage:link
php artisan config:cache route:cache view:cache event:cache
```

### Processes (systemd/supervisor)

| Process | Command |
| --- | --- |
| Web | php-fpm behind nginx (`docker/nginx.conf` is a reference incl. `/app` websocket proxy) |
| Queues | `php artisan horizon` |
| WebSockets | `php artisan reverb:start --host=0.0.0.0 --port=8080` |
| Scheduler | cron `* * * * * php artisan schedule:run` |

### Production .env essentials

```env
APP_ENV=production
APP_DEBUG=false                # never true: hides stack traces/SQL from users
APP_URL=https://yourapp.com
DB_*, REDIS_*                  # point at your services
QUEUE_CONNECTION=redis
CACHE_STORE=redis
BROADCAST_CONNECTION=reverb
REVERB_APP_KEY/SECRET          # strong random values
REVERB_SCHEME=https
FILESYSTEM_DISK=s3             # S3-compatible storage in production
AWS_*                          # bucket credentials
WHATSAPP_PROVIDER=meta
META_*                         # see META_SETUP.md
SANCTUM_STATEFUL_DOMAINS=yourapp.com
SESSION_DOMAIN=.yourapp.com
```

## Scheduler jobs

- `automation:resume-due-waits` (every minute) — safety net for delayed automation waits
- `campaigns:dispatch-due` (every minute) — launches scheduled campaigns
- `templates:sync-all` (hourly) — provider template sync
- `webhooks:prune` (daily) — prunes processed webhook events after 30 days

## Hardening notes

- HTTPS enforced (`URL::forceScheme` in production).
- Webhook endpoint is signature-verified and rate limited; auth endpoints throttled.
- WhatsApp access tokens encrypted at rest and hidden from all serialization.
- The HTTP automation node blocks private/reserved IP ranges (SSRF) and caps timeout/response size.
- Uploads are MIME-whitelisted and size limited.
- Horizon dashboard: protect `/horizon` via `HorizonServiceProvider` gate before exposing publicly.

## Scaling

- Horizon workers scale per queue (`whatsapp-webhooks`, `whatsapp-messages`, `automations`, `campaigns`, `notifications`, `default`) — tune `maxProcesses` in `config/horizon.php`.
- Reverb supports horizontal scaling with the Redis pub/sub scaling driver (`REVERB_SCALING_ENABLED=true`).
- Campaign pacing: `CAMPAIGN_CHUNK_SIZE`, `CAMPAIGN_MESSAGES_PER_SECOND`.

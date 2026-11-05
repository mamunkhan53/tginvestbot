# Telegram integration

## Implemented

Authenticated webhook ingestion, private-chat sender validation, update deduplication,
transactional user registration and response queuing, `/start`, `/help`, Back navigation,
read-only portfolio/cabinet, paginated ledger history, and two-level referral counts.
Replayed `/start` never changes the referrer. New users can only reference existing users,
so registration cannot introduce referral cycles.

Balances/history read only the user-owned account with code `user:{internal_id}:available`.
Future financial services must use that account convention or update the shared interface.
This module creates no ledger entries and cannot initiate deposits, investments or payouts.
No deposit address is invented while custody integration is absent.
English copy is in a translation file; Russian, Bangla and language selection remain pending.
The complete investment, settings and financial bot workflows are not yet implemented.

## Configuration and activation

Use environment secrets for `TELEGRAM_BOT_TOKEN` and `TELEGRAM_WEBHOOK_SECRET`.
The webhook secret must contain 32–256 ASCII letters, digits, underscores or hyphens.
Set `TELEGRAM_BOT_USERNAME` (without @) and `TELEGRAM_WEBHOOK_URL` (HTTPS).
Keep tokens out of chat, URLs exposed to users, source control and logs.

1. Apply migrations with `php novikov/bin/migrate.php`.
2. Route the chosen public webhook URL to `novikov/webhooks/telegram.php` with a PHP-capable
   web server. Do not expose the repository root or config/CLI directories.
   Public routing has not been connected by this module; confirm it before registration.
3. Run `php novikov/bin/telegram-webhook.php register` explicitly when the bot is ready.
   Existing pending Telegram updates are preserved.
4. Schedule `php novikov/cron/process_telegram.php` once per minute.
5. Inspect registration using `php novikov/bin/telegram-webhook.php status`.

No webhook is registered and no worker schedule is installed automatically.
Webhook requests are acknowledged only after durable storage. Database failures return 503
for Telegram retry. Unsupported authenticated updates are acknowledged and ignored.
Anonymous/incorrect-secret requests cannot queue work. Requests are capped at 64 KiB.
Add reverse-proxy request limits and network controls before production.

## Delivery and recovery

The worker uses a database advisory lock, processes up to 50 updates, and sends at most
100 outbox items per run. User registration, processed state and response queue entries
commit in one transaction. Five processing failures quarantine an update as failed.
Retries for Telegram 429 honor retry_after, capped at one day, with ten delivery attempts.
Rejected requests are failed. Timeout, 5xx and process-crash send outcomes are uncertain,
not automatically retried: Telegram sendMessage has no idempotency key. Operators must
review uncertain deliveries before deciding whether to resend. User state is never rolled
back solely because a response was not delivered. Callback acknowledgements can expire
when workers are delayed; run the worker more frequently for responsive operation.
Payloads contain user data; restrict database access and define a retention policy.

## Verification

`php novikov/tests/telegram.php` tests validation without Telegram calls.
`php novikov/tests/telegram-database.php` verifies registration, referrals, update
deduplication, account menu authorization, outbox retries and crash recovery in
connection-local temporary tables. Delivery is stubbed only in these tests.
These checks do not prove live bot delivery or production webhook reachability.
Do not treat missing credentials as an operational integration.

# NOVIKOV TRADE

## Current delivery status

This is an initial PHP foundation, **not a completed platform or a production-ready financial service**.
Implemented: PHP autoloading, MySQL/MariaDB PDO connection, version validation, initial migration runner,
ledger posting with decimal arithmetic and account locking, fee calculation, secure CLI admin creation,
and arithmetic tests. The PHP/Bootstrap administrator console now includes sign-in,
secure sessions, CSRF checks, durable login limits, role checks and audit history.

Not yet implemented: Telegram handlers, deposit scanning,
withdrawal signing, investment products and settlement, referrals, automation, KYC/AML integrations,
full immutable-ledger database protections.
No operational financial endpoints are exposed.

## Requirements and installation

PHP 8.2+ with PDO MySQL, BCMath, and Argon2 support. MySQL 8.0+ or MariaDB 11.8.9+ is required.
MariaDB is explicitly approved by the project owner. Do not substitute PostgreSQL or SQLite. Configure the values documented in
`.env.example` using environment variables; this application does not load dotenv files.
Use a TLS-enabled MySQL connection and a trusted CA for remote hosting.

1. Supply `MYSQL_DSN`, `MYSQL_USER`, `MYSQL_PASSWORD`, and where required `MYSQL_SSL_CA`.
2. Run `php novikov/bin/migrate.php` using an installation account with DDL privileges.
3. Supply `ADMIN_EMAIL` and `ADMIN_INITIAL_PASSWORD` through a secure secret store.
4. Run `php novikov/bin/create-admin.php`, then remove the initial password secret.
5. Run `php novikov/tests/run.php`.
6. Run `php novikov/tests/database.php` for persistent-schema ledger, concurrency, recovery, foreign-key, trigger, and migration checks. Use a test database server/account with CREATE/DROP DATABASE, table DDL, TRIGGER, and DML permissions. The suite creates a random `novikov_test_<hex>` schema, switches every fixture connection to it, and drops it in `finally`, including after failures. It never inserts fixtures into the configured application schema and fails rather than falling back when privileges are missing. PHP `proc_open` is required for independent concurrent connections. Errors suppress connection details; the last PASS line identifies progress. If the whole test runner is forcibly killed, an administrator must remove the leftover `novikov_test_<hex>` schema.

The same suite can be run against MySQL 8.0+ and MariaDB 11.8.9+ by configuring the
connection environment for each test server. A pass on one engine is not a claim
that the other engine has been exercised.

Verified on MySQL 8.0.42: 74 database checks and 16 arithmetic checks.
The configured remote account denied CREATE DATABASE (1044), so its MariaDB
integration run remains unverified; do not grant extra production permissions
just to run tests. Use a dedicated test server/account instead.

There is no default password or public registration. Supply a random `SESSION_SECRET`
of at least 32 characters. Open the NOVIKOV TRADE Admin preview to sign in after
provisioning an administrator with the CLI above. Existing accounts are never overwritten.
MySQL migrations auto-commit DDL; the runner uses an advisory lock and records checksums
only after each migration succeeds. Back up the database before future migrations.

## Administrator console

The artifact wrapper starts PHP, not React or the unrelated Node API starter.
For local development run `PORT=8080 BASE_PATH=/ php -S 0.0.0.0:8080 -t novikov/public novikov/router.php`.
Use HTTPS at the reverse proxy; session cookies are always Secure, HttpOnly and
SameSite=Strict. Direct non-localhost HTTP cannot support login. Serve only
`novikov/public` with PHP-FPM and a hardened web server for production; the bundled
PHP development server is for preview only. No production deployment is authorized.

- `/login`: GET form, POST credentials and CSRF token.
- `/`: authenticated dashboard available to all five defined roles.
- `/audit`: latest 100 actual audit events; super administrators and administrators only.
- `/logout`: POST only, with CSRF token; destroys the session and clears its cookie.
- Unlisted permissions are denied, including every financial action. No route or
  dashboard control can enable real-money operations, regardless of database settings.

Sessions expire after 30 minutes idle or 8 hours absolute. IDs rotate on login and
every 15 minutes; login also rotates CSRF. Active state, role and password fingerprint
are reloaded on each protected request so disabled accounts and password changes
invalidate access. Session files live in a private 0700 directory under the host's
temporary directory. This foundation is single-instance only; configure a shared
session backend before horizontal scaling. Restart/ephemeral host loss may log users out.

Login attempts are limited to 5 per normalized email and 30 per source address in
15-minute fixed windows, including successful attempts. Reservations use database
row locks and persist across PHP restarts. Account and source bucket identifiers use
HMAC; attempted credentials and raw IP addresses are not recorded. Forwarded headers
are deliberately not trusted: behind a proxy, its connection address shares the source
limit across users. Add an explicitly trusted proxy policy before large-scale use.
The dashboard does not claim external services are connected merely because a secret exists.

Audit includes successful/failed/throttled login, logout, expiration/revocation,
CSRF failures, denied access and audit viewing. Runtime failures return a generic 503
without DSNs or credentials. Use a least-privilege runtime database account; migrations
and installation should use a separate operator account. MFA, tamper-resistant audit
retention, shared sessions and external integrations remain production gates.

Run `php novikov/tests/admin-auth.php` against a disposable MySQL/MariaDB server
with CREATE/DROP DATABASE permissions. It creates and removes its own random test
schema; it never writes fixtures to the application schema. It covers sessions,
all role permissions, CSRF, rate limits and audit records. The suite was verified on
MySQL 8.0.42; the migration and login rendering were verified with configured MariaDB
11.8.9, but the full isolated suite still needs a dedicated MariaDB test server.

## Accounting rules

Money is a decimal string, never a PHP float. USDT units use 18 decimal places.
The withdrawal input means **net payout**; a 5 USDT request at 1% reserves 5.05 USDT.
Fee fractions below the smallest token unit are truncated.
The ledger derives balances from entries; it has no editable cached balance.
Journal posting requires equal debits and credits, locks affected accounts in ID order,
and rejects negative protected account balances.
An idempotency key may only be reused for the same payload.
The posting service owns its transaction. Future domain services must use
`Ledger::postEvent()` to commit business state and postings together; see the
[financial-event contract](docs/financial-events.md) for retry identity, callback
restrictions and isolated recovery tests. `post()` is accounting-only.

## Production gates

This foundation must not accept funds. Complete the remaining modules and tests first.
Provision the [restricted runtime database role](docs/database-permissions.md) after
applying the append-only ledger migration. Integrate financial services with the atomic event boundary, and complete audit protections,
administrator MFA, shared production sessions, reconciliation, and end-to-end financial event recovery tests.
Use BSC testnet chain 97 with an explicitly configured test token; it is not mainnet USDT.
Use an external isolated signing service, never private keys in the dashboard or database.
Independent legal/compliance approval, custody arrangements and security review are required
before real-money switches may be enabled. They currently default to false.

The pre-existing Node starter packages are unrelated template scaffolding, not this
application's backend. No PHP routes currently depend on them.

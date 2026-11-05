# Append-only ledger and database identities

All journals and entries are immutable from insertion; there is no draft state.
Migration 011 rejects every UPDATE and DELETE (including no-op updates, upserts
and REPLACE deletions) using triggers. Transaction rollback remains supported.
Correct mistakes with new balanced, independently keyed correction journals,
never by editing history.

Triggers do not intercept TRUNCATE or protect against a schema owner dropping
them. Use separate migration/operator and runtime identities. Do not give the
web server, Telegram worker or financial worker the migration credentials.

## Operator provisioning (MySQL 8 / MariaDB 11.8.9)

1. Back up the database and apply migrations with the operator identity:
   `php novikov/bin/migrate.php`. Keep that identity outside service environments.
2. Generate and review the SQL for a **new** role:
   `php novikov/bin/runtime-grants.php YOUR_SCHEMA novikov_runtime`.
   Execute the output through an operator connection. Role creation deliberately
   fails if the role already exists; do not substitute an already privileged role.
   This step is separate from migrations because global role administration
   permissions are not required for ordinary schema migrations.
3. Create a new, dedicated runtime login using your host's secure account
   provisioning, with a generated password and the narrowest allowed host.
   Do not reuse an account with global, database-level, direct or inherited grants.
4. Grant the role and activate it by default (replace account and host):

   ```sql
   GRANT novikov_runtime TO 'runtime_login'@'APP_HOST';
   -- MySQL:
   SET DEFAULT ROLE novikov_runtime TO 'runtime_login'@'APP_HOST';
   -- MariaDB (use this instead of the MySQL statement):
   SET DEFAULT ROLE novikov_runtime FOR 'runtime_login'@'APP_HOST';
   ```

5. Inspect SHOW GRANTS for both role and login, including inherited/mandatory
   roles. No ALL, GRANT OPTION, DDL, TRIGGER, FILE or administrative privileges
   may be effective. There must be no UPDATE/DELETE on journals or entries. MySQL
   privileges are additive: a narrow role cannot revoke a broad existing grant.
6. Configure runtime MYSQL_USER / MYSQL_PASSWORD through the secret manager.
   Reconnect services and verify CURRENT_ROLE() activates the role. Leave
   installation and migrations on the separate operator identity.

The generated role allows only current application operations: journal/entry/
account insert and read, user registration/read/profile update, administrator read/password
rehash/reset, MFA and account recovery state, login throttling, audit append/read, Telegram delivery state, and settings
read. It cannot change real-money switches, manage administrators or migrations,
delete history, grant permissions, or change schema. New features need an explicit
permission review; never solve permission errors with schema-wide ALL grants.

When upgrading an existing role after MFA migration 012, grant only SELECT,
INSERT and UPDATE on `YOUR_SCHEMA`.`admin_security` to that role before restarting
the console. See [administrator recovery](administrator-recovery.md) for the exact
upgrade SQL and recovery operator procedure. Do not recreate the role or grant
schema-wide privileges. The MFA regression suite verifies all authentication and
recovery paths with a separate restricted-role connection.

MySQL locking reads require a write privilege for SELECT FOR UPDATE. The role
has column-level UPDATE(id) on accounts solely to permit posting locks; an
additional trigger rejects every actual account update (including no-ops).
Account identity, owner and balance interpretation therefore remain immutable.
User profile-column update grants permit registration's user locking read but
do not permit changing referral relationships or status.

The posting service remains responsible for balancing and atomic insertion.
Append-only does not prohibit inserting more entries into an existing journal;
these protections are not a database-enforced journal finalization protocol.
Trusted operators can still change schema, so backups and independent audit
retention remain necessary.

## Isolated verification

Run `php novikov/tests/database.php` against a dedicated disposable local test
server, not production credentials. Besides CREATE/DROP DATABASE, the test account
needs CREATE USER/ROLE and grant authority. The suite creates a random
`novikov_test_*` schema and random runtime login/role, verifies real permission
denials through a separate connection, then removes them in finally blocks.
The test DSN must be a local socket or localhost connection for the temporary
localhost-only login. It never changes the configured application schema or
enables real-money switches. An interrupted process may require an operator to
remove leftover `novikov_test_*`, `nt_user_*` and `nt_role_*` objects.

<?php
declare(strict_types=1);
namespace Novikov;

use InvalidArgumentException;

/** Operator-only SQL generation; never run by a web request or migration. */
final class RuntimeGrants
{
    public static function statements(string $schema, string $role): array
    {
        foreach ([$schema, $role] as $identifier) {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/D', $identifier)) {
                throw new InvalidArgumentException('Use 1-64 ASCII letters, digits or underscores, beginning with a letter.');
            }
        }
        // CREATE (not IF NOT EXISTS) intentionally refuses to reuse a role
        // which may already carry broader privileges.
        $sql = ["CREATE ROLE `$role`"];
        $permissions = [
            'users' => 'SELECT, INSERT, UPDATE (username, first_name, last_name)',
            'admin_users' => 'SELECT, UPDATE (password_hash)',
            'admin_login_limits' => 'SELECT, INSERT, UPDATE',
            'admin_security' => 'SELECT, INSERT, UPDATE',
            'admin_activity_logs' => 'SELECT, INSERT',
            // FOR UPDATE needs a write privilege on supported MySQL versions.
            // The account UPDATE trigger denies all actual updates.
            'ledger_accounts' => 'SELECT, INSERT, UPDATE (id)',
            'ledger_journals' => 'SELECT, INSERT',
            'ledger_entries' => 'SELECT, INSERT',
            'system_settings' => 'SELECT',
            'telegram_updates' => 'SELECT, INSERT, UPDATE',
            'telegram_outbox' => 'SELECT, INSERT, UPDATE',
        ];
        foreach ($permissions as $table => $grants) {
            $sql[] = "GRANT $grants ON `$schema`.`$table` TO `$role`";
        }
        return $sql;
    }
}

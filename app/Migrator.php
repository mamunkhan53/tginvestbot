<?php
declare(strict_types=1);
namespace Novikov;

use PDO;
use RuntimeException;

final class Migrator
{
    public static function run(PDO $db): array
    {
        if (!$db->query("SELECT GET_LOCK('novikov_migrations',10)")->fetchColumn()) {
            throw new RuntimeException('Another migration is running.');
        }
        try {
            $applied = [];
            $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(191) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)');
            foreach (glob(dirname(__DIR__) . '/database/migrations/*.sql') as $file) {
                $name = basename($file);
                $checksum = hash_file('sha256', $file);
                $q = $db->prepare('SELECT checksum FROM schema_migrations WHERE name = ?');
                $q->execute([$name]);
                if ($old = $q->fetchColumn()) {
                    if (!hash_equals($old, $checksum)) throw new RuntimeException("Applied migration changed: $name");
                    continue;
                }
                $source = file_get_contents($file);
                $statements = str_contains($source, '-- @statement')
                    ? explode('-- @statement', $source) : explode(';', $source);
                foreach ($statements as $sql) {
                    if (trim($sql) === '') continue;
                    if (preg_match('/^\s*ALTER TABLE (\w+) ADD COLUMN IF NOT EXISTS (\w+)/i',$sql,$column)) {
                        $exists=$db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
                        $exists->execute([$column[1],$column[2]]);
                        if($exists->fetchColumn())continue;
                        $sql=preg_replace('/ADD COLUMN IF NOT EXISTS/i','ADD COLUMN',$sql,1);
                    }
                    // MySQL before 8.0.29 lacks CREATE TRIGGER IF NOT EXISTS.
                    // Preserve migration checksums and re-runnable DDL on both engines.
                    if (preg_match('/^\s*CREATE TRIGGER IF NOT EXISTS (\w+)/i', $sql, $match)) {
                        $q = $db->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?');
                        $q->execute([$match[1]]);
                        if ($q->fetchColumn()) continue;
                        $sql = preg_replace('/CREATE TRIGGER IF NOT EXISTS/i', 'CREATE TRIGGER', $sql, 1);
                    }
                    $db->exec($sql);
                }
                $db->prepare('INSERT INTO schema_migrations (name,checksum) VALUES (?,?)')->execute([$name,$checksum]);
                $applied[] = $name;
            }
            return $applied;
        } finally {
            $db->query("SELECT RELEASE_LOCK('novikov_migrations')");
        }
    }
}

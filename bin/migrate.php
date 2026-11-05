<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

try {
    $db = Novikov\Database::connect();
    foreach (Novikov\Migrator::run($db) as $name) {
        echo "Applied $name\n";
    }
} catch (Throwable $e) {
    $code = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
    fwrite(STDERR, "Migration failed (driver code $code). Check MySQL/MariaDB configuration and migration compatibility; no credentials are printed.\n");
    exit(1);
}

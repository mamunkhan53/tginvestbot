<?php
declare(strict_types=1);
namespace Novikov;

use PDO;
use RuntimeException;

final class Database
{
    public static function connect(): PDO
    {
        foreach (['MYSQL_DSN', 'MYSQL_USER', 'MYSQL_PASSWORD'] as $name) {
            if (getenv($name) === false || getenv($name) === '') {
                throw new RuntimeException("Missing required configuration: $name");
            }
        }
        $dsn = getenv('MYSQL_DSN');
        if (!str_starts_with($dsn, 'mysql:')) {
            throw new RuntimeException('Use a mysql: PDO DSN for MySQL or MariaDB.');
        }
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if (getenv('MYSQL_SSL_CA')) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = getenv('MYSQL_SSL_CA');
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
        $pdo = new PDO($dsn, getenv('MYSQL_USER'), getenv('MYSQL_PASSWORD'), $options);
        $version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
        $isMariaDb = stripos($version, 'mariadb') !== false;
        $minimum = $isMariaDb ? '11.8.9' : '8.0.0';
        if (!preg_match('/^(\d+\.\d+\.\d+)/', $version, $matches)
            || version_compare($matches[1], $minimum, '<')) {
            throw new RuntimeException('MySQL 8.0+ or MariaDB 11.8.9+ is required.');
        }
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }
}

<?php
declare(strict_types=1);
namespace Novikov\Telegram;

use PDO;
use PDOException;

final class Inbox
{
    public function __construct(private PDO $db) {}

    public function accept(array $update): bool
    {
        $insert = $this->db->prepare('INSERT INTO telegram_updates (update_id,payload) VALUES (?,?)');
        try {
            $insert->execute([$update['update_id'],json_encode($update, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
            return true;
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) return false;
            throw $e;
        }
    }
}

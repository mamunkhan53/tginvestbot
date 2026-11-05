<?php
declare(strict_types=1);
namespace Novikov\Telegram;

use InvalidArgumentException;
use RuntimeException;

final class Update
{
    public static function authenticate(string $expected, string $provided): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]{32,256}$/D', $expected)) {
            throw new RuntimeException('Telegram webhook secret is not configured securely.');
        }
        if ($provided === '' || !hash_equals($expected, $provided)) {
            throw new InvalidArgumentException('Unauthorized webhook.');
        }
    }

    /** Only private-chat interactions from their actual sender are accepted. */
    public static function parse(string $json): array
    {
        if (strlen($json) > 65536) throw new InvalidArgumentException('Update too large.');
        try {
            $update = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new InvalidArgumentException('Invalid JSON.');
        }
        if (!is_array($update) || !isset($update['update_id']) || !is_int($update['update_id'])
            || $update['update_id'] < 0) throw new InvalidArgumentException('Invalid update ID.');
        $callback = $update['callback_query'] ?? null;
        if ($callback !== null && !is_array($callback)) throw new InvalidArgumentException('Invalid callback.');
        $message = $callback['message'] ?? $update['message'] ?? null;
        $sender = $callback['from'] ?? $message['from'] ?? null;
        if (!is_array($message) || !is_array($sender)
            || ($message['chat']['type'] ?? '') !== 'private'
            || !is_int($sender['id'] ?? null) || $sender['id'] <= 0
            || ($sender['is_bot'] ?? true) !== false
            || ($message['chat']['id'] ?? null) !== $sender['id']) {
            throw new InvalidArgumentException('Only private messages from human users are supported.');
        }
        $text = $callback === null ? ($message['text'] ?? '') : ($callback['data'] ?? '');
        if (!is_string($text) || strlen($text) > ($callback === null ? 4096 : 64)) {
            throw new InvalidArgumentException('Invalid message text.');
        }
        if (!is_int($message['message_id'] ?? null) || $message['message_id'] < 1) {
            throw new InvalidArgumentException('Invalid message ID.');
        }
        if ($callback !== null && (!is_string($callback['id'] ?? null) || strlen($callback['id']) > 128)) {
            throw new InvalidArgumentException('Invalid callback ID.');
        }
        foreach (['first_name'=>255,'last_name'=>255,'username'=>64] as $field=>$limit) {
            if (isset($sender[$field]) && (!is_string($sender[$field]) || mb_strlen($sender[$field]) > $limit)) {
                throw new InvalidArgumentException('Invalid sender profile.');
            }
        }
        return [
            'update_id'=>$update['update_id'], 'telegram_id'=>$sender['id'],
            'first_name'=>$sender['first_name'] ?? '', 'last_name'=>$sender['last_name'] ?? null,
            'username'=>$sender['username'] ?? null, 'text'=>$text,
            'message_id'=>$message['message_id'], 'callback_id'=>$callback['id'] ?? null,
        ];
    }
}

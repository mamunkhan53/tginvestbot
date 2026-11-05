<?php
declare(strict_types=1);
namespace Novikov\Telegram;

use RuntimeException;

final class Client implements Api
{
    public function __construct(private string $token)
    {
        if (!preg_match('/^[0-9]+:[A-Za-z0-9_-]{20,}$/D', $token)) {
            throw new RuntimeException('Telegram bot token is not configured.');
        }
    }

    /** Errors deliberately omit the token-bearing URL and raw API response. */
    public function call(string $method, array $payload): array
    {
        if (!in_array($method, ['sendMessage','editMessageText','answerCallbackQuery','setWebhook','getWebhookInfo'], true)) {
            throw new RuntimeException('Unsupported Telegram method.');
        }
        $handle = curl_init('https://api.telegram.org/bot' . $this->token . '/' . $method);
        curl_setopt_array($handle, [
            CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_CONNECTTIMEOUT=>5, CURLOPT_TIMEOUT=>20,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
        ]);
        $body = curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($body === false) return ['state'=>'uncertain','code'=>0];
        $result = json_decode($body, true);
        if ($status === 200 && is_array($result) && ($result['ok'] ?? false) === true) {
            return ['state'=>'sent','result'=>$result['result'] ?? null,'code'=>200];
        }
        if ($method === 'editMessageText' && $status === 400
            && str_starts_with((string)($result['description'] ?? ''), 'Bad Request: message is not modified')) {
            return ['state'=>'sent','code'=>400];
        }
        if ($status === 429 && is_array($result)) {
            return ['state'=>'retry','code'=>429,'delay'=>max(1,min(86400,(int)($result['parameters']['retry_after'] ?? 60)))];
        }
        return ['state'=>$status >= 400 && $status < 500 ? 'failed' : 'uncertain','code'=>$status];
    }
}

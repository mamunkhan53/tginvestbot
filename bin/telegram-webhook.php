<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $client = new Novikov\Telegram\Client(getenv('TELEGRAM_BOT_TOKEN') ?: '');
    if (($argv[1] ?? '') === 'register') {
        $url = getenv('TELEGRAM_WEBHOOK_URL') ?: '';
        if (!filter_var($url,FILTER_VALIDATE_URL) || parse_url($url,PHP_URL_SCHEME) !== 'https'
            || parse_url($url,PHP_URL_USER) || parse_url($url,PHP_URL_PASS)) {
            throw new RuntimeException('Configure an HTTPS webhook URL.');
        }
        $secret = getenv('TELEGRAM_WEBHOOK_SECRET') ?: '';
        Novikov\Telegram\Update::authenticate($secret,$secret);
        $result = $client->call('setWebhook',[
            'url'=>$url,'secret_token'=>$secret,'allowed_updates'=>['message','callback_query'],
            'drop_pending_updates'=>false,
        ]);
        if ($result['state'] !== 'sent') throw new RuntimeException('Webhook registration failed.');
        echo "Webhook registered. Pending updates preserved.\n";
    } elseif (($argv[1] ?? '') === 'status') {
        $result = $client->call('getWebhookInfo',[]);
        if ($result['state'] !== 'sent') throw new RuntimeException('Webhook status unavailable.');
        $info = $result['result'];
        echo json_encode([
            'registered'=>!empty($info['url']),
            'pending_updates'=>(int)($info['pending_update_count'] ?? 0),
            'last_error_at'=>$info['last_error_date'] ?? null,
        ],JSON_THROW_ON_ERROR) . "\n";
    } else {
        fwrite(STDERR,"Usage: php novikov/bin/telegram-webhook.php register|status\n");
        exit(1);
    }
} catch (Throwable) {
    fwrite(STDERR,"Telegram operation failed. Check token, secret, HTTPS URL and network access. Sensitive details suppressed.\n");
    exit(1);
}

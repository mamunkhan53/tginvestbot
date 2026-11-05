<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo '{"error":"Method not allowed"}';
    exit;
}
try {
    Novikov\Telegram\Update::authenticate(
        getenv('TELEGRAM_WEBHOOK_SECRET') ?: '',
        $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''
    );
} catch (InvalidArgumentException) {
    http_response_code(403); echo '{"error":"Forbidden"}'; exit;
} catch (Throwable) {
    http_response_code(503); echo '{"error":"Webhook not configured"}'; exit;
}
try {
    $body = file_get_contents('php://input', false, null, 0, 65537);
    $update = Novikov\Telegram\Update::parse($body === false ? '' : $body);
} catch (InvalidArgumentException) {
    // Authenticated unsupported update types must not cause endless retries.
    http_response_code(200); echo '{"ok":true,"ignored":true}'; exit;
}
try {
    (new Novikov\Telegram\Inbox(Novikov\Database::connect()))->accept($update);
    echo '{"ok":true}';
} catch (Throwable) {
    http_response_code(503);
    echo '{"error":"Temporarily unavailable"}';
}

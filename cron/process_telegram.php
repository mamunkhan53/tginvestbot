<?php
declare(strict_types=1);
require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $client = new Novikov\Telegram\Client(getenv('TELEGRAM_BOT_TOKEN') ?: '');
    $result = (new Novikov\Telegram\Worker(Novikov\Database::connect(),$client))->run();
    echo json_encode($result,JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable) {
    fwrite(STDERR,"Telegram worker unavailable. Check configuration and migrations; sensitive details suppressed.\n");
    exit(1);
}

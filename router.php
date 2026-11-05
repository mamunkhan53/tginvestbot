<?php
declare(strict_types=1);
// Development server router. Only explicitly public CSS assets may bypass auth.
$base = rtrim(getenv('BASE_PATH') ?: '', '/');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$relative = substr((string)$path, strlen($base));
if (preg_match('~^/assets/[a-zA-Z0-9_.-]+\.css$~D', $relative)) {
    $file = __DIR__ . '/public' . $relative;
    if (is_file($file)) {
        header('Content-Type: text/css; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        return;
    }
}
require __DIR__ . '/public/index.php';

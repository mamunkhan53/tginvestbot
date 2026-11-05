<?php
declare(strict_types=1);

date_default_timezone_set('UTC');
spl_autoload_register(static function (string $class): void {
    $prefix = 'Novikov\\';
    if (str_starts_with($class, $prefix)) {
        $path = __DIR__ . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($path)) {
            require $path;
        }
    }
});

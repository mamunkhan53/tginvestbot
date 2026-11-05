<?php
declare(strict_types=1);
namespace Novikov\Telegram;

interface Api
{
    /** Return sent, retry, failed or uncertain, without exposing credentials. */
    public function call(string $method, array $payload): array;
}

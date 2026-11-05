<?php
declare(strict_types=1);
namespace Novikov;

final class Totp
{
    public static function secret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out = '';
        foreach (unpack('C*', random_bytes(32)) as $byte) $out .= $alphabet[$byte & 31];
        return $out;
    }

    public static function code(string $secret, int $step): string
    {
        $bits = '';
        foreach (str_split($secret) as $char) {
            $n = strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char);
            if ($n === false) throw new \RuntimeException('Invalid authenticator secret');
            $bits .= str_pad(decbin($n), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        for ($i = 0; $i + 8 <= strlen($bits); $i += 8) $key .= chr(bindec(substr($bits, $i, 8)));
        $hash = hash_hmac('sha1', pack('N2', 0, $step), $key, true);
        $offset = ord($hash[19]) & 15;
        $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string)($number % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, int $lastStep): ?int
    {
        if (!preg_match('/^\d{6}$/D', $code)) return null;
        $step = intdiv(time(), 30);
        for ($i = $step - 1; $i <= $step + 1; $i++) {
            if ($i > $lastStep && hash_equals(self::code($secret, $i), $code)) return $i;
        }
        return null;
    }
}

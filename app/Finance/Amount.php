<?php
declare(strict_types=1);
namespace Novikov\Finance;

use InvalidArgumentException;

final class Amount
{
    public const SCALE = 18;

    public static function positive(string $value): string
    {
        if (!preg_match('/^(0|[1-9][0-9]{0,17})(\.[0-9]{1,18})?$/D', $value)
            || bccomp($value, '0', self::SCALE) <= 0) {
            throw new InvalidArgumentException('Enter a positive decimal amount with at most 18 decimal places.');
        }
        return bcadd($value, '0', self::SCALE);
    }

    /** Entered amount is the net payout; fee is added to the deduction. */
    public static function withdrawal(string $net, string $rate = '1'): array
    {
        $net = self::positive($net);
        if (!preg_match('/^(0|[1-9][0-9]?|100)(\.[0-9]{1,4})?$/D', $rate)
            || bccomp($rate, '100', 4) > 0) {
            throw new InvalidArgumentException('Fee percentage must be between 0 and 100.');
        }
        $fee = bcdiv(bcmul($net, $rate, 22), '100', self::SCALE);
        return ['net' => $net, 'fee' => $fee, 'total' => bcadd($net, $fee, self::SCALE)];
    }
}

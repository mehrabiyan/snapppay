<?php
declare(strict_types=1);
namespace SnappPay;

final class Money
{
    // Conservative cap avoids all products overflowing signed 64-bit arithmetic.
    public const MAX = 1000000000000;

    public static function rials(string $value, string $currency): int
    {
        $scale = self::scale($currency);
        if (!preg_match('/^(0|[1-9][0-9]{0,12})(?:\.([0-9]{1,2}))?$/D', $value, $m)) {
            throw new Failure('invalid_amount');
        }
        $cents = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '', 2, '0');
        if ($cents % (100 / $scale) !== 0) {
            throw new Failure('fractional_rial');
        }
        $amount = intdiv($cents, (int) (100 / $scale));
        if ($amount > self::MAX) {
            throw new Failure('amount_overflow');
        }
        return $amount;
    }

    public static function decimal(int $rials, string $currency): string
    {
        $scale = self::scale($currency);
        return intdiv($rials, $scale) . '.' . str_pad((string) (($rials % $scale) * (100 / $scale)), 2, '0', STR_PAD_LEFT);
    }

    private static function scale(string $currency): int
    {
        if ($currency === 'IRR') {
            return 1;
        }
        if (in_array($currency, ['IRT', 'TMN'], true)) {
            return 10;
        }
        throw new Failure('unsupported_currency');
    }

    /** Largest remainder allocation using arbitrary precision, no floating point. */
    public static function allocate(int $total, array $weights): array
    {
        $sum = array_sum($weights);
        if ($total < 0 || $total > self::MAX || $sum <= 0 || $sum > self::MAX || !$weights) {
            throw new Failure('invalid_allocation');
        }
        $out = [];
        $remainders = [];
        foreach ($weights as $i => $weight) {
            if (!is_int($weight) || $weight < 0) {
                throw new Failure('invalid_allocation');
            }
            $product = bcmul((string) $total, (string) $weight, 0);
            $out[$i] = (int) bcdiv($product, (string) $sum, 0);
            $remainders[$i] = (int) bcmod($product, (string) $sum);
        }
        arsort($remainders, SORT_NUMERIC);
        $left = $total - array_sum($out);
        foreach ($remainders as $i => $unused) {
            if ($left-- <= 0) {
                break;
            }
            ++$out[$i];
        }
        return $out;
    }
}

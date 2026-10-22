<?php

namespace App\Services\Settlement;

use InvalidArgumentException;

class SettlementMath
{
    public static function asDecimal(mixed $value, int $scale = 12): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        $normalized = is_string($value) ? trim($value) : (string) $value;

        if ($normalized === '') {
            return '0';
        }

        $normalized = str_replace(',', '.', $normalized);

        if (! preg_match('/^-?\d+(\.\d+)?$/', $normalized)) {
            throw new InvalidArgumentException('Invalid numeric value provided for settlement calculation.');
        }

        return self::trim(self::normalizePrecision($normalized, $scale));
    }

    public static function add(mixed ...$values): string
    {
        $total = '0';

        foreach ($values as $value) {
            $total = bcadd($total, self::asDecimal($value), 12);
        }

        return self::trim($total);
    }

    public static function subtract(mixed $left, mixed $right): string
    {
        return self::trim(bcsub(self::asDecimal($left), self::asDecimal($right), 12));
    }

    public static function multiply(mixed $left, mixed $right): string
    {
        return self::trim(bcmul(self::asDecimal($left), self::asDecimal($right), 12));
    }

    public static function divide(mixed $left, mixed $right, int $scale = 12): string
    {
        if (self::asDecimal($right) === '0') {
            return '0';
        }

        return self::trim(bcdiv(self::asDecimal($left), self::asDecimal($right), $scale));
    }

    public static function roundMoney(mixed $value): string
    {
        $normalized = self::asDecimal($value, 12);
        $sign = '';

        if (str_starts_with($normalized, '-')) {
            $sign = '-';
            $normalized = substr($normalized, 1);
        }

        $scaled = self::asDecimal(bcmul($normalized, '100', 12), 12);
        $roundedCents = $scaled;

        if (str_contains($scaled, '.')) {
            [$whole, $fraction] = explode('.', $scaled, 2);
            $firstDigit = $fraction !== '' ? $fraction[0] : '0';

            if ($fraction !== '' && (int) $firstDigit >= 5) {
                $whole = bcadd($whole, '1', 0);
            }

            $roundedCents = $whole;
        }

        $roundedCents = ltrim($roundedCents, '0');
        if ($roundedCents === '') {
            $roundedCents = '0';
        }

        $formatted = $roundedCents;
        while (strlen($formatted) < 3) {
            $formatted = '0'.$formatted;
        }

        $dollars = substr($formatted, 0, -2) ?: '0';
        $cents = substr($formatted, -2);
        $money = $dollars.'.'.$cents;

        if ($sign === '-') {
            $money = '-'.$money;
        }

        return self::trim($money);
    }

    public static function compare(mixed $left, mixed $right): int
    {
        return bccomp(self::asDecimal($left), self::asDecimal($right), 12);
    }

    public static function minValue(mixed $left, mixed $right): string
    {
        return self::compare($left, $right) <= 0 ? self::asDecimal($left) : self::asDecimal($right);
    }

    public static function maxValue(mixed $left, mixed $right): string
    {
        return self::compare($left, $right) >= 0 ? self::asDecimal($left) : self::asDecimal($right);
    }

    public static function sumArray(array $values): string
    {
        $total = '0';

        foreach ($values as $value) {
            $total = bcadd($total, self::asDecimal($value), 12);
        }

        return self::trim($total);
    }

    protected static function normalizePrecision(string $value, int $scale): string
    {
        $parts = explode('.', $value);

        if (count($parts) === 1) {
            return $value;
        }

        $whole = $parts[0];
        $fraction = substr($parts[1], 0, $scale);

        return $whole.'.'.$fraction;
    }

    protected static function trim(string $value): string
    {
        if (! str_contains($value, '.')) {
            return $value === '' ? '0' : $value;
        }

        $value = rtrim(rtrim($value, '0'), '.');

        return $value === '' ? '0' : $value;
    }
}

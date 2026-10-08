<?php
declare(strict_types=1);

final class HttpRequestBudgetExceeded extends RuntimeException {}

/** A shared deadline for all upstream calls made during one scanner request. */
final class HttpRequestBudget
{
    private static ?float $deadline = null;

    public static function start(float $seconds): void
    {
        self::$deadline = hrtime(true) / 1e9 + $seconds;
    }

    public static function timeoutMs(int $maximumSeconds): int
    {
        if (self::$deadline === null) return $maximumSeconds * 1000;
        $remaining = (int) floor((self::$deadline - hrtime(true) / 1e9) * 1000);
        if ($remaining < 1) throw new HttpRequestBudgetExceeded('Analysis request deadline exceeded');
        return min($maximumSeconds * 1000, $remaining);
    }
}

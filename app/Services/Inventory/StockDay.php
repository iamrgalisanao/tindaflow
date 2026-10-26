<?php

namespace App\Services\Inventory;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The store's STOCK DAY (ADR-017 section 4, client decision 3): the date daily stock belongs to. It is the store-local calendar date of the
 * instant MINUS the store's cutover hour, so with a cutover of 5 the stock day runs from 05:00 to the next 05:00 and a sale at 04:59 still belongs
 * to yesterday's stock. The store's clock is the application timezone (Asia/Manila, which has no daylight saving time, so a stock day is
 * always exactly 24 hours); the instant is converted to it before the subtraction, whatever zone the caller's Carbon carries.
 *
 * It is a function of the clock only: it does not depend on any terminal's fiscal day, so two tills always agree and nothing depends on someone
 * closing a day. One class, used by checkout, the entry screens and the reports.
 */
final class StockDay
{
    /** The stock date, `Y-m-d`, of an instant. */
    public static function of(CarbonInterface $at, int $cutoverHour = 0): string
    {
        return CarbonImmutable::instance($at)->setTimezone(self::timezone())->subHours($cutoverHour)->toDateString();
    }

    /** The stock date right now. */
    public static function today(int $cutoverHour = 0): string
    {
        return self::of(CarbonImmutable::now(), $cutoverHour);
    }

    /** The instant the given stock date starts at, store-local. */
    public static function startsAt(string $stockDate, int $cutoverHour = 0): CarbonImmutable
    {
        return CarbonImmutable::parse($stockDate, self::timezone())->startOfDay()->addHours($cutoverHour);
    }

    private static function timezone(): string
    {
        return (string) config('app.timezone');
    }
}

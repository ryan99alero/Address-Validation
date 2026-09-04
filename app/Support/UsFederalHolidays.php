<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Minimal US federal-holiday calendar for the FedEx commitment "business days" denominator. Hand-
 * rolled deliberately: no holiday package is installed (composer has none), and the alternative —
 * counting only weekends — would overstate working days and inflate the per-day commitment metrics.
 * Covers the 11 federal holidays with weekend-observance shifting (Sat→Fri, Sun→Mon), which is how a
 * shipper counts non-operating days. Juneteenth applies from 2021 (when it became federal).
 */
class UsFederalHolidays
{
    /**
     * Count Mon–Fri days in [$from, $to] (inclusive) excluding observed federal holidays.
     */
    public static function businessDays(CarbonInterface $from, CarbonInterface $to): int
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        if ($end->lessThan($start)) {
            return 0;
        }

        $holidays = self::observedSet((int) $start->year, (int) $end->year);

        $count = 0;
        for ($cursor = $start->copy(); $cursor->lessThanOrEqualTo($end); $cursor->addDay()) {
            if (! $cursor->isWeekend() && ! isset($holidays[$cursor->format('Y-m-d')])) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Observed holiday dates (Y-m-d => true) for every year in the span.
     *
     * @return array<string, true>
     */
    public static function observedSet(int $fromYear, int $toYear): array
    {
        $set = [];
        for ($year = $fromYear; $year <= $toYear; $year++) {
            foreach (self::forYear($year) as $date) {
                $set[$date] = true;
            }
        }

        return $set;
    }

    /**
     * The 11 federal holidays for a year, with weekend observance applied to the fixed-date ones.
     *
     * @return array<int, string> Y-m-d strings
     */
    private static function forYear(int $year): array
    {
        $fixedObserved = array_map(
            static fn (Carbon $d): string => self::observed($d)->format('Y-m-d'),
            array_filter([
                Carbon::create($year, 1, 1),                            // New Year's Day
                Carbon::create($year, 7, 4),                            // Independence Day
                Carbon::create($year, 11, 11),                          // Veterans Day
                Carbon::create($year, 12, 25),                          // Christmas Day
                $year >= 2021 ? Carbon::create($year, 6, 19) : null,    // Juneteenth (federal from 2021)
            ]),
        );

        $floating = array_map(
            static fn (Carbon $d): string => $d->format('Y-m-d'),
            [
                Carbon::create($year, 1, 1)->nthOfMonth(3, Carbon::MONDAY),    // MLK Jr. Day
                Carbon::create($year, 2, 1)->nthOfMonth(3, Carbon::MONDAY),    // Washington's Birthday
                Carbon::create($year, 5, 1)->lastOfMonth(Carbon::MONDAY),      // Memorial Day
                Carbon::create($year, 9, 1)->nthOfMonth(1, Carbon::MONDAY),    // Labor Day
                Carbon::create($year, 10, 1)->nthOfMonth(2, Carbon::MONDAY),   // Columbus Day
                Carbon::create($year, 11, 1)->nthOfMonth(4, Carbon::THURSDAY), // Thanksgiving
            ],
        );

        return array_merge($fixedObserved, $floating);
    }

    /**
     * Shift a fixed-date holiday to its observed weekday: Saturday → Friday, Sunday → Monday.
     */
    private static function observed(Carbon $date): Carbon
    {
        return match ((int) $date->dayOfWeek) {
            Carbon::SATURDAY => $date->copy()->subDay(),
            Carbon::SUNDAY => $date->copy()->addDay(),
            default => $date,
        };
    }
}

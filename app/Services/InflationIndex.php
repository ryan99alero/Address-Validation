<?php

namespace App\Services;

/**
 * Converts nominal (as-billed) dollars to constant base-year dollars using
 * CPI-U annual averages, so fees can be compared across years in "real" terms.
 *
 * Note: general CPI is a rough proxy — it does NOT track fuel/shipping inflation
 * precisely. Rate-based metrics (% of base spend) are already inflation-neutral
 * and don't need this; it's most meaningful on flat per-occurrence fees.
 *
 * CPI-U annual averages (BLS) through 2024; 2025-2026 estimated.
 */
class InflationIndex
{
    public const BASE_YEAR = 2026;

    /**
     * @var array<int, float>
     */
    private const CPI = [
        2009 => 214.537,
        2010 => 218.056,
        2011 => 224.939,
        2012 => 229.594,
        2013 => 232.957,
        2014 => 236.736,
        2015 => 237.017,
        2016 => 240.007,
        2017 => 245.120,
        2018 => 251.107,
        2019 => 255.657,
        2020 => 258.811,
        2021 => 270.970,
        2022 => 292.655,
        2023 => 304.702,
        2024 => 313.689,
        2025 => 322.000,
        2026 => 330.000,
    ];

    public static function factor(int $year, int $base = self::BASE_YEAR): float
    {
        if (! isset(self::CPI[$year], self::CPI[$base])) {
            return 1.0;
        }

        return self::CPI[$base] / self::CPI[$year];
    }

    /**
     * SQL expression that scales a nominal amount in a given date column up to
     * base-year dollars (e.g. SUM(cc.amount * <this>)).
     */
    public static function sqlFactor(string $dateColumn): string
    {
        $cases = [];
        foreach (array_keys(self::CPI) as $year) {
            $cases[] = "WHEN {$year} THEN ".round(self::factor($year), 5);
        }

        return '(CASE YEAR('.$dateColumn.') '.implode(' ', $cases).' ELSE 1 END)';
    }
}

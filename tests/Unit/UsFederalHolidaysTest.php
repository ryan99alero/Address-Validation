<?php

use App\Support\UsFederalHolidays;
use Carbon\Carbon;

test('counts weekdays excluding federal holidays', function () {
    // Week of US Independence Day 2026: Jul 4 2026 is a Saturday → observed Friday Jul 3.
    // Mon Jun 29 – Fri Jul 3 = 5 weekdays, minus the observed holiday (Jul 3) = 4.
    $days = UsFederalHolidays::businessDays(Carbon::parse('2026-06-29'), Carbon::parse('2026-07-03'));

    expect($days)->toBe(4);
});

test('a plain full week is five business days', function () {
    // Mon Jun 8 – Sun Jun 14 2026, no holidays → 5 weekdays.
    expect(UsFederalHolidays::businessDays(Carbon::parse('2026-06-08'), Carbon::parse('2026-06-14')))->toBe(5);
});

test('reversed or empty range is zero', function () {
    expect(UsFederalHolidays::businessDays(Carbon::parse('2026-06-10'), Carbon::parse('2026-06-01')))->toBe(0);
});

test('Christmas 2027 (Saturday) is observed on Friday Dec 24', function () {
    // Dec 24 2027 is a Friday; being the observed Christmas it should be excluded.
    // Mon Dec 20 – Fri Dec 24 = 5 weekdays − 1 observed holiday = 4.
    expect(UsFederalHolidays::businessDays(Carbon::parse('2027-12-20'), Carbon::parse('2027-12-24')))->toBe(4);
});

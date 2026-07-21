<?php

use App\Services\Chargebacks\ChargebackPusher;

function narrow(array $rows, ?string $ref): array
{
    return (fn () => $this->narrowByShipDate($rows, $ref))->call(new ChargebackPusher);
}

test('a recycled tracking is narrowed to the shipment from the charge period', function () {
    // One UPS tracking recycled across 2013 / 2021 / 2026 under three different jobs.
    $rows = [
        ['job' => 'A2013', 'shipDate' => '2013-02-05 06:00:00', 'jobChargesOK' => false],
        ['job' => 'B2021', 'shipDate' => '2021-08-04 05:00:00', 'jobChargesOK' => false],
        ['job' => 'C2026', 'shipDate' => '2026-04-02 05:00:00', 'jobChargesOK' => false],
    ];

    $narrowed = narrow($rows, '2026-04-02'); // the charge's ship date

    expect($narrowed)->toHaveCount(1)
        ->and($narrowed[0]['job'])->toBe('C2026');
});

test('the invoice date (a few weeks after shipment) still selects the right period', function () {
    $rows = [
        ['job' => 'B2021', 'shipDate' => '2021-08-04'],
        ['job' => 'C2026', 'shipDate' => '2026-04-02'],
    ];

    expect(narrow($rows, '2026-04-18'))->toHaveCount(1) // invoice_date, 16 days after ship
        ->and(narrow($rows, '2026-04-18')[0]['job'])->toBe('C2026');
});

test('when nothing falls in the window, the full set is kept (never drop a resolvable charge)', function () {
    $rows = [
        ['job' => 'A2013', 'shipDate' => '2013-02-05', 'jobChargesOK' => true],
        ['job' => 'B2021', 'shipDate' => '2021-08-04', 'jobChargesOK' => true],
    ];

    expect(narrow($rows, '2026-04-02'))->toHaveCount(2);
});

test('narrowing is a no-op for a single match, a null reference, or missing carton dates', function () {
    expect(narrow([['job' => 'A', 'shipDate' => '2013-02-05']], '2026-04-02'))->toHaveCount(1)
        ->and(narrow([['job' => 'A', 'shipDate' => '2013'], ['job' => 'B', 'shipDate' => '2026']], null))->toHaveCount(2)
        ->and(narrow([['job' => 'A'], ['job' => 'B']], '2026-04-02'))->toHaveCount(2); // no shipDate → keep all
});

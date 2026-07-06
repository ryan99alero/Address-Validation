<?php

use App\Jobs\SyncInvoiceCartonCosts;
use App\Models\Carrier;
use App\Models\CartonCost;
use App\Services\Integrations\PaceApiClient;
use App\Services\Recoup\CartonCostSyncService;
use App\Services\Recoup\RecoupService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Cache::flush(); // coverage() is cached; keep tests independent
    foreach ([
        [RecoupService::CAT_BASE_TRANSPORT, 'Base Transportation'],
        [9, 'Weekly / Service Charge'],
    ] as [$id, $name]) {
        DB::table('charge_categories')->insert(['id' => $id, 'name' => $name, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->carrier = Carrier::factory()->create(['slug' => 'ups']);
    $this->invoiceId = DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $this->carrier->id,
        'filename' => 'inv.csv',
        'file_hash' => 'hash-'.uniqid(),
        'invoice_date' => now()->toDateString(), // recent — within the carton-sync window
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

function charge(int $invoiceId, int $carrierId, string $tracking, float $amount, ?string $service = null, ?int $categoryId = RecoupService::CAT_BASE_TRANSPORT): void
{
    // Default to a base-transportation line so the tracking counts as a real shipment; pass a
    // different category (e.g. 9 Weekly/Service) to model an account-level fee pseudo-tracking.
    DB::table('carrier_charges')->insert([
        'carrier_invoice_id' => $invoiceId,
        'carrier_id' => $carrierId,
        'tracking_number' => $tracking,
        'amount' => $amount,
        'service' => $service,
        'charge_category_id' => $categoryId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function carton(string $tracking, float $shipCost, ?string $customer = 'CUST1', ?string $job = 'JOB1'): CartonCost
{
    return CartonCost::create([
        'tracking_number' => $tracking,
        'ship_cost' => $shipCost,
        'ship_date' => '2026-06-01',
        'pace_job_number' => $job,
        'pace_customer_id' => $customer,
    ]);
}

test('recoup delta is invoiced total minus recorded ship cost', function () {
    // Carrier billed $10 base + $3 residential + $2 address correction = $15 on this tracking.
    // Ship Cost recorded at ship time was $10.50 => recoup $4.50.
    charge($this->invoiceId, $this->carrier->id, '1Z001', 10.00);
    charge($this->invoiceId, $this->carrier->id, '1Z001', 3.00);
    charge($this->invoiceId, $this->carrier->id, '1Z001', 2.00);
    carton('1Z001', 10.50);

    $candidates = app(RecoupService::class)->candidates();

    expect($candidates)->toHaveCount(1);
    $row = $candidates->first();
    expect($row->tracking_number)->toBe('1Z001')
        ->and($row->actual)->toBe(15.00)
        ->and($row->ship_cost)->toBe(10.50)
        ->and($row->delta)->toBe(4.50)
        ->and($row->pace_customer_id)->toBe('CUST1');
});

test('credits net against the actual so an overcharge that was refunded is not recouped', function () {
    // $12 billed then a $12 credit nets to $0 actual; ship cost $10 => delta −$10, not a candidate.
    charge($this->invoiceId, $this->carrier->id, '1Z002', 12.00);
    charge($this->invoiceId, $this->carrier->id, '1Z002', -12.00);
    carton('1Z002', 10.00);

    expect(app(RecoupService::class)->candidates())->toHaveCount(0);
});

test('cartons at or below ship cost are not candidates', function () {
    charge($this->invoiceId, $this->carrier->id, '1Z003', 9.99);
    carton('1Z003', 10.00);

    expect(app(RecoupService::class)->candidates())->toHaveCount(0);
});

test('cartons with no recorded cost (0) are excluded — no valid baseline', function () {
    charge($this->invoiceId, $this->carrier->id, '1Z005', 118.27);
    carton('1Z005', 0.00);

    expect(app(RecoupService::class)->candidates())->toHaveCount(0);
});

test('already recouped cartons are excluded', function () {
    charge($this->invoiceId, $this->carrier->id, '1Z004', 20.00);
    carton('1Z004', 10.00)->update(['recouped_at' => now()]);

    expect(app(RecoupService::class)->candidates())->toHaveCount(0);
});

test('summary by customer sums recoupable and orders largest first', function () {
    charge($this->invoiceId, $this->carrier->id, '1Z010', 30.00); // +20
    carton('1Z010', 10.00, 'BIG');
    charge($this->invoiceId, $this->carrier->id, '1Z011', 15.00); // +5
    carton('1Z011', 10.00, 'SMALL');
    charge($this->invoiceId, $this->carrier->id, '1Z012', 13.00); // +3
    carton('1Z012', 10.00, 'BIG');

    $summary = app(RecoupService::class)->summaryByCustomer();

    expect($summary->first()->pace_customer_id)->toBe('BIG')
        ->and($summary->first()->cartons)->toBe(2)
        ->and($summary->first()->recoupable)->toBe(23.00)
        ->and(app(RecoupService::class)->totalRecoupable())->toBe(28.00);
});

test('coverage counts outbound matched vs unmatched and excludes vendor shipments', function () {
    charge($this->invoiceId, $this->carrier->id, '1ZA', 30.00, 'Ground Commercial Package'); // outbound, matched
    carton('1ZA', 10.00);
    charge($this->invoiceId, $this->carrier->id, '1ZB', 20.00, 'Ground Residential');          // outbound, unmatched
    charge($this->invoiceId, $this->carrier->id, '1ZC', 50.00, 'Ground Commercial Collect');    // vendor — excluded
    charge($this->invoiceId, $this->carrier->id, '1ZD', 40.00, 'Ground Commercial Third Party'); // vendor — excluded

    $cov = app(RecoupService::class)->coverage();

    expect($cov->total)->toBe(2)
        ->and($cov->matched)->toBe(1)
        ->and($cov->unmatched)->toBe(1)
        ->and($cov->pct)->toBe(50.0);
});

test('unmatchedTrackings excludes vendor Collect and Third-Party shipments', function () {
    charge($this->invoiceId, $this->carrier->id, '1ZB', 20.00, 'Ground Residential');
    charge($this->invoiceId, $this->carrier->id, '1ZC', 50.00, 'Ground Commercial Collect');
    charge($this->invoiceId, $this->carrier->id, '1ZD', 40.00, 'Ground Commercial Third Party');

    expect(app(RecoupService::class)->unmatchedTrackings()->pluck('tracking_number')->all())->toBe(['1ZB']);
});

test('coverage and unmatched exclude fee-only pseudo-trackings with no base transport', function () {
    // A real outbound shipment (base-transport charge) with no carton yet.
    charge($this->invoiceId, $this->carrier->id, '1ZSHIP', 30.00, 'Ground Residential');
    // FedEx account-level fee (Regularly Scheduled Pickup) on a pseudo tracking — only a
    // Weekly/Service charge, no base transport, never a carton. Not a shipment.
    charge($this->invoiceId, $this->carrier->id, '000004598785', 35.50, null, 9);

    $cov = app(RecoupService::class)->coverage();

    expect($cov->total)->toBe(1)
        ->and($cov->unmatched)->toBe(1)
        ->and(app(RecoupService::class)->unmatchedTrackings()->pluck('tracking_number')->all())->toBe(['1ZSHIP']);
});

test('unmatched trackings surface charges with no carton', function () {
    charge($this->invoiceId, $this->carrier->id, '1Z020', 25.00);
    charge($this->invoiceId, $this->carrier->id, '1Z021', 40.00);
    carton('1Z020', 10.00); // only 1Z020 has a carton

    $unmatched = app(RecoupService::class)->unmatchedTrackings();

    expect($unmatched)->toHaveCount(1)
        ->and($unmatched->first()->tracking_number)->toBe('1Z021')
        ->and($unmatched->first()->actual)->toBe(40.00);
});

test('sync upsert writes carton rows and updates on repeat', function () {
    $sync = app(CartonCostSyncService::class);

    $written = $sync->upsert([
        ['tracking_number' => '1Z100', 'ship_cost' => 12.34, 'ship_date' => '2026-06-01', 'pace_job_number' => 'J1', 'pace_customer_id' => 'C1'],
        ['tracking_number' => '', 'ship_cost' => 9], // skipped (blank tracking)
    ]);

    expect($written)->toBe(1);
    expect(CartonCost::where('tracking_number', '1Z100')->value('ship_cost'))->toBe('12.34');

    $sync->upsert([['tracking_number' => '1Z100', 'ship_cost' => 20.00]]);

    expect(CartonCost::count())->toBe(1)
        ->and(CartonCost::where('tracking_number', '1Z100')->value('ship_cost'))->toBe('20.00');
});

test('pending tracking numbers exclude already-synced cartons', function () {
    charge($this->invoiceId, $this->carrier->id, '1Z200', 10.00);
    charge($this->invoiceId, $this->carrier->id, '1Z201', 10.00);
    carton('1Z200', 8.00);

    expect(app(CartonCostSyncService::class)->pendingTrackingNumbers())->toBe(['1Z201']);
});

test('pending tracking numbers exclude invoices older than the recoup window', function () {
    $oldInvoiceId = DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $this->carrier->id,
        'filename' => 'old.csv',
        'file_hash' => 'old-'.uniqid(),
        'invoice_date' => now()->subYears(3)->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    charge($oldInvoiceId, $this->carrier->id, '1ZOLD', 50.00);
    charge($this->invoiceId, $this->carrier->id, '1ZNEW', 30.00); // recent (beforeEach invoice)

    $pending = app(CartonCostSyncService::class)->pendingTrackingNumbers();
    expect($pending)->toContain('1ZNEW')->not->toContain('1ZOLD');
});

test('mapCartonRow converts a Carbon ship date to a plain date string', function () {
    $row = app(CartonCostSyncService::class)->mapCartonRow([
        'tracking_number' => '1Z',
        'ship_cost' => 5,
        'ship_date' => Carbon::parse('2026-06-02 13:45:00'),
    ]);

    expect($row['ship_date'])->toBe('2026-06-02');
});

test('syncFromPace maps carton value objects from Pace and upserts them', function () {
    charge($this->invoiceId, $this->carrier->id, '1ZP01', 15.00);

    $client = Mockery::mock(PaceApiClient::class);
    $client->shouldReceive('loadValueObjects')->once()->andReturn(['valueObjects' => [['stub']]]);
    $client->shouldReceive('parseValueObjects')->once()->andReturn(collect([
        ['tracking_number' => '1ZP01', 'ship_cost' => 10.00, 'ship_date' => Carbon::parse('2026-06-01'), 'pace_job_number' => 'J7', 'pace_customer_id' => 'C9'],
    ]));

    $written = app(CartonCostSyncService::class)->syncFromPace($client);

    expect($written)->toBe(1);
    $carton = CartonCost::where('tracking_number', '1ZP01')->first();
    expect($carton->ship_cost)->toBe('10.00')
        ->and($carton->ship_date->toDateString())->toBe('2026-06-01')
        ->and($carton->pace_job_number)->toBe('J7')
        ->and($carton->pace_customer_id)->toBe('C9');

    // And it now becomes a recoup candidate: $15 billed − $10 ship cost = $5.
    expect(app(RecoupService::class)->totalRecoupable())->toBe(5.00);
});

test('upsert keeps the latest carton per recycled tracking number', function () {
    // UPS recycles tracking numbers — Pace returns several cartons for one number across years.
    // Only the most recent (the current shipment) should survive.
    $written = app(CartonCostSyncService::class)->upsert([
        ['tracking_number' => '1Z9', 'ship_cost' => null, 'ship_date' => '2013-12-04', 'pace_job_number' => 'OLD', 'pace_customer_id' => 'X'],
        ['tracking_number' => '1Z9', 'ship_cost' => 18.94, 'ship_date' => '2026-07-02', 'pace_job_number' => 'M254402', 'pace_customer_id' => 'WP1200'],
        ['tracking_number' => '1Z9', 'ship_cost' => 0, 'ship_date' => '2021-11-10', 'pace_job_number' => 'MID', 'pace_customer_id' => 'Y'],
    ]);

    expect($written)->toBe(1)
        ->and(CartonCost::count())->toBe(1);
    $carton = CartonCost::where('tracking_number', '1Z9')->first();
    expect($carton->ship_cost)->toBe('18.94')
        ->and($carton->ship_date->toDateString())->toBe('2026-07-02')
        ->and($carton->pace_job_number)->toBe('M254402')
        ->and($carton->pace_customer_id)->toBe('WP1200');
});

test('SyncInvoiceCartonCosts syncs the distinct tracking numbers of the given invoices', function () {
    charge($this->invoiceId, $this->carrier->id, '1ZA', 10.00);
    charge($this->invoiceId, $this->carrier->id, '1ZA', 5.00); // duplicate tracking on same invoice
    charge($this->invoiceId, $this->carrier->id, '1ZB', 8.00);

    $mock = Mockery::mock(CartonCostSyncService::class);
    $mock->shouldReceive('syncTrackings')
        ->once()
        ->withArgs(function (array $trackings): bool {
            sort($trackings);

            return $trackings === ['1ZA', '1ZB'];
        })
        ->andReturn(2);

    (new SyncInvoiceCartonCosts([$this->invoiceId]))->handle($mock);
});

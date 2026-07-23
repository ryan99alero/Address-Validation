<?php

use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Models\ChargebackPush;
use App\Models\IntegrationConnection;
use App\Services\Chargebacks\ChargebackPusher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('the backfill enriches FedEx 2026 chargeback rows from the Carton lookup', function () {
    IntegrationConnection::create(['driver' => 'pace', 'name' => 'Pace']);
    $fedex = Carrier::factory()->create(['slug' => 'fedex']);
    $ups = Carrier::factory()->create(['slug' => 'ups']);

    $invoice2026 = CarrierInvoice::create(['carrier_id' => $fedex->id, 'invoice_number' => 'F2026', 'invoice_date' => '2026-03-01', 'status' => 'completed']);
    $invoice2025 = CarrierInvoice::create(['carrier_id' => $fedex->id, 'invoice_number' => 'F2025', 'invoice_date' => '2025-03-01', 'status' => 'completed']);

    $target = ChargebackPush::create(['dedupe_key' => 'a', 'carrier_id' => $fedex->id, 'carrier_invoice_id' => $invoice2026->id, 'tracking_number' => 'FX1', 'amount' => 9.10, 'status' => 'skipped_job_closed', 'ship_date' => '2026-03-02']);
    $wrongYear = ChargebackPush::create(['dedupe_key' => 'b', 'carrier_id' => $fedex->id, 'carrier_invoice_id' => $invoice2025->id, 'tracking_number' => 'FX2', 'amount' => 9.10, 'status' => 'skipped_job_closed']);
    $wrongCarrier = ChargebackPush::create(['dedupe_key' => 'c', 'carrier_id' => $ups->id, 'carrier_invoice_id' => null, 'tracking_number' => 'UP1', 'amount' => 9.10, 'status' => 'skipped_job_closed']);

    // Mock only the Pace query; repShipment/enrichmentFrom are static and run for real.
    $pusher = Mockery::mock(ChargebackPusher::class);
    $pusher->shouldReceive('lookupJobShipments')->andReturn([
        ['job' => 'J9', 'jobChargesOK' => false, 'customer' => '3035',
            'customerName' => 'KUBOTA - SOURCING GROUP', 'csrName' => 'HEATHER', 'salespersonName' => 'RANDALL V'],
    ]);
    $this->app->instance(ChargebackPusher::class, $pusher);

    $this->artisan('chargebacks:backfill-reps --carrier=fedex --year=2026')->assertSuccessful();

    expect($target->refresh()->pace_customer_name)->toBe('KUBOTA - SOURCING GROUP')
        ->and($target->pace_csr_name)->toBe('HEATHER')
        ->and($target->pace_salesperson_name)->toBe('RANDALL V')
        ->and($target->pace_customer_id)->toBe('3035');

    // Scope respected: a 2025 FedEx row and a UPS row are untouched.
    expect($wrongYear->refresh()->pace_csr_name)->toBeNull()
        ->and($wrongCarrier->refresh()->pace_csr_name)->toBeNull();
});

<?php

use App\Models\Carrier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function billingShipment(int $carrierId, int $invoiceId, array $attrs): int
{
    return DB::table('carrier_shipments')->insertGetId(array_merge([
        'carrier_id' => $carrierId, 'carrier_invoice_id' => $invoiceId, 'source_type' => 'pdf',
        'is_third_party' => false, 'created_at' => now(), 'updated_at' => now(),
    ], $attrs));
}

test('backfill classifies UPS + FedEx shipments into prepaid / collect / third_party', function () {
    $ups = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $fx = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);
    $upsInv = DB::table('carrier_invoices')->insertGetId(['carrier_id' => $ups->id, 'invoice_number' => 'U1', 'invoice_date' => '2026-01-03', 'created_at' => now(), 'updated_at' => now()]);
    $fxInv = DB::table('carrier_invoices')->insertGetId(['carrier_id' => $fx->id, 'invoice_number' => 'F1', 'invoice_date' => '2026-01-03', 'created_at' => now(), 'updated_at' => now()]);

    $ids = [
        'ups_prepaid' => billingShipment($ups->id, $upsInv, ['tracking_number' => 'U-OUT', 'section' => 'outbound']),
        'ups_collect' => billingShipment($ups->id, $upsInv, ['tracking_number' => 'U-IN', 'section' => 'inbound']),
        'ups_third' => billingShipment($ups->id, $upsInv, ['tracking_number' => 'U-3P', 'section' => 'outbound', 'is_third_party' => true]),
        'fx_collect' => billingShipment($fx->id, $fxInv, ['tracking_number' => 'F-COL', 'service' => 'Collect, Domestic']),
        'fx_prepaid' => billingShipment($fx->id, $fxInv, ['tracking_number' => 'F-PPD', 'service' => 'Ppd, Domestic']),
        'fx_third_bill' => billingShipment($fx->id, $fxInv, ['tracking_number' => 'F-3PB', 'service' => 'Bill 3rd Party, Dom']),
        'fx_real_svc' => billingShipment($fx->id, $fxInv, ['tracking_number' => 'F-2DAY', 'service' => 'FedEx 2Day']),
    ];

    $this->artisan('carrier:backfill-billing-type')->assertSuccessful();

    $bt = fn (int $id): ?string => DB::table('carrier_shipments')->where('id', $id)->value('billing_type');

    expect($bt($ids['ups_prepaid']))->toBe('prepaid')
        ->and($bt($ids['ups_collect']))->toBe('collect')
        ->and($bt($ids['ups_third']))->toBe('third_party')
        ->and($bt($ids['fx_collect']))->toBe('collect')
        ->and($bt($ids['fx_prepaid']))->toBe('prepaid')
        ->and($bt($ids['fx_third_bill']))->toBe('third_party')
        ->and($bt($ids['fx_real_svc']))->toBe('prepaid');
});

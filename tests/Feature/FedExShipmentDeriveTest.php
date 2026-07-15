<?php

use App\Models\Carrier;
use App\Models\CarrierCharge;
use App\Models\CarrierInvoice;
use App\Models\CarrierShipment;
use App\Models\ChargeCategory;
use App\Services\Invoices\FedExShipmentDeriveService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->carrier = Carrier::factory()->create(['slug' => 'fedex']);
    $this->invoice = CarrierInvoice::create([
        'carrier_id' => $this->carrier->id, 'invoice_number' => 'F1',
        'invoice_date' => '2026-05-07', 'filename' => 'f.csv',
    ]);
    $this->base = ChargeCategory::create(['name' => 'Base Transportation', 'abbreviation' => 'BASE']);
    $this->fuel = ChargeCategory::create(['name' => 'Fuel Surcharge', 'abbreviation' => 'FUEL']);
});

function fdxCharge(string $tracking, int $categoryId, float $amount, array $extra = []): void
{
    CarrierCharge::create(array_merge([
        'carrier_invoice_id' => test()->invoice->id, 'carrier_id' => test()->carrier->id,
        'tracking_number' => $tracking, 'charge_category_id' => $categoryId,
        'amount' => $amount, 'source_type' => 'pdf',
    ], $extra));
}

it('derives one shipment per tracking with total + billing type from the charges', function () {
    // T1: base + fuel = on-account (has a base charge). Service/weight/zone from lines.
    fdxCharge('T1', $this->base->id, 10, ['service' => 'FedEx Ground', 'weight' => 5, 'zone' => '5', 'ship_date' => '2026-05-01']);
    fdxCharge('T1', $this->fuel->id, 2);
    // T2: fuel only = third-party (no base charge).
    fdxCharge('T2', $this->fuel->id, 3, ['service' => 'FedEx 2Day']);

    $count = app(FedExShipmentDeriveService::class)->deriveForInvoice($this->invoice);

    expect($count)->toBe(2);

    $t1 = CarrierShipment::where('tracking_number', 'T1')->first();
    expect((float) $t1->printed_total)->toBe(12.0)
        ->and((float) $t1->base_amount)->toBe(10.0)
        ->and((float) $t1->fee_amount)->toBe(2.0)
        ->and($t1->fee_abbrevs)->toBe('FUEL')
        ->and($t1->is_third_party)->toBeFalse()
        ->and($t1->service)->toBe('FedEx Ground')
        ->and((float) $t1->billed_weight)->toBe(5.0)
        ->and($t1->carrier_invoice_id)->toBe($this->invoice->id);

    $t2 = CarrierShipment::where('tracking_number', 'T2')->first();
    expect((float) $t2->printed_total)->toBe(3.0)
        ->and($t2->is_third_party)->toBeTrue();
});

it('enriches existing (UPS PDF) shipments with the cost split, without duplicating', function () {
    CarrierShipment::insert([
        'carrier_invoice_id' => $this->invoice->id, 'carrier_id' => $this->carrier->id,
        'tracking_number' => 'U1', 'source_type' => 'pdf', 'is_third_party' => false, 'printed_total' => 15,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    fdxCharge('U1', $this->base->id, 12);
    fdxCharge('U1', $this->fuel->id, 3);

    $updated = app(FedExShipmentDeriveService::class)->enrichCostsForInvoice($this->invoice);

    $u1 = CarrierShipment::where('tracking_number', 'U1')->first();
    expect($updated)->toBe(1)
        ->and((float) $u1->base_amount)->toBe(12.0)
        ->and((float) $u1->fee_amount)->toBe(3.0)
        ->and($u1->fee_abbrevs)->toBe('FUEL')
        ->and(CarrierShipment::where('tracking_number', 'U1')->count())->toBe(1); // no new row
});

it('is idempotent and never touches non-derived (UPS PDF) shipments', function () {
    // A UPS PDF-extracted shipment on the same invoice — must survive.
    CarrierShipment::insert([
        'carrier_invoice_id' => $this->invoice->id, 'carrier_id' => $this->carrier->id,
        'tracking_number' => 'UPS_PDF', 'source_type' => 'pdf', 'is_third_party' => false,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    fdxCharge('T1', $this->fuel->id, 3);

    $svc = app(FedExShipmentDeriveService::class);
    $svc->deriveForInvoice($this->invoice);
    $svc->deriveForInvoice($this->invoice); // re-run

    expect(CarrierShipment::where('source_type', 'derived')->count())->toBe(1)     // no dupes
        ->and(CarrierShipment::where('tracking_number', 'UPS_PDF')->exists())->toBeTrue(); // untouched
});

<?php

use App\Filament\Widgets\ShippingHeatmap;
use App\Models\Carrier;
use App\Models\CarrierImportFile;
use App\Models\FolderIntegration;
use App\Models\User;
use App\Services\Analytics\HeatmapService;
use App\Services\CarrierInvoiceParserService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->ups = Carrier::factory()->create(['slug' => 'ups', 'name' => 'UPS']);
    $this->fedex = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);

    DB::table('zip_centroids')->insert([
        ['zip' => '78701', 'lat' => 30.271, 'lng' => -97.742, 'city' => 'Austin', 'state' => 'TX'],
        ['zip' => '90001', 'lat' => 33.973, 'lng' => -118.249, 'city' => 'Los Angeles', 'state' => 'CA'],
    ]);

    $this->upsInv = DB::table('carrier_invoices')->insertGetId(['carrier_id' => $this->ups->id, 'invoice_number' => 'U1', 'invoice_date' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);
    $this->fedexInv = DB::table('carrier_invoices')->insertGetId(['carrier_id' => $this->fedex->id, 'invoice_number' => 'F1', 'invoice_date' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);
});

function seedShipment(int $invId, int $carrierId, ?string $zip, string $shipDate): void
{
    DB::table('carrier_shipments')->insert([
        'carrier_invoice_id' => $invId, 'carrier_id' => $carrierId, 'zip' => $zip, 'ship_date' => $shipDate,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Write a minimal FedEx invoice CSV (named + positional columns the parser needs) and return its path. */
function makeFedexInvoiceCsv(): string
{
    $header = array_fill(0, 110, '');
    $header[1] = 'Bill to Account Number';
    $header[2] = 'Invoice Date';
    $header[3] = 'Invoice Number';
    $header[9] = 'Express or Ground Tracking ID';
    $header[10] = 'Transportation Charge Amount';
    $header[21] = 'Rated Weight Amount';
    $header[33] = 'Recipient Name';
    $header[35] = 'Recipient Address Line 1';
    $header[37] = 'Recipient City';
    $header[38] = 'Recipient State';
    $header[39] = 'Recipient Zip Code';
    $header[50] = 'Service Type';
    $header[60] = 'Ship Date';
    $header[107] = 'Tracking ID Charge Description';
    $header[108] = 'Tracking ID Charge Amount';

    $row = array_fill(0, 110, '');
    $row[1] = 'ACCT1';
    $row[2] = '01/15/2026';
    $row[3] = '990000001';
    $row[9] = '794600000001';
    $row[10] = '12.50';
    $row[21] = '5.0';
    $row[33] = 'Acme Co';
    $row[35] = '123 Main St';
    $row[37] = 'Austin';
    $row[38] = 'TX';
    $row[39] = '78701';
    $row[50] = 'FedEx Ground';
    $row[60] = '01/10/2026';
    $row[107] = 'Fuel Surcharge';
    $row[108] = '2.00';

    $path = tempnam(sys_get_temp_dir(), 'fx_').'.csv';
    $fh = fopen($path, 'w');
    fputcsv($fh, $header, ',', '"', '');
    fputcsv($fh, $row, ',', '"', '');
    fclose($fh);

    return $path;
}

test('shipments aggregate by ZIP centroid; ZIP+4 joins on the 5-digit prefix, non-US drops to unmatched', function () {
    seedShipment($this->upsInv, $this->ups->id, '78701', '2026-03-01');
    seedShipment($this->upsInv, $this->ups->id, '78701-1234', '2026-03-02'); // ZIP+4 → same centroid
    seedShipment($this->upsInv, $this->ups->id, '78701', '2026-03-03');
    seedShipment($this->upsInv, $this->ups->id, '90001', '2026-03-04');
    seedShipment($this->upsInv, $this->ups->id, 'K1A0B1', '2026-03-05');   // Canadian → no US centroid

    $result = app(HeatmapService::class)->shipments(2026, null);

    expect($result['meta']['matched'])->toBe(4)     // 3 in 78701 + 1 in 90001
        ->and($result['meta']['total'])->toBe(5)    // all five carry a ZIP
        ->and($result['meta']['unmatched'])->toBe(1) // the Canadian one
        ->and($result['meta']['max'])->toBe(3.0)    // busiest ZIP = 78701 (×3)
        ->and($result['points'])->toHaveCount(2);

    $austin = collect($result['points'])->firstWhere(0, 30.271);
    expect($austin[2])->toBe(3); // weight = shipment count in 78701
});

test('the period filter constrains shipments to the selected year/month', function () {
    seedShipment($this->upsInv, $this->ups->id, '78701', '2026-06-15');
    seedShipment($this->upsInv, $this->ups->id, '78701', '2025-06-15');

    expect(app(HeatmapService::class)->shipments(2026, null)['meta']['matched'])->toBe(1)
        ->and(app(HeatmapService::class)->shipments(2026, 6)['meta']['matched'])->toBe(1)
        ->and(app(HeatmapService::class)->shipments(2026, 7)['meta']['matched'])->toBe(0);
});

test('corrections split by carrier, filtered by INVOICE date (not the unreliable line ship_date)', function () {
    // Both UPS invoices are dated 2026 (see beforeEach); the line ship_dates are deliberately stale/
    // null — the map must still count them in 2026 via the invoice date.
    DB::table('carrier_invoice_lines')->insert([
        ['carrier_invoice_id' => $this->upsInv, 'tracking_number' => 'U1', 'original_postal' => '78701', 'ship_date' => '2013-05-01', 'created_at' => now(), 'updated_at' => now()],
        ['carrier_invoice_id' => $this->upsInv, 'tracking_number' => 'U2', 'original_postal' => '78701', 'ship_date' => null, 'created_at' => now(), 'updated_at' => now()],
        ['carrier_invoice_id' => $this->fedexInv, 'tracking_number' => 'F1', 'original_postal' => '90001', 'ship_date' => '2026-02-01', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $svc = app(HeatmapService::class);
    expect($svc->corrections(2026, null, 'ups')['meta']['matched'])->toBe(2)   // both, despite stale/null ship_date
        ->and($svc->corrections(2026, null, 'fedex')['meta']['matched'])->toBe(1)
        ->and($svc->corrections(2026, null, null)['meta']['matched'])->toBe(3);
});

test('the shipping heatmap widget renders server-side with its heading (tile is decluttered)', function () {
    $this->actingAs(User::factory()->create());
    seedShipment($this->upsInv, $this->ups->id, '78701', '2026-05-01');

    Livewire::test(ShippingHeatmap::class)
        ->assertOk()
        ->assertSee('Shipping Destinations')
        // The subtitle + the density/ZIP stats block are hidden on the map tile now.
        ->assertDontSee('busiest ZIP');
});

test('FedEx CSV import persists shipments (dest ZIP + service) into carrier_shipments', function () {
    app(CarrierInvoiceParserService::class)->importFedExCsv($this->fedex->id, makeFedexInvoiceCsv());

    $s = DB::table('carrier_shipments')->where('tracking_number', '794600000001')->first();
    expect($s)->not->toBeNull();
    expect((int) $s->carrier_id)->toBe($this->fedex->id)
        ->and($s->zip)->toBe('78701')
        ->and($s->service)->toBe('FedEx Ground')
        ->and($s->source_type)->toBe('csv');
});

test('fedex:backfill-shipments re-imports a local source file into carrier_shipments', function () {
    $csv = makeFedexInvoiceCsv();
    $folder = FolderIntegration::create([
        'name' => 'FX', 'carrier_id' => $this->fedex->id, 'connection_type' => 'local',
        'base_path' => dirname($csv), 'is_active' => true,
    ]);
    CarrierImportFile::create([
        'carrier_id' => $this->fedex->id, 'folder_integration_id' => $folder->id,
        'file_hash' => hash('sha256', $csv), 'filename' => 'inv.csv', 'source_reference' => $csv,
    ]);

    $this->artisan('fedex:backfill-shipments')->assertSuccessful();

    $s = DB::table('carrier_shipments')->where('tracking_number', '794600000001')->first();
    expect($s)->not->toBeNull();
    expect($s->zip)->toBe('78701')->and($s->service)->toBe('FedEx Ground');
});

test('the zipcentroids:import command parses a GeoNames file and is re-runnable', function () {
    $path = tempnam(sys_get_temp_dir(), 'us_').'.txt';
    // country, postal, place, admin1, state, ...(4-8)..., lat(9), lng(10), acc(11)
    file_put_contents($path, implode("\n", [
        "US\t10001\tNew York\tNew York\tNY\t\t\t\t\t40.7506\t-73.9971\t4",
        "US\t10001\tNew York Dup\tNew York\tNY\t\t\t\t\t40.7\t-73.9\t4", // dup ZIP → ignored
        "US\tABCDE\tBad\tX\tXX\t\t\t\t\t1\t2\t4",                         // non-5-digit → skipped
        "US\t60601\tChicago\tIllinois\tIL\t\t\t\t\t41.8853\t-87.6216\t4",
    ]));

    $this->artisan('zipcentroids:import', ['--file' => $path])->assertSuccessful();
    $this->artisan('zipcentroids:import', ['--file' => $path])->assertSuccessful();

    expect(DB::table('zip_centroids')->where('zip', '10001')->count())->toBe(1)  // idempotent, not duplicated
        ->and(DB::table('zip_centroids')->where('zip', '60601')->count())->toBe(1)
        ->and(DB::table('zip_centroids')->where('zip', 'ABCDE')->exists())->toBeFalse() // non-5-digit skipped
        ->and(DB::table('zip_centroids')->where('zip', '10001')->value('state'))->toBe('NY');
});

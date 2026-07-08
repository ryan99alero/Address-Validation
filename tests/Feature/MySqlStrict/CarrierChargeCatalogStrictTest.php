<?php

/**
 * STRICT-MYSQL GUARD. Our normal suite runs on SQLite, which does NOT enforce reserved words or
 * ONLY_FULL_GROUP_BY — so grouped/selectRaw Filament tables can pass tests then 500 on prod MySQL
 * (this happened three times with the Charge Catalog). This group exercises the ACTUAL Filament
 * table pipeline (sort/search/filter, which emit the real SQL incl. the pagination key tie-break)
 * against a scratch MySQL `_test` database, so strict-mode violations fail here, not in production.
 *
 * One-time setup on the local/dev MySQL (3307):  CREATE DATABASE address_validation_test;
 * Run:  DB_CONNECTION=mysql DB_DATABASE=address_validation_test php artisan test --group=mysql-strict
 * Normal `php artisan test` (sqlite) skips it.
 */

use App\Filament\Pages\CarrierChargeCatalog;
use App\Models\Carrier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class)->group('mysql-strict');

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Runs only against a scratch MySQL database (--group=mysql-strict with DB_CONNECTION=mysql).');
    }
    // Never let RefreshDatabase wipe a real DB — only a *_test database.
    expect(DB::connection()->getDatabaseName())->toEndWith('_test');
    // Force prod's strict mode even if config strictness is ever relaxed.
    DB::statement("SET SESSION sql_mode = CONCAT(@@sql_mode, ',ONLY_FULL_GROUP_BY')");
});

it('renders, sorts, searches and filters the Charge Catalog on strict MySQL', function () {
    $ups = Carrier::factory()->create(['name' => 'UPS', 'slug' => 'ups']);
    $catId = DB::table('charge_categories')->insertGetId(['name' => 'Fuel Surcharge', 'abbreviation' => 'FUEL', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $invoiceId = DB::table('carrier_invoices')->insertGetId(['carrier_id' => $ups->id, 'invoice_number' => 'INV-1', 'invoice_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
    foreach ([['Fuel Surcharge', $catId, 3.29], ['Residential Surcharge', null, 4.13], ['Ground Commercial', $catId, 12.28]] as [$desc, $cat, $amt]) {
        foreach (range(1, 3) as $_) {
            DB::table('carrier_charges')->insert(['carrier_id' => $ups->id, 'carrier_invoice_id' => $invoiceId, 'raw_charge_description' => $desc, 'charge_category_id' => $cat, 'driver' => 'normal', 'amount' => $amt, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    $this->actingAs(User::factory()->create());

    Livewire::test(CarrierChargeCatalog::class)
        ->assertOk()
        ->sortTable('total', 'desc')->assertOk()   // ORDER BY total + key tie-break — the 1055 case
        ->sortTable('line_count')->assertOk()
        ->sortTable('carrier')->assertOk()
        ->searchTable('Residential')->assertOk()    // WHERE on the derived description column
        ->filterTable('carrier_id', $ups->id)->assertOk()
        ->assertSee('Residential Surcharge')
        ->assertSee('UNMAPPED');                    // the null-category row
});

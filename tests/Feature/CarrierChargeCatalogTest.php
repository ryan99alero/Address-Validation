<?php

use App\Filament\Pages\CarrierChargeCatalog;
use App\Models\Carrier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->carrier = Carrier::factory()->create(['name' => 'UPS', 'slug' => 'ups']);
    DB::table('charge_categories')->insert(['id' => 13, 'name' => 'Base Transportation', 'abbreviation' => 'BASE', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    $invoiceId = DB::table('carrier_invoices')->insertGetId(['carrier_id' => $this->carrier->id, 'invoice_number' => 'INV-1', 'invoice_date' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('carrier_charges')->insert([
        ['carrier_id' => $this->carrier->id, 'carrier_invoice_id' => $invoiceId, 'raw_charge_code' => 'ISS', 'raw_charge_description' => 'Ground Commercial', 'charge_category_id' => 13, 'driver' => 'normal', 'amount' => 8.60, 'created_at' => now(), 'updated_at' => now()],
        ['carrier_id' => $this->carrier->id, 'carrier_invoice_id' => $invoiceId, 'raw_charge_code' => 'MYST', 'raw_charge_description' => 'Some Unmapped Fee', 'charge_category_id' => null, 'driver' => 'normal', 'amount' => 5.00, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $this->actingAs(User::factory()->create());
});

test('the charge catalog lists distinct carrier charges with their mapping', function () {
    Livewire::test(CarrierChargeCatalog::class)
        ->assertOk()
        ->assertSee('Ground Commercial')
        ->assertSee('BASE')
        ->assertSee('UPS')
        // an unmapped charge is surfaced so gaps are visible
        ->assertSee('Some Unmapped Fee')
        ->assertSee('UNMAPPED');
});

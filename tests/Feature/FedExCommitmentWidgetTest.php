<?php

use App\Filament\Widgets\FedExExpressCommitmentStats;
use App\Filament\Widgets\FedExGroundCommitmentStats;
use App\Models\Carrier;
use App\Models\FedExCommitmentSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->actingAs(User::factory()->create());

    $carrier = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);
    $baseCat = DB::table('charge_categories')->insertGetId([
        'name' => 'Base Transportation', 'abbreviation' => 'BASE', 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $invoiceId = DB::table('carrier_invoices')->insertGetId([
        'carrier_id' => $carrier->id, 'invoice_number' => 'INV-1', 'invoice_date' => now()->toDateString(),
        'charges_reconciled' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    FedExCommitmentSetting::create(['day_count_mode' => 'calendar']);

    $today = now()->toDateString();
    foreach ([['A1', 'FedEx 2Day', 300.0], ['U1', 'FedEx International Economy', 500.0]] as [$tracking, $service, $amount]) {
        DB::table('carrier_shipments')->insert([
            'carrier_id' => $carrier->id, 'carrier_invoice_id' => $invoiceId, 'tracking_number' => $tracking,
            'service' => $service, 'source_type' => 'csv', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('carrier_charges')->insert([
            'carrier_id' => $carrier->id, 'carrier_invoice_id' => $invoiceId, 'charge_category_id' => $baseCat,
            'tracking_number' => $tracking, 'amount' => $amount, 'ship_date' => $today, 'source_type' => 'csv',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
});

test('the Express commitment widget renders with the three metrics', function () {
    Livewire::test(FedExExpressCommitmentStats::class)
        ->assertOk()
        ->assertSee('FedEx Express Commitments')
        ->assertSee('Avg Daily Packages')
        ->assertSee('Avg Gross Charge / Package');
});

test('the Ground commitment widget renders and surfaces the Unclassified tile', function () {
    Livewire::test(FedExGroundCommitmentStats::class)
        ->assertOk()
        ->assertSee('FedEx Ground Commitments')
        ->assertSee('Unclassified');
});

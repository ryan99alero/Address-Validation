<?php

use App\Models\AccountOwner;
use App\Models\Carrier;
use App\Models\CarrierAccount;
use App\Models\CompanySetting;
use App\Models\Plant;
use App\Models\ShipViaCode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    CompanySetting::query()->delete();
    CompanySetting::create(['company_name' => 'RAND Graphics']);
    $this->fedex = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);
});

function bfSvCode(Carrier $c, string $code, string $account, ?string $owner, string $plant = 'PLANT002'): ShipViaCode
{
    return ShipViaCode::factory()->create([
        'carrier_id' => $c->id, 'code' => $code, 'service_type' => 'FEDEX_GROUND',
        'plant_id' => $plant, 'payment_type' => ShipViaCode::PAYMENT_SENDER,
        'account_number' => $account, 'account_owner' => $owner, 'is_active' => true,
    ]);
}

it('backfills plants, a company owner, deduped accounts, and links ship-via codes', function () {
    // Two codes on the SAME account (dedup), one on another account, various owner text.
    $a1 = bfSvCode($this->fedex, 'G1', '111', 'RAND');       // → company owner
    $a2 = bfSvCode($this->fedex, 'OV', '111', 'Rand Graphics'); // same account 111
    $b1 = bfSvCode($this->fedex, 'G2', '222', null);         // blank → null owner
    $c1 = bfSvCode($this->fedex, 'CX', '333', 'Acme Co', 'PLANT001'); // customer owner

    $this->artisan('accounts:backfill')->assertSuccessful();

    // Plants seeded (normalized, distinct).
    expect(Plant::pluck('code')->sort()->values()->all())->toBe(['PLANT001', 'PLANT002']);

    // One company owner + one customer owner ("Acme Co"). "RAND"/"Rand Graphics" both map to company.
    expect(AccountOwner::where('type', 'company')->count())->toBe(1)
        ->and(AccountOwner::where('type', 'customer')->pluck('name')->all())->toBe(['Acme Co']);

    // 3 distinct accounts (111 deduped from two codes).
    expect(CarrierAccount::count())->toBe(3);
    $company = AccountOwner::where('type', 'company')->first();
    expect(CarrierAccount::where('account_number', '111')->first()->account_owner_id)->toBe($company->id)
        ->and(CarrierAccount::where('account_number', '222')->first()->account_owner_id)->toBeNull(); // never guessed

    // All four codes linked; the two on 111 share one account.
    expect($a1->refresh()->carrier_account_id)->not->toBeNull()
        ->and($a1->carrier_account_id)->toBe($a2->refresh()->carrier_account_id)
        ->and($b1->refresh()->carrier_account_id)->toBe(CarrierAccount::where('account_number', '222')->first()->id);
});

it('is idempotent — re-running creates no duplicates', function () {
    bfSvCode($this->fedex, 'G1', '111', 'RAND');
    $this->artisan('accounts:backfill')->assertSuccessful();
    $this->artisan('accounts:backfill')->assertSuccessful();

    expect(CarrierAccount::where('account_number', '111')->count())->toBe(1)
        ->and(AccountOwner::where('type', 'company')->count())->toBe(1);
});

it('folds the singular company_settings account number into a company-owned account', function () {
    CompanySetting::instance()->update(['fedex_account_number' => '740561073']);

    $this->artisan('accounts:backfill')->assertSuccessful();

    $acct = CarrierAccount::where('account_number', '740561073')->first();
    expect($acct)->not->toBeNull()
        ->and($acct->owner->type)->toBe('company');
});

it('billedAccount() resolves by payment type (sender vs third-party)', function () {
    $owner = AccountOwner::create(['name' => 'RAND', 'type' => 'company']);
    $ours = CarrierAccount::create(['carrier_id' => $this->fedex->id, 'account_number' => 'OURS', 'nickname' => 'ours', 'account_owner_id' => $owner->id]);
    $client = CarrierAccount::create(['carrier_id' => $this->fedex->id, 'account_number' => 'CLIENT', 'nickname' => 'client']);

    $sender = ShipViaCode::factory()->create([
        'carrier_id' => $this->fedex->id, 'payment_type' => ShipViaCode::PAYMENT_SENDER,
        'carrier_account_id' => $ours->id, 'third_party_account_id' => $client->id,
    ]);
    $thirdParty = ShipViaCode::factory()->create([
        'carrier_id' => $this->fedex->id, 'payment_type' => ShipViaCode::PAYMENT_THIRD_PARTY,
        'carrier_account_id' => $ours->id, 'third_party_account_id' => $client->id,
    ]);

    expect($sender->billedAccount()->account_number)->toBe('OURS')
        ->and($thirdParty->billedAccount()->account_number)->toBe('CLIENT');
});

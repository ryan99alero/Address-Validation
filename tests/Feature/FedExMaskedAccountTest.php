<?php

use App\Models\Carrier;
use App\Models\CarrierAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * FedEx PDFs mask the account, printing only the last digits (e.g. "XXXX-X560-4"). Import must resolve
 * that visible suffix to the full known account so the invoice is labeled to the right account.
 */
beforeEach(function () {
    $this->fedex = Carrier::factory()->create(['slug' => 'fedex', 'name' => 'FedEx']);
    foreach (['226125604', '067201043', '245538367'] as $n) {
        CarrierAccount::create(['carrier_id' => $this->fedex->id, 'account_number' => $n, 'nickname' => "FedEx {$n}", 'is_active' => true]);
    }
});

it('resolves a masked FedEx account by its visible suffix', function () {
    expect(CarrierAccount::resolveInvoiceAccount($this->fedex->id, 'XXXX-X560-4'))->toBe('226125604')   // last4 5604
        ->and(CarrierAccount::resolveInvoiceAccount($this->fedex->id, 'XXXX-X104-3'))->toBe('067201043'); // last4 1043
});

it('trusts a fully-printed account number as-is', function () {
    expect(CarrierAccount::resolveInvoiceAccount($this->fedex->id, '226125604'))->toBe('226125604');
});

it('returns null when the masked suffix matches no known account', function () {
    expect(CarrierAccount::resolveInvoiceAccount($this->fedex->id, 'XXXX-X999-9'))->toBeNull();
});

it('returns null when the masked suffix is ambiguous (never guesses which to bill)', function () {
    CarrierAccount::create(['carrier_id' => $this->fedex->id, 'account_number' => '999995604', 'nickname' => 'FedEx dup', 'is_active' => true]);

    expect(CarrierAccount::resolveInvoiceAccount($this->fedex->id, 'XXXX-X560-4'))->toBeNull(); // 5604 now matches two
});

it('returns null for empty / fully-masked input', function () {
    expect(CarrierAccount::resolveInvoiceAccount($this->fedex->id, null))->toBeNull()
        ->and(CarrierAccount::resolveInvoiceAccount($this->fedex->id, 'XXXX-XXXX-X'))->toBeNull();
});

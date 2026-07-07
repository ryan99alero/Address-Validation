<?php

use App\Enums\ChargeDriver;
use App\Services\Invoices\ChargeDriverResolver;

beforeEach(function () {
    $this->resolver = new ChargeDriverResolver;
});

test('a UPS billing code wins and reports csv_code source', function () {
    expect($this->resolver->resolve('ADC', 'outbound', '2nd Day Air'))
        ->toBe([ChargeDriver::AddressCorrection->value, 'csv_code']);
    expect($this->resolver->resolve('SCC', null, 'Shipping Charge Correction'))
        ->toBe([ChargeDriver::AuditCorrection->value, 'csv_code']);
    expect($this->resolver->resolve('FEES', null, 'Late Payment Fee'))
        ->toBe([ChargeDriver::LateFee->value, 'csv_code']);
});

test('FedEx ADDCOR code maps to address correction', function () {
    expect($this->resolver->resolve('ADDCOR', null, 'Address Correction'))
        ->toBe([ChargeDriver::AddressCorrection->value, 'csv_code']);
});

test('without a code the PDF section drives it', function () {
    expect($this->resolver->resolve(null, 'address_correction', '2nd Day Air'))
        ->toBe([ChargeDriver::AddressCorrection->value, 'pdf_section']);
    expect($this->resolver->resolve(null, 'shipping_charge_correction', 'Ground'))
        ->toBe([ChargeDriver::AuditCorrection->value, 'pdf_section']);
    expect($this->resolver->resolve(null, 'outbound', 'Ground'))
        ->toBe([ChargeDriver::Normal->value, 'pdf_section']);
});

test('with neither code nor section it falls back to the description', function () {
    expect($this->resolver->resolve(null, null, 'Address Correction'))
        ->toBe([ChargeDriver::AddressCorrection->value, 'description']);
    expect($this->resolver->resolve(null, null, 'Invalid Account Number'))
        ->toBe([ChargeDriver::ThirdPartyChargeback->value, 'description']);
});

test('a plain charge with no signal defaults to normal', function () {
    expect($this->resolver->resolve(null, null, 'Fuel Surcharge'))
        ->toBe([ChargeDriver::Normal->value, 'default']);
});

test('an unrecognized code falls through to section then default', function () {
    expect($this->resolver->resolve('ZZZ', 'address_correction', 'x'))
        ->toBe([ChargeDriver::AddressCorrection->value, 'pdf_section']);
    expect($this->resolver->resolve('ZZZ', null, 'x'))
        ->toBe([ChargeDriver::Normal->value, 'default']);
});

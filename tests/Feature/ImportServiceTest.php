<?php

use App\Services\ImportService;

describe('ImportService auto-matching', function () {
    beforeEach(function () {
        $this->service = new ImportService;
    });

    it('matches standard address field names', function () {
        $headers = ['Address', 'City', 'State', 'Zip', 'Country'];

        $mappings = $this->service->autoMatchHeaders($headers);

        expect($mappings[0]['target'])->toBe('input_address_1');
        expect($mappings[1]['target'])->toBe('input_city');
        expect($mappings[2]['target'])->toBe('input_state');
        expect($mappings[3]['target'])->toBe('input_postal');
        expect($mappings[4]['target'])->toBe('input_country');
    });

    it('matches abbreviated field names like add1, addr1', function () {
        $headers = ['add1', 'addr1', 'add2', 'addr2'];

        $mappings = $this->service->autoMatchHeaders($headers);

        expect($mappings[0]['target'])->toBe('input_address_1');
        expect($mappings[2]['target'])->toBe('input_address_2');
    });

    it('matches camelCase field names like DestinationCity', function () {
        $headers = ['DestinationCity', 'DestinationState', 'DestinationZip'];

        $mappings = $this->service->autoMatchHeaders($headers);

        expect($mappings[0]['target'])->toBe('input_city');
        expect($mappings[1]['target'])->toBe('input_state');
        expect($mappings[2]['target'])->toBe('input_postal');
    });

    it('matches fields with ship/delivery prefixes', function () {
        $headers = ['ShipToAddr1', 'ShipToCity', 'Shipping State'];

        $mappings = $this->service->autoMatchHeaders($headers);

        expect($mappings[0]['target'])->toBe('input_address_1');
        expect($mappings[1]['target'])->toBe('input_city');
        expect($mappings[2]['target'])->toBe('input_state');
    });

    it('matches postal code variations', function () {
        $headers = ['ZipCode', 'Postal Code', 'PostalCode', 'Zip5'];

        // Each header should match input_postal, but only first gets it (no duplicates)
        $mappings = $this->service->autoMatchHeaders($headers);

        expect($mappings[0]['target'])->toBe('input_postal');
        // Others default to pass-through since input_postal is already used
        expect($mappings[1]['target'])->toBe('_passthrough');
    });

    it('matches company and contact name fields correctly for shipping context', function () {
        // In shipping: "Ship To Name" / "Name" / "Recipient Name" = company (primary recipient line)
        // "Contact" / "Attention" / "Attn" = person's name
        $headers = ['Ship To Name', 'Attention', 'Company Name', 'Contact Name'];

        $mappings = $this->service->autoMatchHeaders($headers);

        // Ship To Name should map to input_company (primary recipient in shipping)
        expect($mappings[0]['target'])->toBe('input_company');
        // Attention should map to input_name (person/contact)
        expect($mappings[1]['target'])->toBe('input_name');
        // Company Name already taken, defaults to pass-through
        expect($mappings[2]['target'])->toBe('_passthrough');
        // Contact Name - name already taken, defaults to pass-through
        expect($mappings[3]['target'])->toBe('_passthrough');
    });

    it('maps generic Name field to company in shipping context', function () {
        $headers = ['Name', 'Attn', 'Address'];

        $mappings = $this->service->autoMatchHeaders($headers);

        // Generic "Name" in shipping typically = company/recipient organization
        expect($mappings[0]['target'])->toBe('input_company');
        // Attn = person
        expect($mappings[1]['target'])->toBe('input_name');
        expect($mappings[2]['target'])->toBe('input_address_1');
    });

    it('maps any field containing contact to name (person)', function () {
        $headers = ['ShipToContact', 'Ship To Contact', 'DeliveryContact', 'RecipientContact'];

        $mappings = $this->service->autoMatchHeaders($headers);

        // All "contact" fields should map to input_name (person)
        expect($mappings[0]['target'])->toBe('input_name');
        // Others default to pass-through since input_name is already used
        expect($mappings[1]['target'])->toBe('_passthrough');
        expect($mappings[2]['target'])->toBe('_passthrough');
        expect($mappings[3]['target'])->toBe('_passthrough');
    });

    it('matches external reference variations', function () {
        $headers = ['Order ID', 'Reference', 'PO Number', 'Job Number'];

        $mappings = $this->service->autoMatchHeaders($headers);

        expect($mappings[0]['target'])->toBe('external_reference');
    });

    it('defaults unmatchable headers to pass-through', function () {
        $headers = ['Random Field', 'Unknown Column', 'Custom Data'];

        $mappings = $this->service->autoMatchHeaders($headers);

        // Unrecognized fields default to pass-through (will be stored in extra_1, extra_2, etc.)
        expect($mappings[0]['target'])->toBe('_passthrough');
        expect($mappings[1]['target'])->toBe('_passthrough');
        expect($mappings[2]['target'])->toBe('_passthrough');
    });

    it('handles underscore and hyphen separators', function () {
        $headers = ['address_line_1', 'ship-to-city', 'postal_code'];

        $mappings = $this->service->autoMatchHeaders($headers);

        expect($mappings[0]['target'])->toBe('input_address_1');
        expect($mappings[1]['target'])->toBe('input_city');
        expect($mappings[2]['target'])->toBe('input_postal');
    });

    it('prevents duplicate field assignments', function () {
        $headers = ['Address', 'Street Address', 'Address Line 1'];

        $mappings = $this->service->autoMatchHeaders($headers);

        // First one gets input_address_1
        expect($mappings[0]['target'])->toBe('input_address_1');
        // Others default to pass-through to prevent duplicate mapping
        expect($mappings[1]['target'])->toBe('_passthrough');
        expect($mappings[2]['target'])->toBe('_passthrough');
    });
});

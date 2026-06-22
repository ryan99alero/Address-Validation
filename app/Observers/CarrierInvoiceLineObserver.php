<?php

namespace App\Observers;

use App\Models\CarrierInvoiceLine;
use App\Services\Invoices\AddressCorrectionAnalyzer;

class CarrierInvoiceLineObserver
{
    public function __construct(private AddressCorrectionAnalyzer $analyzer) {}

    /**
     * Grade the correction (severity + change type) whenever a line with both
     * an original and corrected address is saved.
     */
    public function saving(CarrierInvoiceLine $line): void
    {
        if (empty($line->original_address_1) || empty($line->corrected_address_1)) {
            return;
        }

        // Only (re)compute when the address fields are dirty (or not yet graded).
        $addressDirty = $line->isDirty([
            'original_address_1', 'original_address_2', 'original_city', 'original_state', 'original_postal',
            'corrected_address_1', 'corrected_address_2', 'corrected_city', 'corrected_state', 'corrected_postal',
        ]);
        if (! $addressDirty && $line->severity_category !== null) {
            return;
        }

        $result = $this->analyzer->analyze(
            [
                'address_1' => $line->original_address_1,
                'address_2' => $line->original_address_2,
                'city' => $line->original_city,
                'state' => $line->original_state,
                'postal' => $line->original_postal,
            ],
            [
                'address_1' => $line->corrected_address_1,
                'address_2' => $line->corrected_address_2,
                'city' => $line->corrected_city,
                'state' => $line->corrected_state,
                'postal' => $line->corrected_postal,
            ],
        );

        $line->severity_score = $result['severity_score'];
        $line->severity_category = $result['severity_category'];
        $line->change_type = $result['change_type'];
    }
}

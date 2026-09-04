<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default numeric commitments (all MINIMUMS)
    |--------------------------------------------------------------------------
    | Seed/fallback values for the six agreement targets. The live values are
    | edited in the UI (Commitment Settings → fedex_commitment_settings row);
    | these apply when a setting is blank. They change on renegotiation.
    */
    'targets' => [
        'express' => [
            'avg_daily_packages' => 2.90,
            'avg_daily_revenue' => 504.60,
            'avg_charge_per_package' => 172.30,
        ],
        'ground' => [
            'avg_daily_packages' => 82.10,
            'avg_daily_revenue' => 1654.10,
            'avg_charge_per_package' => 20.10,
        ],
    ],

    // Amber "at risk" band: at or below this fraction ABOVE target (e.g. 0.10 = within +10%).
    'at_risk_margin' => 0.10,

    // Default day-count denominator: business | calendar | active. UI-overridable.
    'day_count_mode' => 'business',

    /*
    |--------------------------------------------------------------------------
    | Bucket membership — EXACT-match allowlists
    |--------------------------------------------------------------------------
    | Matched against carrier_shipments.service (the exact strings the parser /
    | CSV store). Both the "FedEx X" (PDF) and bare "X" (CSV) forms are listed.
    | Anything not matched is Unclassified and surfaced in the widget — never
    | silently dropped into a bucket. Do NOT substring-match: "FedEx Ground
    | Economy" is NOT "FedEx Ground".
    */
    'buckets' => [
        // Domestic Express Non-Freight.
        'express' => [
            'FedEx Priority Overnight',
            'FedEx Standard Overnight',
            'FedEx 2Day',
            'FedEx 2Day AM',
            'FedEx Express Saver',
        ],
        // Ground Domestic Single Piece.
        'ground' => [
            'FedEx Ground',
            'Ground',
        ],
    ],

    /*
    | Optional members toggled on/off in the settings UI (values here are the
    | defaults). Their status is shown in the widget so the number is never
    | ambiguous.
    */
    'optional' => [
        'ground_home_delivery' => [
            'label' => 'Home Delivery in Ground',
            'services' => ['FedEx Home Delivery', 'Home Delivery'],
            'bucket' => 'ground',
            'default' => true,
        ],
        'express_first_overnight' => [
            'label' => 'First Overnight in Express',
            'services' => ['FedEx First Overnight'],
            'bucket' => 'express',
            'default' => false,
        ],
        'express_sameday' => [
            'label' => 'SameDay in Express',
            'services' => ['FedEx SameDay', 'FedEx SameDay City'],
            'bucket' => 'express',
            'default' => false,
        ],
    ],
];

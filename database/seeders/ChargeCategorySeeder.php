<?php

namespace Database\Seeders;

use App\Models\Carrier;
use App\Models\ChargeCategory;
use App\Models\ChargeCodeMapping;
use Illuminate\Database\Seeder;

class ChargeCategorySeeder extends Seeder
{
    public function run(): void
    {
        // Canonical fee taxonomy. Add children later for finer grouping.
        $categories = [
            'Address Correction',
            'Fuel Surcharge',
            'Additional Handling',
            'Residential Surcharge',
            'Delivery Area Surcharge',
            'Oversize / Large Package',
            'Broker / Customs Fee',
            'Weekly / Service Charge',
            'Audit / Correction Fee',
            'Late / Interest Fee',
            'Base Transportation',
            'Peak / Demand Surcharge',
            'Discount / Credit',
            'Other / Misc',
        ];

        $ids = [];
        foreach ($categories as $i => $name) {
            $ids[$name] = ChargeCategory::firstOrCreate(
                ['name' => $name],
                ['sort_order' => $i, 'is_active' => true]
            )->id;
        }

        $ups = Carrier::where('slug', 'ups')->first();
        $fedex = Carrier::where('slug', 'fedex')->first();

        // [carrier_id, match_type, match_value, category, priority]
        $mappings = [
            // Exact carrier codes (highest confidence)
            [$ups?->id, 'code', 'ADC', 'Address Correction', 100],
            [$fedex?->id, 'code', 'ADDCOR', 'Address Correction', 100],

            // Cross-carrier description patterns
            [null, 'description', 'Address Correction', 'Address Correction', 50],
            [null, 'description', 'Fuel Surcharge', 'Fuel Surcharge', 50],
            [null, 'description', 'Additional Handling', 'Additional Handling', 50],
            [null, 'description', 'Residential', 'Residential Surcharge', 50],
            [null, 'description', 'Delivery Area', 'Delivery Area Surcharge', 50],
            [null, 'description', 'Large Package', 'Oversize / Large Package', 50],
            [null, 'description', 'Oversize', 'Oversize / Large Package', 50],
            [null, 'description', 'Dimensional', 'Oversize / Large Package', 40],
            [null, 'description', 'Broker', 'Broker / Customs Fee', 50],
            [null, 'description', 'Customs', 'Broker / Customs Fee', 40],
            [null, 'description', 'Weekly Service Charge', 'Weekly / Service Charge', 50],
            [null, 'description', 'Service Fee', 'Weekly / Service Charge', 40],
            [null, 'description', 'Correction Audit', 'Audit / Correction Fee', 50],
            [null, 'description', 'Addl. Handling', 'Additional Handling', 50],
            [null, 'description', 'Shipping Charge Correction', 'Audit / Correction Fee', 45],
            [null, 'description', 'Invoice Surcharge', 'Other / Misc', 30],
            // FedEx-specific descriptions
            [null, 'description', 'DAS', 'Delivery Area Surcharge', 55],
            [null, 'description', 'AHS', 'Additional Handling', 55],
            [null, 'description', "Add'l Handling", 'Additional Handling', 55],
            [null, 'description', 'Demand', 'Peak / Demand Surcharge', 55],
            [null, 'description', 'Earned Discount', 'Discount / Credit', 60],
            [null, 'description', 'Performance Pricing', 'Discount / Credit', 60],
            [null, 'description', 'Discount', 'Discount / Credit', 50],
            [null, 'description', 'Transportation', 'Base Transportation', 20],

            // Base transportation service levels (low priority so surcharges win).
            [null, 'description', 'Commercial', 'Base Transportation', 10],
            [null, 'description', 'Residential', 'Base Transportation', 9],
            [null, 'description', 'Saver', 'Base Transportation', 10],
            [null, 'description', 'Ground', 'Base Transportation', 8],
        ];

        foreach ($mappings as [$carrierId, $type, $value, $categoryName, $priority]) {
            ChargeCodeMapping::firstOrCreate(
                [
                    'carrier_id' => $carrierId,
                    'match_type' => $type,
                    'match_value' => $value,
                ],
                [
                    'charge_category_id' => $ids[$categoryName],
                    'priority' => $priority,
                    'is_active' => true,
                ]
            );
        }
    }
}

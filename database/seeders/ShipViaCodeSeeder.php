<?php

namespace Database\Seeders;

use App\Models\Carrier;
use App\Models\ShipViaCode;
use Illuminate\Database\Seeder;

class ShipViaCodeSeeder extends Seeder
{
    /**
     * Seed common ship via codes.
     */
    public function run(): void
    {
        $fedexCarrier = Carrier::where('slug', 'fedex')->first();
        $upsCarrier = Carrier::where('slug', 'ups')->first();

        $codes = [
            // FedEx Services
            [
                'code' => 'FDG',
                'carrier_code' => 'FDG',
                'carrier_id' => $fedexCarrier?->id,
                'service_type' => 'FEDEX_GROUND',
                'service_name' => 'FedEx Ground',
            ],
            [
                'code' => 'FHD',
                'carrier_code' => 'FHD',
                'carrier_id' => $fedexCarrier?->id,
                'service_type' => 'GROUND_HOME_DELIVERY',
                'service_name' => 'FedEx Home Delivery',
            ],
            [
                'code' => 'FES',
                'carrier_code' => 'FES',
                'carrier_id' => $fedexCarrier?->id,
                'service_type' => 'FEDEX_EXPRESS_SAVER',
                'service_name' => 'FedEx Express Saver',
            ],
            [
                'code' => 'F2D',
                'carrier_code' => 'F2D',
                'carrier_id' => $fedexCarrier?->id,
                'service_type' => 'FEDEX_2_DAY',
                'service_name' => 'FedEx 2Day',
            ],
            [
                'code' => 'F2A',
                'carrier_code' => 'F2A',
                'carrier_id' => $fedexCarrier?->id,
                'service_type' => 'FEDEX_2_DAY_AM',
                'service_name' => 'FedEx 2Day A.M.',
            ],
            [
                'code' => 'FSO',
                'carrier_code' => 'FSO',
                'carrier_id' => $fedexCarrier?->id,
                'service_type' => 'STANDARD_OVERNIGHT',
                'service_name' => 'FedEx Standard Overnight',
            ],
            [
                'code' => 'FPO',
                'carrier_code' => 'FPO',
                'carrier_id' => $fedexCarrier?->id,
                'service_type' => 'PRIORITY_OVERNIGHT',
                'service_name' => 'FedEx Priority Overnight',
            ],
            [
                'code' => 'FFO',
                'carrier_code' => 'FFO',
                'carrier_id' => $fedexCarrier?->id,
                'service_type' => 'FIRST_OVERNIGHT',
                'service_name' => 'FedEx First Overnight',
            ],

            // UPS Services
            [
                'code' => 'GND',
                'carrier_code' => '03',
                'carrier_id' => $upsCarrier?->id,
                'service_type' => 'GND',
                'service_name' => 'UPS Ground',
            ],
            [
                'code' => '2DA',
                'carrier_code' => '02',
                'carrier_id' => $upsCarrier?->id,
                'service_type' => '2DA',
                'service_name' => 'UPS 2nd Day Air',
            ],
            [
                'code' => '2DM',
                'carrier_code' => '59',
                'carrier_id' => $upsCarrier?->id,
                'service_type' => '2DM',
                'service_name' => 'UPS 2nd Day Air A.M.',
            ],
            [
                'code' => '3DS',
                'carrier_code' => '12',
                'carrier_id' => $upsCarrier?->id,
                'service_type' => '3DS',
                'service_name' => 'UPS 3 Day Select',
            ],
            [
                'code' => 'NDA',
                'carrier_code' => '01',
                'carrier_id' => $upsCarrier?->id,
                'service_type' => 'NDA',
                'service_name' => 'UPS Next Day Air',
            ],
            [
                'code' => 'NDS',
                'carrier_code' => '13',
                'carrier_id' => $upsCarrier?->id,
                'service_type' => 'NDS',
                'service_name' => 'UPS Next Day Air Saver',
            ],
            [
                'code' => 'NDM',
                'carrier_code' => '14',
                'carrier_id' => $upsCarrier?->id,
                'service_type' => 'NDM',
                'service_name' => 'UPS Next Day Air Early',
            ],
        ];

        foreach ($codes as $code) {
            ShipViaCode::updateOrCreate(
                ['code' => $code['code']],
                $code
            );
        }
    }
}

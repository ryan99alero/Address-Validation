<?php

namespace Database\Seeders;

use App\Models\Carrier;
use App\Models\ShipViaCode;
use Illuminate\Database\Seeder;

class UserShipViaCodeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * Imports user's ship via codes from their Untitled.xls mapping.
     */
    public function run(): void
    {
        $fedex = Carrier::where('slug', 'fedex')->first();
        $ups = Carrier::where('slug', 'ups')->first();

        if (! $fedex || ! $ups) {
            $this->command->error('Carriers not found. Please run CarrierSeeder first.');

            return;
        }

        // Clear existing ship via codes (using delete to respect FK constraints)
        ShipViaCode::query()->delete();

        // FedEx services with user codes
        $fedexServices = [
            [
                'carrier_code' => 'FDG',
                'service_type' => 'FEDEX_GROUND',
                'service_name' => 'FedEx Ground',
                'user_codes' => ['5049', '5137', '5185', '5225'],
            ],
            [
                'carrier_code' => 'FPO',
                'service_type' => 'PRIORITY_OVERNIGHT',
                'service_name' => 'FedEx Priority Overnight',
                'user_codes' => ['5132', '5180'],
            ],
            [
                'carrier_code' => 'FSO',
                'service_type' => 'STANDARD_OVERNIGHT',
                'service_name' => 'FedEx Standard Overnight',
                'user_codes' => ['5133', '5181', '5241'],
            ],
            [
                'carrier_code' => 'FFO',
                'service_type' => 'FIRST_OVERNIGHT',
                'service_name' => 'FedEx First Overnight',
                'user_codes' => ['5134', '5182'],
            ],
            [
                'carrier_code' => 'F2D',
                'service_type' => 'FEDEX_2_DAY',
                'service_name' => 'FedEx 2Day',
                'user_codes' => ['5135', '5183', '5271'],
            ],
            [
                'carrier_code' => 'F2A',
                'service_type' => 'FEDEX_2_DAY_AM',
                'service_name' => 'FedEx 2Day A.M.',
                'user_codes' => ['5239'],
            ],
            [
                'carrier_code' => 'FES',
                'service_type' => 'FEDEX_EXPRESS_SAVER',
                'service_name' => 'FedEx Express Saver',
                'user_codes' => ['5136', '5184'],
            ],
            [
                'carrier_code' => 'FHD',
                'service_type' => 'GROUND_HOME_DELIVERY',
                'service_name' => 'FedEx Home Delivery',
                'user_codes' => ['5138', '5186'],
            ],
            [
                'carrier_code' => 'F1F',
                'service_type' => 'FEDEX_1_DAY_FREIGHT',
                'service_name' => 'FedEx 1 Day Freight',
                'user_codes' => ['5139', '5187'],
            ],
            [
                'carrier_code' => 'F2F',
                'service_type' => 'FEDEX_2_DAY_FREIGHT',
                'service_name' => 'FedEx 2 Day Freight',
                'user_codes' => ['5140', '5188'],
            ],
            [
                'carrier_code' => 'F3F',
                'service_type' => 'FEDEX_3_DAY_FREIGHT',
                'service_name' => 'FedEx 3 Day Freight',
                'user_codes' => ['5189'],
            ],
            [
                'carrier_code' => 'FIP',
                'service_type' => 'INTERNATIONAL_PRIORITY',
                'service_name' => 'FedEx International Priority',
                'user_codes' => ['5142', '5190'],
            ],
            [
                'carrier_code' => 'FIE',
                'service_type' => 'INTERNATIONAL_ECONOMY',
                'service_name' => 'FedEx International Economy',
                'user_codes' => ['5143', '5191'],
            ],
            [
                'carrier_code' => 'FIF',
                'service_type' => 'INTERNATIONAL_FIRST',
                'service_name' => 'FedEx International First',
                'user_codes' => ['5144', '5192'],
            ],
            [
                'carrier_code' => 'FIPF',
                'service_type' => 'INTERNATIONAL_PRIORITY_FREIGHT',
                'service_name' => 'FedEx International Priority Freight',
                'user_codes' => ['5145', '5193'],
            ],
            [
                'carrier_code' => 'FIEF',
                'service_type' => 'INTERNATIONAL_ECONOMY_FREIGHT',
                'service_name' => 'FedEx International Economy Freight',
                'user_codes' => ['5146', '5194'],
            ],
            [
                'carrier_code' => 'FEIP',
                'service_type' => 'EUROPE_FIRST_INTERNATIONAL_PRIORITY',
                'service_name' => 'FedEx Europe First International Priority',
                'user_codes' => ['5147', '5195'],
            ],
            [
                'carrier_code' => 'FIG',
                'service_type' => 'FEDEX_INTERNATIONAL_GROUND',
                'service_name' => 'FedEx International Ground',
                'user_codes' => ['5231', '5232'],
            ],
        ];

        // UPS services with user codes
        $upsServices = [
            [
                'carrier_code' => '03',
                'service_type' => 'GND',
                'service_name' => 'UPS Ground',
                'user_codes' => ['5090', '5102', '5126', '5201', '5240', '5264'],
            ],
            [
                'carrier_code' => '01',
                'service_type' => 'NDA',
                'service_name' => 'UPS Next Day Air',
                'user_codes' => ['5084', '5096', '5120', '5203', '5258'],
            ],
            [
                'carrier_code' => '14',
                'service_type' => 'NDM',
                'service_name' => 'UPS Next Day Air Early A.M.',
                'user_codes' => ['5085', '5097', '5121', '5221', '5259'],
            ],
            [
                'carrier_code' => '13',
                'service_type' => 'NDS',
                'service_name' => 'UPS Next Day Air Saver',
                'user_codes' => ['5086', '5098', '5122', '5260'],
            ],
            [
                'carrier_code' => '02',
                'service_type' => '2DA',
                'service_name' => 'UPS 2nd Day Air',
                'user_codes' => ['5087', '5099', '5123', '5202', '5261'],
            ],
            [
                'carrier_code' => '59',
                'service_type' => '2DM',
                'service_name' => 'UPS 2nd Day Air A.M.',
                'user_codes' => ['5088', '5100', '5124', '5262'],
            ],
            [
                'carrier_code' => '12',
                'service_type' => '3DS',
                'service_name' => 'UPS 3 Day Select',
                'user_codes' => ['5089', '5101', '5125', '5206', '5263'],
            ],
            [
                'carrier_code' => '11',
                'service_type' => 'STD',
                'service_name' => 'UPS Standard',
                'user_codes' => ['5091', '5103', '5127', '5218', '5265'],
            ],
            [
                'carrier_code' => '07',
                'service_type' => 'WXS',
                'service_name' => 'UPS Worldwide Express',
                'user_codes' => ['5093', '5105', '5129', '5266'],
            ],
            [
                'carrier_code' => '08',
                'service_type' => 'WXD',
                'service_name' => 'UPS Worldwide Expedited',
                'user_codes' => ['5094', '5106', '5130', '5267'],
            ],
            [
                'carrier_code' => '54',
                'service_type' => 'WXSP',
                'service_name' => 'UPS Worldwide Express Plus',
                'user_codes' => ['5095', '5107', '5131', '5268'],
            ],
            [
                'carrier_code' => '65',
                'service_type' => 'WSV',
                'service_name' => 'UPS Worldwide Saver',
                'user_codes' => ['5104', '5128', '5223', '5224', '5269'],
            ],
            [
                'carrier_code' => '93',
                'service_type' => 'SP',
                'service_name' => 'UPS SurePost',
                'user_codes' => ['5270'],
            ],
        ];

        // Create FedEx ship via codes
        foreach ($fedexServices as $service) {
            ShipViaCode::create([
                'code' => null,
                'carrier_code' => $service['carrier_code'],
                'alternate_codes' => $service['user_codes'],
                'carrier_id' => $fedex->id,
                'service_type' => $service['service_type'],
                'service_name' => $service['service_name'],
                'is_active' => true,
            ]);
        }

        // Create UPS ship via codes
        foreach ($upsServices as $service) {
            ShipViaCode::create([
                'code' => null,
                'carrier_code' => $service['carrier_code'],
                'alternate_codes' => $service['user_codes'],
                'carrier_id' => $ups->id,
                'service_type' => $service['service_type'],
                'service_name' => $service['service_name'],
                'is_active' => true,
            ]);
        }

        $this->command->info('Created '.count($fedexServices).' FedEx ship via codes');
        $this->command->info('Created '.count($upsServices).' UPS ship via codes');
        $this->command->info('Total user codes mapped: '.
            collect($fedexServices)->sum(fn ($s) => count($s['user_codes'])) +
            collect($upsServices)->sum(fn ($s) => count($s['user_codes']))
        );
    }
}

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Short code for each normalized fee category, so the per-shipment view can
     * list "what we got hit with" compactly (FUEL, DAS, RES…) instead of full
     * 90-character names.
     */
    private const ABBREVIATIONS = [
        'Additional Handling' => 'ADDL HDL',
        'Address Correction' => 'ADC',
        'Audit / Correction Fee' => 'AUDIT',
        'Base Transportation' => 'BASE',
        'Broker / Customs Fee' => 'BROKER',
        'Delivery Area Surcharge' => 'DAS',
        'Discount / Credit' => 'CREDIT',
        'Fuel Surcharge' => 'FUEL',
        'Late / Interest Fee' => 'LATE',
        'Other / Misc' => 'MISC',
        'Oversize / Large Package' => 'OVERSIZE',
        'Peak / Demand Surcharge' => 'PEAK',
        'Residential Surcharge' => 'RES',
        'Weekly / Service Charge' => 'SVC',
    ];

    public function up(): void
    {
        Schema::table('charge_categories', function (Blueprint $table): void {
            $table->string('abbreviation', 16)->nullable()->after('name');
        });

        foreach (self::ABBREVIATIONS as $name => $abbreviation) {
            DB::table('charge_categories')->where('name', $name)->update(['abbreviation' => $abbreviation]);
        }
    }

    public function down(): void
    {
        Schema::table('charge_categories', function (Blueprint $table): void {
            $table->dropColumn('abbreviation');
        });
    }
};

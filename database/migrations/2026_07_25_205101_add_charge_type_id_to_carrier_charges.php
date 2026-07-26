<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stamp which crosswalk row (carrier_charge_types) classified each charge — gives the GUI cheap
 * per-type usage counts/totals and an audit trail of how a charge got its category.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_charges', function (Blueprint $table) {
            $table->foreignId('charge_type_id')->nullable()->after('charge_category_id')
                ->constrained('carrier_charge_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('carrier_charges', function (Blueprint $table) {
            $table->dropConstrainedForeignId('charge_type_id');
        });
    }
};

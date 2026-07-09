<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            if (! Schema::hasColumn('addresses', 'input_is_residential')) {
                // Residential/commercial as supplied in the import file (customer's claim). Distinct
                // from is_residential, which is what carrier validation (USPS RDI) determined.
                $table->boolean('input_is_residential')->nullable()->after('input_country');
            }
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            if (Schema::hasColumn('addresses', 'input_is_residential')) {
                $table->dropColumn('input_is_residential');
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds transit_carrier_id to specify which carrier to use for
     * Time In Transit lookups (UPS or FedEx).
     */
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->foreignId('transit_carrier_id')
                ->nullable()
                ->after('include_transit_times')
                ->constrained('carriers')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropForeign(['transit_carrier_id']);
            $table->dropColumn('transit_carrier_id');
        });
    }
};

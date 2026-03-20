<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * These indexes optimize the following operations:
     * 1. Import: batch lookups, row number ordering
     * 2. Address Correction API: finding uncorrected addresses
     * 3. Transit Times API: finding addresses without transit times
     * 4. Recommendations: updating addresses by batch
     * 5. Exporting: filtering by validation status, sorting
     * 6. Cascade Delete: already handled by foreign keys
     */
    public function up(): void
    {
        // Import batches - status lookups for dashboard queries
        Schema::table('import_batches', function (Blueprint $table) {
            // Quick status filtering for dashboard
            if (! $this->hasIndex('import_batches', 'import_batches_status_index')) {
                $table->index('status', 'import_batches_status_index');
            }
            // Export status filtering
            if (! $this->hasIndex('import_batches', 'import_batches_export_status_index')) {
                $table->index('export_status', 'import_batches_export_status_index');
            }
        });

        // Addresses - optimize filtering & export queries
        Schema::table('addresses', function (Blueprint $table) {
            // Speed up ship_via_code lookups for recommendations
            if (! $this->hasIndex('addresses', 'addresses_ship_via_code_index')) {
                $table->index('ship_via_code', 'addresses_ship_via_code_index');
            }
        });

        // Transit times - optimize service type lookups
        Schema::table('transit_times', function (Blueprint $table) {
            // Composite for finding specific service for an address
            if (! $this->hasIndex('transit_times', 'transit_times_addr_service_index')) {
                $table->index(['address_id', 'service_type'], 'transit_times_addr_service_index');
            }
            // Speed up delivery date sorting
            if (! $this->hasIndex('transit_times', 'transit_times_delivery_date_index')) {
                $table->index('delivery_date', 'transit_times_delivery_date_index');
            }
        });

        // Ship via codes - optimize code resolution
        Schema::table('ship_via_codes', function (Blueprint $table) {
            // Speed up code lookups
            if (! $this->hasIndex('ship_via_codes', 'ship_via_codes_code_index')) {
                $table->index('code', 'ship_via_codes_code_index');
            }
            // Speed up carrier+service lookups
            if (! $this->hasIndex('ship_via_codes', 'ship_via_codes_carrier_service_index')) {
                $table->index(['carrier_id', 'service_type'], 'ship_via_codes_carrier_service_index');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropIndex('import_batches_status_index');
            $table->dropIndex('import_batches_export_status_index');
        });

        Schema::table('addresses', function (Blueprint $table) {
            $table->dropIndex('addresses_ship_via_code_index');
        });

        Schema::table('transit_times', function (Blueprint $table) {
            $table->dropIndex('transit_times_addr_service_index');
            $table->dropIndex('transit_times_delivery_date_index');
        });

        Schema::table('ship_via_codes', function (Blueprint $table) {
            $table->dropIndex('ship_via_codes_code_index');
            $table->dropIndex('ship_via_codes_carrier_service_index');
        });
    }

    /**
     * Check if an index exists.
     */
    protected function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))
            ->contains(fn ($idx) => $idx['name'] === $index);
    }
};

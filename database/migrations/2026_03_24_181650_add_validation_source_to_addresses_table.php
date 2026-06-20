<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Validation sources:
     * - local_cache: Validated from local address correction cache (carrier invoice data)
     * - ups_api: Validated via UPS Address Validation API
     * - fedex_api: Validated via FedEx Address Resolution API
     * - usps_api: Validated via USPS Address API (future)
     * - manual: Manually entered/corrected by user
     */
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('validation_source', 20)->nullable()->after('validated_at')
                ->comment('Where validation came from: local_cache, ups_api, fedex_api, usps_api, manual');

            // Index for reporting queries
            $table->index(['validation_source', 'validated_at'], 'idx_validation_reporting');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropIndex('idx_validation_reporting');
            $table->dropColumn('validation_source');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A single carrier slug ('fedex', 'ups') behaves exactly as before; a chain
     * ('fedex_ups', 'ups_fedex') runs cache-first, then each carrier in order
     * until one returns a usable correction. Null falls back to the batch's
     * carrier_id slug for old batches.
     */
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->string('validation_engine')->nullable()->after('carrier_id');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn('validation_engine');
        });
    }
};

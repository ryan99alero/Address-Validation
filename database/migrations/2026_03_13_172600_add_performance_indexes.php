<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Addresses table indexes for faster batch queries and exports
        Schema::table('addresses', function (Blueprint $table) {
            // Index for filtering/sorting by creation date
            $table->index('created_at');

            // Index for source filtering
            $table->index('source');

            // Composite index for batch exports (batch + eager load optimization)
            $table->index(['import_batch_id', 'id']);
        });

        // Address corrections indexes for faster joins and sorting
        Schema::table('address_corrections', function (Blueprint $table) {
            // Single column index on address_id for faster lookups
            // (existing composite index address_id+carrier_id may not be optimal for all queries)
            $table->index('address_id', 'address_corrections_address_id_index');

            // Index for sorting by validation time
            $table->index('validated_at');

            // Index for sorting by confidence score
            $table->index('confidence_score');

            // Composite index for the "latest correction" subquery pattern
            $table->index(['address_id', 'id'], 'address_corrections_latest_lookup');
        });

        // Import batches indexes
        Schema::table('import_batches', function (Blueprint $table) {
            // Index for filtering by status (common query)
            $table->index('status');

            // Index for user's batches sorted by date
            $table->index(['imported_by', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
            $table->dropIndex(['source']);
            $table->dropIndex(['import_batch_id', 'id']);
        });

        Schema::table('address_corrections', function (Blueprint $table) {
            $table->dropIndex('address_corrections_address_id_index');
            $table->dropIndex(['validated_at']);
            $table->dropIndex(['confidence_score']);
            $table->dropIndex('address_corrections_latest_lookup');
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['imported_by', 'created_at']);
        });
    }
};

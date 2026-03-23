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

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropIndex(['imported_by', 'created_at']);
        });
    }
};

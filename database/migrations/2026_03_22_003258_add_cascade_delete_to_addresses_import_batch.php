<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Check if foreign key already exists using raw query
        $foreignKeys = DB::select("
            SELECT CONSTRAINT_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'addresses'
            AND COLUMN_NAME = 'import_batch_id'
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ");

        if (count($foreignKeys) > 0) {
            return;
        }

        // First, clean up any orphaned addresses (pointing to non-existent batches)
        DB::statement('
            DELETE FROM addresses
            WHERE import_batch_id IS NOT NULL
            AND import_batch_id NOT IN (SELECT id FROM import_batches)
        ');

        // Add foreign key constraint with cascade delete
        Schema::table('addresses', function (Blueprint $table) {
            $table->foreign('import_batch_id')
                ->references('id')
                ->on('import_batches')
                ->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropForeign(['import_batch_id']);
        });
    }
};

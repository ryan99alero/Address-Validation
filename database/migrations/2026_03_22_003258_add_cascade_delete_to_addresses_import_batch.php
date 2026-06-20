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
        // SQLite (used by the test suite) has no information_schema and cannot
        // ALTER a table to add a foreign key. The cascade FK is a production
        // (MySQL) concern, so skip it on SQLite.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        // Check if foreign key already exists using portable schema introspection
        $hasForeignKey = collect(Schema::getForeignKeys('addresses'))
            ->contains(fn (array $fk): bool => in_array('import_batch_id', $fk['columns'] ?? [], true));

        if ($hasForeignKey) {
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
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('addresses', function (Blueprint $table) {
            $table->dropForeign(['import_batch_id']);
        });
    }
};

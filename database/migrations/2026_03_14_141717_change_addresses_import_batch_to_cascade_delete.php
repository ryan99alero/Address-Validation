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
        Schema::table('addresses', function (Blueprint $table) {
            // Drop the existing foreign key constraint
            $table->dropForeign(['import_batch_id']);

            // Re-add with cascade on delete
            $table->foreign('import_batch_id')
                ->references('id')
                ->on('import_batches')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            // Drop the cascade foreign key
            $table->dropForeign(['import_batch_id']);

            // Re-add with null on delete (original behavior)
            $table->foreign('import_batch_id')
                ->references('id')
                ->on('import_batches')
                ->nullOnDelete();
        });
    }
};

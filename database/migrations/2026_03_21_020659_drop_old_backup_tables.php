<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Drops backup tables created during schema migration.
     */
    public function up(): void
    {
        // Drop in correct order due to foreign key constraints
        Schema::dropIfExists('transit_times_old');
        Schema::dropIfExists('address_corrections_old');
        Schema::dropIfExists('addresses_old');
    }

    /**
     * Reverse the migrations.
     *
     * These tables cannot be restored - this migration is irreversible.
     */
    public function down(): void
    {
        // Cannot restore dropped backup tables
    }
};

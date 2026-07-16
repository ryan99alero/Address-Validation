<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plants — a minimal lookup so plant IDs stop being free text (the form placeholder said
 * "Plant001" while the data says "PLANT002"). `code` IS the join value; ship_via_codes.plant_id
 * and import_batches.bestway_plant_id keep matching on it (no FK-id rewrite), the drift fix
 * comes from feeding dropdowns off this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plants', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plants');
    }
};

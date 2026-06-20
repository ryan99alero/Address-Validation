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
        Schema::create('charge_code_mappings', function (Blueprint $table) {
            $table->id();
            // null carrier_id = applies to any carrier.
            $table->foreignId('carrier_id')->nullable()->constrained('carriers')->cascadeOnDelete();
            // How to match a raw invoice charge line: by exact code or description substring.
            $table->string('match_type', 20)->default('code'); // code | description
            $table->string('match_value');
            $table->foreignId('charge_category_id')->constrained('charge_categories')->cascadeOnDelete();
            // Higher priority wins when multiple rules match.
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['carrier_id', 'match_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('charge_code_mappings');
    }
};

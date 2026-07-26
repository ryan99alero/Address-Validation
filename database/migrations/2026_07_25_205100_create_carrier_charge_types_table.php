<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner-editable per-carrier charge crosswalk: "what the carrier calls a charge" → "our
 * universal category". One row carries BOTH format identifiers (CSV header label + PDF line label,
 * plus an optional UPS CSV section-code qualifier), so the operator manages a single row per charge
 * type across formats. Consulted by ChargeCategoryResolver ahead of the legacy charge_code_mappings
 * fallback. A null charge_category_id = "needs review" (the operator's worklist).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_charge_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carrier_id')->nullable()->constrained('carriers')->cascadeOnDelete();
            $table->string('display_name');
            $table->string('csv_label')->nullable();
            $table->string('csv_code', 20)->nullable();
            $table->string('pdf_label')->nullable();
            $table->string('match_style', 10)->default('exact');
            $table->foreignId('charge_category_id')->nullable()->constrained('charge_categories')->nullOnDelete();
            $table->unsignedInteger('priority')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['carrier_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_charge_types');
    }
};

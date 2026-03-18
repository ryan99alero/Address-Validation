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
        Schema::create('ship_via_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique()->comment('Your internal code (e.g., 5137)');
            $table->string('carrier_code', 20)->nullable()->index()->comment('Carrier shorthand (e.g., FDG, GND, 03)');
            $table->foreignId('carrier_id')->nullable()->constrained('carriers')->nullOnDelete();
            $table->string('service_type', 50)->nullable()->comment('API service type (e.g., FEDEX_GROUND)');
            $table->string('service_name', 100)->comment('Display name (e.g., FedEx Ground)');
            $table->string('description')->nullable()->comment('Optional description');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['carrier_id', 'service_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ship_via_codes');
    }
};

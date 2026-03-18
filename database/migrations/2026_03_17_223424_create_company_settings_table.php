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
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();

            // Company Information
            $table->string('company_name')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            // Ship-From Address (used for transit time calculations)
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('state', 50)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country_code', 2)->default('US');

            // Carrier Account Numbers (optional, for future use)
            $table->string('ups_account_number')->nullable();
            $table->string('fedex_account_number')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};

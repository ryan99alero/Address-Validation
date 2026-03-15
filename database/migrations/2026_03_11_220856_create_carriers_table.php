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
        Schema::create('carriers', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // UPS, FedEx, USPS
            $table->string('slug')->unique(); // ups, fedex, usps
            $table->boolean('is_active')->default(true);
            $table->string('environment')->default('sandbox'); // sandbox, production
            $table->string('base_url')->nullable();
            $table->string('auth_type')->default('oauth2'); // oauth2, api_key, basic
            $table->text('auth_credentials')->nullable(); // encrypted JSON
            $table->integer('timeout_seconds')->default(30);
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carriers');
    }
};

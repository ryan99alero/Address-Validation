<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GUI-configurable connection to the external shipping database (SQL Server), used to
     * back-fill FedEx original recipient addresses by tracking number. Singleton row.
     * Replaces the .env-only `shipping` connection config.
     */
    public function up(): void
    {
        Schema::create('shipping_database_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            $table->string('driver')->default('sqlsrv');
            $table->string('host')->nullable();
            $table->string('port')->default('1433');
            $table->string('database')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable(); // encrypted at rest
            $table->string('table_name')->default('xCarrierShipping');
            $table->string('tracking_column')->default('trackingno');
            $table->boolean('encrypt')->default(false);
            $table->boolean('trust_server_certificate')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_database_settings');
    }
};

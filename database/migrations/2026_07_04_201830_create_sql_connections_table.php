<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Generic registry of external SQL database connections (SQL Server, etc.), each tagged
     * with a purpose (e.g. shipping address lookup) and a field map so the query adapts to
     * differently-named source columns. Replaces the single-purpose shipping_database_settings.
     */
    public function up(): void
    {
        Schema::create('sql_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('purpose')->nullable();
            $table->boolean('is_active')->default(false);
            $table->string('driver')->default('sqlsrv');
            $table->string('host')->nullable();
            $table->string('port')->default('1433');
            $table->string('database')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable(); // encrypted at rest
            $table->string('table_name')->nullable();
            $table->json('field_map')->nullable();
            $table->text('custom_query')->nullable();
            $table->boolean('encrypt')->default(false);
            $table->boolean('trust_server_certificate')->default(true);
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_status')->nullable();
            $table->timestamps();

            $table->index(['purpose', 'is_active']);
        });

        Schema::dropIfExists('shipping_database_settings');
    }

    public function down(): void
    {
        Schema::dropIfExists('sql_connections');
    }
};

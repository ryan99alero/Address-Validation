<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('Human-friendly connection name');
            $table->string('driver', 50)->comment('Integration driver: pace, generic_rest, etc.');
            $table->string('integration_method', 20)->default('api');
            $table->string('base_url')->nullable();
            $table->string('api_version', 20)->nullable();
            $table->string('auth_type', 50)->default('basic')->comment('basic, bearer, api_key, oauth2');
            $table->text('auth_credentials')->nullable()->comment('Encrypted JSON credentials');
            $table->integer('timeout_seconds')->default(30);
            $table->integer('retry_attempts')->default(3);
            $table->integer('rate_limit_per_minute')->nullable();
            $table->unsignedInteger('sync_interval_minutes')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('webhook_token', 64)->nullable()->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('driver');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_connections');
    }
};

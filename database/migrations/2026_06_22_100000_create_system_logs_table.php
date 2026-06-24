<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_logs', function (Blueprint $table) {
            $table->id();
            $table->string('category', 50)->comment('integration, api, system, device, user, error');
            $table->string('type', 100)->comment('sync, request, response, event, action');
            $table->string('level', 20)->default('info');
            $table->nullableMorphs('loggable');
            $table->string('status', 50)->nullable();
            $table->string('summary', 500);
            $table->text('description')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->json('counts')->nullable();
            $table->json('request_data')->nullable();
            $table->json('response_data')->nullable();
            $table->text('error_message')->nullable();
            $table->json('error_details')->nullable();
            $table->json('metadata')->nullable();
            $table->json('tags')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['category', 'created_at']);
            $table->index(['type', 'created_at']);
            $table->index(['level', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_logs');
    }
};

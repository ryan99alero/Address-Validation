<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_query_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('integration_connections')->cascadeOnDelete();
            $table->foreignId('object_id')->nullable()->constrained('integration_objects')->nullOnDelete();
            $table->string('name', 100)->comment('Template name');
            $table->text('description')->nullable();
            $table->string('object_name', 100)->comment('Root object to query');
            $table->json('fields')->comment('Array of field definitions (name + xpath)');
            $table->json('children')->nullable();
            $table->json('filter')->nullable();
            $table->json('sort')->nullable();
            $table->integer('default_limit')->default(100);
            $table->integer('max_limit')->default(1000);
            $table->integer('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_query_templates');
    }
};

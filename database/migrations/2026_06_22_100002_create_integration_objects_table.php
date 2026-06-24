<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_objects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('integration_connections')->cascadeOnDelete();
            $table->string('object_name', 100)->comment('API object name: Job, Customer, JobShipment, etc.');
            $table->string('display_name', 100);
            $table->text('description')->nullable();
            $table->string('primary_key_field', 100)->default('@id')->comment('XPath to primary key');
            $table->string('primary_key_type', 50)->default('Integer');
            $table->json('available_fields')->nullable()->comment('Fields discovered from the API');
            $table->json('available_children')->nullable();
            $table->text('default_filter')->nullable()->comment("Default XPath filter, e.g. @status = 'A'");
            $table->string('local_model', 100)->nullable()->comment('Laravel model class if mapped');
            $table->string('local_table', 100)->nullable();
            $table->boolean('sync_enabled')->default(false);
            $table->string('sync_direction', 20)->default('pull')->comment('pull, push, bidirectional');
            $table->string('api_method', 50)->default('loadValueObjects');
            $table->string('sync_frequency', 50)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['connection_id', 'object_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_objects');
    }
};

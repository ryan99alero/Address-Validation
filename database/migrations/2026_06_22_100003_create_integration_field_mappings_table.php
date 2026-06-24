<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_field_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('object_id')->constrained('integration_objects')->cascadeOnDelete();
            $table->string('local_table', 100)->nullable();
            $table->string('external_field', 100)->comment('External field name from API');
            $table->string('external_xpath')->comment('XPath to field in API response');
            $table->string('external_type', 50)->comment('String, Integer, Date, etc.');
            $table->string('local_field', 100)->comment('Local database column name');
            $table->string('local_type', 50)->comment('string, integer, datetime, etc.');
            $table->string('transform', 50)->nullable()->comment('date_ms_to_carbon, cents_to_dollars, etc.');
            $table->json('transform_options')->nullable();
            $table->boolean('sync_on_pull')->default(true);
            $table->boolean('sync_on_push')->default(false);
            $table->boolean('is_identifier')->default(false)->comment('Used to match records for upsert');
            $table->timestamps();

            $table->unique(['object_id', 'external_field']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_field_mappings');
    }
};

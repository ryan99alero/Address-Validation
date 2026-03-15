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
        Schema::create('export_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('target_system')->nullable(); // epace, ups_worldship, fedex_ship, generic
            $table->json('field_layout'); // Maps system fields to export positions/headers
            $table->string('file_format')->default('csv'); // csv, xlsx, fixed_width
            $table->string('delimiter')->default(','); // For CSV
            $table->boolean('include_header')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('export_templates');
    }
};

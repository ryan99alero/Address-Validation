<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_field_mappings', function (Blueprint $table) {
            $table->string('local_field', 100)->nullable()->change();
            $table->string('local_type', 50)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('integration_field_mappings', function (Blueprint $table) {
            $table->string('local_field', 100)->nullable(false)->change();
            $table->string('local_type', 50)->nullable(false)->change();
        });
    }
};

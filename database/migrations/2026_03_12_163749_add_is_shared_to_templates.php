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
        Schema::table('import_field_templates', function (Blueprint $table) {
            $table->boolean('is_shared')->default(true)->after('is_default');
        });

        Schema::table('export_templates', function (Blueprint $table) {
            $table->boolean('is_shared')->default(true)->after('include_header');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_field_templates', function (Blueprint $table) {
            $table->dropColumn('is_shared');
        });

        Schema::table('export_templates', function (Blueprint $table) {
            $table->dropColumn('is_shared');
        });
    }
};

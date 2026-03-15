<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->json('field_mappings')->nullable()->after('mapping_template_id');
            $table->unsignedInteger('validated_rows')->default(0)->after('failed_rows');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn(['name', 'field_mappings', 'validated_rows']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_connections', function (Blueprint $table) {
            $table->boolean('dry_run')->default(false)->after('validation_carriers');
        });
    }

    public function down(): void
    {
        Schema::table('integration_connections', function (Blueprint $table) {
            $table->dropColumn('dry_run');
        });
    }
};

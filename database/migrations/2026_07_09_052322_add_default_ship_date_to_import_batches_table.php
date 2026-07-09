<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('import_batches', 'default_ship_date')) {
                // Batch-wide Ship Date for BestWay (the transit clock start). A per-row Ship Date in
                // the file overrides this at import time.
                $table->date('default_ship_date')->nullable()->after('default_on_site_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            if (Schema::hasColumn('import_batches', 'default_ship_date')) {
                $table->dropColumn('default_ship_date');
            }
        });
    }
};

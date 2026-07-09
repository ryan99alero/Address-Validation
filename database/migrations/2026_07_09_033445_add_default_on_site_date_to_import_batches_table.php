<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('import_batches', 'default_on_site_date')) {
                // Batch-wide On-Site Date for BestWay optimization. A per-row Required On-Site Date
                // in the file overrides this at import time.
                $table->date('default_on_site_date')->nullable()->after('find_best_service');
            }
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            if (Schema::hasColumn('import_batches', 'default_on_site_date')) {
                $table->dropColumn('default_on_site_date');
            }
        });
    }
};

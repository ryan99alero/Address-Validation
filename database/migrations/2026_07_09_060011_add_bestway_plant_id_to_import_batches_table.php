<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('import_batches', 'bestway_plant_id')) {
                // User-selected plant for BestWay: resolve the chosen service to THIS plant's ShipVia
                // code. Null = fall back to the plant on each row's original Ship Via.
                $table->string('bestway_plant_id')->nullable()->after('default_ship_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            if (Schema::hasColumn('import_batches', 'bestway_plant_id')) {
                $table->dropColumn('bestway_plant_id');
            }
        });
    }
};

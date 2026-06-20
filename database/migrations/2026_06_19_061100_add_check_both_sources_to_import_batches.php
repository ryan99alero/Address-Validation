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
        Schema::table('import_batches', function (Blueprint $table) {
            // When true, validate against BOTH the invoice cache and the carrier
            // API; disagreements are flagged needs_review with candidates to pick.
            $table->boolean('check_both_sources')->default(false)->after('include_transit_times');
        });
    }

    public function down(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn('check_both_sources');
        });
    }
};

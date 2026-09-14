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
        Schema::table('carrier_shipments', function (Blueprint $table) {
            // prepaid | third_party | collect — who the carrier billed the freight to.
            $table->string('billing_type', 16)->nullable()->after('section');
            $table->index(['carrier_id', 'billing_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carrier_shipments', function (Blueprint $table) {
            $table->dropIndex(['carrier_id', 'billing_type']);
            $table->dropColumn('billing_type');
        });
    }
};

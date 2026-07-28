<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The carton mirror stored the Pace job # and customer id but not the names. Adding the
 * customer/CSR/salesperson NAMES lets a single carton row carry the full "who to bill / who owns
 * this" set, so the correction-cache Pace lookup persists it and views read it without a
 * chargeback row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carton_costs', function (Blueprint $table) {
            $table->string('pace_customer_name', 150)->nullable()->after('pace_customer_id');
            $table->string('pace_csr_name', 100)->nullable()->after('pace_customer_name');
            $table->string('pace_salesperson_name', 100)->nullable()->after('pace_csr_name');
        });
    }

    public function down(): void
    {
        Schema::table('carton_costs', function (Blueprint $table) {
            $table->dropColumn(['pace_customer_name', 'pace_csr_name', 'pace_salesperson_name']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chargeback_pushes', function (Blueprint $table): void {
            // Pace Job → customer/CSR/salesperson names, resolved in the same Carton lookup that already
            // returns pace_customer_id. Captured so closed-job charges (which can't be billed) can be
            // downloaded and sent to the responsible CSR / Sales Rep.
            $table->string('pace_customer_name')->nullable()->after('pace_customer_id');
            $table->string('pace_csr_name')->nullable()->after('pace_customer_name');
            $table->string('pace_salesperson_name')->nullable()->after('pace_csr_name');
        });
    }

    public function down(): void
    {
        Schema::table('chargeback_pushes', function (Blueprint $table): void {
            $table->dropColumn(['pace_customer_name', 'pace_csr_name', 'pace_salesperson_name']);
        });
    }
};

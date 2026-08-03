<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_supersessions', function (Blueprint $table): void {
            // Denormalized evidence for the Re-Corrections table columns: the tracking of the
            // correction that created this event (+ its Pace job/customer when known), so the view
            // shows them without a per-row lookup. Populated in rebuildSearchText().
            $table->string('tracking')->nullable()->after('reference_date');
            $table->string('pace_job', 50)->nullable()->after('tracking');
            $table->string('pace_customer_id', 50)->nullable()->after('pace_job');
            $table->string('pace_customer_name')->nullable()->after('pace_customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('address_supersessions', function (Blueprint $table): void {
            $table->dropColumn(['tracking', 'pace_job', 'pace_customer_id', 'pace_customer_name']);
        });
    }
};

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
        Schema::create('carton_costs', function (Blueprint $table) {
            $table->id();
            // Local mirror of the Pace "Carton" object (one per package/tracking). Recoup joins
            // carrier_charges to this by tracking_number, then bills (actual − ship_cost) back to
            // the customer. Populated by CartonCostSyncService from the configured carton source.
            $table->string('tracking_number', 40)->unique();
            $table->decimal('ship_cost', 10, 2)->default(0); // actual rated cost recorded at ship time (baseline)
            $table->date('ship_date')->nullable();           // disambiguates recycled tracking numbers
            $table->string('pace_job_number', 50)->nullable();
            $table->string('pace_customer_id', 50)->nullable();
            $table->timestamp('synced_at')->nullable();       // last pull from the carton source
            $table->timestamp('recouped_at')->nullable();     // set once (actual − ship_cost) is billed back; excludes from candidates
            $table->timestamps();

            $table->index('pace_customer_id');
            $table->index('ship_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carton_costs');
    }
};

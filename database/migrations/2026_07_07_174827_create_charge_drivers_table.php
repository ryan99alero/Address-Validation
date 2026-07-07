<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The driver catalog — the human/config layer for each charge "driver" (why we were charged).
 * Keyed by the App\Enums\ChargeDriver value the code switches on; holds the operator-editable
 * label, chargeback disposition, and Pace mapping that power the "Carrier Chargeback Codes" page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_drivers', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40)->unique();          // matches App\Enums\ChargeDriver value
            $table->string('label');
            $table->string('abbreviation', 16)->nullable();
            $table->string('description')->nullable();
            // customer_chargebackable | carrier_disputable | informational (App\Enums\ChargeDisposition)
            $table->string('disposition', 30)->default('informational');
            $table->string('color', 20)->nullable();       // badge color for the UI
            $table->string('pace_activity_code')->nullable();
            $table->boolean('push_to_pace')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_drivers');
    }
};

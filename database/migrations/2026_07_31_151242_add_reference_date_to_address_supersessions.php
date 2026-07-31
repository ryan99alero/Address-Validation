<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_supersessions', function (Blueprint $table): void {
            // The real business date of the re-correction — the correction-2 shipment's ship date,
            // else its invoice date. Shown/sorted instead of detected_at (the processing timestamp),
            // so a reviewer can judge how old a re-correction actually is.
            $table->date('reference_date')->nullable()->after('search_text');
            $table->index('reference_date');
        });
    }

    public function down(): void
    {
        Schema::table('address_supersessions', function (Blueprint $table): void {
            $table->dropIndex(['reference_date']);
            $table->dropColumn('reference_date');
        });
    }
};

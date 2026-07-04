<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stamp each charge with the source format it came from ('csv' | 'pdf'). Charges
     * stay append-only + first-writer-wins on dedup; this column lets description
     * precedence (CSV > PDF) be resolved at read time without mutating charges.
     */
    public function up(): void
    {
        Schema::table('carrier_charges', function (Blueprint $table) {
            $table->string('source_type', 8)->nullable()->after('weight');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_charges', function (Blueprint $table) {
            $table->dropColumn('source_type');
        });
    }
};

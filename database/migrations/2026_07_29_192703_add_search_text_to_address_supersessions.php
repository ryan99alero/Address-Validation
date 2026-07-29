<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_supersessions', function (Blueprint $table): void {
            // Denormalized lowercase haystack for the Re-Corrections search box: both corrections'
            // addresses (original + corrected), tracking numbers, invoice numbers, and Pace job/customer.
            $table->text('search_text')->nullable()->after('guard_result');
        });
    }

    public function down(): void
    {
        Schema::table('address_supersessions', function (Blueprint $table): void {
            $table->dropColumn('search_text');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third address line. When the engine extracts a secondary unit (STE/APT/etc.) out of
 * Address 1 into Address 2, any pre-existing Address 2 content shifts down to Address 3
 * rather than being appended/mashed together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->string('input_address_3')->nullable()->after('input_address_2');
            $table->string('output_address_3')->nullable()->after('output_address_2');
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table): void {
            $table->dropColumn(['input_address_3', 'output_address_3']);
        });
    }
};

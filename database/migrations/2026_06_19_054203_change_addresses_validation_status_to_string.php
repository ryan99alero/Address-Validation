<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Convert validation_status from a brittle ENUM to a varchar so new states
     * (e.g. needs_review) can be added in app code without further migrations,
     * consistent with how validation_source is already stored. Values are
     * enforced via Address model constants.
     */
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('validation_status', 20)->default('pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->enum('validation_status', ['pending', 'valid', 'invalid', 'ambiguous'])
                ->default('pending')
                ->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carrier_invoice_lines', function (Blueprint $table) {
            // Normalized Levenshtein distance between original and corrected address.
            $table->unsignedInteger('severity_score')->nullable()->after('charge_amount');
            // Distance bucket: formatting_only | micro | minor | major
            $table->string('severity_category', 20)->nullable()->after('severity_score');
            // The most significant component that changed: formatting_only |
            // suite_changed | zip_changed | state_changed | street_number_changed |
            // street_renamed | city_changed | other
            $table->string('change_type', 30)->nullable()->after('severity_category');

            $table->index('severity_category');
            $table->index('change_type');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_invoice_lines', function (Blueprint $table) {
            $table->dropIndex(['severity_category']);
            $table->dropIndex(['change_type']);
            $table->dropColumn(['severity_score', 'severity_category', 'change_type']);
        });
    }
};

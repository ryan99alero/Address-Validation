<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corrected_addresses', function (Blueprint $table): void {
            // Non-null = this form was superseded; the engine resolves to superseded_by_id's terminal.
            // History-only pointer (never walked on the hot lookup path — variants carry the binding).
            $table->foreignId('superseded_by_id')->nullable()->after('first_carrier_id')
                ->constrained('corrected_addresses')->nullOnDelete();
            $table->timestamp('superseded_at')->nullable()->after('superseded_by_id');
            $table->string('supersede_reason', 30)->nullable()->after('superseded_at');
        });
    }

    public function down(): void
    {
        Schema::table('corrected_addresses', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('superseded_by_id');
            $table->dropColumn(['superseded_at', 'supersede_reason']);
        });
    }
};

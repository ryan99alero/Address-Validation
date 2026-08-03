<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_supersessions', function (Blueprint $table): void {
            // A human-edited "corrected to" address (company/name/address/city/state/zip). When set, it
            // is what the engine supersedes to on Apply, and the event is flagged as manually edited.
            $table->json('corrected_override')->nullable()->after('new_snapshot');
            $table->timestamp('corrected_edited_at')->nullable()->after('corrected_override');
            $table->foreignId('corrected_edited_by')->nullable()->after('corrected_edited_at')
                ->constrained('users')->nullOnDelete();
            $table->index('corrected_edited_at');
        });
    }

    public function down(): void
    {
        Schema::table('address_supersessions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('corrected_edited_by');
            $table->dropIndex(['corrected_edited_at']);
            $table->dropColumn(['corrected_override', 'corrected_edited_at']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recent_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 16);                     // 'page' | 'record'
            $table->string('route_name');
            $table->string('record_key')->default('');      // '' for pages; PK (string, for future ULID/UUID) for records
            $table->string('filament_class')->nullable();   // resource or page class → canAccess() at read time
            $table->string('label');                        // denormalized at visit time
            $table->string('url', 500);
            $table->unsignedInteger('visit_count')->default(1);
            $table->timestamp('visited_at');
            $table->timestamps();

            // Non-null record_key avoids MySQL's "NULLs are distinct" duplicate-page bug.
            $table->unique(['user_id', 'route_name', 'record_key']);
            $table->index(['user_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recent_items');
    }
};

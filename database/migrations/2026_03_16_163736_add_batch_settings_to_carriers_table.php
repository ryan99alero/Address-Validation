<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carriers', function (Blueprint $table) {
            $table->integer('chunk_size')->default(100)->after('is_active')
                ->comment('Number of addresses to process per batch');
            $table->integer('concurrent_requests')->default(10)->after('chunk_size')
                ->comment('Max parallel HTTP requests within a chunk');
            $table->integer('rate_limit_per_minute')->nullable()->after('concurrent_requests')
                ->comment('Optional rate limit (requests per minute)');
            $table->boolean('supports_native_batch')->default(false)->after('rate_limit_per_minute')
                ->comment('Whether the API supports native batch requests');
            $table->integer('native_batch_size')->nullable()->after('supports_native_batch')
                ->comment('Max addresses per native batch API call');
        });
    }

    public function down(): void
    {
        Schema::table('carriers', function (Blueprint $table) {
            $table->dropColumn([
                'chunk_size',
                'concurrent_requests',
                'rate_limit_per_minute',
                'supports_native_batch',
                'native_batch_size',
            ]);
        });
    }
};

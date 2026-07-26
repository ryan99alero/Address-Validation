<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the categories hard-referenced by name in code (the resolver's correction logic, the
 * base-transport third-party heuristic, the rollup service) as "system": renameable/deletable
 * protection in the new Fee Categories CRUD. Non-system categories become fully editable.
 */
return new class extends Migration
{
    private const SYSTEM_CATEGORIES = [
        'Base Transportation',
        'Address Correction',
        'Audit / Correction Fee',
        'Discount / Credit',
    ];

    public function up(): void
    {
        Schema::table('charge_categories', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('is_active');
        });

        DB::table('charge_categories')
            ->whereIn('name', self::SYSTEM_CATEGORIES)
            ->update(['is_system' => true]);
    }

    public function down(): void
    {
        Schema::table('charge_categories', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};

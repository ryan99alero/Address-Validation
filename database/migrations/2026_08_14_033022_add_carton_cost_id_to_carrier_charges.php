<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The era-correct link from a charge line to its Pace carton. UPS recycles tracking numbers, so
     * a bare tracking join marries a 2013 charge to a 2026 carton (wrong job). This id is stamped at
     * carton-sync time, scoped to the invoice being synced, so each charge points at the carton for
     * its OWN era; old (gated-out) invoices leave it null = correctly unattributed. Deliberately NOT
     * a DB foreign key: the carton is created async after the charge (and absent entirely for old /
     * non-Pace shipments), and charges are deleted+recreated on re-import — a hard FK would churn and
     * reject inserts. It's a derived, cheaply recomputable pointer, enforced by the resolver.
     */
    public function up(): void
    {
        Schema::table('carrier_charges', function (Blueprint $table): void {
            // Appended (no ->after) so MySQL adds it INSTANT on the multi-million-row table.
            $table->unsignedBigInteger('carton_cost_id')->nullable();
            $table->index('carton_cost_id');
        });
    }

    public function down(): void
    {
        Schema::table('carrier_charges', function (Blueprint $table): void {
            $table->dropIndex(['carton_cost_id']);
            $table->dropColumn('carton_cost_id');
        });
    }
};

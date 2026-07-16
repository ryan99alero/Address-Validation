<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link ship-via codes to structured carrier accounts (replacing the free-text account_number
 * / account_owner stopgap, which stay for the transition and get dropped later).
 *
 *  - carrier_account_id — the account billed on a SENDER-paid code (normally ours).
 *  - third_party_account_id — "3rd Party account usage": the existing account billed on a
 *    THIRD-PARTY code (normally the customer's own account). BestWay pools by the owner of
 *    whichever of these is set for the code's payment_type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ship_via_codes', function (Blueprint $table): void {
            $table->foreignId('carrier_account_id')->nullable()->after('account_owner')->constrained('carrier_accounts')->nullOnDelete();
            $table->foreignId('third_party_account_id')->nullable()->after('carrier_account_id')->constrained('carrier_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ship_via_codes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('carrier_account_id');
            $table->dropConstrainedForeignId('third_party_account_id');
        });
    }
};

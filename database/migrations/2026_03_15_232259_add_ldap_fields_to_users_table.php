<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('auth_type')->default('local')->after('id'); // local or ldap
            $table->string('ldap_guid')->nullable()->unique()->after('auth_type'); // AD objectGUID
            $table->string('ldap_domain')->nullable()->after('ldap_guid'); // Domain name
            $table->timestamp('ldap_synced_at')->nullable()->after('ldap_domain');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['auth_type', 'ldap_guid', 'ldap_domain', 'ldap_synced_at']);
        });
    }
};

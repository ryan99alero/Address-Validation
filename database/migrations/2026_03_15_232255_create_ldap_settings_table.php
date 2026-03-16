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
        Schema::create('ldap_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);

            // Connection settings
            $table->string('host')->nullable();
            $table->integer('port')->default(389);
            $table->boolean('use_ssl')->default(false);
            $table->boolean('use_tls')->default(false);
            $table->string('base_dn')->nullable();

            // Bind credentials (encrypted)
            $table->text('bind_username')->nullable();
            $table->text('bind_password')->nullable();

            // User settings
            $table->string('user_ou')->nullable(); // OU to search for users
            $table->string('login_attribute')->default('sAMAccountName'); // or userPrincipalName
            $table->string('email_attribute')->default('mail');
            $table->string('name_attribute')->default('cn');

            // Group settings
            $table->string('admin_group')->nullable(); // AD group that grants admin access
            $table->string('user_filter')->nullable(); // Additional LDAP filter

            // Connection test results
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_success')->nullable();
            $table->text('last_test_message')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ldap_settings');
    }
};

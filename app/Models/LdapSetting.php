<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class LdapSetting extends Model
{
    protected $fillable = [
        'enabled',
        'host',
        'port',
        'use_ssl',
        'use_tls',
        'base_dn',
        'bind_username',
        'bind_password',
        'user_ou',
        'login_attribute',
        'email_attribute',
        'name_attribute',
        'admin_group',
        'user_filter',
        'last_tested_at',
        'last_test_success',
        'last_test_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port' => 'integer',
            'use_ssl' => 'boolean',
            'use_tls' => 'boolean',
            'last_tested_at' => 'datetime',
            'last_test_success' => 'boolean',
        ];
    }

    /**
     * Get the singleton instance of LDAP settings.
     */
    public static function instance(): self
    {
        return self::firstOrCreate([], [
            'enabled' => false,
            'port' => 389,
            'login_attribute' => 'sAMAccountName',
            'email_attribute' => 'mail',
            'name_attribute' => 'cn',
        ]);
    }

    /**
     * Check if LDAP is configured and enabled.
     */
    public static function isEnabled(): bool
    {
        $settings = self::first();

        return $settings
            && $settings->enabled
            && ! empty($settings->host)
            && ! empty($settings->base_dn);
    }

    /**
     * Set the bind password (encrypted).
     */
    public function setBindPasswordAttribute(?string $value): void
    {
        $this->attributes['bind_password'] = $value ? Crypt::encryptString($value) : null;
    }

    /**
     * Get the bind password (decrypted).
     */
    public function getBindPasswordAttribute(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        try {
            return Crypt::decryptString($value);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get the decrypted bind password for LDAP connection.
     */
    public function getDecryptedBindPassword(): ?string
    {
        return $this->bind_password;
    }

    /**
     * Get the full LDAP URL.
     */
    public function getLdapUrl(): string
    {
        $protocol = $this->use_ssl ? 'ldaps' : 'ldap';

        return "{$protocol}://{$this->host}:{$this->port}";
    }

    /**
     * Get the user search base DN.
     */
    public function getUserSearchBase(): string
    {
        if ($this->user_ou) {
            return "{$this->user_ou},{$this->base_dn}";
        }

        return $this->base_dn;
    }

    /**
     * Record a connection test result.
     */
    public function recordTestResult(bool $success, string $message): void
    {
        $this->update([
            'last_tested_at' => now(),
            'last_test_success' => $success,
            'last_test_message' => $message,
        ]);
    }
}

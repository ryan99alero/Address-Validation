<?php

namespace App\Services;

use App\Models\LdapSetting;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use LdapRecord\Connection;
use LdapRecord\Models\ActiveDirectory\User as LdapUser;

class LdapService
{
    protected ?Connection $connection = null;

    protected ?LdapSetting $settings = null;

    /**
     * Get the LDAP settings.
     */
    public function getSettings(): LdapSetting
    {
        if (! $this->settings) {
            $this->settings = LdapSetting::instance();
        }

        return $this->settings;
    }

    /**
     * Check if LDAP is enabled and configured.
     */
    public function isEnabled(): bool
    {
        return LdapSetting::isEnabled();
    }

    /**
     * Get or create the LDAP connection.
     */
    public function getConnection(): Connection
    {
        if ($this->connection) {
            return $this->connection;
        }

        $settings = $this->getSettings();

        $config = [
            'hosts' => [$settings->host],
            'port' => $settings->port,
            'base_dn' => $settings->base_dn,
            'username' => $settings->bind_username,
            'password' => $settings->getDecryptedBindPassword(),
            'use_ssl' => $settings->use_ssl,
            'use_tls' => $settings->use_tls,
            'timeout' => 5,
            'options' => [
                LDAP_OPT_REFERRALS => 0,  // Don't follow referrals (fixes "Operations error")
                LDAP_OPT_PROTOCOL_VERSION => 3,
            ],
        ];

        $this->connection = new Connection($config);

        return $this->connection;
    }

    /**
     * Test the LDAP connection.
     */
    public function testConnection(): array
    {
        $settings = $this->getSettings();

        Log::info('LDAP Test Connection - Starting', [
            'host' => $settings->host,
            'port' => $settings->port,
            'bind_username' => $settings->bind_username,
            'password_length' => strlen($settings->getDecryptedBindPassword() ?? ''),
            'base_dn' => $settings->base_dn,
            'use_ssl' => $settings->use_ssl,
            'use_tls' => $settings->use_tls,
        ]);

        try {
            // Reset connection to ensure fresh attempt
            $this->connection = null;

            $connection = $this->getConnection();

            Log::info('LDAP Test Connection - Attempting connect/bind...');

            $connection->connect();

            Log::info('LDAP Test Connection - SUCCESS');

            $settings->recordTestResult(true, 'Connection successful');

            return [
                'success' => true,
                'message' => 'Successfully connected to LDAP server',
            ];
        } catch (Exception $e) {
            $message = 'Connection failed: '.$e->getMessage();

            Log::error('LDAP Test Connection - FAILED', [
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
                'trace' => $e->getTraceAsString(),
            ]);

            $settings->recordTestResult(false, $message);

            return [
                'success' => false,
                'message' => $message,
            ];
        }
    }

    /**
     * Authenticate a user against LDAP.
     */
    public function authenticate(string $username, string $password): ?User
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $ldapUser = $this->findLdapUser($username);

            if (! $ldapUser) {
                return null;
            }

            // Try to bind with the user's credentials
            $connection = $this->getConnection();
            $userDn = $ldapUser->getDn();

            if (! $connection->auth()->attempt($userDn, $password)) {
                return null;
            }

            // Authentication successful - sync user to local database
            return $this->syncUser($ldapUser);

        } catch (Exception $e) {
            Log::error('LDAP authentication failed', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Find a user in LDAP by username.
     */
    public function findLdapUser(string $username): ?LdapUser
    {
        try {
            $connection = $this->getConnection();

            Log::info('LDAP connecting...', [
                'host' => $this->getSettings()->host,
                'port' => $this->getSettings()->port,
                'bind_user' => $this->getSettings()->bind_username,
            ]);

            $connection->connect();

            Log::info('LDAP connected and bound successfully');

            $settings = $this->getSettings();
            $searchBase = $settings->getUserSearchBase();

            // Build the LDAP filter
            $filter = "(&(objectClass=user)({$settings->login_attribute}={$username}))";

            // Add custom filter if specified
            if ($settings->user_filter) {
                $userFilter = $settings->user_filter;
                // Ensure the filter is wrapped in parentheses
                if (! str_starts_with($userFilter, '(')) {
                    $userFilter = "({$userFilter})";
                }
                $filter = "(&{$filter}{$userFilter})";
            }

            Log::info('LDAP user search', [
                'username' => $username,
                'search_base' => $searchBase,
                'filter' => $filter,
            ]);

            // Use LdapRecord's Ldap wrapper search method
            $ldap = $connection->getLdapConnection();

            // Disable referral chasing (fixes "Operations error" in AD)
            $ldap->setOption(LDAP_OPT_REFERRALS, 0);

            $result = $ldap->search($searchBase, $filter, ['*'], false, 1);

            if ($result === false) {
                $error = $ldap->getLastError();
                Log::error('LDAP search failed', [
                    'error' => $error,
                    'search_base' => $searchBase,
                    'filter' => $filter,
                ]);

                return null;
            }

            $entries = $ldap->getEntries($result);

            if ($entries['count'] === 0) {
                Log::info('LDAP user not found', ['username' => $username]);

                return null;
            }

            // Create LdapUser from the entry
            $entry = $entries[0];
            $ldapUser = new LdapUser;
            $ldapUser->setRawAttributes($entry);

            Log::info('LDAP user found', [
                'dn' => $entry['dn'] ?? 'unknown',
                'name' => $entry[$settings->name_attribute][0] ?? 'unknown',
            ]);

            return $ldapUser;

        } catch (Exception $e) {
            Log::error('LDAP user search failed', [
                'username' => $username,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Sync an LDAP user to the local database.
     */
    public function syncUser(LdapUser $ldapUser): User
    {
        $settings = $this->getSettings();

        // Get the objectGUID as a unique identifier
        $guid = $ldapUser->getConvertedGuid();

        // Get user attributes
        $email = $ldapUser->getFirstAttribute($settings->email_attribute);
        $name = $ldapUser->getFirstAttribute($settings->name_attribute);
        $username = $ldapUser->getFirstAttribute($settings->login_attribute);

        // Use email or construct one from username
        if (empty($email)) {
            $email = $username.'@'.$this->getDomainFromDn($settings->base_dn);
        }

        // Check if user is in admin group
        $isAdmin = $this->isUserInAdminGroup($ldapUser);

        // Find or create the local user
        $user = User::where('ldap_guid', $guid)->first();

        if (! $user) {
            // Try to find by email (might be a local user being converted)
            $user = User::where('email', $email)->first();
        }

        if ($user) {
            // Update existing user
            $user->update([
                'name' => $name ?: $username,
                'email' => $email,
                'auth_type' => 'ldap',
                'ldap_guid' => $guid,
                'ldap_domain' => $this->getDomainFromDn($settings->base_dn),
                'ldap_synced_at' => now(),
                'is_admin' => $isAdmin,
            ]);
        } else {
            // Create new user
            $user = User::create([
                'name' => $name ?: $username,
                'email' => $email,
                'password' => Hash::make(bin2hex(random_bytes(16))), // Random password
                'auth_type' => 'ldap',
                'ldap_guid' => $guid,
                'ldap_domain' => $this->getDomainFromDn($settings->base_dn),
                'ldap_synced_at' => now(),
                'is_admin' => $isAdmin,
                'email_verified_at' => now(),
            ]);
        }

        return $user;
    }

    /**
     * Check if an LDAP user is in the admin group.
     */
    protected function isUserInAdminGroup(LdapUser $ldapUser): bool
    {
        $settings = $this->getSettings();

        if (empty($settings->admin_group)) {
            return false;
        }

        $memberOf = $ldapUser->getAttribute('memberof') ?? [];

        foreach ($memberOf as $groupDn) {
            if (stripos($groupDn, $settings->admin_group) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract domain name from base DN.
     */
    protected function getDomainFromDn(string $baseDn): string
    {
        $parts = [];
        preg_match_all('/DC=([^,]+)/i', $baseDn, $matches);

        if (! empty($matches[1])) {
            return implode('.', $matches[1]);
        }

        return 'local';
    }

    /**
     * Search for users in LDAP (for user browser).
     *
     * @return array<array{dn: string, name: string, email: string, username: string}>
     */
    public function searchUsers(string $search = '', int $limit = 50): array
    {
        if (! $this->isEnabled()) {
            return [];
        }

        try {
            $connection = $this->getConnection();
            $connection->connect();

            $settings = $this->getSettings();

            $query = $connection->query()
                ->in($settings->getUserSearchBase())
                ->where('objectclass', '=', 'person')
                ->limit($limit);

            if ($search) {
                $query->whereContains($settings->login_attribute, $search)
                    ->orWhereContains($settings->name_attribute, $search)
                    ->orWhereContains($settings->email_attribute, $search);
            }

            $users = [];
            foreach ($query->get() as $result) {
                $ldapUser = new LdapUser;
                $ldapUser->setRawAttributes($result);

                $users[] = [
                    'dn' => $ldapUser->getDn(),
                    'name' => $ldapUser->getFirstAttribute($settings->name_attribute),
                    'email' => $ldapUser->getFirstAttribute($settings->email_attribute),
                    'username' => $ldapUser->getFirstAttribute($settings->login_attribute),
                ];
            }

            return $users;

        } catch (Exception $e) {
            Log::error('LDAP user search failed', [
                'search' => $search,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}

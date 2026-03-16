<?php

namespace App\Filament\Pages\Auth;

use App\Models\LdapSetting;
use App\Models\User;
use App\Services\LdapService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    /**
     * Handle the form submission.
     */
    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();
        $credentials = $this->getCredentialsFromFormData($data);

        // Try LDAP authentication first if enabled
        if (LdapSetting::isEnabled()) {
            $user = $this->attemptLdapAuthentication($credentials);

            if ($user) {
                Auth::login($user, $data['remember'] ?? false);
                session()->regenerate();

                return app(LoginResponse::class);
            }
        }

        // Fall back to local authentication
        $user = $this->attemptLocalAuthentication($credentials);

        if ($user) {
            Auth::login($user, $data['remember'] ?? false);
            session()->regenerate();

            return app(LoginResponse::class);
        }

        $this->throwFailureValidationException();
    }

    /**
     * Attempt LDAP authentication.
     */
    protected function attemptLdapAuthentication(array $credentials): ?User
    {
        try {
            $ldapService = new LdapService;

            // The username could be email or sAMAccountName depending on settings
            $username = $credentials['email'];

            // If it looks like an email and LDAP uses sAMAccountName, extract username part
            $settings = $ldapService->getSettings();
            if ($settings->login_attribute === 'sAMAccountName' && str_contains($username, '@')) {
                $username = explode('@', $username)[0];
            }

            Log::info('LDAP authentication attempt', [
                'username' => $username,
                'login_attribute' => $settings->login_attribute,
            ]);

            $user = $ldapService->authenticate($username, $credentials['password']);

            Log::info('LDAP authentication result', [
                'success' => $user !== null,
                'user_id' => $user?->id,
            ]);

            return $user;
        } catch (\Exception $e) {
            // Log but don't expose LDAP errors to user
            Log::warning('LDAP authentication error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Attempt local authentication.
     */
    protected function attemptLocalAuthentication(array $credentials): ?User
    {
        // Find user by email
        $user = User::where('email', $credentials['email'])->first();

        if (! $user) {
            return null;
        }

        // For LDAP users without local password, don't allow local auth
        // (they must authenticate via LDAP)
        if ($user->isLdapUser()) {
            // Check if LDAP is down - if so, we might want to allow cached auth
            // For now, require LDAP for LDAP users
            if (LdapSetting::isEnabled()) {
                return null;
            }
        }

        // Verify password
        if (! Hash::check($credentials['password'], $user->password)) {
            return null;
        }

        return $user;
    }

    /**
     * Get the form username/email field.
     */
    protected function getEmailFormComponent(): Component
    {
        $ldapEnabled = LdapSetting::isEnabled();
        $settings = $ldapEnabled ? LdapSetting::instance() : null;

        // Determine label based on LDAP login attribute
        $label = 'Email';
        $placeholder = 'your@email.com';

        if ($ldapEnabled && $settings) {
            if ($settings->login_attribute === 'sAMAccountName') {
                $label = 'Username or Email';
                $placeholder = 'username or email@domain.com';
            }
        }

        return TextInput::make('email')
            ->label($label)
            ->placeholder($placeholder)
            ->required()
            ->autocomplete()
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    /**
     * Throw a validation exception for failed authentication.
     */
    protected function throwFailureValidationException(): never
    {
        throw ValidationException::withMessages([
            'data.email' => 'These credentials do not match our records.',
        ]);
    }
}

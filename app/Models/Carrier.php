<?php

namespace App\Models;

use Database\Factories\CarrierFactory;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class Carrier extends Model
{
    /** @use HasFactory<CarrierFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'is_active',
        'chunk_size',
        'concurrent_requests',
        'rate_limit_per_minute',
        'supports_native_batch',
        'native_batch_size',
        'environment',
        'sandbox_url',
        'production_url',
        'auth_type',
        'auth_credentials',
        'timeout_seconds',
        'last_connected_at',
        'last_error_at',
        'last_error_message',
    ];

    protected $hidden = [
        'auth_credentials',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'chunk_size' => 'integer',
            'concurrent_requests' => 'integer',
            'rate_limit_per_minute' => 'integer',
            'supports_native_batch' => 'boolean',
            'native_batch_size' => 'integer',
            'timeout_seconds' => 'integer',
            'last_connected_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    /**
     * Get decrypted credentials.
     *
     * @return array<string, mixed>
     */
    public function getCredentials(): array
    {
        if (empty($this->auth_credentials)) {
            return [];
        }

        try {
            return json_decode(Crypt::decryptString($this->auth_credentials), true) ?? [];
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Set encrypted credentials.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function setCredentials(array $credentials): void
    {
        $this->auth_credentials = Crypt::encryptString(json_encode($credentials));
    }

    /**
     * Get a specific credential value.
     */
    public function getCredential(string $key, mixed $default = null): mixed
    {
        return $this->getCredentials()[$key] ?? $default;
    }

    /**
     * Mark carrier as successfully connected.
     */
    public function markConnected(): void
    {
        $this->update([
            'last_connected_at' => now(),
            'last_error_at' => null,
            'last_error_message' => null,
        ]);
    }

    /**
     * Mark carrier as having an error.
     */
    public function markError(string $message): void
    {
        $this->update([
            'last_error_at' => now(),
            'last_error_message' => $message,
        ]);
    }

    /**
     * Check if carrier has recent errors.
     */
    public function hasRecentErrors(int $minutesThreshold = 60): bool
    {
        return $this->last_error_at && $this->last_error_at->diffInMinutes(now()) < $minutesThreshold;
    }

    /**
     * Get the base URL for the current environment.
     */
    public function getBaseUrl(): string
    {
        if ($this->environment === 'production') {
            return $this->production_url ?? $this->getDefaultProductionUrl();
        }

        return $this->sandbox_url ?? $this->getDefaultSandboxUrl();
    }

    /**
     * Get the default sandbox URL for this carrier.
     */
    public function getDefaultSandboxUrl(): string
    {
        return match ($this->slug) {
            'ups' => 'https://wwwcie.ups.com',
            'fedex' => 'https://apis-sandbox.fedex.com',
            'smarty' => 'https://us-street.api.smarty.com',
            default => '',
        };
    }

    /**
     * Get the default production URL for this carrier.
     */
    public function getDefaultProductionUrl(): string
    {
        return match ($this->slug) {
            'ups' => 'https://onlinetools.ups.com',
            'fedex' => 'https://apis.fedex.com',
            'smarty' => 'https://us-street.api.smarty.com',
            default => '',
        };
    }

    // Relationships

    public function corrections(): HasMany
    {
        return $this->hasMany(AddressCorrection::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ImportBatch::class);
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBySlug($query, string $slug)
    {
        return $query->where('slug', $slug);
    }
}

<?php

namespace App\Models;

use Database\Factories\IntegrationConnectionFactory;
use Exception;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Crypt;

class IntegrationConnection extends Model
{
    /** @use HasFactory<IntegrationConnectionFactory> */
    use HasFactory;

    public const METHOD_API = 'api';

    public const METHOD_FLATFILE = 'flatfile';

    public const DRIVER_PACE = 'pace';

    public const DRIVER_GENERIC_REST = 'generic_rest';

    protected $fillable = [
        'name',
        'driver',
        'integration_method',
        'base_url',
        'api_version',
        'auth_type',
        'auth_credentials',
        'timeout_seconds',
        'retry_attempts',
        'rate_limit_per_minute',
        'validation_carriers',
        'dry_run',
        'chargeback_push_enabled',
        'chargeback_record_only',
        'correction_cache_min_lookup',
        'is_active',
        'last_connected_at',
        'last_error_at',
        'last_error_message',
        'sync_interval_minutes',
        'last_synced_at',
        'webhook_token',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'auth_credentials',
        'webhook_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_connected_at' => 'datetime',
            'last_error_at' => 'datetime',
            'timeout_seconds' => 'integer',
            'retry_attempts' => 'integer',
            'rate_limit_per_minute' => 'integer',
            'validation_carriers' => 'array',
            'dry_run' => 'boolean',
            'chargeback_push_enabled' => 'boolean',
            'chargeback_record_only' => 'boolean',
            'correction_cache_min_lookup' => 'integer',
            'sync_interval_minutes' => 'integer',
            'last_synced_at' => 'datetime',
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

    public function getCredential(string $key, mixed $default = null): mixed
    {
        return $this->getCredentials()[$key] ?? $default;
    }

    public function markConnected(): void
    {
        $this->update([
            'last_connected_at' => now(),
            'last_error_at' => null,
            'last_error_message' => null,
        ]);
    }

    public function markError(string $message): void
    {
        $this->update([
            'last_error_at' => now(),
            'last_error_message' => $message,
        ]);
    }

    public function hasRecentErrors(int $minutesThreshold = 60): bool
    {
        return $this->last_error_at && $this->last_error_at->diffInMinutes(now()) < $minutesThreshold;
    }

    // Sync scheduling

    public function isPollingEnabled(): bool
    {
        return $this->is_active && $this->sync_interval_minutes > 0;
    }

    public function isDueForSync(): bool
    {
        if (! $this->isPollingEnabled()) {
            return false;
        }

        if ($this->last_synced_at === null) {
            return true;
        }

        return $this->last_synced_at->addMinutes($this->sync_interval_minutes)->isPast();
    }

    public function markSynced(): void
    {
        $this->update(['last_synced_at' => now()]);
    }

    public function isPushMode(): bool
    {
        return $this->sync_interval_minutes <= 0;
    }

    // Webhook token (for pull-trigger webhooks)

    public function generateWebhookToken(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->update(['webhook_token' => $token]);

        return $token;
    }

    public function getOrCreateWebhookToken(): string
    {
        if (! empty($this->webhook_token)) {
            return $this->webhook_token;
        }

        return $this->generateWebhookToken();
    }

    public function getWebhookUrl(?string $objectName = null): string
    {
        $token = $this->getOrCreateWebhookToken();

        return url("/api/integrations/pace/{$token}");
    }

    // Relationships

    public function objects(): HasMany
    {
        return $this->hasMany(IntegrationObject::class, 'connection_id');
    }

    public function queryTemplates(): HasMany
    {
        return $this->hasMany(IntegrationQueryTemplate::class, 'connection_id');
    }

    public function syncLogs(): MorphMany
    {
        return $this->morphMany(SystemLog::class, 'loggable')
            ->where('category', SystemLog::CATEGORY_INTEGRATION);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Scopes

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByDriver($query, string $driver)
    {
        return $query->where('driver', $driver);
    }

    public function scopePollingEnabled($query)
    {
        return $query->where('is_active', true)->where('sync_interval_minutes', '>', 0);
    }

    // Integration type helpers

    public function isApiMethod(): bool
    {
        return $this->integration_method === self::METHOD_API;
    }

    /**
     * The unified "Integration Type" options shown in the admin form.
     *
     * @return array<string, string>
     */
    public static function getIntegrationTypes(): array
    {
        return [
            'pace_api' => 'Pace / ePace ERP (API)',
            'generic_api' => 'Generic REST API',
        ];
    }

    /**
     * @return array{driver: string, method: string}
     */
    public static function parseIntegrationType(string $type): array
    {
        return match ($type) {
            'pace_api' => ['driver' => self::DRIVER_PACE, 'method' => self::METHOD_API],
            'generic_api' => ['driver' => self::DRIVER_GENERIC_REST, 'method' => self::METHOD_API],
            default => ['driver' => $type, 'method' => self::METHOD_API],
        };
    }

    public function getIntegrationType(): string
    {
        return match ($this->driver) {
            self::DRIVER_PACE => 'pace_api',
            default => 'generic_api',
        };
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class SystemLog extends Model
{
    use HasFactory;

    // Log categories
    public const CATEGORY_INTEGRATION = 'integration';

    public const CATEGORY_API = 'api';

    public const CATEGORY_SYSTEM = 'system';

    public const CATEGORY_DEVICE = 'device';

    public const CATEGORY_USER = 'user';

    public const CATEGORY_ERROR = 'error';

    // Log levels
    public const LEVEL_DEBUG = 'debug';

    public const LEVEL_INFO = 'info';

    public const LEVEL_WARNING = 'warning';

    public const LEVEL_ERROR = 'error';

    public const LEVEL_CRITICAL = 'critical';

    // Common statuses
    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PARTIAL = 'partial';

    protected $fillable = [
        'category',
        'type',
        'level',
        'loggable_type',
        'loggable_id',
        'status',
        'summary',
        'description',
        'started_at',
        'completed_at',
        'duration_ms',
        'counts',
        'request_data',
        'response_data',
        'error_message',
        'error_details',
        'metadata',
        'tags',
        'user_id',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'duration_ms' => 'integer',
        'counts' => 'array',
        'request_data' => 'array',
        'response_data' => 'array',
        'error_details' => 'array',
        'metadata' => 'array',
        'tags' => 'array',
    ];

    // ========================================
    // Static factory methods
    // ========================================

    /**
     * Start an integration sync operation log
     */
    public static function startIntegrationSync(
        Model $connection,
        string $operation,
        ?Model $object = null,
        ?array $requestData = null
    ): self {
        $objectName = $object?->object_name ?? 'all objects';

        return self::create([
            'category' => self::CATEGORY_INTEGRATION,
            'type' => 'sync',
            'level' => self::LEVEL_INFO,
            'loggable_type' => get_class($connection),
            'loggable_id' => $connection->id,
            'status' => self::STATUS_RUNNING,
            'summary' => "Syncing {$objectName} from {$connection->name}",
            'started_at' => now(),
            'request_data' => $requestData,
            'metadata' => [
                'object_id' => $object?->id,
                'object_name' => $object?->object_name,
                'connection_name' => $connection->name,
            ],
            'user_id' => Auth::id(),
            'ip_address' => Request::ip(),
        ]);
    }

    /**
     * Log an API request/response
     */
    public static function logApiCall(
        string $method,
        string $endpoint,
        ?array $requestData = null,
        ?array $responseData = null,
        ?string $status = null,
        ?int $durationMs = null,
        ?Model $context = null
    ): self {
        $level = match ($status) {
            'success', '2xx' => self::LEVEL_INFO,
            'failed', '4xx', '5xx' => self::LEVEL_ERROR,
            default => self::LEVEL_INFO,
        };

        return self::create([
            'category' => self::CATEGORY_API,
            'type' => 'request',
            'level' => $level,
            'loggable_type' => $context ? get_class($context) : null,
            'loggable_id' => $context?->id,
            'status' => $status,
            'summary' => "{$method} {$endpoint}",
            'started_at' => now()->subMilliseconds($durationMs ?? 0),
            'completed_at' => now(),
            'duration_ms' => $durationMs,
            'request_data' => $requestData,
            'response_data' => $responseData,
            'user_id' => Auth::id(),
            'ip_address' => Request::ip(),
        ]);
    }

    /**
     * Log a system event
     */
    public static function logEvent(
        string $type,
        string $summary,
        string $level = self::LEVEL_INFO,
        ?array $metadata = null,
        ?Model $context = null
    ): self {
        return self::create([
            'category' => self::CATEGORY_SYSTEM,
            'type' => $type,
            'level' => $level,
            'loggable_type' => $context ? get_class($context) : null,
            'loggable_id' => $context?->id,
            'summary' => $summary,
            'metadata' => $metadata,
            'user_id' => Auth::id(),
            'ip_address' => Request::ip(),
        ]);
    }

    /**
     * Log a device operation
     */
    public static function logDevice(
        Model $device,
        string $type,
        string $summary,
        string $level = self::LEVEL_INFO,
        ?array $metadata = null
    ): self {
        return self::create([
            'category' => self::CATEGORY_DEVICE,
            'type' => $type,
            'level' => $level,
            'loggable_type' => get_class($device),
            'loggable_id' => $device->id,
            'summary' => $summary,
            'metadata' => $metadata,
            'user_id' => Auth::id(),
            'ip_address' => Request::ip(),
        ]);
    }

    /**
     * Log a user action
     */
    public static function logUserAction(
        string $action,
        string $summary,
        ?Model $target = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'category' => self::CATEGORY_USER,
            'type' => $action,
            'level' => self::LEVEL_INFO,
            'loggable_type' => $target ? get_class($target) : null,
            'loggable_id' => $target?->id,
            'summary' => $summary,
            'metadata' => $metadata,
            'user_id' => Auth::id(),
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
        ]);
    }

    /**
     * Log an error
     */
    public static function logError(
        string $message,
        ?string $type = 'exception',
        ?array $errorDetails = null,
        ?Model $context = null
    ): self {
        return self::create([
            'category' => self::CATEGORY_ERROR,
            'type' => $type,
            'level' => self::LEVEL_ERROR,
            'loggable_type' => $context ? get_class($context) : null,
            'loggable_id' => $context?->id,
            'status' => self::STATUS_FAILED,
            'summary' => $message,
            'error_message' => $message,
            'error_details' => $errorDetails,
            'user_id' => Auth::id(),
            'ip_address' => Request::ip(),
        ]);
    }

    // ========================================
    // Instance methods for updating logs
    // ========================================

    /**
     * Mark operation as successful
     */
    public function markSuccess(?array $counts = null, ?array $responseData = null): void
    {
        $this->update([
            'status' => self::STATUS_SUCCESS,
            'level' => self::LEVEL_INFO,
            'completed_at' => now(),
            'duration_ms' => $this->started_at ? $this->started_at->diffInMilliseconds(now()) : null,
            'counts' => $counts,
            'response_data' => $responseData,
        ]);
    }

    /**
     * Mark operation as failed
     */
    public function markFailed(string $message, ?array $errorDetails = null): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'level' => self::LEVEL_ERROR,
            'completed_at' => now(),
            'duration_ms' => $this->started_at ? $this->started_at->diffInMilliseconds(now()) : null,
            'error_message' => $message,
            'error_details' => $errorDetails,
        ]);
    }

    /**
     * Mark operation as partial success
     */
    public function markPartial(string $message, ?array $counts = null, ?array $errorDetails = null): void
    {
        $this->update([
            'status' => self::STATUS_PARTIAL,
            'level' => self::LEVEL_WARNING,
            'completed_at' => now(),
            'duration_ms' => $this->started_at ? $this->started_at->diffInMilliseconds(now()) : null,
            'error_message' => $message,
            'counts' => $counts,
            'error_details' => $errorDetails,
        ]);
    }

    /**
     * Check if operation is still running
     */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * Get human-readable duration
     */
    public function getDurationForHumans(): string
    {
        if (! $this->duration_ms) {
            return 'N/A';
        }

        if ($this->duration_ms < 1000) {
            return $this->duration_ms.'ms';
        }

        $seconds = $this->duration_ms / 1000;
        if ($seconds < 60) {
            return round($seconds, 1).'s';
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        return $minutes.'m '.round($remainingSeconds).'s';
    }

    /**
     * Get total processed count
     */
    public function getTotalProcessed(): int
    {
        if (! $this->counts) {
            return 0;
        }

        return ($this->counts['created'] ?? 0) +
               ($this->counts['updated'] ?? 0) +
               ($this->counts['skipped'] ?? 0);
    }

    // ========================================
    // Relationships
    // ========================================

    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ========================================
    // Scopes
    // ========================================

    public function scopeCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeType($query, string $type)
    {
        return $query->where('type', $type);
    }

    public function scopeLevel($query, string $level)
    {
        return $query->where('level', $level);
    }

    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    public function scopeFailed($query)
    {
        return $query->whereIn('status', [self::STATUS_FAILED, self::STATUS_PARTIAL]);
    }

    public function scopeForLoggable($query, Model $model)
    {
        return $query->where('loggable_type', get_class($model))
            ->where('loggable_id', $model->id);
    }

    public function scopeIntegration($query)
    {
        return $query->where('category', self::CATEGORY_INTEGRATION);
    }

    public function scopeByMinLevel($query, string $minLevel)
    {
        $levels = [
            self::LEVEL_DEBUG => 0,
            self::LEVEL_INFO => 1,
            self::LEVEL_WARNING => 2,
            self::LEVEL_ERROR => 3,
            self::LEVEL_CRITICAL => 4,
        ];

        $minLevelValue = $levels[$minLevel] ?? 0;
        $allowedLevels = array_keys(array_filter($levels, fn ($v) => $v >= $minLevelValue));

        return $query->whereIn('level', $allowedLevels);
    }
}

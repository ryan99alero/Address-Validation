<?php

namespace App\Models;

use Database\Factories\ImportBatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    /** @use HasFactory<ImportBatchFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_MAPPING = 'mapping';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    // Processing phases (more granular than status)
    public const PHASE_IMPORTING = 'importing';

    public const PHASE_VALIDATING = 'validating';

    public const PHASE_TRANSIT_TIMES = 'fetching_transit_times';

    public const PHASE_COMPLETE = 'complete';

    protected $fillable = [
        'name',
        'original_filename',
        'file_path',
        'status',
        'total_rows',
        'processed_rows',
        'successful_rows',
        'failed_rows',
        'validated_rows',
        'processing_phase',
        'transit_time_rows',
        'total_for_transit',
        'mapping_template_id',
        'field_mappings',
        'carrier_id',
        'include_transit_times',
        'origin_postal_code',
        'origin_country_code',
        'ship_via_code_column',
        'error_file_path',
        'export_file_path',
        'export_status',
        'export_completed_at',
        'imported_by',
        'started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'successful_rows' => 'integer',
            'failed_rows' => 'integer',
            'validated_rows' => 'integer',
            'transit_time_rows' => 'integer',
            'total_for_transit' => 'integer',
            'field_mappings' => 'array',
            'include_transit_times' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'export_completed_at' => 'datetime',
        ];
    }

    /**
     * Get the display name (name or original filename).
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->name ?? $this->original_filename ?? 'Unnamed Import';
    }

    /**
     * Get validation progress percentage.
     */
    public function getValidationProgressAttribute(): int
    {
        if ($this->successful_rows === 0) {
            return 0;
        }

        return (int) (($this->validated_rows / $this->successful_rows) * 100);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isMapping(): bool
    {
        return $this->status === self::STATUS_MAPPING;
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function markCancelled(): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'completed_at' => now(),
        ]);
    }

    public function markMapping(): void
    {
        $this->update(['status' => self::STATUS_MAPPING]);
    }

    public function markProcessing(): void
    {
        $this->update([
            'status' => self::STATUS_PROCESSING,
            'started_at' => now(),
        ]);
    }

    public function markCompleted(): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'processing_phase' => self::PHASE_COMPLETE,
            'completed_at' => now(),
        ]);
    }

    public function setPhase(string $phase): void
    {
        $this->update(['processing_phase' => $phase]);
    }

    /**
     * Get the human-readable phase label.
     */
    public function getPhaseLabel(): string
    {
        return match ($this->processing_phase) {
            self::PHASE_IMPORTING => 'Importing Records',
            self::PHASE_VALIDATING => 'Validating Addresses',
            self::PHASE_TRANSIT_TIMES => 'Fetching Transit Times',
            self::PHASE_COMPLETE => 'Complete',
            default => 'Processing',
        };
    }

    /**
     * Get the current phase progress percentage (0-100).
     */
    public function getPhaseProgress(): int
    {
        return match ($this->processing_phase) {
            self::PHASE_IMPORTING => $this->total_rows > 0
                ? (int) (($this->processed_rows / $this->total_rows) * 100)
                : 0,
            self::PHASE_VALIDATING => $this->successful_rows > 0
                ? (int) (($this->validated_rows / $this->successful_rows) * 100)
                : 0,
            self::PHASE_TRANSIT_TIMES => $this->total_for_transit > 0
                ? (int) (($this->transit_time_rows / $this->total_for_transit) * 100)
                : 0,
            self::PHASE_COMPLETE => 100,
            default => 0,
        };
    }

    /**
     * Get overall progress across all phases (0-100).
     */
    public function getOverallProgress(): int
    {
        // Weight: Import 20%, Validation 50%, Transit Times 30%
        $includeTransit = $this->include_transit_times;

        if ($includeTransit) {
            $importWeight = 20;
            $validationWeight = 50;
            $transitWeight = 30;
        } else {
            $importWeight = 30;
            $validationWeight = 70;
            $transitWeight = 0;
        }

        $importProgress = $this->total_rows > 0
            ? ($this->processed_rows / $this->total_rows) * $importWeight
            : 0;

        $validationProgress = $this->successful_rows > 0
            ? ($this->validated_rows / $this->successful_rows) * $validationWeight
            : 0;

        $transitProgress = 0;
        if ($includeTransit && $this->total_for_transit > 0) {
            $transitProgress = ($this->transit_time_rows / $this->total_for_transit) * $transitWeight;
        } elseif ($includeTransit && $this->processing_phase === self::PHASE_COMPLETE) {
            $transitProgress = $transitWeight;
        }

        return (int) min(100, $importProgress + $validationProgress + $transitProgress);
    }

    /**
     * Check if currently in a specific phase.
     */
    public function isInPhase(string $phase): bool
    {
        return $this->processing_phase === $phase;
    }

    /**
     * Check if all processing is truly complete.
     */
    public function isFullyComplete(): bool
    {
        return $this->processing_phase === self::PHASE_COMPLETE;
    }

    public function markFailed(): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'completed_at' => now(),
        ]);
    }

    public function incrementProcessed(bool $success = true): void
    {
        $this->increment('processed_rows');

        if ($success) {
            $this->increment('successful_rows');
        } else {
            $this->increment('failed_rows');
        }
    }

    /**
     * Get progress percentage.
     */
    public function getProgressAttribute(): int
    {
        if ($this->total_rows === 0) {
            return 0;
        }

        return (int) (($this->processed_rows / $this->total_rows) * 100);
    }

    /**
     * Get progress text for display.
     */
    public function getProgressTextAttribute(): string
    {
        if ($this->isCompleted()) {
            $failed = $this->failed_rows > 0 ? " ({$this->failed_rows} failed)" : '';

            return "Imported {$this->successful_rows} addresses{$failed}";
        }

        if ($this->isFailed()) {
            return 'Import failed';
        }

        if ($this->total_rows && $this->processed_rows) {
            return "{$this->processed_rows} / {$this->total_rows} processed";
        }

        return match ($this->status) {
            self::STATUS_PENDING => 'Pending...',
            self::STATUS_MAPPING => 'Mapping fields...',
            self::STATUS_PROCESSING => 'Processing...',
            default => 'Unknown',
        };
    }

    // Relationships

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function mappingTemplate(): BelongsTo
    {
        return $this->belongsTo(ImportFieldTemplate::class, 'mapping_template_id');
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', self::STATUS_PROCESSING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }
}

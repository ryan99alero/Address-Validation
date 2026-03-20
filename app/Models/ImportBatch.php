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

    public const PHASE_RECOMMENDATIONS = 'calculating_recommendations';

    public const PHASE_COMPLETE = 'complete';

    // Export phases
    public const EXPORT_PHASE_PREPARING = 'preparing';

    public const EXPORT_PHASE_LOADING = 'loading_data';

    public const EXPORT_PHASE_WRITING = 'writing_rows';

    public const EXPORT_PHASE_COMPLETE = 'complete';

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
        'export_total_rows',
        'export_processed_rows',
        'export_phase',
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
            'export_total_rows' => 'integer',
            'export_processed_rows' => 'integer',
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
            self::PHASE_RECOMMENDATIONS => 'Calculating Recommendations',
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
            // Recommendations phase shows 50% (quick pass, no detailed tracking)
            self::PHASE_RECOMMENDATIONS => 50,
            self::PHASE_COMPLETE => 100,
            default => 0,
        };
    }

    /**
     * Get overall progress across all phases (0-100).
     */
    public function getOverallProgress(): int
    {
        // Weight: Import 15%, Validation 45%, Transit Times 30%, Recommendations 10%
        $includeTransit = $this->include_transit_times;

        if ($includeTransit) {
            $importWeight = 15;
            $validationWeight = 45;
            $transitWeight = 30;
            $recommendationsWeight = 10;
        } else {
            $importWeight = 30;
            $validationWeight = 70;
            $transitWeight = 0;
            $recommendationsWeight = 0;
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
        } elseif ($includeTransit && in_array($this->processing_phase, [self::PHASE_RECOMMENDATIONS, self::PHASE_COMPLETE])) {
            // Transit times done if we're in recommendations or complete phase
            $transitProgress = $transitWeight;
        }

        // Recommendations progress
        $recommendationsProgress = 0;
        if ($includeTransit) {
            if ($this->processing_phase === self::PHASE_COMPLETE) {
                $recommendationsProgress = $recommendationsWeight;
            } elseif ($this->processing_phase === self::PHASE_RECOMMENDATIONS) {
                // Show 50% of recommendations weight while in progress
                $recommendationsProgress = $recommendationsWeight * 0.5;
            }
        }

        return (int) min(100, $importProgress + $validationProgress + $transitProgress + $recommendationsProgress);
    }

    /**
     * Get the import phase percentage (0-100).
     */
    public function getImportPhasePercent(): int
    {
        if ($this->total_rows === 0) {
            return 0;
        }

        return (int) min(100, ($this->processed_rows / $this->total_rows) * 100);
    }

    /**
     * Get the validation phase percentage (0-100).
     */
    public function getValidationPhasePercent(): int
    {
        if (($this->successful_rows ?? 0) === 0) {
            return 0;
        }

        return (int) min(100, (($this->validated_rows ?? 0) / $this->successful_rows) * 100);
    }

    /**
     * Get the transit times phase percentage (0-100).
     */
    public function getTransitPhasePercent(): int
    {
        if (($this->total_for_transit ?? 0) === 0) {
            return 0;
        }

        return (int) min(100, (($this->transit_time_rows ?? 0) / $this->total_for_transit) * 100);
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

    // Export progress methods

    /**
     * Get export progress percentage (0-100).
     */
    public function getExportProgressPercent(): int
    {
        if (($this->export_total_rows ?? 0) === 0) {
            return 0;
        }

        return (int) min(100, (($this->export_processed_rows ?? 0) / $this->export_total_rows) * 100);
    }

    /**
     * Get the export phase label.
     */
    public function getExportPhaseLabel(): string
    {
        return match ($this->export_phase) {
            self::EXPORT_PHASE_PREPARING => 'Preparing Export',
            self::EXPORT_PHASE_LOADING => 'Loading Data',
            self::EXPORT_PHASE_WRITING => 'Writing Rows',
            self::EXPORT_PHASE_COMPLETE => 'Export Complete',
            default => 'Processing',
        };
    }

    /**
     * Check if export is in progress.
     */
    public function isExporting(): bool
    {
        return $this->export_status === 'processing';
    }

    /**
     * Check if export completed successfully.
     */
    public function isExportComplete(): bool
    {
        return $this->export_status === 'completed';
    }

    /**
     * Set export phase and optionally update progress.
     */
    public function setExportPhase(string $phase, ?int $totalRows = null): void
    {
        $data = ['export_phase' => $phase];

        if ($totalRows !== null) {
            $data['export_total_rows'] = $totalRows;
        }

        $this->update($data);
    }

    /**
     * Increment export progress.
     */
    public function incrementExportProgress(int $count = 1): void
    {
        $this->increment('export_processed_rows', $count);
    }

    /**
     * Reset export progress fields for a new export.
     */
    public function resetExportProgress(): void
    {
        $this->update([
            'export_status' => 'processing',
            'export_phase' => self::EXPORT_PHASE_PREPARING,
            'export_total_rows' => null,
            'export_processed_rows' => 0,
            'export_file_path' => null,
            'export_completed_at' => null,
        ]);
    }
}

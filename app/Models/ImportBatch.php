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
        'mapping_template_id',
        'field_mappings',
        'carrier_id',
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
            'field_mappings' => 'array',
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
            'completed_at' => now(),
        ]);
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

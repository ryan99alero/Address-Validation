<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarrierInvoice extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'carrier_id',
        'filename',
        'original_path',
        'archived_path',
        'file_hash',
        'invoice_number',
        'invoice_date',
        'account_number',
        'total_records',
        'correction_records',
        'new_corrections',
        'duplicate_corrections',
        'total_correction_charges',
        'status',
        'error_message',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'total_records' => 'integer',
            'correction_records' => 'integer',
            'new_corrections' => 'integer',
            'duplicate_corrections' => 'integer',
            'total_correction_charges' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    // Relationships

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CarrierInvoiceLine::class);
    }

    public function correctionLines(): HasMany
    {
        return $this->hasMany(CarrierInvoiceLine::class)
            ->whereNotNull('corrected_address_1');
    }

    // Static Methods

    /**
     * Check if a file has already been processed.
     */
    public static function isFileProcessed(string $filePath): bool
    {
        $hash = hash_file('sha256', $filePath);

        return self::where('file_hash', $hash)->exists();
    }

    /**
     * Compute hash for a file.
     */
    public static function computeFileHash(string $filePath): string
    {
        return hash_file('sha256', $filePath);
    }

    // Status Methods

    public function markProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    public function markCompleted(int $totalRecords, int $correctionRecords, int $newCorrections, int $duplicates, float $totalCharges): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'total_records' => $totalRecords,
            'correction_records' => $correctionRecords,
            'new_corrections' => $newCorrections,
            'duplicate_corrections' => $duplicates,
            'total_correction_charges' => $totalCharges,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
        ]);
    }

    // Scopes

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    // Accessors

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'warning',
            self::STATUS_PROCESSING => 'info',
            self::STATUS_COMPLETED => 'success',
            self::STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }
}

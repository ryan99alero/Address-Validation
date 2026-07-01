<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records that a source file (by content hash) has been imported, decoupled from
 * invoices — one batch file yields several CarrierInvoices, so file-level "already
 * processed" tracking can't live on carrier_invoices anymore.
 */
class CarrierImportFile extends Model
{
    protected $fillable = [
        'carrier_id',
        'file_hash',
        'filename',
        'source_reference',
        'invoice_count',
        'imported_at',
    ];

    protected function casts(): array
    {
        return [
            'invoice_count' => 'integer',
            'imported_at' => 'datetime',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(Carrier::class);
    }
}

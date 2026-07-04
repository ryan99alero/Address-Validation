<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Records that a source file (by content hash) has been imported, decoupled from
 * invoices — one batch file yields several CarrierInvoices, so file-level "already
 * processed" tracking can't live on carrier_invoices anymore.
 */
class CarrierImportFile extends Model
{
    protected $fillable = [
        'carrier_id',
        'folder_integration_id',
        'file_hash',
        'filename',
        'source_reference',
        'invoice_count',
        'skip_reason',
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

    public function folderIntegration(): BelongsTo
    {
        return $this->belongsTo(FolderIntegration::class);
    }

    public function invoices(): BelongsToMany
    {
        return $this->belongsToMany(CarrierInvoice::class, 'carrier_import_file_invoice');
    }
}

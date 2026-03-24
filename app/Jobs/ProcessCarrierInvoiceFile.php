<?php

namespace App\Jobs;

use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Services\CarrierInvoiceParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessCarrierInvoiceFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(
        public string $filePath,
        public string $carrierSlug,
    ) {}

    public function handle(CarrierInvoiceParserService $parserService): void
    {
        $filename = basename($this->filePath);

        // Check if file exists
        if (! file_exists($this->filePath)) {
            Log::warning('Invoice file not found, skipping', ['file' => $this->filePath]);

            return;
        }

        // Check if already processed (by hash)
        if (CarrierInvoice::isFileProcessed($this->filePath)) {
            Log::info('Invoice already processed, skipping', ['file' => $filename]);

            return;
        }

        // Get carrier
        $carrier = Carrier::where('slug', $this->carrierSlug)->first();
        if (! $carrier) {
            Log::error('Unknown carrier', ['slug' => $this->carrierSlug, 'file' => $filename]);

            return;
        }

        try {
            // Create invoice record
            $invoice = CarrierInvoice::create([
                'carrier_id' => $carrier->id,
                'filename' => $filename,
                'original_path' => $this->filePath,
                'file_hash' => CarrierInvoice::computeFileHash($this->filePath),
                'status' => CarrierInvoice::STATUS_PENDING,
            ]);

            // Parse the file
            $result = $parserService->parse($invoice, $this->filePath);

            Log::info('Invoice processed', [
                'file' => $filename,
                'carrier' => $carrier->name,
                'total_records' => $result['total_records'],
                'corrections' => $result['corrections'],
                'new_corrections' => $result['new_corrections'],
                'total_charges' => $result['total_charges'],
            ]);

        } catch (\Exception $e) {
            Log::error('Invoice processing failed', [
                'file' => $filename,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['carrier-invoice', $this->carrierSlug, basename($this->filePath)];
    }
}

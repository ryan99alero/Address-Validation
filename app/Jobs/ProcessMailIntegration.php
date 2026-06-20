<?php

namespace App\Jobs;

use App\Models\MailIntegration;
use App\Services\Mail\InvoiceMailProcessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessMailIntegration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900; // 15 minutes

    public function __construct(
        public MailIntegration $integration
    ) {}

    public function handle(InvoiceMailProcessService $service): void
    {
        $stats = $service->process($this->integration);
        $this->integration->markChecked('ok');

        Log::info('ProcessMailIntegration: completed', [
            'integration_id' => $this->integration->id,
            'messages' => $stats['messages'],
            'invoices' => $stats['invoices'],
            'corrections' => $stats['corrections'],
            'skipped' => $stats['skipped'],
            'errors' => $stats['errors'],
            'mail_warnings' => $stats['mail_warnings'],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->integration->markChecked('error', $exception->getMessage());

        Log::error('ProcessMailIntegration: failed', [
            'integration_id' => $this->integration->id,
            'error' => $exception->getMessage(),
        ]);
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\ProcessMailIntegration;
use App\Models\MailIntegration;
use Illuminate\Console\Command;

class ProcessInvoiceMail extends Command
{
    protected $signature = 'mail:process-invoices
        {--integration= : Process a single mail integration by id}
        {--due : Only dispatch integrations that are due per their poll frequency}
        {--sync : Run inline now instead of dispatching to the queue}';

    protected $description = 'Queue carrier-invoice processing for configured mailboxes (fetch, parse, cache, archive)';

    public function handle(): int
    {
        $query = MailIntegration::where('is_active', true);
        if ($id = $this->option('integration')) {
            $query->whereKey($id);
        }

        $integrations = $query->get();
        if ($this->option('due')) {
            $integrations = $integrations->filter->isDueForPoll();
        }

        if ($integrations->isEmpty()) {
            $this->info('No mail integrations to process.');

            return self::SUCCESS;
        }

        foreach ($integrations as $integration) {
            if ($this->option('sync')) {
                ProcessMailIntegration::dispatchSync($integration);
                $this->info("Processed inline: {$integration->name}");
            } else {
                ProcessMailIntegration::dispatch($integration);
                $this->info("Queued: {$integration->name}");
            }
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Jobs\ProcessFolderIntegration;
use App\Models\FolderIntegration;
use Illuminate\Console\Command;

class ProcessFolderIntegrations extends Command
{
    protected $signature = 'folders:process
        {--integration= : Process a single folder integration by id}
        {--due : Only dispatch integrations that are due per their poll frequency}
        {--sync : Run inline now instead of dispatching to the queue}';

    protected $description = 'Queue carrier-invoice folder scans for configured SMB/local shares (enumerate, import new files, parse fees)';

    public function handle(): int
    {
        $query = FolderIntegration::where('is_active', true);
        if ($id = $this->option('integration')) {
            $query->whereKey($id);
        }

        $integrations = $query->get();
        if ($this->option('due')) {
            $integrations = $integrations->filter->isDueForPoll();
        }

        if ($integrations->isEmpty()) {
            $this->info('No folder integrations to process.');

            return self::SUCCESS;
        }

        foreach ($integrations as $integration) {
            if ($this->option('sync')) {
                ProcessFolderIntegration::dispatchSync($integration);
                $this->info("Processed inline: {$integration->name}");
            } else {
                ProcessFolderIntegration::dispatch($integration);
                $this->info("Queued: {$integration->name}");
            }
        }

        return self::SUCCESS;
    }
}

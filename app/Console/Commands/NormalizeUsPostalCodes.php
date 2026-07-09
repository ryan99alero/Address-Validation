<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Support\PostalCode;
use Illuminate\Console\Command;

class NormalizeUsPostalCodes extends Command
{
    protected $signature = 'zips:normalize-us
        {--dry-run : Report what would change without saving}
        {--batch= : Limit to a single import_batch_id}';

    protected $description = 'Left-pad Excel-truncated US ZIP codes on existing addresses (e.g. 7001 → 07001)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // LENGTH() works on both MySQL and SQLite; the numeric/US decision is made by the normalizer.
        $query = Address::query()
            ->whereNotNull('input_postal')
            ->whereRaw('LENGTH(input_postal) < 5');

        if ($this->option('batch')) {
            $query->where('import_batch_id', $this->option('batch'));
        }

        $changed = 0;
        $samples = [];

        $query->chunkById(500, function ($addresses) use (&$changed, &$samples, $dryRun): void {
            foreach ($addresses as $address) {
                $fixed = PostalCode::normalizeUs($address->input_postal, $address->input_country);

                if ($fixed === $address->input_postal) {
                    continue; // foreign / non-numeric short value — leave it
                }

                if (count($samples) < 15) {
                    $samples[] = sprintf('%s → %s  (%s)', $address->input_postal, $fixed, $address->input_state ?? '?');
                }

                if (! $dryRun) {
                    $address->input_postal = $fixed;
                    $address->saveQuietly();
                }

                $changed++;
            }
        });

        foreach ($samples as $sample) {
            $this->line('  '.$sample);
        }

        $this->newLine();
        $this->info(($dryRun ? '[DRY RUN] Would repair ' : 'Repaired ').$changed.' US ZIP code(s).');

        if ($dryRun && $changed > 0) {
            $this->comment('Re-run without --dry-run to apply. Consider re-validating affected batches afterward.');
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\CarrierInvoiceLine;
use App\Services\Invoices\AddressCorrectionAnalyzer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Telescope\Telescope;

class AnalyzeCorrectionSeverity extends Command
{
    protected $signature = 'corrections:analyze {--fresh : Re-grade lines that already have a severity}';

    protected $description = 'Grade address corrections by severity (normalized Levenshtein) and change type';

    public function handle(AddressCorrectionAnalyzer $analyzer): int
    {
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
        DB::disableQueryLog();

        $query = CarrierInvoiceLine::query()
            ->whereNotNull('original_address_1')->where('original_address_1', '<>', '')
            ->whereNotNull('corrected_address_1')->where('corrected_address_1', '<>', '');

        if (! $this->option('fresh')) {
            $query->whereNull('severity_category');
        }

        $total = $query->count();
        $this->info("Grading {$total} correction line(s)".($this->option('fresh') ? ' (fresh re-grade)' : '').'.');

        $graded = 0;
        $query->orderBy('id')->chunkById(1000, function ($lines) use ($analyzer, &$graded, $total): void {
            foreach ($lines as $line) {
                $result = $analyzer->analyze(
                    [
                        'address_1' => $line->original_address_1,
                        'address_2' => $line->original_address_2,
                        'city' => $line->original_city,
                        'state' => $line->original_state,
                        'postal' => $line->original_postal,
                    ],
                    [
                        'address_1' => $line->corrected_address_1,
                        'address_2' => $line->corrected_address_2,
                        'city' => $line->corrected_city,
                        'state' => $line->corrected_state,
                        'postal' => $line->corrected_postal,
                    ],
                );

                // Update directly (no model save) to skip the observer and stay fast.
                DB::table('carrier_invoice_lines')->where('id', $line->id)->update([
                    'severity_score' => $result['severity_score'],
                    'severity_category' => $result['severity_category'],
                    'change_type' => $result['change_type'],
                ]);
                $graded++;
            }

            if ($graded % 5000 === 0) {
                $this->line("  ...{$graded}/{$total}");
            }
        });

        $this->info("Done. Graded {$graded} correction line(s).");

        return self::SUCCESS;
    }
}

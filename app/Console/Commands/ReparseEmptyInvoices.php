<?php

namespace App\Console\Commands;

use App\Models\Carrier;
use App\Models\CarrierInvoice;
use App\Services\CarrierInvoiceParserService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Telescope\Telescope;
use Throwable;

class ReparseEmptyInvoices extends Command
{
    protected $signature = 'invoices:reparse-empty
        {--carrier=fedex : Carrier slug}
        {--pdf-only : Only re-parse PDF invoices}
        {--limit=0 : Max invoices to process (0 = all)}';

    protected $description = 'Re-parse invoices that imported with zero charges and zero correction lines (e.g. older formats now supported)';

    public function handle(CarrierInvoiceParserService $parser): int
    {
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
        DB::disableQueryLog();

        $carrier = Carrier::where('slug', $this->option('carrier'))->first();
        if (! $carrier) {
            $this->error("Unknown carrier: {$this->option('carrier')}");

            return self::FAILURE;
        }

        $query = CarrierInvoice::where('carrier_id', $carrier->id)
            ->whereNotNull('original_path')
            ->whereDoesntHave('charges')
            ->whereDoesntHave('lines');
        if ($this->option('pdf-only')) {
            $query->where('filename', 'like', '%.pdf');
        }

        $total = (int) $this->option('limit') > 0
            ? min($query->count(), (int) $this->option('limit'))
            : $query->count();
        $this->info("Re-parsing up to {$total} empty {$carrier->slug} invoice(s).");

        $recovered = 0;
        $stillEmpty = 0;
        $missing = 0;
        $failed = 0;
        $processed = 0;

        $query->orderBy('id')->chunkById(100, function ($invoices) use ($parser, $total, &$recovered, &$stillEmpty, &$missing, &$failed, &$processed): bool {
            foreach ($invoices as $invoice) {
                if ((int) $this->option('limit') > 0 && $processed >= (int) $this->option('limit')) {
                    return false;
                }
                $processed++;

                if (! is_file((string) $invoice->original_path)) {
                    $missing++;

                    continue;
                }

                try {
                    $parser->parse($invoice, $invoice->original_path);
                    $invoice->charges()->count() > 0 ? $recovered++ : $stillEmpty++;
                } catch (Throwable $e) {
                    $failed++;
                }

                if ($processed % 50 === 0) {
                    $this->line("  ...{$processed}/{$total} (recovered {$recovered}, still-empty {$stillEmpty}, missing {$missing}, failed {$failed})");
                }
            }

            return true;
        });

        $this->info("Done. Recovered {$recovered}, still-empty {$stillEmpty} (unsupported format), missing-file {$missing}, failed {$failed}.");

        return self::SUCCESS;
    }
}

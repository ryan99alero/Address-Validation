<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seeds address_verifications from what the carriers have already told us for free: every ADC line is
 * the carrier printing its preferred form, so the newest ship/invoice date per (good address, carrier)
 * is a real "verified fee-free on this date" stamp. Gives the reverify job realistic staleness dates
 * up front so it spends its daily budget on genuinely stale addresses instead of the whole table.
 * insertOrIgnore — never clobbers an existing (API or prior) verification.
 */
class SeedAddressVerifications extends Command
{
    protected $signature = 'correction-cache:seed-verifications {--dry-run : Report the count without writing}';

    protected $description = 'Seed per-carrier verification dates from existing invoice-correction evidence';

    public function handle(): int
    {
        $rows = DB::table('carrier_invoice_lines as l')
            ->join('carrier_invoices as ci', 'ci.id', '=', 'l.carrier_invoice_id')
            ->join('corrected_addresses as ca', 'ca.id', '=', 'l.corrected_address_id')
            ->whereNull('ca.superseded_by_id')
            ->whereNotNull('l.corrected_address_id')
            ->groupBy('l.corrected_address_id', 'ci.carrier_id')
            ->selectRaw('l.corrected_address_id, ci.carrier_id, MAX(COALESCE(l.ship_date, ci.invoice_date)) as vdate')
            ->get();

        $this->info('Verification stamps to seed: '.$rows->count().($this->option('dry-run') ? '  (DRY RUN)' : ''));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $now = now();
        $seeded = 0;
        foreach ($rows->chunk(500) as $chunk) {
            $batch = [];
            foreach ($chunk as $row) {
                if ($row->vdate === null) {
                    continue;
                }
                $batch[] = [
                    'corrected_address_id' => $row->corrected_address_id,
                    'carrier_id' => $row->carrier_id,
                    'status' => 'verified',
                    'verified_at' => $row->vdate,
                    'checked_at' => $row->vdate,
                    'source' => 'invoice',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($batch !== []) {
                DB::table('address_verifications')->insertOrIgnore($batch);
                $seeded += count($batch);
            }
        }

        $this->info("Seeded up to {$seeded} verification row(s) (existing rows left untouched).");

        return self::SUCCESS;
    }
}

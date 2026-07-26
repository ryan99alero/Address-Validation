<?php

namespace App\Console\Commands;

use App\Jobs\RecategorizeChargesJob;
use App\Models\CarrierChargeType;
use App\Services\Invoices\ChargeCategoryResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the operator crosswalk (carrier_charge_types) from the distinct charges already imported —
 * one row per (carrier, description), recording which format(s) it appeared in (csv_label /
 * pdf_label) and the category the CURRENT resolver assigns it (so the seed is behavior-neutral;
 * unknowns land with a null category = the review worklist). Skips reconciliation residuals and
 * correction-prefix lines (governed by the resolver's correction rule, not the crosswalk). Existing
 * crosswalk rows are never overwritten, so re-running only adds newly-seen charges. Afterwards it
 * re-stamps charge_type_id on existing charges unless --no-restamp is given.
 */
class BackfillCarrierChargeTypes extends Command
{
    protected $signature = 'charge-types:backfill
        {--min-lines=3 : Only seed charge types seen on at least this many charge lines}
        {--dry-run : Report what would be created without writing}
        {--no-restamp : Skip re-stamping charge_type_id on existing charges}';

    protected $description = 'Seed the carrier charge-type crosswalk from existing charges (behavior-neutral)';

    /** Descriptions governed by the resolver's correction-prefix rule — not crosswalk-mappable. */
    private const CORRECTION_PREFIXES = ['Address Correction', 'Shipping Charge Correction'];

    /** Parser reconciliation remainders — no charge identity, must never become crosswalk rows. */
    private const RESIDUAL_PREFIXES = ['UPS charge (unclassified', 'UPS credit/adjustment (unclassified'];

    public function handle(): int
    {
        $minLines = max(1, (int) $this->option('min-lines'));
        $dryRun = (bool) $this->option('dry-run');
        $resolver = new ChargeCategoryResolver;

        $existing = $this->existingLabelKeys();

        $rows = DB::table('carrier_charges')
            ->select('carrier_id', 'source_type', 'raw_charge_description', DB::raw('COUNT(*) as line_count'))
            ->whereNotNull('raw_charge_description')
            ->where('raw_charge_description', '!=', '')
            ->groupBy('carrier_id', 'source_type', 'raw_charge_description')
            ->get();

        /** @var array<string, array{carrier_id: ?int, display_name: string, csv_label: ?string, pdf_label: ?string, lines: int}> $agg */
        $agg = [];
        foreach ($rows as $r) {
            $desc = trim((string) $r->raw_charge_description);
            if ($desc === '' || $this->startsWithAny($desc, self::RESIDUAL_PREFIXES) || $this->startsWithAny($desc, self::CORRECTION_PREFIXES)) {
                continue;
            }

            $key = ($r->carrier_id ?? 'n').'|'.mb_strtolower($desc);
            $agg[$key] ??= ['carrier_id' => $r->carrier_id, 'display_name' => $desc, 'csv_label' => null, 'pdf_label' => null, 'lines' => 0];
            $agg[$key]['lines'] += (int) $r->line_count;

            if ($r->source_type === 'pdf') {
                $agg[$key]['pdf_label'] = $desc;
            } else { // 'csv' or legacy null → treated as the CSV/label side
                $agg[$key]['csv_label'] = $desc;
            }
        }

        $created = 0;
        $skippedExisting = 0;
        $skippedThreshold = 0;
        foreach ($agg as $key => $row) {
            if (isset($existing[$key])) {
                $skippedExisting++;

                continue;
            }
            if ($row['lines'] < $minLines) {
                $skippedThreshold++;

                continue;
            }

            $categoryId = $resolver->resolve($row['carrier_id'], null, $row['display_name']);

            if (! $dryRun) {
                CarrierChargeType::create([
                    'carrier_id' => $row['carrier_id'],
                    'display_name' => $row['display_name'],
                    'csv_label' => $row['csv_label'],
                    'pdf_label' => $row['pdf_label'],
                    'match_style' => CarrierChargeType::MATCH_EXACT,
                    'charge_category_id' => $categoryId,
                    'priority' => 100,
                    'is_active' => true,
                ]);
            }
            $created++;
        }

        $verb = $dryRun ? 'Would create' : 'Created';
        $this->info("{$verb} {$created} charge types. Skipped {$skippedExisting} already present, {$skippedThreshold} below the {$minLines}-line threshold.");

        if (! $dryRun && ! $this->option('no-restamp')) {
            $changed = RecategorizeChargesJob::run(null, [], fn (int $n) => $this->info("Re-stamping charge_type_id across {$n} distinct combos…"));
            $this->info("Charges re-stamped. Rows updated: {$changed}.");
        }

        return self::SUCCESS;
    }

    /**
     * Existing crosswalk label keys ((carrier|normalized label) for both format columns) so a
     * re-run never duplicates or overwrites an operator-tuned row.
     *
     * @return array<string, true>
     */
    private function existingLabelKeys(): array
    {
        $keys = [];
        foreach (CarrierChargeType::query()->get(['carrier_id', 'csv_label', 'pdf_label']) as $row) {
            foreach ([$row->csv_label, $row->pdf_label] as $label) {
                if ($label !== null && $label !== '') {
                    $keys[($row->carrier_id ?? 'n').'|'.mb_strtolower(trim($label))] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * @param  list<string>  $prefixes
     */
    private function startsWithAny(string $value, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (stripos($value, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY forensic audit. Lists every Pace JobCost we actually posted (chargeback_pushes with a
 * returned pace_jobcost_id) and flags likely overcharges, captured BEFORE the carton_cost_id join
 * swap changes the attribution:
 *   DOUBLE_POST_DUP  — an orphaned (backing charge deleted on re-import) push that has a live-pushed
 *                      sibling for the same tracking+activity = the same charge billed twice.
 *   ORPHANED_NO_LINE — orphaned push with no live sibling (backing charge gone; verify vs Pace).
 *   OLD_ERA_BACKED   — backed by a pre-2020 charge that shouldn't have been billable.
 *   WRONG_JOB        — the era-correct carton (carton_cost_id) says a different job than we posted to.
 * Writes a CSV and prints a per-flag summary. Changes nothing.
 */
class AuditChargebackOvercharges extends Command
{
    protected $signature = 'chargeback:audit-overcharges {--path=}';

    protected $description = 'Read-only: list posted Pace JobCosts, flagging likely overcharges (double-post / orphaned / wrong-era / wrong-job)';

    public function handle(): int
    {
        $path = $this->option('path') ?: storage_path('app/chargeback-overcharge-audit.csv');
        $fh = fopen($path, 'w');
        fputcsv($fh, ['flag', 'status', 'pace_job', 'correct_job', 'pace_customer_id', 'pace_customer_name', 'pace_jobcost_id', 'tracking_number', 'activity_code', 'activity_label', 'driver', 'amount', 'pushed_at', 'carrier_charge_id', 'backing_invoice_date', 'notes']);

        $rows = DB::table('chargeback_pushes')
            ->whereNotNull('pace_jobcost_id')->where('pace_jobcost_id', '!=', '')
            ->orderBy('pace_job')->orderBy('activity_code')
            ->get();

        $counts = [];
        $suspectAmount = 0.0;
        foreach ($rows as $r) {
            $flag = '';
            $correctJob = null;
            $backingEra = null;

            if ($r->carrier_charge_id === null) {
                $sibling = DB::table('chargeback_pushes')
                    ->where('tracking_number', $r->tracking_number)
                    ->where('activity_code', $r->activity_code)
                    ->whereNotNull('carrier_charge_id')
                    ->where('status', 'pushed')
                    ->exists();
                $flag = $sibling ? 'DOUBLE_POST_DUP' : 'ORPHANED_NO_LINE';
            } else {
                $charge = DB::table('carrier_charges')->where('id', $r->carrier_charge_id)->first(['invoice_date', 'carton_cost_id']);
                if ($charge) {
                    $backingEra = $charge->invoice_date;
                    if ((string) $charge->invoice_date < '2020-01-01') {
                        $flag = 'OLD_ERA_BACKED';
                    }
                    if ($charge->carton_cost_id) {
                        $correctJob = DB::table('carton_costs')->where('id', $charge->carton_cost_id)->value('pace_job_number');
                        if ($correctJob && (string) $correctJob !== (string) $r->pace_job) {
                            $flag = $flag ? $flag.'+WRONG_JOB' : 'WRONG_JOB';
                        }
                    }
                }
            }

            $key = $flag ?: 'OK';
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            if ($flag !== '') {
                $suspectAmount += (float) $r->amount;
            }

            $activityLabel = match ((string) $r->activity_code) {
                '72510' => 'Address Correction',
                '72520' => 'Fuel Surcharge (Address)',
                '72530' => 'Audit / Weight Correction',
                '72540' => 'Residential Reclassification',
                '72550' => 'Fuel Surcharge (Audit)',
                default => (string) $r->activity_code,
            };

            fputcsv($fh, [$flag, $r->status, $r->pace_job, $correctJob, $r->pace_customer_id, $r->pace_customer_name, $r->pace_jobcost_id, $r->tracking_number, $r->activity_code, $activityLabel, $r->driver, $r->amount, $r->pushed_at, $r->carrier_charge_id, $backingEra, $r->notes]);
        }
        fclose($fh);

        $this->info('Wrote '.count($rows).' posted JobCosts to '.$path);
        foreach ($counts as $flag => $n) {
            $this->line("  {$flag}: {$n}");
        }
        $this->info('Flagged (suspect) dollar total: $'.number_format($suspectAmount, 2));

        return self::SUCCESS;
    }
}

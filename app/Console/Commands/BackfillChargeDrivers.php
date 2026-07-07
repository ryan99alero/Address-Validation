<?php

namespace App\Console\Commands;

use App\Enums\ChargeDriver;
use App\Services\Invoices\ChargeDriverResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfills carrier_charges.driver / driver_source from the strongest signal, in precedence order
 * (matches ChargeDriverResolver): billing code → PDF section → description → default 'normal'.
 * Set-based and idempotent — by default only fills rows where driver IS NULL; --fresh re-derives all.
 */
class BackfillChargeDrivers extends Command
{
    protected $signature = 'charges:backfill-drivers {--fresh : Re-derive every row, not just unset ones}';

    protected $description = 'Attribute a driver (why we were billed) to existing carrier charges';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->warn('Re-deriving driver for ALL charges.');
            DB::table('carrier_charges')->update(['driver' => null, 'driver_source' => null]);
        }

        // 1) Billing code (UPS CSV col / FedEx ADDCOR) — the highest-confidence signal.
        foreach (ChargeDriverResolver::codeMap() as $code => $driver) {
            $n = DB::table('carrier_charges')
                ->whereNull('driver')->whereRaw('UPPER(TRIM(raw_charge_code)) = ?', [$code])
                ->update(['driver' => $driver, 'driver_source' => 'csv_code']);
            $this->line("  code {$code} → {$driver}: {$n}");
        }

        // 2) UPS PDF section (stored on the linked shipment). Subquery, not a join-update, so it's
        // portable across MySQL (prod) and SQLite (tests).
        foreach (ChargeDriverResolver::sectionMap() as $section => $driver) {
            $n = DB::table('carrier_charges')
                ->whereNull('driver')
                ->whereIn('carrier_shipment_id', fn ($q) => $q->select('id')->from('carrier_shipments')->where('section', $section))
                ->update(['driver' => $driver, 'driver_source' => 'pdf_section']);
            $this->line("  section {$section} → {$driver}: {$n}");
        }

        // 3) Description rules (FedEx flat text). Mirrors ChargeDriverResolver::fromDescription.
        $descRules = [
            [ChargeDriver::AddressCorrection->value, ['%address correction%']],
            [ChargeDriver::AuditCorrection->value, ['%shipping charge correction%', '%rated weight%', '%weight correction%']],
            [ChargeDriver::ThirdPartyChargeback->value, ['%invalid account%', '%chargeback%']],
            [ChargeDriver::Returned->value, ['%return to sender%', '%reroute%', '%reschedul%']],
        ];
        foreach ($descRules as [$driver, $likes]) {
            $n = DB::table('carrier_charges')->whereNull('driver')
                ->where(function ($w) use ($likes): void {
                    foreach ($likes as $like) {
                        $w->orWhere('raw_charge_description', 'like', $like);
                    }
                })
                ->update(['driver' => $driver, 'driver_source' => 'description']);
            $this->line("  description → {$driver}: {$n}");
        }

        // 4) Everything else is a normal shipment charge.
        $n = DB::table('carrier_charges')->whereNull('driver')
            ->update(['driver' => ChargeDriver::Normal->value, 'driver_source' => 'default']);
        $this->line("  default → normal: {$n}");

        $this->info('Driver backfill complete.');

        return self::SUCCESS;
    }
}

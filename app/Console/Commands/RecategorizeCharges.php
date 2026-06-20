<?php

namespace App\Console\Commands;

use App\Models\CarrierCharge;
use App\Services\Invoices\ChargeCategoryResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laravel\Telescope\Telescope;

class RecategorizeCharges extends Command
{
    protected $signature = 'invoices:recategorize-charges';

    protected $description = 'Re-resolve every carrier_charge against the current category mappings';

    public function handle(): int
    {
        // Millions of rows: stop Telescope/query log buffering them in memory.
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }
        DB::disableQueryLog();

        $resolver = new ChargeCategoryResolver;
        $updated = 0;

        CarrierCharge::query()->orderBy('id')->chunkById(1000, function ($charges) use ($resolver, &$updated): void {
            foreach ($charges as $charge) {
                $categoryId = $resolver->resolve($charge->carrier_id, $charge->raw_charge_code, $charge->raw_charge_description);
                if ($categoryId !== $charge->charge_category_id) {
                    $charge->update(['charge_category_id' => $categoryId]);
                    $updated++;
                }
            }
        });

        $this->info("Re-categorized {$updated} charge(s).");

        return self::SUCCESS;
    }
}

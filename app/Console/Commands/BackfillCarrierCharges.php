<?php

namespace App\Console\Commands;

use App\Models\CarrierCharge;
use App\Models\CarrierInvoice;
use App\Models\CarrierInvoiceLine;
use App\Services\Invoices\ChargeCategoryResolver;
use Illuminate\Console\Command;

class BackfillCarrierCharges extends Command
{
    protected $signature = 'invoices:backfill-charges {--fresh : Wipe carrier_charges and rebuild from existing invoice lines}';

    protected $description = 'Populate carrier_charges (fee analytics) from existing invoice line data';

    public function handle(): int
    {
        if ($this->option('fresh')) {
            $this->warn('Wiping carrier_charges and rebuilding from invoice lines...');
            CarrierCharge::query()->delete();
        }

        // Pre-load invoice meta to avoid N+1 across ~1k invoices.
        $invoices = CarrierInvoice::query()->get(['id', 'carrier_id', 'invoice_date', 'account_number'])->keyBy('id');
        $resolver = new ChargeCategoryResolver;
        $now = now();
        $created = 0;

        CarrierInvoiceLine::query()
            ->whereNotNull('charge_amount')
            ->orderBy('id')
            ->chunk(1000, function ($lines) use ($invoices, $resolver, $now, &$created): void {
                $rows = [];
                foreach ($lines as $line) {
                    $invoice = $invoices->get($line->carrier_invoice_id);
                    if (! $invoice) {
                        continue;
                    }

                    $rows[] = [
                        'carrier_invoice_id' => $invoice->id,
                        'carrier_id' => $invoice->carrier_id,
                        'invoice_date' => $invoice->invoice_date ?? $line->ship_date,
                        'account_number' => $invoice->account_number,
                        'tracking_number' => $line->tracking_number,
                        'raw_charge_code' => $line->charge_code,
                        'raw_charge_description' => $line->charge_description,
                        'charge_category_id' => $resolver->resolve($invoice->carrier_id, $line->charge_code, $line->charge_description),
                        'amount' => $line->charge_amount,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if (! empty($rows)) {
                    CarrierCharge::insert($rows);
                    $created += count($rows);
                }
            });

        $this->info("Backfilled {$created} carrier_charges from invoice lines.");

        return self::SUCCESS;
    }
}

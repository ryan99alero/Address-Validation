<?php

namespace App\Filament\Exports;

use App\Models\SystemLog;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class PaceCorrectionExporter extends Exporter
{
    protected static ?string $model = SystemLog::class;

    /**
     * @return array<int, ExportColumn>
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('created_at')->label('When'),
            ExportColumn::make('job_number')->label('Job #')
                ->getStateUsing(fn (SystemLog $r): ?string => $r->metadata['job_number'] ?? null),
            ExportColumn::make('shipment_id')->label('Shipment')
                ->getStateUsing(fn (SystemLog $r): ?string => $r->metadata['shipment_id'] ?? null),
            ExportColumn::make('contact_id')->label('Contact')
                ->getStateUsing(fn (SystemLog $r): ?string => $r->metadata['contact_id'] ?? null),
            ExportColumn::make('status')->label('Status'),
            ExportColumn::make('mode')->label('Mode')
                ->getStateUsing(fn (SystemLog $r): string => ($r->metadata['dry_run'] ?? false) ? 'Dry-run' : 'Live'),
            ExportColumn::make('validator')->label('Validator')
                ->getStateUsing(fn (SystemLog $r): string => match ($r->metadata['source'] ?? null) {
                    'local_cache' => 'Local Cache',
                    'ups_api' => 'UPS',
                    'fedex_api' => 'FedEx',
                    'smarty_api' => 'Smarty',
                    'usps_api' => 'USPS',
                    default => (string) ($r->metadata['source'] ?? ''),
                }),
            ExportColumn::make('changed_fields')->label('Changed Fields')
                ->getStateUsing(fn (SystemLog $r): string => implode(', ', $r->metadata['changed_fields'] ?? [])),

            ExportColumn::make('orig_company')->label('Original Company')
                ->getStateUsing(fn (SystemLog $r): ?string => $r->metadata['original']['company'] ?? null),
            ExportColumn::make('orig_street')->label('Original Street')
                ->getStateUsing(fn (SystemLog $r): ?string => $r->metadata['original']['address1'] ?? null),
            ExportColumn::make('orig_suite')->label('Original Suite')
                ->getStateUsing(fn (SystemLog $r): ?string => $r->metadata['original']['address2'] ?? null),
            ExportColumn::make('orig_city')->label('Original City')
                ->getStateUsing(fn (SystemLog $r): ?string => $r->metadata['original']['city'] ?? null),
            ExportColumn::make('orig_state')->label('Original State')
                ->getStateUsing(fn (SystemLog $r): ?string => $r->metadata['original']['state'] ?? null),
            ExportColumn::make('orig_zip')->label('Original ZIP')
                ->getStateUsing(fn (SystemLog $r): ?string => $r->metadata['original']['zip'] ?? null),

            ExportColumn::make('corr_company')->label('Corrected Company')
                ->getStateUsing(fn (SystemLog $r): ?string => $r->metadata['corrected']['company'] ?? null),
            ExportColumn::make('corr_street')->label('Corrected Street')
                ->getStateUsing(fn (SystemLog $r): ?string => $r->metadata['corrected']['address1'] ?? null),
            ExportColumn::make('corr_suite')->label('Corrected Suite')
                ->getStateUsing(fn (SystemLog $r): ?string => $r->metadata['corrected']['address2'] ?? null),
            ExportColumn::make('corr_city')->label('Corrected City')
                ->getStateUsing(fn (SystemLog $r): ?string => $r->metadata['corrected']['city'] ?? null),
            ExportColumn::make('corr_state')->label('Corrected State')
                ->getStateUsing(fn (SystemLog $r): ?string => $r->metadata['corrected']['state'] ?? null),
            ExportColumn::make('corr_zip')->label('Corrected ZIP')
                ->getStateUsing(fn (SystemLog $r): ?string => $r->metadata['corrected']['zip'] ?? null),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your Pace Corrections export is ready — '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if (($failed = $export->getFailedRowsCount()) > 0) {
            $body .= ' '.number_format($failed).' '.str('row')->plural($failed).' failed.';
        }

        return $body;
    }
}

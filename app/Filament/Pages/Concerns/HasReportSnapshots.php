<?php

namespace App\Filament\Pages\Concerns;

use App\Models\ReportSnapshot;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Shared wiring for snapshot-backed report pages. The page reads from a
 * pre-built ReportSnapshot when one exists for the current filters, and only
 * falls back to a live computation (which it then caches) on a miss — e.g. a
 * filter combination the background job did not pre-build. Pairs with the
 * App\Contracts\ReportSnapshotProvider static members.
 */
trait HasReportSnapshots
{
    /**
     * The current (live) filter state read from the table — page specific.
     *
     * @return array<string, mixed>
     */
    abstract protected function currentFilters(): array;

    /**
     * Return the report rows, preferring a stored snapshot and computing + caching
     * on a miss.
     *
     * @param  callable(array<string, mixed>): Collection<int, array<string, mixed>>  $compute
     * @return Collection<int, array<string, mixed>>
     */
    protected function reportRecords(callable $compute): Collection
    {
        $filters = $this->currentFilters();
        $key = static::reportKey();

        $snapshot = ReportSnapshot::query()
            ->where('report_key', $key)
            ->where('signature', ReportSnapshot::signatureFor($filters))
            ->first();

        if ($snapshot) {
            return collect($snapshot->payload);
        }

        $start = microtime(true);
        $data = $compute($filters);
        ReportSnapshot::store($key, $filters, $data, (int) ((microtime(true) - $start) * 1000));

        return $data;
    }

    public function getSubheading(): ?string
    {
        $generatedAt = ReportSnapshot::query()
            ->where('report_key', static::reportKey())
            ->where('signature', ReportSnapshot::signatureFor($this->currentFilters()))
            ->value('generated_at');

        if (! $generatedAt) {
            return 'Building this view on first open — it will be instant afterwards.';
        }

        return 'Snapshot built '.Carbon::parse($generatedAt)->diffForHumans().' · "Refresh now" rebuilds it from the latest data.';
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshSnapshot')
                ->label('Refresh now')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    ReportSnapshot::where('report_key', static::reportKey())->delete();

                    Notification::make()
                        ->title('Rebuilt from the latest data')
                        ->body('This report was recomputed from the current numbers.')
                        ->success()
                        ->send();
                }),
        ];
    }
}

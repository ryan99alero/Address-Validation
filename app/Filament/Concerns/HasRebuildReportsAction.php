<?php

namespace App\Filament\Concerns;

use App\Jobs\RebuildCarrierRollup;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Adds a "Rebuild Reports" header button that queues a rollup rebuild. Reports read
 * pre-aggregated rollup tables (rebuilt nightly + on import), so this lets a user
 * force an immediate refresh after bulk imports/deletions.
 */
trait HasRebuildReportsAction
{
    protected function rebuildReportsAction(): Action
    {
        return Action::make('rebuildReports')
            ->label('Rebuild Reports')
            ->icon('heroicon-o-arrow-path')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Rebuild report data')
            ->modalDescription('Recomputes the report rollups (Fee Summary, Per-Shipment Costs, Comparison, Correction Audit) from the current invoices. It runs on the queue — the numbers refresh in a couple of minutes when it finishes. A queue worker must be running.')
            ->modalSubmitActionLabel('Rebuild now')
            ->action(function (): void {
                RebuildCarrierRollup::dispatch();

                Notification::make()
                    ->title('Rebuild queued')
                    ->body('Reports will refresh in a couple of minutes.')
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->rebuildReportsAction(),
        ];
    }
}

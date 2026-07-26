<?php

namespace App\Filament\Concerns;

use App\Jobs\RebuildCarrierRollup;
use App\Jobs\RecategorizeChargesJob;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Bus;

/**
 * A "Apply & Rebuild Stats" header button for the charge-classification screens. After the operator
 * adds or changes a category mapping, this re-applies the classifications across every imported
 * charge and then rebuilds the report rollups, so the calculated stats (Fee Summary, Per-Shipment
 * Costs, Comparison, Correction Audit) reflect the change. Chained + queued so the rollup rebuild
 * always runs after a fresh re-resolve, in the background.
 */
trait HasApplyReclassificationAction
{
    protected function applyReclassificationAction(): Action
    {
        return Action::make('applyReclassification')
            ->label('Apply & Rebuild Stats')
            ->icon('heroicon-o-arrow-path')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Apply classification changes & rebuild stats')
            ->modalIcon('heroicon-o-arrow-path')
            ->modalDescription('Re-applies your charge classifications to every imported charge, then rebuilds the report statistics (Fee Summary, Per-Shipment Costs, Comparison, Correction Audit) so they reflect your latest changes. Runs in the background and can take a few minutes — a queue worker must be running.')
            ->modalSubmitActionLabel('Apply & rebuild')
            ->action(function (): void {
                Bus::chain([
                    new RecategorizeChargesJob,
                    new RebuildCarrierRollup,
                ])->dispatch();

                Notification::make()
                    ->title('Applying & rebuilding')
                    ->body('Your classification changes are being applied to all charges and the report stats are rebuilding in the background. The numbers will refresh when it finishes.')
                    ->success()
                    ->send();
            });
    }
}

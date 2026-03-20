<?php

namespace App\Filament\Resources\ImportBatches\Tables;

use App\Models\ImportBatch;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ImportBatchesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable(),
                TextColumn::make('original_filename')
                    ->label('File')
                    ->searchable()
                    ->limit(30)
                    ->tooltip(fn ($record) => $record->original_filename),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'mapping' => 'info',
                        'processing' => 'warning',
                        'completed' => 'success',
                        'failed' => 'danger',
                        'cancelled' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state, $record): string => match ($state) {
                        'processing' => 'Processing: '.$record->getPhaseLabel(),
                        default => ucfirst($state),
                    }),
                TextColumn::make('progress')
                    ->label('Progress')
                    ->state(function ($record): string {
                        if ($record->isCompleted()) {
                            $validated = $record->validated_rows ?? 0;
                            $total = $record->successful_rows ?? 0;

                            return $total > 0 ? "{$validated}/{$total} validated" : 'No addresses';
                        }

                        if ($record->isProcessing()) {
                            return $record->getOverallProgress().'%';
                        }

                        if ($record->isFailed()) {
                            return 'Failed';
                        }

                        return '-';
                    })
                    ->color(fn ($record): string => match (true) {
                        $record->isFailed() => 'danger',
                        $record->isProcessing() => 'warning',
                        $record->isCompleted() && $record->validated_rows > 0 => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('total_rows')
                    ->label('Rows')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('carrier.name')
                    ->label('Carrier')
                    ->placeholder('None'),
                TextColumn::make('importer.name')
                    ->label('Imported By')
                    ->placeholder('System'),
                TextColumn::make('created_at')
                    ->label('Started')
                    ->dateTime('M j, g:i A')
                    ->sortable(),
                TextColumn::make('completed_at')
                    ->label('Completed')
                    ->dateTime('M j, g:i A')
                    ->sortable()
                    ->placeholder('In Progress'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'mapping' => 'Mapping',
                        'processing' => 'Processing',
                        'completed' => 'Completed',
                        'failed' => 'Failed',
                        'cancelled' => 'Cancelled',
                    ]),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('View Progress')
                    ->icon('heroicon-o-arrow-path')
                    ->url(fn (ImportBatch $record): string => route('filament.admin.pages.batch-processing', ['batch' => $record->id]))
                    ->visible(fn (ImportBatch $record): bool => $record->isProcessing() || $record->isCompleted()),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->poll(fn (): ?string => ImportBatch::where('status', 'processing')->exists() ? '5s' : null);
    }
}

<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use UnitEnum;

/**
 * Admin view of the queue's failed jobs, with retry/delete/purge — so failures are visible and
 * cleanable without SSH. A nightly `queue:prune-failed` (routes/console.php) auto-drops rows
 * older than 7 days; this page handles on-demand review and cleanup.
 */
class FailedJobs extends Page implements HasTable
{
    use AdminOnly;
    use InteractsWithTable;

    protected string $view = 'filament.pages.failed-jobs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Admin';

    protected static ?string $navigationLabel = 'Failed Jobs';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Failed Queue Jobs';

    public static function getNavigationBadge(): ?string
    {
        $n = DB::table('failed_jobs')->count();

        return $n > 0 ? (string) $n : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return DB::table('failed_jobs')->count() > 0 ? 'danger' : null;
    }

    /**
     * The failed_jobs rows mapped to display rows, keyed by id (for record resolution).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function rows(): Collection
    {
        return DB::table('failed_jobs')->orderByDesc('failed_at')->get()
            ->map(function (object $r): array {
                $payload = json_decode((string) $r->payload, true) ?: [];

                return [
                    'id' => $r->id,
                    'uuid' => $r->uuid,
                    'name' => $payload['displayName'] ?? 'Unknown job',
                    'queue' => $r->queue,
                    'connection' => $r->connection,
                    'failed_at' => $r->failed_at,
                    'error' => trim(strtok((string) $r->exception, "\n")),
                ];
            })
            ->keyBy('id');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => static::rows())
            ->columns([
                TextColumn::make('name')->label('Job')->weight('bold')->wrap()->searchable(),
                TextColumn::make('queue')->label('Queue')->badge(),
                TextColumn::make('failed_at')->label('Failed At')->dateTime()->sortable(),
                TextColumn::make('error')->label('Error')->wrap()->limit(120)->color('danger')
                    ->tooltip(fn (array $record): string => $record['error']),
            ])
            ->recordActions([
                Action::make('retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->action(function (array $record): void {
                        Artisan::call('queue:retry', ['id' => [$record['uuid']]]);
                    }),
                Action::make('delete')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (array $record): void {
                        DB::table('failed_jobs')->where('id', $record['id'])->delete();
                    }),
            ])
            ->headerActions([
                Action::make('retryAll')
                    ->label('Retry all')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->visible(fn (): bool => DB::table('failed_jobs')->exists())
                    ->action(fn () => Artisan::call('queue:retry', ['id' => ['all']])),
                Action::make('purgeAll')
                    ->label('Purge all')
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (): bool => DB::table('failed_jobs')->exists())
                    ->action(fn () => Artisan::call('queue:flush')),
            ])
            ->emptyStateHeading('No failed jobs')
            ->emptyStateDescription('The queue is clean.')
            ->emptyStateIcon(Heroicon::OutlinedCheckCircle)
            ->paginated([25, 50, 100]);
    }
}

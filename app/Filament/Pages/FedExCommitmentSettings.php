<?php

namespace App\Filament\Pages;

use App\Models\FedExCommitmentSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Edit the FedEx-agreement commitment configuration without a deploy: the six numeric targets, the
 * optional-membership toggles, and the day-count denominator. Blank targets fall back to
 * config('fedex_commitments'). Saving bumps the settings timestamp, which busts the widget cache.
 */
class FedExCommitmentSettings extends Page implements HasSchemas
{
    use InteractsWithSchemas;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?string $navigationLabel = 'FedEx Commitments';

    protected static string|UnitEnum|null $navigationGroup = 'Configuration';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.fedex-commitment-settings';

    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isAdmin() ?? false;
    }

    public function mount(): void
    {
        $settings = FedExCommitmentSetting::instance();

        $this->form->fill([
            'express_avg_daily_packages' => $settings->express_avg_daily_packages,
            'express_avg_daily_revenue' => $settings->express_avg_daily_revenue,
            'express_avg_charge_per_package' => $settings->express_avg_charge_per_package,
            'ground_avg_daily_packages' => $settings->ground_avg_daily_packages,
            'ground_avg_daily_revenue' => $settings->ground_avg_daily_revenue,
            'ground_avg_charge_per_package' => $settings->ground_avg_charge_per_package,
            'include_home_delivery' => $settings->include_home_delivery,
            'include_first_overnight' => $settings->include_first_overnight,
            'include_sameday' => $settings->include_sameday,
            'day_count_mode' => $settings->dayCountMode(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $defaults = config('fedex_commitments.targets');

        return $schema
            ->schema([
                Section::make('Commitment Targets (all minimums)')
                    ->description('The six numeric commitments from the transportation agreement. Leave a field blank to use the built-in default (shown as the placeholder). These change on renegotiation.')
                    ->schema([
                        Fieldset::make('Express — Domestic Express Non-Freight')
                            ->schema([
                                TextInput::make('express_avg_daily_packages')->label('Avg daily packages')->numeric()->placeholder((string) $defaults['express']['avg_daily_packages']),
                                TextInput::make('express_avg_daily_revenue')->label('Avg daily gross revenue')->numeric()->prefix('$')->placeholder((string) $defaults['express']['avg_daily_revenue']),
                                TextInput::make('express_avg_charge_per_package')->label('Avg gross charge / package')->numeric()->prefix('$')->placeholder((string) $defaults['express']['avg_charge_per_package']),
                            ])->columns(3),
                        Fieldset::make('Ground — Ground Domestic Single Piece')
                            ->schema([
                                TextInput::make('ground_avg_daily_packages')->label('Avg daily packages')->numeric()->placeholder((string) $defaults['ground']['avg_daily_packages']),
                                TextInput::make('ground_avg_daily_revenue')->label('Avg daily gross revenue')->numeric()->prefix('$')->placeholder((string) $defaults['ground']['avg_daily_revenue']),
                                TextInput::make('ground_avg_charge_per_package')->label('Avg gross charge / package')->numeric()->prefix('$')->placeholder((string) $defaults['ground']['avg_charge_per_package']),
                            ])->columns(3),
                    ]),

                Section::make('Bucket membership')
                    ->description('Services whose commitment status is optional — toggle them into or out of a bucket. The widget shows the current state so the number is never ambiguous.')
                    ->schema([
                        Toggle::make('include_home_delivery')->label('Count FedEx Home Delivery in Ground')->helperText('Shares Ground\'s rate table; the commitment header names only "Ground Domestic Single Piece".'),
                        Toggle::make('include_first_overnight')->label('Count FedEx First Overnight in Express')->helperText('The contract does not price it; commitment status unconfirmed — default off.'),
                        Toggle::make('include_sameday')->label('Count FedEx SameDay / SameDay City in Express')->helperText('Default off — commitment status unconfirmed.'),
                    ])->columns(1),

                Section::make('Day-count denominator')
                    ->description('How the "per day" metrics divide. Business days is the default; switch once FedEx confirms the basis in writing.')
                    ->schema([
                        Select::make('day_count_mode')
                            ->label('Days counted as')
                            ->options([
                                'business' => 'Business days (weekdays minus US federal holidays)',
                                'calendar' => 'Calendar days',
                                'active' => 'Active days (distinct dates with a shipment in the bucket)',
                            ])
                            ->native(false)
                            ->required(),
                    ]),
            ])
            ->statePath('data');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save Settings')
                ->icon(Heroicon::OutlinedCheck)
                ->action(fn () => $this->saveSettings()),
        ];
    }

    public function saveSettings(): void
    {
        $data = $this->form->getState();

        FedExCommitmentSetting::instance()->update([
            'express_avg_daily_packages' => $data['express_avg_daily_packages'] ?: null,
            'express_avg_daily_revenue' => $data['express_avg_daily_revenue'] ?: null,
            'express_avg_charge_per_package' => $data['express_avg_charge_per_package'] ?: null,
            'ground_avg_daily_packages' => $data['ground_avg_daily_packages'] ?: null,
            'ground_avg_daily_revenue' => $data['ground_avg_daily_revenue'] ?: null,
            'ground_avg_charge_per_package' => $data['ground_avg_charge_per_package'] ?: null,
            'include_home_delivery' => (bool) $data['include_home_delivery'],
            'include_first_overnight' => (bool) $data['include_first_overnight'],
            'include_sameday' => (bool) $data['include_sameday'],
            'day_count_mode' => $data['day_count_mode'],
        ]);

        Notification::make()
            ->success()
            ->title('Commitment settings saved')
            ->body('The dashboard widgets will reflect the new targets and toggles.')
            ->send();
    }
}

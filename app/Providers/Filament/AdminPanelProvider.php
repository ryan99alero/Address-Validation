<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Login;
use App\Filament\Pages\Dashboard;
use App\Filament\Spotlight\RecentItemCommand;
use App\Http\Middleware\RecordRecentItem;
use App\Models\RecentItem;
use App\Support\RecentItems;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use LivewireUI\Spotlight\Spotlight;
use pxlrbt\FilamentSpotlight\SpotlightPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login(Login::class)
            ->brandName('Address Validation')
            ->navigationGroups([
                NavigationGroup::make()->label('Address Intelligence'),
                NavigationGroup::make()->label('Carrier Costs'),
                NavigationGroup::make()->label('Configuration'),
                NavigationGroup::make()->label('Admin'),
                // Populated per-user in the serving hook below; collapsed so it never grows the
                // sidebar until you open it — a real menu at the bottom, like the others.
                NavigationGroup::make()->label('Recent')->collapsed(),
            ])
            ->sidebarCollapsibleOnDesktop()
            ->renderHook(
                PanelsRenderHook::SIDEBAR_FOOTER,
                fn (): string => view('filament.sidebar-environment')->render(),
            )
            ->bootUsing(function (): void {
                // Per request (php-fpm), after the spotlight plugin's serving listener populates
                // Spotlight::$commands: prepend the user's recents so they lead the Ctrl+K empty
                // state, and enable show-results-without-input to reveal them before any typing.
                Filament::serving(function (): void {
                    $user = Filament::auth()->user();
                    if (! $user) {
                        return;
                    }

                    $recents = app(RecentItems::class)->forUser($user, 7);
                    if ($recents->isEmpty()) {
                        return;
                    }

                    $commands = $recents
                        ->map(fn (RecentItem $item): RecentItemCommand => new RecentItemCommand(
                            (string) $item->id,
                            $item->label,
                            route('recent.go', $item),
                        ))
                        ->all();

                    Spotlight::$commands = array_merge($commands, Spotlight::$commands);
                    config()->set('livewire-ui-spotlight.show_results_without_input', true);

                    // Surface the same recents as a real, collapsible "Recent" nav group at the
                    // bottom of the sidebar (added here because auth is available during serving).
                    Filament::getCurrentPanel()->navigationItems(
                        $recents->values()->map(fn (RecentItem $item, int $i): NavigationItem => NavigationItem::make($item->label)
                            ->url(route('recent.go', $item))
                            ->icon($item->type === RecentItem::TYPE_RECORD ? Heroicon::OutlinedDocument : Heroicon::OutlinedClock)
                            ->group('Recent')
                            ->sort($i))
                            ->all(),
                    );
                });
            })
            ->darkMode(true)
            // Bell (top-right) with an unread-count badge — where completed exports
            // land with a Download link, so no email/mail server is needed.
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverClusters(in: app_path('Filament/Clusters'), for: 'App\Filament\Clusters')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                // Cost Intelligence widgets (Bleed + Prevent zones) auto-discover from
                // app/Filament/Widgets; the Filament marketing widget is intentionally dropped.
            ])
            ->navigationItems([
                NavigationItem::make('Telescope')
                    ->url('/telescope', shouldOpenInNewTab: true)
                    ->icon(Heroicon::OutlinedMagnifyingGlass)
                    ->group('Admin')
                    ->sort(99)
                    ->visible(fn (): bool => Auth::user()?->isAdmin() ?? false),
            ])
            ->plugins([
                // Ctrl/Cmd+K command palette. Auto-indexes the pages + resources the current user
                // can access (respects Filament gating), plus record search via recordTitleAttribute.
                SpotlightPlugin::make(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                RecordRecentItem::class,
            ]);
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\RecentItems;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records the current panel page/record into the user's Recents. Runs in terminate() (after the
 * response is flushed, so zero request latency on php-fpm), and only for authenticated full-page
 * GET loads — Livewire AJAX updates, redirects, downloads, and auth pages are all skipped.
 */
class RecordRecentItem
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            if (! $request->isMethod('GET')) {
                return;
            }
            if ($response->getStatusCode() !== 200) {
                return;
            }
            if ($request->headers->has('X-Livewire')) {
                return;
            }
            if (! $request->user()) {
                return;
            }

            $route = $request->route();
            if (! $route || ! str_starts_with((string) $route->getName(), 'filament.admin.')) {
                return;
            }

            app(RecentItems::class)->record($request, Filament::getPanel('admin'));
        } catch (\Throwable $e) {
            report($e); // best-effort — recents must never disrupt a live panel
        }
    }
}

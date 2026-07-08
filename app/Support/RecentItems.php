<?php

namespace App\Support;

use App\Models\RecentItem;
use App\Models\User;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;

/**
 * Per-user Recents: capture (resolve a panel request into a denormalized recent_items row) and the
 * auth-filtered read model. Labels are stored at visit time so deleted/renamed records never break
 * the list; access is re-checked at render via the stored Filament class's canAccess().
 */
class RecentItems
{
    private const KEEP = 30;

    public function record(Request $request, Panel $panel): void
    {
        $route = $request->route();
        $routeName = $route?->getName();

        if (! $route || ! $routeName) {
            return;
        }

        [$class, $type, $recordKey, $label] = $this->resolve($panel, $routeName, $route);

        if ($class === null || $label === null || $label === '') {
            return; // not a known resource/page, a create form, or a vanished record — skip
        }

        $item = RecentItem::updateOrCreate(
            [
                'user_id' => $request->user()->getKey(),
                'route_name' => $routeName,
                'record_key' => (string) $recordKey,
            ],
            [
                'type' => $type,
                'filament_class' => $class,
                'label' => $label,
                'url' => $request->url(), // path + host, no query string
                'visited_at' => now(),
            ],
        );

        if ($item->wasRecentlyCreated) {
            $this->prune((int) $request->user()->getKey());
        } else {
            $item->increment('visit_count');
        }
    }

    /**
     * @return array{0: ?string, 1: string, 2: int|string, 3: ?string} [filamentClass, type, recordKey, label]
     */
    protected function resolve(Panel $panel, string $routeName, Route $route): array
    {
        foreach ($panel->getResources() as $resource) {
            if (! str_starts_with($routeName, $resource::getRouteBaseName($panel).'.')) {
                continue;
            }

            $pageKey = str($routeName)->afterLast('.')->value();
            if ($pageKey === 'create') {
                return [null, RecentItem::TYPE_PAGE, '', null];
            }

            $recordParam = $route->parameter('record');
            if ($recordParam !== null) {
                $key = $recordParam instanceof Model ? $recordParam->getKey() : $recordParam;
                $model = $recordParam instanceof Model
                    ? $recordParam
                    : $resource::getModel()::query()->find($key);

                if ($model === null) {
                    return [null, RecentItem::TYPE_RECORD, $key, null];
                }

                $label = trim((string) $resource::getRecordTitle($model));

                return [$resource, RecentItem::TYPE_RECORD, $key, ucfirst($resource::getModelLabel()).': '.$label];
            }

            return [$resource, RecentItem::TYPE_PAGE, '', $resource::getPluralModelLabel() ?: $resource::getModelLabel()];
        }

        foreach ($panel->getPages() as $page) {
            if ($page::getRouteName($panel) === $routeName) {
                return [$page, RecentItem::TYPE_PAGE, '', $page::getNavigationLabel()];
            }
        }

        return [null, RecentItem::TYPE_PAGE, '', null];
    }

    /**
     * Last $limit items the user can STILL access, newest first.
     *
     * @return Collection<int, RecentItem>
     */
    public function forUser(User $user, int $limit = 7): Collection
    {
        try {
            return RecentItem::forUser((int) $user->getKey())
                ->orderByDesc('visited_at')->orderByDesc('id')
                ->limit($limit * 3)
                ->get()
                ->filter(fn (RecentItem $item): bool => $this->accessible($item))
                ->take($limit)
                ->values();
        } catch (\Throwable $e) {
            // The sidebar + palette read this on every render — never let a missing table (code
            // deployed before `migrate` runs) or a query error take down the whole panel.
            report($e);

            return collect();
        }
    }

    protected function accessible(RecentItem $item): bool
    {
        $class = $item->filament_class;

        if (! $class || ! class_exists($class)) {
            return false;
        }

        try {
            return (bool) $class::canAccess();
        } catch (\Throwable) {
            return false;
        }
    }

    protected function prune(int $userId): void
    {
        $stale = RecentItem::forUser($userId)
            ->orderByDesc('visited_at')->orderByDesc('id')
            ->skip(self::KEEP)->limit(100)
            ->pluck('id');

        if ($stale->isNotEmpty()) {
            RecentItem::whereIn('id', $stale)->delete();
        }
    }
}

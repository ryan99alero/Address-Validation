<?php

namespace App\Filament\Spotlight;

use LivewireUI\Spotlight\Spotlight;
use LivewireUI\Spotlight\SpotlightCommand;

/**
 * A recent item as a Spotlight (Ctrl+K) command. Prepended to the command list so it appears first
 * in the empty-query state. getId() MUST be overridden — the base returns md5(static::class), which
 * would collide across every instance. Points at the /recent/{item} redirector for fail-soft.
 */
class RecentItemCommand extends SpotlightCommand
{
    public function __construct(
        protected string $recentId,
        string $label,
        protected string $url,
    ) {
        $this->name = $label;
        $this->description = 'Recent';
    }

    public function getId(): string
    {
        return 'recent-'.$this->recentId;
    }

    public function execute(Spotlight $spotlight): void
    {
        $spotlight->redirect($this->url);
    }
}

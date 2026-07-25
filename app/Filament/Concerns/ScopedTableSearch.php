<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Adds a "Search in: [ All fields ▾ ]" scope to a table's single search box. Default (null) keeps
 * Filament's normal all-fields global search; picking a column narrows the SAME search box to just
 * that column — reusing the column's own applySearchConstraint(), so relationship and custom-query
 * columns behave exactly like the global search, only narrowed.
 *
 * To enable on a table Livewire component (Page / ListRecords / RelationManager):
 *   use ScopedTableSearch;
 *   protected function applyGlobalSearchToTableQuery(Builder $query): Builder
 *   {
 *       return $this->applyScopedColumnSearch($query) ?? parent::applyGlobalSearchToTableQuery($query);
 *   }
 * The "Search in" dropdown renders itself on any component using this trait (TOOLBAR_SEARCH_AFTER hook).
 */
trait ScopedTableSearch
{
    public ?string $tableSearchColumn = null;

    /**
     * "All fields" plus every globally-searchable, visible column — the scope dropdown options.
     *
     * @return array<string, string>
     */
    public function getSearchScopeOptions(): array
    {
        $options = ['' => 'All fields'];

        foreach ($this->getTable()->getColumns() as $column) {
            if ($column->isHidden() || ! $column->isGloballySearchable()) {
                continue;
            }
            $options[$column->getName()] = (string) $column->getLabel();
        }

        return $options;
    }

    /**
     * Apply the search to only the selected column, or return null to signal "no scope — let the
     * caller run the default all-fields search".
     */
    protected function applyScopedColumnSearch(Builder $query): ?Builder
    {
        if (blank($this->tableSearchColumn)) {
            return null;
        }

        $search = $this->getTableSearch();
        if (blank($search)) {
            return $query;
        }

        $column = collect($this->getTable()->getColumns())
            ->first(fn ($column): bool => $column->getName() === $this->tableSearchColumn);

        if (! $column || $column->isHidden() || ! $column->isGloballySearchable()) {
            return null; // a stale/hidden selection falls back to all-fields search
        }

        $query->where(function (Builder $query) use ($column, $search): void {
            $isFirst = true;
            $column->applySearchConstraint($query, $search, $isFirst);
        });

        return $query;
    }
}

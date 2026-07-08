<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecentItem extends Model
{
    public const TYPE_PAGE = 'page';

    public const TYPE_RECORD = 'record';

    protected $fillable = [
        'user_id', 'type', 'route_name', 'record_key', 'filament_class', 'label', 'url', 'visit_count', 'visited_at',
    ];

    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
            'visit_count' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}

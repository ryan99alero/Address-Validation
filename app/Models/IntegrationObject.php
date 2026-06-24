<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationObject extends Model
{
    use HasFactory;

    protected $fillable = [
        'connection_id',
        'object_name',
        'display_name',
        'description',
        'primary_key_field',
        'primary_key_type',
        'available_fields',
        'available_children',
        'default_filter',
        'local_model',
        'local_table',
        'sync_enabled',
        'sync_direction',
        'api_method',
        'sync_frequency',
        'last_synced_at',
    ];

    protected $casts = [
        'available_fields' => 'array',
        'available_children' => 'array',
        'sync_enabled' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    /**
     * Get the local model class if defined
     */
    public function getLocalModelClass(): ?string
    {
        if (empty($this->local_model)) {
            return null;
        }

        if (class_exists($this->local_model)) {
            return $this->local_model;
        }

        return null;
    }

    /**
     * Get available field names
     */
    public function getFieldNames(): array
    {
        if (empty($this->available_fields)) {
            return [];
        }

        return array_column($this->available_fields, 'name');
    }

    /**
     * Get available child object names
     */
    public function getChildNames(): array
    {
        if (empty($this->available_children)) {
            return [];
        }

        return array_column($this->available_children, 'objectName');
    }

    // Relationships

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'connection_id');
    }

    public function fieldMappings(): HasMany
    {
        return $this->hasMany(IntegrationFieldMapping::class, 'object_id');
    }

    public function queryTemplates(): HasMany
    {
        return $this->hasMany(IntegrationQueryTemplate::class, 'object_id');
    }

    /**
     * Get sync logs for this object from the system_logs table
     */
    public function syncLogs()
    {
        return SystemLog::query()
            ->where('category', SystemLog::CATEGORY_INTEGRATION)
            ->where('type', 'sync')
            ->whereJsonContains('metadata->object_id', $this->id);
    }

    // Scopes

    public function scopeSyncEnabled($query)
    {
        return $query->where('sync_enabled', true);
    }

    public function scopeByDirection($query, string $direction)
    {
        return $query->where('sync_direction', $direction);
    }
}

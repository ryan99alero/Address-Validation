<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class IntegrationFieldMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'object_id',
        'local_table',
        'external_field',
        'external_xpath',
        'external_type',
        'local_field',
        'local_type',
        'transform',
        'transform_options',
        'sync_on_pull',
        'sync_on_push',
        'is_identifier',
    ];

    protected $casts = [
        'transform_options' => 'array',
        'sync_on_pull' => 'boolean',
        'sync_on_push' => 'boolean',
        'is_identifier' => 'boolean',
    ];

    /**
     * Transform a value from external format to local format
     */
    public function transformToLocal($value)
    {
        if ($value === null) {
            return null;
        }

        return match ($this->transform) {
            'date_ms_to_carbon' => $this->dateMillisecondsToCarbon($value),
            'date_iso_to_carbon' => Carbon::parse($value),
            'cents_to_dollars' => $value / 100,
            'string_to_int' => (int) $value,
            'string_to_float' => (float) $value,
            'string_to_bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json_decode' => json_decode($value, true),
            'trim' => trim($value),
            'uppercase' => strtoupper($value),
            'lowercase' => strtolower($value),
            'fk_lookup' => $this->foreignKeyLookup($value),
            'value_map' => $this->valueMap($value),
            default => $value,
        };
    }

    /**
     * Transform a value from local format to external format
     */
    public function transformToExternal($value)
    {
        if ($value === null) {
            return null;
        }

        return match ($this->transform) {
            'date_ms_to_carbon' => $this->carbonToDateMilliseconds($value),
            'date_iso_to_carbon' => $value instanceof Carbon ? $value->toIso8601String() : $value,
            'cents_to_dollars' => (int) ($value * 100),
            'string_to_int' => (string) $value,
            'string_to_float' => (string) $value,
            'string_to_bool' => $value ? 'true' : 'false',
            'json_decode' => json_encode($value),
            default => $value,
        };
    }

    /**
     * Convert milliseconds timestamp to Carbon
     * Handles receiving a Carbon instance (PaceApiClient parseValueObject already converts Date fields)
     */
    protected function dateMillisecondsToCarbon($value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        return Carbon::createFromTimestampMs($value);
    }

    /**
     * Look up a foreign key value using transform_options config
     *
     * Expected transform_options format:
     * {"model": "App\\Models\\Department", "match_column": "external_department_id", "return_column": "id"}
     */
    protected function foreignKeyLookup($value)
    {
        $options = $this->transform_options;

        if (empty($options['model']) || empty($options['match_column']) || empty($options['return_column'])) {
            return $value;
        }

        $modelClass = $options['model'];

        if (! class_exists($modelClass)) {
            return null;
        }

        return $modelClass::where($options['match_column'], $value)
            ->value($options['return_column']);
    }

    /**
     * Map values using a configurable mapping table
     *
     * Expected transform_options format:
     * {"map": {"A": true, "I": false}, "default": null}
     *
     * Or for string values:
     * {"map": {"active": "Active", "inactive": "Inactive"}, "default": "Unknown"}
     */
    protected function valueMap($value)
    {
        $options = $this->transform_options;

        if (empty($options['map']) || ! is_array($options['map'])) {
            return $value;
        }

        $stringValue = (string) $value;

        if (array_key_exists($stringValue, $options['map'])) {
            return $options['map'][$stringValue];
        }

        return $options['default'] ?? $value;
    }

    /**
     * Convert Carbon to milliseconds timestamp
     */
    protected function carbonToDateMilliseconds($value): int
    {
        if ($value instanceof Carbon) {
            return $value->getTimestampMs();
        }

        return Carbon::parse($value)->getTimestampMs();
    }

    /**
     * Get the effective table for this mapping.
     * Returns local_table if set, otherwise falls back to the object's local_table.
     */
    public function getEffectiveTable(): ?string
    {
        return $this->local_table ?? $this->object?->local_table;
    }

    // Relationships

    public function object(): BelongsTo
    {
        return $this->belongsTo(IntegrationObject::class, 'object_id');
    }

    // Scopes

    public function scopeIdentifiers($query)
    {
        return $query->where('is_identifier', true);
    }

    public function scopePullEnabled($query)
    {
        return $query->where('sync_on_pull', true);
    }

    public function scopePushEnabled($query)
    {
        return $query->where('sync_on_push', true);
    }
}

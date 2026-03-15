<?php

namespace App\Models;

use Database\Factories\ImportFieldTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportFieldTemplate extends Model
{
    /** @use HasFactory<ImportFieldTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'field_mappings',
        'is_default',
        'is_shared',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'field_mappings' => 'array',
            'is_default' => 'boolean',
            'is_shared' => 'boolean',
        ];
    }

    /**
     * Get a mapping for a specific source field.
     */
    public function getMappingForSource(string $sourceField): ?string
    {
        $mappings = $this->field_mappings ?? [];

        foreach ($mappings as $mapping) {
            if (($mapping['source'] ?? '') === $sourceField) {
                return $mapping['target'] ?? null;
            }
        }

        return null;
    }

    /**
     * Get a mapping for a specific target field.
     */
    public function getSourceForTarget(string $targetField): ?string
    {
        $mappings = $this->field_mappings ?? [];

        foreach ($mappings as $mapping) {
            if (($mapping['target'] ?? '') === $targetField) {
                return $mapping['source'] ?? null;
            }
        }

        return null;
    }

    /**
     * Get field count summary.
     */
    public function getMappedFieldCountAttribute(): int
    {
        return count($this->field_mappings ?? []);
    }

    // Relationships

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(ImportBatch::class, 'mapping_template_id');
    }

    // Scopes

    public function scopeDefault($query)
    {
        return $query->where('is_default', true);
    }
}

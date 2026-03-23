<?php

namespace App\Services;

use App\Models\CompanySetting;

class DynamicFieldService
{
    /**
     * Get the configured extra field count from company settings.
     * With JSON storage, this is just the number of fields shown in the UI.
     */
    public function getConfiguredExtraFieldCount(): int
    {
        return CompanySetting::instance()->getExtraFieldCount();
    }

    /**
     * Get the current number of extra fields available.
     * With JSON storage, this equals the configured count (no column checks needed).
     */
    public function getCurrentExtraFieldCount(): int
    {
        return $this->getConfiguredExtraFieldCount();
    }

    /**
     * Update the configured extra field count.
     * With JSON storage, we just update the setting - no column changes needed.
     *
     * @return array{added: int, current: int, configured: int}
     */
    public function updateExtraFieldCount(int $newCount): array
    {
        $oldCount = $this->getConfiguredExtraFieldCount();

        $settings = CompanySetting::instance();
        $settings->update(['extra_field_count' => $newCount]);

        return [
            'added' => max(0, $newCount - $oldCount),
            'current' => $newCount,
            'configured' => $newCount,
        ];
    }

    /**
     * Expand extra fields to the specified count.
     * With JSON storage, this is a no-op but kept for backward compatibility.
     *
     * @return array{added: int, current: int}
     */
    public function expandExtraFields(int $newCount): array
    {
        $currentCount = $this->getConfiguredExtraFieldCount();

        return ['added' => 0, 'current' => max($currentCount, $newCount)];
    }

    /**
     * Get list of all extra field names that are configured.
     *
     * @return array<string>
     */
    public function getExtraFieldNames(): array
    {
        $fields = [];
        $count = $this->getConfiguredExtraFieldCount();

        for ($i = 1; $i <= $count; $i++) {
            $fields[] = "extra_{$i}";
        }

        return $fields;
    }

    /**
     * Get extra fields with labels for forms/exports.
     *
     * @return array<string, string>
     */
    public function getExtraFieldOptions(): array
    {
        $options = [];
        $count = $this->getConfiguredExtraFieldCount();

        for ($i = 1; $i <= $count; $i++) {
            $options["extra_{$i}"] = "Extra Field {$i}";
        }

        return $options;
    }

    /**
     * Check if an extra field key is valid.
     */
    public function isValidExtraField(string $key): bool
    {
        if (! preg_match('/^extra_(\d+)$/', $key, $matches)) {
            return false;
        }

        $index = (int) $matches[1];

        return $index >= 1 && $index <= $this->getConfiguredExtraFieldCount();
    }
}

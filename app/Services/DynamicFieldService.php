<?php

namespace App\Services;

use App\Models\CompanySetting;
use Illuminate\Support\Facades\Schema;

class DynamicFieldService
{
    /**
     * Get the current number of extra fields that exist in the addresses table.
     */
    public function getCurrentExtraFieldCount(): int
    {
        $count = 0;

        for ($i = 1; $i <= 100; $i++) {
            if (Schema::hasColumn('addresses', "extra_{$i}")) {
                $count = $i;
            } else {
                break;
            }
        }

        return $count;
    }

    /**
     * Get the configured extra field count from company settings.
     */
    public function getConfiguredExtraFieldCount(): int
    {
        return CompanySetting::instance()->getExtraFieldCount();
    }

    /**
     * Expand extra fields to the specified count.
     * Only adds columns - never removes them.
     *
     * @return array{added: int, current: int}
     */
    public function expandExtraFields(int $newCount): array
    {
        $currentCount = $this->getCurrentExtraFieldCount();
        $added = 0;

        if ($newCount <= $currentCount) {
            return ['added' => 0, 'current' => $currentCount];
        }

        Schema::table('addresses', function ($table) use ($currentCount, $newCount, &$added) {
            for ($i = $currentCount + 1; $i <= $newCount; $i++) {
                $table->string("extra_{$i}")->nullable();
                $added++;
            }
        });

        return ['added' => $added, 'current' => $newCount];
    }

    /**
     * Update the configured extra field count and expand columns if needed.
     *
     * @return array{added: int, current: int, configured: int}
     */
    public function updateExtraFieldCount(int $newCount): array
    {
        // Expand columns first
        $result = $this->expandExtraFields($newCount);

        // Update the setting
        $settings = CompanySetting::instance();
        $settings->update(['extra_field_count' => $newCount]);

        return [
            'added' => $result['added'],
            'current' => $result['current'],
            'configured' => $newCount,
        ];
    }

    /**
     * Get list of all extra field names that currently exist.
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
}

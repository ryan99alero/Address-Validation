<?php

namespace App\Services\Integrations;

use Illuminate\Support\Collection;

class SyncResult
{
    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    public int $errors = 0;

    public array $errorMessages = [];

    public Collection $syncedIdentifiers;

    public Collection $parsedRecords;

    public function __construct()
    {
        $this->syncedIdentifiers = collect();
        $this->parsedRecords = collect();
    }

    public function addError(string $message): void
    {
        $this->errors++;
        $this->errorMessages[] = $message;
    }

    public function total(): int
    {
        return $this->created + $this->updated + $this->skipped + $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors > 0;
    }

    public function toArray(): array
    {
        return [
            'fetched' => $this->total(),
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'failed' => $this->errors,
        ];
    }
}

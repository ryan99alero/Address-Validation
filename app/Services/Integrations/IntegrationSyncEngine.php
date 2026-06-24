<?php

namespace App\Services\Integrations;

use App\Models\IntegrationFieldMapping;
use App\Models\IntegrationObject;
use App\Models\SystemLog;
use App\Services\ModelDiscoveryService;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class IntegrationSyncEngine
{
    protected PaceApiClient $client;

    /**
     * Pre-built FK lookup caches: mapping_id => [external_value => local_id]
     */
    protected array $fkCaches = [];

    /**
     * Current sync log entry
     */
    protected ?SystemLog $syncLog = null;

    public function __construct(PaceApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * Sync records from the remote API into the local database using field mappings.
     *
     * @param  IntegrationObject  $object  The object definition with field mappings
     * @param  string|null  $filterOverride  Override the default XPath filter
     * @param  callable|null  $enrichCallback  Called per-record after mapping, before upsert: fn(array &$attributes, array $parsedRecord)
     */
    public function sync(
        IntegrationObject $object,
        ?string $filterOverride = null,
        ?callable $enrichCallback = null,
    ): SyncResult {
        $result = new SyncResult;

        // Start sync log
        $this->syncLog = SystemLog::startIntegrationSync(
            connection: $object->connection,
            operation: 'pull',
            object: $object,
            requestData: [
                'object_name' => $object->object_name,
                'filter' => $filterOverride ?? $object->default_filter,
            ]
        );

        try {
            // Load ALL field mappings (need all fields for the API call)
            $mappings = $object->fieldMappings()->get();

            if ($mappings->isEmpty()) {
                $result->addError("No field mappings defined for {$object->object_name}");
                $this->finalizeSyncLog($result);

                return $result;
            }

            // Build the fields array for the API call
            $fields = $mappings->map(fn (IntegrationFieldMapping $m) => [
                'name' => $m->external_field,
                'xpath' => $m->external_xpath,
            ])->values()->toArray();

            // Pre-build FK lookup caches
            $this->buildFkCaches($mappings);

            // Determine filter
            $filter = $filterOverride ?? $object->default_filter;

            // Determine API method (default to loadValueObjects for backward compatibility)
            $apiMethod = $object->api_method ?? 'loadValueObjects';

            if (in_array($apiMethod, ['createObject', 'updateObject'])) {
                $result->addError("API method '{$apiMethod}' is not supported for pull sync. Use loadValueObjects or findObjects.");
                $this->finalizeSyncLog($result);

                return $result;
            }

            // Fetch all records from the API
            $valueObjects = $this->client->loadAllValueObjects(
                objectName: $object->object_name,
                fields: $fields,
                children: [],
                xpathFilter: $filter,
            );

            if ($valueObjects->isEmpty()) {
                $this->finalizeSyncLog($result);

                return $result;
            }

            // Get pull-enabled mappings and identifier mappings
            $pullMappings = $mappings->where('sync_on_pull', true);
            $identifierMappings = $mappings->where('is_identifier', true);

            // Resolve the local model class
            $modelClass = $object->getLocalModelClass();
            if (! $modelClass) {
                $result->addError("Local model class not found: {$object->local_model}");
                $this->finalizeSyncLog($result);

                return $result;
            }

            // Group pull mappings by effective table (null = primary)
            $primaryTable = $object->local_table;
            $primaryMappings = $pullMappings->filter(
                fn (IntegrationFieldMapping $m) => empty($m->local_table) || $m->local_table === $primaryTable
            );
            $relatedMappingsByTable = $pullMappings->filter(
                fn (IntegrationFieldMapping $m) => ! empty($m->local_table) && $m->local_table !== $primaryTable
            )->groupBy('local_table');

            // Resolve relationship map for related tables
            $relationshipMap = [];
            if ($relatedMappingsByTable->isNotEmpty()) {
                $relationshipMap = $this->resolveRelationships($modelClass, $relatedMappingsByTable->keys()->toArray());
            }

            foreach ($valueObjects as $vo) {
                $parsed = $this->client->parseValueObject($vo);
                $result->parsedRecords->push($parsed);

                try {
                    // Build attributes for the primary table
                    $attributes = $this->buildAttributes($primaryMappings, $parsed);

                    // Build identifier conditions for upsert matching
                    $identifierConditions = $this->buildIdentifierConditions($identifierMappings, $parsed);

                    if (empty($identifierConditions)) {
                        $result->addError('Record missing identifier fields, skipping');

                        continue;
                    }

                    // Track synced identifiers
                    $identifierValue = reset($identifierConditions);
                    $result->syncedIdentifiers->push((string) $identifierValue);

                    // Apply enrich callback
                    if ($enrichCallback) {
                        $enrichCallback($attributes, $parsed);
                    }

                    // Upsert: find existing or create
                    $existing = $modelClass::where($identifierConditions)->first();

                    if ($existing) {
                        if ($this->hasChanges($existing, $attributes)) {
                            $existing->update($attributes);
                            $result->updated++;
                        } else {
                            $result->skipped++;
                        }

                        $primaryRecord = $existing;
                    } else {
                        $createData = array_merge($identifierConditions, $attributes);
                        $primaryRecord = $modelClass::create($createData);
                        $result->created++;
                    }

                    // Sync related tables
                    foreach ($relatedMappingsByTable as $table => $tableMappings) {
                        $this->syncRelatedTable($primaryRecord, $table, $tableMappings, $parsed, $relationshipMap);
                    }
                } catch (Exception $e) {
                    $identifierDisplay = $identifierConditions[$identifierMappings->first()?->local_field ?? 'id'] ?? 'unknown';
                    $result->addError("{$object->object_name} {$identifierDisplay}: ".$e->getMessage());
                }
            }

            // Update last_synced_at on the object
            $object->update(['last_synced_at' => now()]);

        } catch (Exception $e) {
            $result->addError($e->getMessage());
        }

        // Finalize sync log with results
        $this->finalizeSyncLog($result);

        return $result;
    }

    /**
     * Finalize the sync log entry based on results
     */
    protected function finalizeSyncLog(SyncResult $result): void
    {
        if (! $this->syncLog) {
            return;
        }

        $counts = $result->toArray();

        if (! $result->hasErrors()) {
            $this->syncLog->markSuccess($counts);
        } elseif ($result->created + $result->updated + $result->skipped === 0) {
            // All records failed - mark as failed
            $this->syncLog->markFailed(
                implode('; ', array_slice($result->errorMessages, 0, 5)),
                ['all_errors' => $result->errorMessages]
            );
        } else {
            // Some records succeeded, some failed - mark as partial
            $this->syncLog->markPartial(
                count($result->errorMessages).' record(s) failed to sync',
                $counts,
                ['failed_records' => $result->errorMessages]
            );
        }

        $this->syncLog = null;
    }

    /**
     * Build attributes array from pull-enabled mappings and a parsed record.
     */
    protected function buildAttributes(Collection $pullMappings, array $parsed): array
    {
        $attributes = [];

        foreach ($pullMappings as $mapping) {
            $rawValue = $parsed[$mapping->external_field] ?? null;
            $localField = $mapping->local_field;

            if (empty($localField)) {
                continue;
            }

            if ($mapping->transform === 'fk_lookup' && isset($this->fkCaches[$mapping->id])) {
                $transformedValue = $this->fkCaches[$mapping->id][(string) $rawValue] ?? null;
            } else {
                $transformedValue = $mapping->transformToLocal($rawValue);
            }

            if ($transformedValue instanceof Carbon) {
                $transformedValue = $transformedValue->toDateString();
            }

            $attributes[$localField] = $transformedValue;
        }

        return $attributes;
    }

    /**
     * Build identifier conditions for upsert matching.
     */
    protected function buildIdentifierConditions(Collection $identifierMappings, array $parsed): array
    {
        $conditions = [];

        foreach ($identifierMappings as $mapping) {
            $rawValue = $parsed[$mapping->external_field] ?? null;
            $localField = $mapping->local_field;

            if (empty($localField) || $rawValue === null) {
                continue;
            }

            $conditions[$localField] = $mapping->transformToLocal($rawValue);
        }

        return $conditions;
    }

    /**
     * Check if a model has changes compared to new attributes.
     */
    protected function hasChanges(Model $existing, array $attributes): bool
    {
        foreach ($attributes as $key => $value) {
            $current = $existing->getAttribute($key);
            $currentStr = $current instanceof Carbon ? $current->toDateString() : (string) ($current ?? '');
            $newStr = (string) ($value ?? '');
            if ($currentStr !== $newStr) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve table names to relationship method names using ModelDiscoveryService.
     *
     * @return array<string, array{relationship: string, type: string}>
     */
    protected function resolveRelationships(string $modelClass, array $tables): array
    {
        $discovery = new ModelDiscoveryService;
        $relatedTables = $discovery->getRelatedTables($modelClass);

        $map = [];
        foreach ($tables as $table) {
            if (isset($relatedTables[$table])) {
                $map[$table] = $relatedTables[$table];
            }
        }

        return $map;
    }

    /**
     * Sync a related table for a given primary record.
     * Supports HasOne and BelongsTo relationships. HasMany is skipped.
     */
    protected function syncRelatedTable(
        Model $primaryRecord,
        string $table,
        Collection $mappings,
        array $parsed,
        array $relationshipMap,
    ): void {
        if (! isset($relationshipMap[$table])) {
            return;
        }

        $info = $relationshipMap[$table];
        $relationType = $info['type'];
        $relationName = $info['relationship'];

        // Only handle HasOne and BelongsTo for now
        if (! in_array($relationType, ['HasOne', 'BelongsTo'])) {
            return;
        }

        $attributes = $this->buildAttributes($mappings, $parsed);

        if (empty($attributes)) {
            return;
        }

        $relation = $primaryRecord->{$relationName}();
        $relatedRecord = $relation->first();

        // Only update existing related records — never create.
        // Creating related records (e.g., user accounts) requires fields
        // beyond what a field mapping provides, so we skip missing relations.
        if ($relatedRecord && $this->hasChanges($relatedRecord, $attributes)) {
            $relatedRecord->update($attributes);
        }
    }

    /**
     * Discover/validate an object's fields against the live API.
     *
     * Probes Pace with a limit-1 call using the object's defined fields,
     * validates which fields returned data, and updates available_fields.
     */
    public function discoverObject(IntegrationObject $object): array
    {
        $mappings = $object->fieldMappings()->get();

        if ($mappings->isEmpty()) {
            return [
                'success' => false,
                'object_name' => $object->object_name,
                'fields_found' => 0,
                'error' => 'No field mappings defined',
            ];
        }

        $fields = $mappings->map(fn (IntegrationFieldMapping $m) => [
            'name' => $m->external_field,
            'xpath' => $m->external_xpath,
        ])->values()->toArray();

        try {
            $response = $this->client->loadValueObjects(
                objectName: $object->object_name,
                fields: $fields,
                xpathFilter: $object->default_filter,
                limit: 1,
            );

            $valueObjects = $response['valueObjects'] ?? [];
            $fieldsFound = [];
            $fieldsNull = [];

            if (! empty($valueObjects)) {
                $rawFields = $valueObjects[0]['fields'] ?? [];
                $parsed = $this->client->parseValueObject($valueObjects[0]);

                // Build a lookup of Pace-declared types from the raw response
                $declaredTypes = [];
                foreach ($rawFields as $rawField) {
                    $declaredTypes[$rawField['name'] ?? ''] = $rawField['type'] ?? null;
                }

                foreach ($mappings as $mapping) {
                    $value = $parsed[$mapping->external_field] ?? null;
                    $fieldInfo = [
                        'name' => $mapping->external_field,
                        'xpath' => $mapping->external_xpath,
                        'has_data' => $value !== null,
                        'sample_type' => $value !== null ? gettype($value) : null,
                        'api_type' => $declaredTypes[$mapping->external_field] ?? null,
                    ];

                    if ($value !== null) {
                        $fieldsFound[] = $fieldInfo;
                    } else {
                        $fieldsNull[] = $fieldInfo;
                    }
                }
            }

            // Update available_fields on the object
            $allFields = array_merge($fieldsFound, $fieldsNull);
            $object->update(['available_fields' => $allFields]);

            return [
                'success' => true,
                'object_name' => $object->object_name,
                'fields_found' => count($fieldsFound),
                'fields_null' => count($fieldsNull),
                'total_records' => $response['totalRecords'] ?? 0,
                'error' => null,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'object_name' => $object->object_name,
                'fields_found' => 0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Pre-build FK lookup caches for fk_lookup mappings.
     * Executes one query per FK mapping instead of one per record.
     */
    protected function buildFkCaches(Collection $mappings): void
    {
        $this->fkCaches = [];

        $fkMappings = $mappings->where('transform', 'fk_lookup');

        foreach ($fkMappings as $mapping) {
            $options = $mapping->transform_options;

            if (empty($options['model']) || empty($options['match_column']) || empty($options['return_column'])) {
                continue;
            }

            $modelClass = $options['model'];
            if (! class_exists($modelClass)) {
                continue;
            }

            // Build cache: match_column_value => return_column_value
            $this->fkCaches[$mapping->id] = $modelClass::whereNotNull($options['match_column'])
                ->pluck($options['return_column'], $options['match_column'])
                ->mapWithKeys(fn ($val, $key) => [(string) $key => $val])
                ->toArray();
        }
    }
}

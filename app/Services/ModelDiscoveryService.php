<?php

namespace App\Services;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

class ModelDiscoveryService
{
    protected array $modelCache = [];

    protected array $fieldCache = [];

    protected array $relationCache = [];

    /**
     * Get all available models with their display names.
     * Dynamically scans app/Models, filtering out abstract classes,
     * non-Model classes, and Integration* internal models.
     */
    public function getAvailableModels(): array
    {
        if (! empty($this->modelCache)) {
            return $this->modelCache;
        }

        $modelsPath = app_path('Models');
        $models = [];

        foreach (glob("{$modelsPath}/*.php") as $file) {
            $className = 'App\\Models\\'.basename($file, '.php');

            if (! class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            // Skip abstract classes
            if ($reflection->isAbstract()) {
                continue;
            }

            // Skip non-Model classes
            if (! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            // Skip Integration* internal models
            $shortName = $reflection->getShortName();
            if (Str::startsWith($shortName, 'Integration')) {
                continue;
            }

            $instance = new $className;
            $table = $instance->getTable();
            $key = $table;

            $models[$key] = [
                'class' => $className,
                'label' => $this->generateModelLabel($shortName),
                'table' => $table,
            ];
        }

        ksort($models);
        $this->modelCache = $models;

        return $this->modelCache;
    }

    /**
     * Generate a human-readable label from a model class short name.
     */
    protected function generateModelLabel(string $shortName): string
    {
        // Convert CamelCase to spaced words, then pluralize
        $words = preg_replace('/(?<!^)([A-Z])/', ' $1', $shortName);

        return Str::plural($words);
    }

    /**
     * Get model options for Filament Select — keyed by FQCN.
     */
    public function getModelOptionsForSelect(): array
    {
        $models = $this->getAvailableModels();
        $options = [];

        foreach ($models as $model) {
            $options[$model['class']] = $model['label'];
        }

        return $options;
    }

    /**
     * Get related tables for a model via relationship introspection.
     *
     * @return array<string, array{table: string, relationship: string, type: string}>
     */
    public function getRelatedTables(string $modelClass): array
    {
        if (isset($this->relationCache[$modelClass])) {
            return $this->relationCache[$modelClass];
        }

        $related = [];

        if (! class_exists($modelClass)) {
            return $related;
        }

        try {
            $reflection = new ReflectionClass($modelClass);
            $instance = $reflection->newInstanceWithoutConstructor();
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            // Methods to skip — audit/meta relationships and common non-relation methods
            $skipMethods = [
                'creator', 'updater', 'createdBy', 'updatedBy',
            ];

            foreach ($methods as $method) {
                $name = $method->getName();

                // Only zero-param methods defined on the model itself (not parent)
                if ($method->getNumberOfParameters() > 0) {
                    continue;
                }
                if ($method->getDeclaringClass()->getName() !== $modelClass) {
                    continue;
                }
                if (in_array($name, $skipMethods)) {
                    continue;
                }
                // Skip accessors, mutators, scopes, etc.
                if (Str::startsWith($name, ['get', 'set', 'scope', 'is', 'has', 'should', 'can'])) {
                    continue;
                }

                try {
                    $result = $method->invoke($instance);

                    if ($result instanceof Relation) {
                        $relatedModel = $result->getRelated();
                        $table = $relatedModel->getTable();
                        $type = class_basename(get_class($result));

                        $related[$table] = [
                            'table' => $table,
                            'relationship' => $name,
                            'type' => $type,
                        ];
                    }
                } catch (Throwable $e) {
                    // Method threw — not a relationship or requires dependencies
                    continue;
                }
            }
        } catch (Throwable $e) {
            // Reflection failed
        }

        $this->relationCache[$modelClass] = $related;

        return $related;
    }

    /**
     * Get table options for a model: primary table + related tables.
     * Format: ['employees' => 'employees (primary)', 'users' => 'users (via user)']
     */
    public function getTableOptionsForModel(string $modelClass): array
    {
        if (! class_exists($modelClass)) {
            return [];
        }

        $instance = new $modelClass;
        $primaryTable = $instance->getTable();

        $options = [
            $primaryTable => "{$primaryTable} (primary)",
        ];

        $relatedTables = $this->getRelatedTables($modelClass);

        foreach ($relatedTables as $table => $info) {
            $options[$table] = "{$table} (via {$info['relationship']})";
        }

        return $options;
    }

    /**
     * Get all columns for a given table as [column => Human Label].
     */
    public function getTableColumns(string $tableName): array
    {
        $cacheKey = "columns_{$tableName}";

        if (isset($this->fieldCache[$cacheKey])) {
            return $this->fieldCache[$cacheKey];
        }

        if (! Schema::hasTable($tableName)) {
            return [];
        }

        $columns = Schema::getColumnListing($tableName);
        $fields = [];

        foreach ($columns as $column) {
            $fields[$column] = $this->generateFieldLabel($column);
        }

        $this->fieldCache[$cacheKey] = $fields;

        return $fields;
    }

    /**
     * Get fields for a specific model (backward compatible — accepts table key or FQCN).
     */
    public function getModelFields(string $modelKey): array
    {
        $cacheKey = "fields_{$modelKey}";

        if (isset($this->fieldCache[$cacheKey])) {
            return $this->fieldCache[$cacheKey];
        }

        // Resolve table name
        $tableName = $this->resolveTableName($modelKey);

        if (! $tableName) {
            return [];
        }

        $columns = Schema::getColumnListing($tableName);
        $fields = [];

        foreach ($columns as $column) {
            if (in_array($column, ['created_at', 'updated_at', 'deleted_at', 'password', 'remember_token'])) {
                continue;
            }

            $fields[$column] = $this->generateFieldLabel($column);
        }

        // Add computed/relationship fields
        $modelClass = $this->resolveModelClass($modelKey);
        if ($modelClass) {
            $relationshipFields = $this->getRelationshipFields($modelClass);
            $fields = array_merge($fields, $relationshipFields);
        }

        $this->fieldCache[$cacheKey] = $fields;

        return $fields;
    }

    /**
     * Resolve a model key (table name or FQCN) to a table name.
     */
    protected function resolveTableName(string $modelKey): ?string
    {
        // If it's a FQCN
        if (class_exists($modelKey) && is_subclass_of($modelKey, Model::class)) {
            return (new $modelKey)->getTable();
        }

        // Check available models by key
        $models = $this->getAvailableModels();
        if (isset($models[$modelKey])) {
            return $models[$modelKey]['table'];
        }

        return null;
    }

    /**
     * Resolve a model key to a FQCN.
     */
    protected function resolveModelClass(string $modelKey): ?string
    {
        if (class_exists($modelKey) && is_subclass_of($modelKey, Model::class)) {
            return $modelKey;
        }

        $models = $this->getAvailableModels();
        if (isset($models[$modelKey])) {
            return $models[$modelKey]['class'];
        }

        return null;
    }

    /**
     * Generate a human-readable label for a field/column name.
     */
    protected function generateFieldLabel(string $fieldName): string
    {
        $label = Str::title(str_replace(['_', '-'], ' ', $fieldName));

        $replacements = [
            'Id' => 'ID',
            'Ip' => 'IP',
            'Mac' => 'MAC',
            'Ntp' => 'NTP',
            'Api' => 'API',
            'Url' => 'URL',
            'Ot' => 'OT',
            'Hr' => 'HR',
        ];

        foreach ($replacements as $search => $replace) {
            $label = str_replace($search, $replace, $label);
        }

        return $label;
    }

    /**
     * Get relationship fields for a model (backward compatible).
     */
    protected function getRelationshipFields(string $modelClass): array
    {
        $fields = [];

        try {
            $reflection = new ReflectionClass($modelClass);
            $methods = $reflection->getMethods(ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                $methodName = $method->getName();

                if (Str::startsWith($methodName, ['get', 'set', 'is', 'has', 'scope']) ||
                    in_array($methodName, ['toArray', 'toJson', 'save', 'delete', 'update', 'create'])) {
                    continue;
                }

                if ($method->getNumberOfParameters() === 0) {
                    $label = $this->generateFieldLabel($methodName).' (Related)';
                    $fields[$methodName] = $label;
                }
            }
        } catch (Exception $e) {
            // Ignore errors in reflection
        }

        return $fields;
    }

    /**
     * Get field options for a specific model for Filament Select component (backward compatible).
     */
    public function getFieldOptionsForSelect(string $modelKey): array
    {
        return $this->getModelFields($modelKey);
    }

    /**
     * Clear caches.
     */
    public function clearCache(): void
    {
        $this->modelCache = [];
        $this->fieldCache = [];
        $this->relationCache = [];
    }
}

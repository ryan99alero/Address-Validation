# Pace API Module — Database Schema & Functions Reference

This is the complete schema and the model "functions" (methods) the integration relies on.
Four tables drive Pace sync, plus one shared logging table.

```
integration_connections   (1) ─┬─< integration_objects        (the "what to sync" definitions)
                                │        └─< integration_field_mappings   (field-level mapping rules)
                                └─< integration_query_templates  (optional saved queries)
system_logs                (shared polymorphic operation log — written via the SystemLog model)
```

> **Logging note:** the code logs to **`system_logs`** (via the `SystemLog` model, default table name).
> The old `integration_sync_logs` table in earlier copies is **legacy and unused** — it has been removed
> from this package. Use `2026_02_10_150000_create_system_logs_table.php`.

---

## Table 1 — `integration_connections`
One row per Pace endpoint. Holds connection config + **encrypted** credentials.
Migrations: `…100000_create…` + `…100000_add_webhook_token…` + `…100000_add_sync_scheduling…`

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | bigint PK | no | — | |
| `name` | varchar(100) | no | — | Human label |
| `driver` | varchar(50) | no | — | **Must be `pace`** for `PaceApiClient` |
| `base_url` | varchar(255) | no | — | Pace REST base URL |
| `api_version` | varchar(20) | yes | null | |
| `auth_type` | varchar(50) | no | `basic` | `basic` \| `bearer` \| `api_key` |
| `auth_credentials` | text | yes | null | **Encrypted JSON** (Laravel `Crypt`) |
| `timeout_seconds` | int | no | 30 | |
| `retry_attempts` | int | no | 3 | |
| `rate_limit_per_minute` | int | yes | null | |
| `sync_interval_minutes` | uint | no | 0 | 0 = push/manual only; >0 = polled |
| `last_synced_at` | timestamp | yes | null | |
| `webhook_token` | varchar(64) unique | yes | null | For the webhook trigger route |
| `is_active` | bool | no | true | |
| `last_connected_at` | timestamp | yes | null | |
| `last_error_at` | timestamp | yes | null | |
| `last_error_message` | text | yes | null | |
| `created_by` / `updated_by` | bigint FK→users | yes | null | |
| `created_at` / `updated_at` | timestamps | — | — | |

*(ADP/payroll-only columns — `is_payroll_provider`, `export_formats`, `export_destination`, `export_path`,
`export_filename_pattern`, `integration_method`, `adp_company_code`, `adp_batch_format` — are added by the
migrations in `_optional_adp_payroll/`. Skip them for a Pace-only install, or trim the model's `$fillable`.)*

---

## Table 2 — `integration_objects`
One row per Pace object you sync (e.g. `Employee`, `Customer`). Maps the object to a local model/table.
Migrations: `…100001_create…` + `…add_default_filter…` + `…add_api_method…`

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | bigint PK | no | — | |
| `connection_id` | bigint FK→integration_connections | no | — | cascade delete |
| `object_name` | varchar(100) | no | — | Pace object, e.g. `Employee` |
| `display_name` | varchar(100) | no | — | |
| `description` | text | yes | null | |
| `primary_key_field` | varchar(100) | no | `@id` | XPath to PK |
| `primary_key_type` | varchar(50) | no | `Integer` | |
| `available_fields` | json | yes | null | Cached from field discovery |
| `available_children` | json | yes | null | |
| `default_filter` | text | yes | null | Default XPath filter, e.g. `@status = 'A'` |
| `local_model` | varchar(100) | yes | null | FQCN, e.g. `App\Models\Employee` |
| `local_table` | varchar(100) | yes | null | Primary local table |
| `sync_enabled` | bool | no | false | |
| `sync_direction` | varchar(20) | no | `pull` | `pull` \| `push` \| `bidirectional` |
| `api_method` | varchar(50) | no | `loadValueObjects` | engine supports `loadValueObjects`/`findObjects` for pull |
| `sync_frequency` | varchar(50) | yes | null | |
| `last_synced_at` | timestamp | yes | null | |
| `created_at` / `updated_at` | timestamps | — | — | |

Unique: `(connection_id, object_name)`.

---

## Table 3 — `integration_field_mappings`
One row per field. The engine builds the API request and the upsert from these.
Migrations: `…100003_create…` + `…add_local_table…`

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | bigint PK | no | — | |
| `object_id` | bigint FK→integration_objects | no | — | cascade delete |
| `local_table` | varchar(100) | yes | null | Override target table (related-table sync); null = object's primary table |
| `external_field` | varchar(100) | no | — | Logical field name (key in parsed record) |
| `external_xpath` | varchar(255) | no | — | Pace XPath selector, e.g. `@firstName`, `/country/@isoCountry` |
| `external_type` | varchar(50) | no | — | `String` \| `Integer` \| `Date` … |
| `local_field` | varchar(100) | no | — | Local DB column |
| `local_type` | varchar(50) | no | — | `string` \| `integer` \| `datetime` … |
| `transform` | varchar(50) | yes | null | See transform list below |
| `transform_options` | json | yes | null | Args for `value_map` / `fk_lookup` |
| `sync_on_pull` | bool | no | true | Include when pulling from Pace |
| `sync_on_push` | bool | no | false | Include when pushing to Pace |
| `is_identifier` | bool | no | false | **Used to match existing rows for upsert** (≥1 required) |
| `created_at` / `updated_at` | timestamps | — | — | |

Unique: `(object_id, external_field)`.

**Transforms** (`transform` column): `date_ms_to_carbon`, `date_iso_to_carbon`, `cents_to_dollars`,
`string_to_int`, `string_to_float`, `string_to_bool`, `json_decode`, `trim`, `uppercase`, `lowercase`,
`value_map` (`transform_options.map` + optional `.default`), `fk_lookup`
(`transform_options.{model, match_column, return_column}`). Null = pass-through.

---

## Table 4 — `integration_query_templates` *(optional)*
Saved, reusable `loadValueObjects` payloads. Only needed if you use `PaceApiClient::loadFromTemplate()`.
Migration: `…100002_create…`

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | bigint PK | no | — | |
| `connection_id` | bigint FK→integration_connections | no | — | cascade delete |
| `object_id` | bigint FK→integration_objects | yes | null | set null on delete |
| `name` | varchar(100) | no | — | |
| `description` | text | yes | null | |
| `object_name` | varchar(100) | no | — | Root object |
| `fields` | json | no | — | Array of `{name, xpath}` |
| `children` | json | yes | null | Child object queries |
| `filter` | json | yes | null | XPath filter |
| `sort` | json | yes | null | XPath sort |
| `default_limit` | int | no | 100 | |
| `max_limit` | int | no | 1000 | |
| `usage_count` | int | no | 0 | |
| `last_used_at` | timestamp | yes | null | |
| `created_by` | bigint FK→users | yes | null | |
| `created_at` / `updated_at` | timestamps | — | — | |

---

## Table 5 — `system_logs` (shared logging)
Polymorphic operation log. The engine/command/controller write here via the `SystemLog` model.
Migration: `2026_02_10_150000_create_system_logs_table.php`

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| `id` | bigint PK | no | — | |
| `category` | varchar(50) | no | — | `integration` for syncs |
| `type` | varchar(100) | no | — | `sync`, `request`, `event`, … |
| `level` | varchar(20) | no | `info` | debug/info/warning/error/critical |
| `loggable_type` / `loggable_id` | nullableMorphs | yes | null | Points at the connection/object |
| `status` | varchar(50) | yes | null | pending/running/success/failed/partial |
| `summary` | varchar(500) | no | — | |
| `description` | text | yes | null | |
| `started_at` / `completed_at` | timestamp | yes | null | |
| `duration_ms` | int | yes | null | |
| `counts` | json | yes | null | `{fetched, created, updated, skipped, failed}` |
| `request_data` / `response_data` | json | yes | null | |
| `error_message` | text | yes | null | |
| `error_details` | json | yes | null | |
| `metadata` | json | yes | null | object_id, connection_name, etc. |
| `tags` | json | yes | null | |
| `user_id` | bigint FK→users | yes | null | |
| `ip_address` | varchar(45) | yes | null | |
| `user_agent` | varchar(500) | yes | null | |
| `created_at` / `updated_at` | timestamps | — | — | |

> If your target app already has logging, you don't need this table — instead stub the `SystemLog`
> methods listed below as no-ops and skip this migration.

---

## Functions (model & service methods you must keep)

### `PaceApiClient` (the API surface)
- `__construct(IntegrationConnection $connection)` — throws unless `driver === 'pace'`.
- `static fromConnection(int $id): self`
- `testConnection(): array` — `GET Version/getVersion`; updates connected/error timestamps.
- `loadValueObjects(objectName, fields, children, primaryKey, xpathFilter, xpathSorts, offset, limit): array`
- `loadAllValueObjects(objectName, fields, children, xpathFilter): Collection` — probe count, fetch all.
- `loadFromTemplate(IntegrationQueryTemplate, offset, additionalFilter): array`
- `parseValueObject(array): array` — flatten + ms-date→Carbon + nested `_children`.
- `parseValueObjects(array): Collection`
- `getCommonObjectTypes(): array` — parsed from `docs/Pace RestFul/swagger.json`.
- *(protected)* `buildClient()` / `post()` / `get()` — auth, retries, error→`markError`.

### `IntegrationConnection`
- `getCredentials(): array` / `setCredentials(array)` / `getCredential(key, default)` — Crypt encrypt/decrypt.
- `markConnected()` / `markError(msg)` / `hasRecentErrors(mins)`
- `isPollingEnabled()` / `isDueForSync()` / `markSynced()` / `isPushMode()`
- `generateWebhookToken()` / `getOrCreateWebhookToken()` / `getWebhookUrl(objectName)`
- Relationships: `objects()`, `queryTemplates()`, `syncLogs()`; scopes `pollingEnabled()`, `active()`, `byDriver()`.

### `IntegrationObject`
- `getLocalModelClass(): ?string`, `getFieldNames()`, `getChildNames()`
- Relationships `connection()`, `fieldMappings()`, `queryTemplates()`; scopes `syncEnabled()`, `byDirection()`.

### `IntegrationFieldMapping`
- `transformToLocal($value)` / `transformToExternal($value)` — the transform dispatcher.
- `getEffectiveTable(): ?string`; scopes `identifiers()`, `pullEnabled()`, `pushEnabled()`.

### `IntegrationSyncEngine`
- `sync(IntegrationObject, ?filterOverride, ?enrichCallback): SyncResult` — the main pull/transform/upsert.
- `discoverObject(IntegrationObject): array` — limit-1 probe to validate fields.

### `SystemLog` (logging — stub these if you don't port the table)
- `static startIntegrationSync(connection, operation, object, requestData): self`
- `markSuccess(counts, responseData)` / `markFailed(msg, details)` / `markPartial(msg, counts, details)`
- `static logEvent(type, summary, level, metadata, context): self`

### `ModelDiscoveryService` (only for related-table sync / Filament UI)
- `getRelatedTables(modelClass)`, `getTableColumns(table)`, `getModelOptionsForSelect()`, etc.

---

## Minimal vs full footprint

| Goal | Tables needed | Models/Services needed |
|------|---------------|------------------------|
| **Call Pace from code only** | `integration_connections` | `PaceApiClient`, `IntegrationConnection` |
| **+ operation logging** | + `system_logs` | + `SystemLog` (or stub it) |
| **Config-driven sync** | + `integration_objects`, `integration_field_mappings` | + `IntegrationSyncEngine`, `SyncResult`, `IntegrationObject`, `IntegrationFieldMapping` |
| **Saved query templates** | + `integration_query_templates` | + `IntegrationQueryTemplate` |
| **Related-table sync / Filament admin** | (same) | + `ModelDiscoveryService` (+ Filament resource) |

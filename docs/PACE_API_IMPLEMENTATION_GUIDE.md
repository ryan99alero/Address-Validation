# Pace ERP API Module — Implementation Guide

This package contains the complete, working Pace / ePace ERP REST API integration extracted from the
**Attend** time-and-attendance application. It is designed to be lifted into another Laravel project
(your **Address Validation** solution) so you don't have to rebuild the Pace connectivity from scratch.

**Scope:** Pace API only. ADP/QuickBooks/flat-file payroll export pieces have been deliberately
excluded, except where they share the same `integration_connections` table (those columns live in
`database/migrations/_optional_adp_payroll/` and are optional — see step 3).

---

## 1. What this module does

Pace (ePace MIS, the EFI print-shop ERP) exposes a generic REST endpoint:

```
POST {base_url}/FindObjects/loadValueObjects
```

You tell it an `objectName` (e.g. `Employee`, `Customer`, `Job`), a list of `fields` with **XPath
selectors**, an optional `xpathFilter`, sorting and pagination. Pace returns "value objects" — an array
of records where each field is `{name, type, value}`. Dates come back as **millisecond epoch
timestamps**. There is one connectivity smoke-test endpoint: `GET {base_url}/Version/getVersion`.

This module wraps all of that behind:

- **`PaceApiClient`** — the HTTP client. Auth, retries, the `loadValueObjects` call, paging, and parsing
  value objects into plain associative arrays (incl. ms-timestamp → Carbon conversion).
- **A config-driven sync engine** — define *what* to pull and *where* it lands in your DB as data rows
  (connections → objects → field mappings), and `IntegrationSyncEngine` does the pull/transform/upsert.
- **A hand-written reference command** — `pace:sync-employees` shows the same thing done imperatively, in
  case you want a concrete worked example rather than the generic engine.

You can use **just the client** (lightweight) or the **full engine** (configurable, no code per object).

---

## 2. File inventory

### Required — core client (the minimum to talk to Pace)
| File | Purpose |
|------|---------|
| `app/Services/Integrations/PaceApiClient.php` | The Pace REST client. Auth, `testConnection()`, `loadValueObjects()`, `loadAllValueObjects()`, `parseValueObject()`. |
| `app/Models/IntegrationConnection.php` | Stores connection config + **encrypted** credentials. The client is constructed from one of these. |

### Required — config-driven sync engine (only if you want mapping-table-driven sync)
| File | Purpose |
|------|---------|
| `app/Services/Integrations/IntegrationSyncEngine.php` | Pull → transform → upsert orchestrator. |
| `app/Services/Integrations/SyncResult.php` | Tally object (created/updated/skipped/failed + messages). |
| `app/Models/IntegrationObject.php` | One per Pace object you sync (e.g. `Employee`). Holds the local model/table mapping. |
| `app/Models/IntegrationFieldMapping.php` | Maps a Pace field/XPath → a local column, with a `transform`. |
| `app/Models/IntegrationQueryTemplate.php` | Optional: saved, reusable `loadValueObjects` payloads. |
| `app/Services/ModelDiscoveryService.php` | Reflection helper used by the engine to resolve related tables/relationships. Also powers the Filament UI dropdowns. |

### Required — logging (the engine & command write sync logs through this)
| File | Purpose |
|------|---------|
| `app/Models/SystemLog.php` | Polymorphic operation log (`startIntegrationSync()`, `markSuccess/Failed/Partial`). Used widely; if you already have logging you can stub these methods instead. |

### Optional — triggers / entry points
| File | Purpose |
|------|---------|
| `app/Console/Commands/PaceSyncEmployees.php` | **Reference implementation.** Full worked example of syncing Pace Employees both ways (engine + legacy). Read this first to learn the API. |
| `app/Console/Commands/RunScheduledIntegrationSyncs.php` | Cron entry: finds due connections and dispatches their sync command. |
| `app/Http/Controllers/Api/WebhookSyncController.php` | Token-auth webhook so Pace (or anything) can POST to trigger a sync. |

### Optional — admin UI (Filament v4)
| File | Purpose |
|------|---------|
| `app/Filament/Resources/IntegrationConnectionResource.php` (+ `Pages/`, `RelationManagers/`) | Admin screens to create connections, test them, discover fields, and manage object/field mappings without code. **Only works if the target app also runs Filament v4.** |

### Database
| File | Purpose |
|------|---------|
| `database/migrations/2026_02_03_100000_create_integration_connections_table.php` | The connections table. |
| `…_100001_create_integration_objects_table.php` | Objects table. |
| `…_100002_create_integration_query_templates_table.php` | Query templates table. |
| `…_100003_create_integration_field_mappings_table.php` | Field mappings table. |
| `…_100004_create_integration_sync_logs_table.php` | Creates the `system_logs` table used by `SystemLog`. |
| `…_100000…sync_scheduling…`, `…default_filter…`, `…webhook_token…`, `…local_table…`, `…api_method…` | Incremental columns the model code relies on. **Apply all of these.** |
| `…_add_pace_employee_fields_to_employees_table.php` | Adds Pace-specific columns to *Attend's* `employees` table — **example only**, not needed unless you mirror Attend's employee schema. |
| `database/migrations/_optional_adp_payroll/*` | ADP/payroll columns on the connections table. Only needed because Attend's `IntegrationConnection` model lists them in `$fillable` — see step 3. |

### Reference docs
| File | Purpose |
|------|---------|
| `docs/INTEGRATIONS.md` | The original, detailed integration docs (XPath selectors, transforms, object types, troubleshooting). **Read this.** |
| `docs/Pace RestFul/swagger.json` | Full Pace API Swagger (3.9 MB). `PaceApiClient::getCommonObjectTypes()` parses this to list available objects — keep it at `docs/Pace RestFul/swagger.json` in the target app, or the client falls back to a hard-coded list. |
| `docs/Pace RestFul/Pace API Guide.htm` | Pace's own API HTML guide. |
| `docs/Pace RestFul/field_mapping_pace_to_attend.csv` | Worked Pace→local field-mapping reference. |

---

## 3. Installation into the target (Address Validation) app

Prereqs: Laravel 11/12 (uses the streamlined structure), PHP 8.2+. The core client needs **no extra
composer packages** — only `illuminate/http` and `illuminate/support`, which ship with Laravel.

1. **Copy files.** Drop the `app/`, `database/migrations/`, and `docs/` folders into the target project,
   preserving paths. Namespaces are all `App\…`, so they resolve as-is in a stock Laravel app. If your app
   uses a non-default namespace, rewrite the `namespace`/`use` lines accordingly.

2. **Trim the `IntegrationConnection` model (recommended).** It ships with ADP/payroll fields in
   `$fillable`/`$casts` (`is_payroll_provider`, `export_formats`, `adp_company_code`, `integration_method`,
   …) and a `payrollExports()`/`employees()` relationship. For a Pace-only install either:
   - **(a)** delete those `$fillable`/`$casts` entries and the payroll relationships/helpers, **or**
   - **(b)** also run the three migrations in `database/migrations/_optional_adp_payroll/` so the columns
     exist. Pick (a) for a clean Pace-only module.
   Also remove the `employees()`/`payrollExports()` relationships unless you port those models.

3. **Decide how much you need.**
   - *Just call Pace from code* → keep only `PaceApiClient` + `IntegrationConnection` + the connections
     migration. Skip the engine, the other models, commands, controller, and Filament.
   - *Full config-driven sync* → keep everything in "Required" above.

4. **Run migrations.** `php artisan migrate`. (The `system_logs` migration is required; if your app
   already has an equivalent log, you can instead stub the `SystemLog::*` calls.)

5. **Webhook route (optional).** If you keep `WebhookSyncController`, add to `routes/api.php`:
   ```php
   use App\Http\Controllers\Api\WebhookSyncController;

   Route::post('/webhooks/sync/{token}/{object?}', [WebhookSyncController::class, 'trigger'])
       ->name('webhooks.sync');
   ```

6. **Scheduler (optional).** If you keep `RunScheduledIntegrationSyncs`, register it in
   `routes/console.php`:
   ```php
   Schedule::command('integration:run-scheduled-syncs')->everyFiveMinutes();
   ```
   Note its `match` only knows the `pace` driver → `pace:sync-employees`. Add your own object's command
   there. For Address Validation you'll likely write your own sync command (see step 8).

---

## 4. Creating a Pace connection

No `.env` keys are needed — connection config lives in the `integration_connections` row, with
credentials **encrypted** via Laravel `Crypt` (so `APP_KEY` must be set, as in any Laravel app).

Create one in Tinker (or via the Filament UI if you ported it):

```php
$conn = new \App\Models\IntegrationConnection();
$conn->name = 'Pace Production';
$conn->driver = 'pace';                 // REQUIRED — PaceApiClient rejects anything else
$conn->base_url = 'https://your-pace-host/rpc/rest/services'; // your Pace REST base URL
$conn->auth_type = 'basic';             // 'basic' | 'bearer' | 'api_key'
$conn->timeout_seconds = 30;
$conn->retry_attempts = 3;
$conn->is_active = true;
$conn->setCredentials([                 // helper encrypts + json-encodes
    'username' => 'apiuser',
    'password' => 'secret',
]);
$conn->save();
```

Credential keys per `auth_type`:
- **basic** → `username`, `password`
- **bearer** → `bearer_token`
- **api_key** → `api_key`, plus `api_key_location` (`header`|`query`), `api_key_name` (defaults
  `Authorization` for header, `api_key` for query)

---

## 5. Using the client directly (the simplest path)

```php
use App\Models\IntegrationConnection;
use App\Services\Integrations\PaceApiClient;

$conn   = IntegrationConnection::where('driver', 'pace')->where('is_active', true)->firstOrFail();
$client = new PaceApiClient($conn);

// 1. Smoke test
$result = $client->testConnection();   // ['success' => true, 'version' => '...']

// 2. Pull records. Fields are name + XPath selector (attributes start with @).
$records = $client->loadAllValueObjects(
    objectName: 'Customer',
    fields: [
        ['name' => 'external_id', 'xpath' => '@id'],
        ['name' => 'name',        'xpath' => '@name'],
        ['name' => 'address1',    'xpath' => '@address1'],
        ['name' => 'city',        'xpath' => '@city'],
        ['name' => 'state',       'xpath' => '@state'],
        ['name' => 'zip',         'xpath' => '@zip'],
        // nested objects use a path: '/country/@isoCountry'
    ],
    xpathFilter: "@state = 'TX'",   // optional ePace XPath filter
);

foreach ($records as $vo) {
    $row = $client->parseValueObject($vo); // ['external_id'=>.., 'address1'=>.., '_primaryKey'=>..]
    // ... validate the address ...
}
```

Key client methods:
- `testConnection(): array` — hits `Version/getVersion`, updates `last_connected_at`/`last_error_*`.
- `loadValueObjects(objectName, fields, children, primaryKey, xpathFilter, xpathSorts, offset, limit)` —
  one raw call; returns the decoded JSON (`['valueObjects'=>[], 'totalRecords'=>N]`).
- `loadAllValueObjects(objectName, fields, children, xpathFilter)` — probes `totalRecords`, then pulls
  them all in one call. Returns a `Collection` of raw value objects.
- `parseValueObject(array): array` — flattens one value object to `name => value`, converts `Date` fields
  (ms timestamps) to `Carbon`, and nests `_children`.
- `getCommonObjectTypes(): array` — object list parsed from `docs/Pace RestFul/swagger.json`.

> **XPath note:** `@field` = an attribute on the current object; `/relation/@field` = an attribute on a
> related object. The full list of available fields per object is in `swagger.json` and the Pace API Guide.

---

## 6. Using the config-driven engine (no code per object)

Define the mapping as **data**, then call the engine. One-time setup per object:

```php
use App\Models\IntegrationObject;
use App\Models\IntegrationFieldMapping;

$object = IntegrationObject::create([
    'connection_id' => $conn->id,
    'object_name'   => 'Customer',          // Pace object
    'display_name'  => 'Customers',
    'local_model'   => \App\Models\Address::class, // your local model
    'local_table'   => 'addresses',
    'sync_enabled'  => true,
    'sync_direction'=> 'pull',
    'default_filter'=> "@state = 'TX'",      // optional
    'api_method'    => 'loadValueObjects',
]);

// One row per field. is_identifier = the column used to match existing rows for upsert.
IntegrationFieldMapping::create([
    'object_id' => $object->id, 'external_field' => 'external_id', 'external_xpath' => '@id',
    'external_type' => 'Integer', 'local_field' => 'external_id', 'local_type' => 'integer',
    'is_identifier' => true, 'sync_on_pull' => true,
]);
IntegrationFieldMapping::create([
    'object_id' => $object->id, 'external_field' => 'address1', 'external_xpath' => '@address1',
    'external_type' => 'String', 'local_field' => 'address_line_1', 'local_type' => 'string',
    'sync_on_pull' => true,
]);
// ... more fields ...
```

Then run a sync:

```php
use App\Services\Integrations\PaceApiClient;
use App\Services\Integrations\IntegrationSyncEngine;

$engine = new IntegrationSyncEngine(new PaceApiClient($conn));
$result = $engine->sync($object); // SyncResult: ->created ->updated ->skipped ->errors ->errorMessages

// Optional enrich callback to compute/override attributes before upsert:
$result = $engine->sync($object, enrichCallback: function (array &$attrs, array $parsed) {
    $attrs['validated_at'] = null; // e.g. mark for re-validation
});
```

What the engine does per record: builds the API `fields` from the mappings → `loadAllValueObjects` →
`parseValueObject` → applies each mapping's `transform` → matches existing rows by the `is_identifier`
column(s) → `update()` if changed, else `create()` → writes a `SystemLog` entry with the tallies.

### Available transforms (`IntegrationFieldMapping::transform`)
`date_ms_to_carbon`, `date_iso_to_carbon`, `cents_to_dollars`, `string_to_int`, `string_to_float`,
`string_to_bool`, `json_decode`, `trim`, `uppercase`, `lowercase`, `value_map` (needs
`transform_options.map`), `fk_lookup` (needs `transform_options.{model,match_column,return_column}` — the
engine pre-caches these to avoid N+1). No `transform` = pass-through.

---

## 7. Data model (tables)

```
integration_connections      one per Pace endpoint (driver, base_url, auth_type, encrypted creds, schedule, webhook_token)
  └─ integration_objects      one per Pace object synced (object_name, local_model, local_table, default_filter, api_method)
       ├─ integration_field_mappings   external_field/xpath → local_field, transform, is_identifier, sync_on_pull/push
       └─ integration_query_templates  (optional) saved loadValueObjects payloads
system_logs                  polymorphic operation log (category='integration')
```

Connections `$hidden` both `auth_credentials` and `webhook_token` so they never leak through JSON.

---

## 8. Recommended path for Address Validation

You probably want to **pull addressable records from Pace, validate, then optionally push results back**.
Since this module's push side is minimal (the engine only *pulls*; `IntegrationFieldMapping` has
`transformToExternal()` and `sync_on_push` flags but no push executor here), the cleanest approach:

1. Port **`PaceApiClient` + `IntegrationConnection`** + the connections & `system_logs` migrations.
2. Use the client's `loadAllValueObjects()` to pull `Customer` (or `ShipTo`/`Contact`) address fields.
3. Run your address-validation logic on each `parseValueObject()` row.
4. To **write back** to Pace, add a small method on the client that POSTs to Pace's
   `UpdateObject`/`createObject` endpoints (see `swagger.json`) — this module doesn't ship a writer, but
   `buildClient()`/`post()` give you the authenticated request plumbing to do it in a few lines.
5. Model your own `address:validate` artisan command on **`PaceSyncEmployees.php`** — it's the best
   end-to-end worked example (probe count → fetch all → parse → upsert → log → summary table).

---

## 9. Gotchas / notes

- **Driver must be `'pace'`** — `PaceApiClient`'s constructor throws otherwise.
- **`swagger.json` path** — `getCommonObjectTypes()` looks at `base_path('docs/Pace RestFul/swagger.json')`.
  Keep it there or expect the hard-coded fallback object list.
- **Dates** — Pace sends ms-epoch; `parseValueObject()` already converts `Date`-typed fields to `Carbon`.
  The engine then casts `Carbon` → `Y-m-d` string on write.
- **No real pagination loop** — `loadAllValueObjects()` probes `totalRecords` then requests that many in
  one call. Fine for employees/customers; for very large objects add offset paging.
- **`SystemLog`** is referenced by the engine, command, and controller. If you don't port it, stub
  `startIntegrationSync()`, `markSuccess()`, `markFailed()`, `markPartial()`, `logEvent()` as no-ops.
- **`ModelDiscoveryService`** is only needed if you use the engine's *related-table* sync or the Filament
  UI. For straight single-table pulls it isn't exercised.
- **Filament UI** requires Filament v4 in the target app; otherwise drop the `app/Filament/` folder.
- **No external composer deps** for the core client — it's all first-party Laravel HTTP/Support.

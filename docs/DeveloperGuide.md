# Address Validation Application - Developer Guide

This document provides comprehensive guidance for developers and AI agents working on the Address Validation application.

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [Core Models](#core-models)
3. [Services](#services)
4. [Filament Pages & Resources](#filament-pages--resources)
5. [Carrier Integration](#carrier-integration)
6. [Import/Export Workflow](#importexport-workflow)
7. [Field Mapping System](#field-mapping-system)
8. [Background Job Processing](#background-job-processing)
9. [Common Patterns](#common-patterns)
10. [Troubleshooting](#troubleshooting)

---

## Architecture Overview

### Technology Stack

| Component | Technology | Version |
|-----------|------------|---------|
| Framework | Laravel | 12.x |
| Admin Panel | Filament | 5.x |
| Reactive UI | Livewire | 4.x |
| CSS | Tailwind CSS | 4.x |
| Testing | Pest | 4.x |
| Excel | Maatwebsite Excel | 3.x |

### Application Structure

```
app/
├── Filament/
│   ├── Pages/              # Custom pages (ValidateAddress, BatchImport, BatchExport)
│   └── Resources/          # CRUD resources (Addresses, Carriers, etc.)
├── Jobs/
│   └── ProcessImportBatchValidation.php  # Background validation job
├── Models/                 # Eloquent models
├── Providers/              # Service providers
│   └── Filament/           # Filament panel providers
└── Services/               # Business logic services
    ├── AddressValidationService.php
    ├── ExportService.php
    ├── ImportService.php
    └── Carriers/           # Carrier-specific implementations
        ├── UpsAddressValidationService.php
        └── FedExAddressValidationService.php
```

### Database Schema

Core tables and their relationships:

```
addresses
    ├── id
    ├── external_reference
    ├── name (recipient)
    ├── company
    ├── address_line_1, address_line_2
    ├── city, state, postal_code, country_code
    ├── source (manual/import/api)
    ├── import_batch_id (FK)
    ├── extra_1 through extra_20 (pass-through fields)
    └── created_by (FK → users)

address_corrections
    ├── id
    ├── address_id (FK)
    ├── carrier_id (FK)
    ├── validation_status (valid/invalid/ambiguous)
    ├── corrected_address_line_1, corrected_address_line_2
    ├── corrected_city, corrected_state
    ├── corrected_postal_code, corrected_postal_code_ext
    ├── is_residential, classification
    ├── confidence_score
    ├── raw_response (JSON - full API response)
    └── validated_at

carriers
    ├── id
    ├── name, slug
    ├── is_active
    ├── environment (sandbox/production)
    ├── sandbox_url, production_url
    └── credentials (encrypted JSON)

import_batches
    ├── id
    ├── name (custom import name)
    ├── original_filename
    ├── status (pending/mapping/processing/completed/failed)
    ├── total_rows, processed_rows, successful_rows, failed_rows
    ├── validated_rows
    ├── field_mappings (JSON)
    ├── carrier_id (FK)
    └── mapping_template_id (FK)

import_field_templates
    ├── id
    ├── name
    ├── field_mappings (JSON)
    └── is_default

export_templates
    ├── id
    ├── name
    ├── target_system (epace/ups_worldship/fedex_ship/generic)
    ├── field_layout (JSON)
    ├── file_format (csv/xlsx/fixed_width)
    └── include_header
```

---

## Core Models

### Address

The `Address` model represents an original submitted address.

**Key Methods:**
- `isValidated()` - Check if address has any corrections
- `getLatestCorrectionAttribute()` - Get most recent correction
- `getFormattedAddressAttribute()` - Full address as string

**Relationships:**
- `importBatch()` - BelongsTo ImportBatch
- `corrections()` - HasMany AddressCorrection
- `latestCorrection()` - HasOne (latest) AddressCorrection

**Extra Fields:**
The model has 20 "extra" fields (`extra_1` through `extra_20`) for pass-through custom data during import/export.

### AddressCorrection

Stores validation results from carrier APIs.

**Key Methods:**
- `isValid()`, `isInvalid()`, `isAmbiguous()` - Status checks
- `hasAddressChanges()` - Check if correction differs from original (NOT `hasChanges()`)
- `getFullPostalCode()` - Returns ZIP+4 formatted string
- `getAllCandidates()` - Parse all candidates from raw API response

**Raw Response Parsing:**
The `raw_response` JSON contains the full carrier API response. Use carrier-specific parsers:
- `parseSmartysCandidates()` - For Smarty API
- `parseUpsCandidates()` - For UPS API
- `parseFedExCandidates()` - For FedEx API

### ImportBatch

Tracks batch import progress.

**Status Constants:**
```php
STATUS_PENDING = 'pending'
STATUS_MAPPING = 'mapping'
STATUS_PROCESSING = 'processing'
STATUS_COMPLETED = 'completed'
STATUS_FAILED = 'failed'
```

**Key Attributes:**
- `display_name` - Returns `name` or `original_filename`
- `validation_progress` - Percentage of addresses validated

### Carrier

Stores carrier API configuration with encrypted credentials.

**Important Methods:**
```php
// Get decrypted credentials
$credentials = $carrier->getCredentials();
// Returns: ['client_id' => '...', 'client_secret' => '...']

// Set encrypted credentials
$carrier->setCredentials([
    'client_id' => 'xxx',
    'client_secret' => 'xxx',
]);

// Get active URL based on environment
$url = $carrier->active_url;
```

**Environment URLs:**
- UPS Sandbox: `https://wwwcie.ups.com`
- UPS Production: `https://onlinetools.ups.com`
- FedEx Sandbox: `https://apis-sandbox.fedex.com`
- FedEx Production: `https://apis.fedex.com`

---

## Services

### AddressValidationService

Orchestrates validation across carriers.

```php
$service = app(AddressValidationService::class);
$correction = $service->validateAddress($address, 'ups');
```

### ImportService

Handles Excel/CSV parsing and field mapping.

**Key Methods:**
```php
$service = app(ImportService::class);

// Parse headers from file
$headers = $service->parseHeaders($uploadedFile);

// Auto-match headers to system fields
$mappings = $service->autoMatchHeaders($headers);

// Get preview rows
$rows = $service->getPreviewRows($file, 3);

// Create addresses from file
$addresses = $service->createAddressesFromFile($file, $batch, $mappings);

// Get system fields
$fields = $service->getSystemFields();
```

**Field Mapping Rules:**
- `name` (person/contact) matches: "contact", "attention", "attn", fields containing "contact"
- `company` matches: "name", "ship to name", "recipient", "company", "business"
- `address_line_1` matches: "address", "addr1", "street"
- `postal_code` matches: "zip", "postal", "zipcode"

### ExportService

Handles exporting addresses using templates.

```php
$service = app(ExportService::class);

// Export a batch
return $service->exportBatch($batch, $template, 'custom_filename');

// Export a collection of addresses
return $service->export($addresses, $template);

// Get field value for an address
$value = $service->getFieldValue($address, 'corrected_address_line_1');
```

---

## Filament Pages & Resources

### Custom Pages

Located in `app/Filament/Pages/`:

1. **ValidateAddress** (`/validate-address`)
   - Single address validation form
   - Carrier selection
   - Real-time validation results
   - Candidates modal for multiple matches

2. **BatchProcessing** (`/batch-processing`)
   - Combined Import/Export with tab navigation (like vacation-management)
   - **Import Tab:**
     - Step 1: File upload with import name (defaults to filename)
     - Step 2: Field mapping with templates (save/load)
     - Step 3: Processing with validation progress bar
     - Auto-validates after import (configurable)
   - **Export Tab:**
     - Select completed batch
     - Choose export template
     - Filter by validation status
     - View batch statistics
     - Download export file

### Resources

Located in `app/Filament/Resources/`:

- **AddressResource** - View/manage addresses with filters
- **ImportBatchResource** - Manage import batches
- **CarrierResource** - Configure carrier APIs
- **ImportFieldTemplateResource** - Manage field mapping templates
- **ExportTemplateResource** - Manage export templates
- **UserResource** - User management

### Address Filters

The AddressResource includes these filters:

| Filter | Options |
|--------|---------|
| Validation Status | Pending, Valid, Invalid, Ambiguous |
| Confidence Score | 90%+, 80%+, 70%+, 50%+, Below 50%, Below 40%, Below 30% |
| Address Type | Residential, Commercial, Mixed, Unknown |
| DPV Status | Confirmed (Y), Secondary Missing (S), Not Confirmed (N), Any Valid |
| Carrier | Dynamic from carriers table |
| Source | Import, Manual, API |
| Import Batch | Dynamic from import_batches table |

---

## Carrier Integration

### UPS Address Validation

**Service:** `UpsAddressValidationService`

**OAuth2 Flow:**
1. Get token from `/security/v1/oauth/token`
2. Use token in `Authorization: Bearer {token}` header
3. Call `/api/addressvalidation/{version}/1`

**Response Parsing:**
```php
$response['XAVResponse']['Candidate'][0]['AddressKeyFormat']
// Contains: AddressLine, PoliticalDivision1, PoliticalDivision2, PostcodePrimaryLow
```

### FedEx Address Validation

**Service:** `FedExAddressValidationService`

**OAuth2 Flow:**
1. Get token from `/oauth/token`
2. Use token in `Authorization: Bearer {token}` header
3. Call `/address/v1/addresses/resolve`

**Response Parsing:**
```php
$response['output']['resolvedAddresses'][0]
// Contains: streetLinesToken, city, stateOrProvinceCode, postalCode, attributes
```

**Important FedEx Fields:**
- `attributes.DPV` - "true" if DPV confirmed
- `attributes.Matched` - "true" if address matched
- `classification` - RESIDENTIAL, BUSINESS, MIXED

### Adding New Carriers

1. Create service in `app/Services/Carriers/`
2. Implement validation logic
3. Add slug to Carrier model seeder
4. Add URL configuration to carrier record

---

## Import/Export Workflow

### Import Flow

```
1. User uploads file → BatchImport page
2. ImportService parses headers
3. Auto-match columns to system fields
4. User adjusts mappings (optional)
5. User can save mapping as template
6. ImportService creates Address records
7. If auto_validate enabled:
   → ProcessImportBatchValidation job dispatched
   → Job validates addresses in chunks of 50
   → Updates validated_rows progress
```

### Export Flow

```
1. User selects batch → BatchExport page
2. User selects export template
3. User filters by validation status
4. ExportService generates file:
   → Gets addresses with latestCorrection
   → Maps fields using template layout
   → Downloads as CSV/XLSX
```

### Field Mapping JSON Structure

```json
[
  {
    "position": 0,
    "source": "Ship To Name",
    "target": "company"
  },
  {
    "position": 1,
    "source": "Contact",
    "target": "name"
  },
  {
    "position": 2,
    "source": "Address",
    "target": "address_line_1"
  }
]
```

### Export Template Layout

```json
[
  {
    "field": "external_reference",
    "header": "RefNum",
    "position": 1
  },
  {
    "field": "corrected_address_line_1",
    "header": "Addr1",
    "position": 2
  }
]
```

---

## Background Job Processing

### ProcessImportBatchValidation

Located at `app/Jobs/ProcessImportBatchValidation.php`

**Configuration:**
- `tries`: 3 (retry attempts)
- `timeout`: 3600 seconds (1 hour max)
- `chunkSize`: 50 addresses per batch

**Usage:**
```php
use App\Jobs\ProcessImportBatchValidation;

// Dispatch with default chunk size
ProcessImportBatchValidation::dispatch($batch);

// Dispatch with custom chunk size
ProcessImportBatchValidation::dispatch($batch, 100);
```

**Progress Tracking:**
The job updates `validated_rows` on the ImportBatch model after each successful validation. The BatchImport page polls this value to show progress.

### Queue Worker

Run the queue worker to process jobs:
```bash
php artisan queue:work
```

**Important:** Restart workers after code changes:
```bash
php artisan queue:restart
```

---

## Common Patterns

### Filament 5 Namespaces

```php
// Form fields
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;

// Layout/Schema
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Schema;

// Infolist entries
use Filament\Infolists\Components\TextEntry;

// Icons
use Filament\Support\Icons\Heroicon;
```

### Case-Insensitive Address Comparison

```php
$isChanged = fn($corrected, $original) =>
    $corrected &&
    strtoupper(trim($corrected)) !== strtoupper(trim($original ?? ''));
```

### ZIP+4 Comparison

```php
$normalizePostal = fn($postal) =>
    preg_replace('/[^0-9]/', '', $postal ?? '');

$originalNormalized = $normalizePostal($original);
$correctedFull = $normalizePostal($corrected . $ext);

// If same length, direct compare
// If original is 5 and corrected is 9, compare first 5
```

### Encrypted Credentials

```php
// In Carrier model
public function getCredentials(): array
{
    return json_decode(
        Crypt::decryptString($this->credentials),
        true
    ) ?? [];
}

public function setCredentials(array $credentials): void
{
    $this->credentials = Crypt::encryptString(
        json_encode($credentials)
    );
}
```

---

## Troubleshooting

### Common Issues

**1. "Address Not Found" but "DPV Confirmed"**
- FedEx returns `attributes.DPV` = "true" even when address needs correction
- Check both `DPV` and `Matched` attributes
- Use `state` field for overall status

**2. Case sensitivity showing changes**
- Use `strtoupper(trim())` for comparisons
- Don't compare raw values

**3. ZIP+4 showing changed when matching**
- Normalize to digits only before comparing
- Handle 5-digit vs 9-digit comparisons

**4. Field mapping backwards**
- "Ship To Name" → `company` (business/organization)
- "Contact", "Attn" → `name` (person)
- Fields containing "contact" → `name`

**5. Queue jobs not running**
- Ensure `php artisan queue:work` is running
- Check `QUEUE_CONNECTION` in `.env`
- Restart workers after code changes

**6. API authentication failures**
- Verify credentials in carrier settings
- Check environment (sandbox vs production)
- Ensure OAuth token is refreshing

### Logging

```php
use Illuminate\Support\Facades\Log;

Log::info('BatchImport: Processing', [
    'batch_id' => $batch->id,
    'file' => $filename,
]);

Log::error('Validation failed', [
    'address_id' => $address->id,
    'error' => $e->getMessage(),
]);
```

### Testing

```bash
# Run all tests
php artisan test --compact

# Run specific test
php artisan test --filter=ImportServiceTest

# Run with coverage
php artisan test --coverage
```

---

## Quick Reference

### System Fields for Import

| Field Key | Label | Examples Matched |
|-----------|-------|------------------|
| `external_reference` | External Reference | Order ID, Reference, PO Number |
| `name` | Recipient Name | Contact, Attention, Attn |
| `company` | Company Name | Name, Ship To Name, Recipient |
| `address_line_1` | Address Line 1 | Address, Addr1, Street |
| `address_line_2` | Address Line 2 | Address 2, Addr2, Suite |
| `city` | City | City, Town |
| `state` | State | State, Province, Region |
| `postal_code` | Postal/ZIP Code | ZIP, Postal Code, ZipCode |
| `country_code` | Country Code | Country, Country Code |
| `extra_1` - `extra_20` | Extra Fields | Custom pass-through data |

### Export Fields

| Field Key | Description |
|-----------|-------------|
| `external_reference` | Original reference |
| `name` | Recipient name |
| `company` | Company name |
| `original_address_line_1` | Original address |
| `corrected_address_line_1` | Validated address |
| `corrected_city` | Validated city |
| `corrected_state` | Validated state |
| `corrected_postal_code` | Validated ZIP |
| `full_postal_code` | ZIP+4 formatted |
| `validation_status` | valid/invalid/ambiguous |
| `is_residential` | Yes/No |
| `classification` | residential/commercial |
| `confidence_score` | Percentage |
| `carrier` | Carrier name used |
| `validated_at` | Timestamp |
| `extra_1` - `extra_20` | Pass-through fields |

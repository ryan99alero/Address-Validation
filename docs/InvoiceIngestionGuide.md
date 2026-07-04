# Carrier Invoice Ingestion Guide

How carrier invoices (UPS, FedEx) are ingested, parsed into real invoices + charges +
shipments, deduplicated, and reconciled. This is the authoritative reference for the
invoice pipeline — enough to reproduce the design without the code.

> Sibling docs: [DeveloperGuide.md](DeveloperGuide.md) (app architecture),
> [carrier-fee-analytics-guide.md](carrier-fee-analytics-guide.md) (how the reports read
> this data), [WorkerEngineGuide.md](WorkerEngineGuide.md) (batch/address validation).

---

## 1. Mental model

- **A carrier invoice file is a *batch*.** One CSV or PDF can contain **many real
  invoices** (different invoice numbers, sometimes different accounts and dates).
- **One `CarrierInvoice` row = one real invoice**, identified by
  **`(carrier_id, invoice_number, invoice_date)`** — *not* the filename, *not* the
  invoice number alone. (See §4, recycled invoice numbers.)
- **A `CarrierCharge` = one charge line** (base transportation or a surcharge), the
  atomic unit the analytics roll up.
- **A `CarrierShipment` = one tracking number on one invoice** (UPS PDF only today),
  holding shipment-level attributes charges can't (dimensions, UPS-audited dimensions,
  weights, message codes, third-party flag).
- **CSV is the authoritative charge source.** When the same invoice arrives as both CSV
  and PDF, CSV owns the charges; the PDF contributes shipment/audit detail (§7).

---

## 2. Entry points & routing

Everything funnels through **`App\Services\CarrierInvoiceParserService`**.

### `importFile(int $carrierId, string $path): array` — the current path
Returns the IDs of every `CarrierInvoice` the file touched. Routes by carrier slug +
extension:

| Carrier | `.csv` | `.pdf` |
|---------|--------|--------|
| ups     | `importUpsCsv()` | `importUpsPdf()` |
| fedex   | `importFedExCsv()` | `importFedExPdf()` |

### `parse(CarrierInvoice $invoice, string $filePath): array` — legacy path
Pre-creates one `CarrierInvoice` then routes to `parseUpsInvoice` / `parseUpsPdfInvoice`
/ `parseFedExInvoice` / `parseFedExPdfInvoice`. **Corrections-focused; does not use the
split-invoice model.** Still called by a few console commands (`ScanInvoiceFolder`,
`ProcessCarrierInvoices`, `ReparseEmptyInvoices`) — migrating these to `importFile` is
open TODO. **SMB folder ingest and email ingest both use `importFile`.**

---

## 3. Ingest sources (who calls `importFile`)

1. **SMB / local folder** — `App\Services\Invoices\FolderInvoiceIngestService`
   - `listCandidates()` enumerates files once (CSV-first via `orderCsvFirst`).
   - `ingestFiles()` processes a slice: content-hash dedup against `carrier_import_files`,
     `importFile()`, then `recordImport()` links the file → invoices (pivot).
   - Driven by `App\Jobs\ProcessFolderIntegration`, which **enumerates then fans out one
     `App\Jobs\ProcessFolderChunk` per 100 files** (`CHUNK_SIZE`). Chunks are individually
     retryable and parallel, so one oversized year-folder can't time out the whole ingest.
2. **Email** — `App\Services\Mail\InvoiceMailProcessService::processFile`
   - Unzips attachments, detects carrier (fixed / sender-domain / file-content),
     dedups by hash (`CarrierInvoice.file_hash` legacy **or** `carrier_import_files`),
     calls `importFile()`, records a `carrier_import_files` row, archives each resulting
     invoice's source PDF to `{disk}:{base}/{Carrier}/{Year}/{Month}/`.
   - Driven by `App\Jobs\ProcessMailIntegration` (webklex IMAP).

Both paths are idempotent: re-ingesting an unchanged file is skipped by content hash.

---

## 4. Invoice identity — recycled numbers (critical)

`getOrCreateInvoice()` uses **`CarrierInvoice::createOrFirst(['carrier_id','invoice_number','invoice_date'], …)`**,
backed by the unique index `carrier_invoices_identity_unique`
(migration `2026_07_03_064158`).

**Why the date is in the key:** UPS **recycles the invoice-number series roughly every
~10 years** (e.g. `E540W###`). Keying on number alone merged the 2009 and 2019
`E540W079` into a single row holding charges a decade apart — the cause of a
~3595→1433 UPS invoice-count collapse after a purge + re-import (real invoices lost to
merges, not deduped).

Two implementation musts:
- **`CarrierInvoice::casts()` sets `invoice_date => 'date:Y-m-d'`** (date-only). Plain
  `'date'` serializes `Y-m-d 00:00:00`, breaking `createOrFirst`'s re-select (the compare
  value must round-trip byte-identically to what's stored) → spurious unique violations.
  **Do not simplify it back to `'date'`.**
- **`createOrFirst`, not `firstOrCreate`** — race-safe when parallel chunk jobs hit the
  same new invoice (the loser catches the unique violation and re-selects).

`App\Services\Invoices\InvoiceIdentity::number()` normalizes: strip non-alphanumeric,
uppercase, **strip leading zeros** (UPS CSV `000000691317025` == PDF `0000691317025` ==
`691317025`). `account()` keeps leading zeros.

---

## 5. Charge dedup

`CarrierCharge` dedup key = **`(tracking_number, charge_category_id, amount, ship_date)`**
as a per-invoice multiset (`loadChargeMultiset` + `chargeKey`). Incoming charges are
diffed against what's already stored, so re-imports and CSV/PDF merges only add the
surplus — cost-safe and idempotent.

`ship_date` is in the key because **carriers recycle tracking numbers** too; without it a
recycled tracking with the same category+amount in a later period would be wrongly dropped.
UPS message code **`dd` = "Identical tracking number used on multiple packages"** confirms
same-tracking duplicates are legitimate → **within-file duplicates are preserved**
(dropping them would understate spend).

`charge_category_id` comes from `App\Services\Invoices\ChargeCategoryResolver` (substring
match of the raw code/description → canonical `charge_categories` row).

---

## 6. The parsers

### UPS CSV — `importUpsCsv()`
UPS "Billing Data" is a **stable, headerless 250-column** layout (unchanged across all
years — there was never a multi-version parser). Fixed 0-based columns:

| Col | Field | Col | Field |
|-----|-------|-----|-------|
| 1 | account | 33 | zone |
| 4 | invoice date | 35 | charge category detail code |
| 5 | invoice number | 45 | charge description |
| 11 | ship date | 52 | net amount |
| 13 | tracking | 66–73 | ADC original address |
| 28 | billed weight | 75–81 | ADC corrected address |

Rows with code `ADC` become address-correction lines (fed to the correction cache).

### UPS PDF — `importUpsPdf()` + `UpsPdfChargeParser`
Full charge/shipment/DIM-audit extraction. See §8.

### FedEx CSV — `importFedExCsv()`
Header-mapped (survives column reordering). Splits the batch by "Invoice Number", resolves
per-shipment ship date from `Tendered Date`/`Shipment Date`/`Ship Date`/`Pickup Date`,
records correction lines (`recordFedExCorrection`, dedup by tracking, ship + POD dates).

### FedEx PDF — `importFedExPdf()` + `FedExInvoiceParser::parseStructured`
Splits the batch by "Invoice Number X-XXX-XXXXX" sections; skips no-tracking summary
blocks (a Multiweight summary block once looked like a $5,237.91 phantom — excluded
because it has no valid tracking). CSV/PDF invoice numbers match once dash-stripped
(`9-148-48578` == `914848578`).

Zero-charge invoices (empty/unreconciled sections) are dropped so they don't clutter the
list.

---

## 7. Source priority (CSV vs PDF) — no double-count

Matched by **invoice number, never filename** (`farmTacoBangTree.csv` and
`UPS_2026-02-24.pdf` both resolve to the same `CarrierInvoice`):

- **PDF imports after CSV** → keep the PDF's `carrier_shipments` rows, **skip its
  charges** (CSV already owns them). Guarded by `$hasCsvCharges` in `persistUpsPdf`.
- **CSV imports after PDF** → on first touch of the invoice, **delete prior
  `source_type='pdf'` charges**, then CSV becomes authoritative. Shipment/audit rows are
  retained.

Every charge carries **`source_type`** ('csv' | 'pdf'). Description precedence at read
time (CSV > PDF) can use it; charges stay append-only + first-writer-wins on dedup.

---

## 8. UPS PDF charge parser (`App\Services\Invoices\UpsPdfChargeParser`)

Extracts charges + per-shipment detail + DIM audit + message codes from the **flattened
smalot PDF text** (no reliable newlines → marker-driven, single forward pass, amount
anchored right-to-left). **Validated to the cent** on the real 1,394-page invoice
(`$4,411.77`, 6,934 shipments, ~125 ms on prod).

**Amount token:** `-?\d{1,3}(?:,\d{3})*\.\d{2}`. Every charge line ends in
`Published, Incentive(neg), Billed`; **Billed is what we pay**. Two-token lines have no
incentive. Σ Billed per section == the printed section total (built-in reconciliation).

**Seven sections** (carved by their column-header run, anchored *after* the pages-2–3
incentive summary, which reuses the same phrases and is a marker trap):

| Section | Payable | Notes |
|---------|---------|-------|
| Outbound Shipping API | Billed | bulk; base line = service + zip/zone/weight + amounts |
| Inbound Collect | Billed | extra `Pickup Record`/`Entry` columns |
| Address Corrections | Billed | carries `Recorded:`/`Corrected:` pairs → correction cache |
| Packages Delivered but not Previously Billed | Billed | `Missing PLD Fee`, audited dims |
| **Shipping Charge Corrections** | **4th "Adjustment Amount" column** (NOT Billed) | two rating lines (orig+corrected), corrected weight is DECIMAL, DIM audits live here (code `w`) |
| Adjustments (audit fee) | Billed | account-level, free text (`FEE BASED ON N PACKAGES…`) |
| Service Charges | Billed | account-level, no tracking (`Weekly Service Charge`) |

**Edge cases the grammar handles (all real, from one invoice):**
- **$0 third-party blocks** dominate (6,708 of 6,886 outbound): 2 amount tokens, no Total,
  service "Ground Commercial Third Party", `Third Party:` bill-to line. A shipment row is
  written; **no charge rows** (amount 0).
- **Bare CWT sibling blocks**: zero amount tokens (shipment row, no charges).
- 3-digit air zones (`204`); decimal SCC weights (`19.0`); parenthesized descriptions
  (`Additional Handling - Weight (4)`).
- `Message Codes` label has 3 spacings (`: ag`, `:bg dd`, ` :w`) and is **fully
  optional** — never require it. Glossary is per-invoice/self-contained → resolve using
  the codes shipments actually reference.

**Message codes** (per-invoice glossary on the last page): `ag`=Minimum Rates Applied,
`w`/`bf`/`bg`/`r`=dimensional-weight adjustments, `KD`=customer-provided info,
`a1`=Additional Handling min billable weight, `a`=hundredweight-eligible,
`dd`=identical tracking on multiple packages.

**DIM audit / dispute signal:** each shipment stores `customer_dims`, `audited_dims`,
`weight`, `billed_weight`. When UPS audits dims larger than entered (e.g. `36×4×4`→`38×5×5`),
the bigger dimensional weight re-rates the shipment up (flagged `w`/`bg`). The account's
effective **DIM divisor is 200** (empirical, NOT the default 139/166); shipments rated at
a different divisor are mis-rate dispute candidates. Store raw dims + billed weight, compute
the effective divisor at read time — don't hardcode.

**Reconciliation policy = import + flag** (never hard-fail). `persistUpsPdf` compares Σ
parsed vs the printed grand total (`Charges this period $…`) and sets
`carrier_invoices.charges_reconciled` / `charges_parsed_total` / `charges_expected_total`;
a mismatch logs a warning.

> Gotcha that cost real time: a `\$` inside a **single-quoted** PHP regex char class breaks
> the lexer (reports a bogus "unclosed {"). Keep `$`/`\$` out of single-quoted classes.

---

## 9. Address correction cache (fed by every parser)

Correction lines (`carrier_invoice_lines`, original→corrected) link into the shared cache:
`corrected_addresses` (the good address; `first_carrier_id`) + `address_variants`
(bad input → corrected, `input_hash`, `times_seen`). `AddressVariant::lookup`/`lookupBatch`
resolve a bad address to its correction during validation, filtered to `is_active = true`
(the "Do Not Use" flag; per-variant, so legit ship-to-RAND corrections still work).

---

## 10. Schema

**`carrier_invoices`** — identity `(carrier_id, invoice_number, invoice_date)` UNIQUE.
Cols incl. `source`, `source_reference`, `filename`, `archived_path`, `file_hash`,
`account_number`, `status`, `processed_at`, and reconciliation:
`charges_parsed_total`, `charges_expected_total`, `charges_reconciled`.

**`carrier_charges`** — `carrier_invoice_id`, **`carrier_shipment_id`** (nullable FK),
`carrier_id`, `invoice_date`, **`ship_date`**, `account_number`, `tracking_number`,
`raw_charge_code`, `raw_charge_description`, `charge_category_id`, `amount`, `service`,
`zone`, `weight`, **`source_type`** ('csv'|'pdf').

**`carrier_shipments`** (UPS PDF; migration `2026_07_03_182103`) — one row per tracking
per invoice: `tracking_number`, `section`, `service`, `zip`, `zone`, `weight`,
`billed_weight`, `ship_date`, `customer_dims`, `audited_dims`, `customer_weight`,
`message_codes` (json), `sender`, `receiver`, `third_party`, **`is_third_party`**,
`printed_total`, `source_type`. Indexes: `(carrier_id, audited_dims)`,
`(carrier_id, is_third_party)`.

**`carrier_import_files`** — content-hash file tracking: `carrier_id`,
`folder_integration_id`, `file_hash`, `filename`, `source_reference`, `invoice_count`,
`imported_at`; `belongsToMany` `carrier_invoices` via `carrier_import_file_invoice`.

**`carrier_invoice_lines`** — address-correction detail (original/corrected fields,
severity, `corrected_address_id`, Pace billing fields).

**`charge_categories`** — canonical categories (`name`, `abbreviation`, `parent_id`).

**Rollup tables** (`carrier_charge_rollup`, `carrier_ship_rollup`,
`carrier_shipment_summary`) power the reports; rebuilt by `reports:rebuild`
(`RebuildReportRollups` / `RebuildCarrierRollup`). See report-snapshots memory + the fee
analytics guide.

---

## 11. Re-import / operations

- **Purge + re-import** a carrier: pause ingest → delete that carrier's invoices → scan
  again. `carrier_import_files` hash tracking means a re-scan re-imports cleanly.
- **Restart queue workers after any parser/job change**: `php artisan queue:restart`
  (prod: `sudo supervisorctl restart address-validation-worker:*`). Workers cache code at
  startup.
- **Verification after a UPS re-import** (recycled-number regression check — a merge shows
  a ~3650-day charge spread, a real rebill ~130):
  ```sql
  SELECT carrier_invoice_id, DATEDIFF(MAX(ship_date), MIN(ship_date)) AS spread
  FROM carrier_charges GROUP BY carrier_invoice_id HAVING spread > 120 ORDER BY spread DESC;
  ```
- **Reconciliation audit** — invoices whose parsed charges didn't match UPS's printed total:
  ```sql
  SELECT invoice_number, charges_parsed_total, charges_expected_total
  FROM carrier_invoices WHERE charges_reconciled = 0;
  ```

---

## 12. Roadmap (schema already supports)

- **Third-party chargeback report** — filter/trend charges on `is_third_party` shipments;
  cross-carrier comparison over N shipments (who bills us more for third-party shipments
  we can't control).
- **DIM re-rate dispute report** — shipments whose effective divisor deviates from the
  account norm (200).
- **Contract-tier monitoring** — the incentive-summary section prints weekly revenue tiers.
- Read-time CSV>PDF description precedence via `source_type`.
- Migrate remaining legacy `parse()` callers to `importFile`.

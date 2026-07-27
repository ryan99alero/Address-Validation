# Shipping & Correction Heatmaps

US destination heatmaps rendered from invoice data with **zero geocoding and no external API calls at request time**. ZIP → lat/lng is a static public-domain lookup (GeoNames); we aggregate by ZIP in SQL and render client-side with Leaflet + Leaflet.heat over free CARTO tiles.

## What shipped (v1)

Two dashboard widgets, both driven by the existing **Year/Month period filter** (via `ReadsDashboardPeriod` + `InteractsWithPageFilters`) and both cached:

1. **Shipping Destinations** (`App\Filament\Widgets\ShippingHeatmap`) — where packages shipped, from `carrier_shipments.zip`. Single blue→red layer, red = busiest ZIPs.
2. **Address Correction Hotspots (Map)** (`App\Filament\Widgets\CorrectionHeatmap`) — where address corrections happen, from `carrier_invoice_lines.original_postal`, split into two overlaid heat layers (**UPS** amber-red, **FedEx** blue-purple) with a Leaflet layer control so each carrier can be toggled — that's the "opaque overprint" carrier comparison.

Supporting pieces:
- `zip_centroids` table + `App\Models\ZipCentroid` — one row per 5-digit ZIP (`id` PK, `zip` unique, `lat`, `lng`, `city`, `state`).
- `zipcentroids:import` command — loads GeoNames `US.txt`/`US.zip` (downloads by default; `--file=` fallback). Re-runnable (upsert). ~42k rows.
- `App\Services\Analytics\HeatmapService` — aggregates any destination-bearing query by period + 5-digit ZIP into `{points: [[lat,lng,weight]], meta: {matched,total,unmatched,max}}`. Cached, keyed to a table version stamp (new rows → recompute).
- Shared view `resources/views/filament/widgets/heatmap.blade.php` (Leaflet + gradient legend, keyed to the period so it re-renders on filter change).
- Index `carrier_shipments.ship_date` (period filter over ~1M rows uses a date range, not `YEAR()`, so the index applies and the SQL stays portable).

### Data coverage (as of build)
- `carrier_shipments`: **1.02M rows with a ZIP, UPS only** (FedEx doesn't populate this table). 17k distinct ZIPs, 2016–2026.
- `carrier_invoice_lines`: **UPS 24k + FedEx 1.6k** corrections — both carriers, which is why the *correction* map can compare carriers and the *shipping* map (v1) is UPS-only.

## Answers to the open questions

### ZIP+4 granularity — will it resolve within a town (e.g. Wellington, KS ~7k)?
No. We plot **one point per 5-digit ZIP** (the ZIP's centroid ≈ town center). Every shipment to Wellington lands on that single centroid; **zooming in will not spread them into quadrants** — the data has no finer position than the ZIP. ZIP+4 *would* localize to a block/building, but:
- There is **no free public ZIP+4 → lat/lng table** (ZIP+4 geocoding needs paid USPS/commercial data or per-address geocoding).
- A national/regional heatmap can't visually resolve finer than ZIP anyway.
So ZIP-centroid resolution is the intended design, not a shortcut, and it keeps address PII out of any third-party system.

### How much more to do a *real* address-level heatmap? (without a huge API bill)
Moderate, and bounded — the trick is to geocode **once per distinct address**, not per request:
1. Add `lat`/`lng` columns to the shipment/address record.
2. Geocode **distinct** destination addresses to rooftop/ZIP+4 lat-lng and cache forever. Two viable sources:
   - **Smarty** — already integrated for address validation; its US Street API can return `latitude`/`longitude` (and ZIP+4). Capture them during validation going forward, and batch-backfill distinct historical addresses. Cost = one call per *new distinct* address, then free forever.
   - Free **Nominatim/OSM** batch geocode (rate-limited ~1 req/s) for a one-time backfill — slow but $0.
3. The map layer swaps ZIP centroids for the stored per-address lat/lng; everything else (aggregation, legend, widget) is unchanged.
Estimate: ~1–2 days. The only cost is the one-time distinct-address geocode (tens/low-hundreds of thousands of calls, cached), **not** a recurring per-view bill.

## Base map
Using **CARTO Positron** (`basemaps.cartocdn.com/light_all`) — a muted light-grey basemap so the colored heat pops cleanly and professionally (free, attribution required, no key). Alternatives if the look should change: **CARTO Dark Matter** (dark, heat pops hard) or **OSM standard** (more detailed but busier/less modern). All are one-line tile-URL swaps in the widget config.

## Future enhancements (backlog)
- **FedEx shipment coverage** — `carrier_shipments` is UPS-only; FedEx destinations live in the FedEx CSV recipient ZIP but aren't persisted to a shipment table. Add FedEx destination persistence (or a lightweight per-invoice-line ZIP source) to make the shipping map all-carrier.
- **Metric toggle** — `carrier_shipments` has `weight`, `billed_weight`, and `printed_total`; `HeatmapService::aggregate()` can take a `metric` (count|weight|spend) and swap `COUNT(*)` for a `SUM(...)`. Add a widget-local selector.
- **State choropleth alternate view** — `zip_centroids.state` is populated; a "By State" toggle could shade a bundled `us-states.geojson` with the same scale (often more readable for business reporting than a heat blur).
- **Address-level heatmap** — the Smarty-geocode Phase 2 above.
- **Perf** — if the all-years group-by on `carrier_shipments` (~1M rows) gets slow, add a stored generated `zip5` column + index (currently the version-stamped cache + date-range filter keep it fine; measure before adding).

## Operations
- After deploy, populate centroids once: `php artisan zipcentroids:import` (downloads GeoNames; or `--file=/path/US.zip`). Re-runnable, no duplicates.
- Widgets recompute only when new shipments/corrections import (version-stamped cache), else serve instantly.

## Constraints (kept)
No external API calls at request time (the only fetch is the one-time GeoNames download in the import command). No npm/build additions — Leaflet + Leaflet.heat load from CDN via Livewire `@assets`. Invoice parsing and the shipments schema are untouched apart from the `ship_date` index.

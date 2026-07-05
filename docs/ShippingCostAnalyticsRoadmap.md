# Shipping Cost Analytics & Recovery — Product Roadmap

Synthesis of the internal + Fable strategy review. The tool's job is not to *store* invoice
data — it's to turn it into **money understood** (where costs come from) and **money
recovered/avoided** (disputes, recoup, prevention). Storage is table stakes; the analysis and
the actions it triggers are the product.

> Defensibility: the edge over parcel-audit SaaS (Sifted/Reveel/71lbs) is **our own parser +
> our own address validation + customer-entered dimensions** — data they don't have. Lean the
> roadmap into what only we can do: DIM disputes with our own dims, and correction-fee
> avoidance/analytics from our validated cache.

---

## The four zones (one dashboard, matching the money flow)

1. **Bleed** — trailing-12-month accessorial load % (accessorial $ / total $), trended by
   category. The headline "how badly are we bleeding." Must include the fuel-on-accessorial
   multiplier (~15–16%) to be honest. *[GREEN — data ready]*
2. **Recover** — dispute funnel (detected → submitted → won → credited), win-rate by category,
   recovered-$ trend. The engine's ROI proof. *[refund engine]*
3. **Recoup** — quote-vs-actual delta by customer, billed vs. absorbed, drill-down to shipment.
   *[needs Pace quote + tracking→job linkage]*
4. **Prevent** — re-correction rate trend (the validation engine's real-world accuracy),
   correction-fee $ trend (should decline as coverage grows), hotspot map. *[GREEN — data ready]*

Zones 1 and 4 are buildable now on the rollup tables with no external data.

---

## How carriers bleed shippers (what our data can catch)

Base rate is a fiction; accessorials are where carrier margin lives, applied by automated
systems that err in the carrier's favor. Auditors find ~1–3% of parcel spend refundable and
5–10% avoidable.

- **DIM weight re-audits** — carrier re-measures and bills the higher of actual vs.
  dimensional weight, with *their* divisor. We hold `customer_dims` vs `audited_dims` vs
  `billed_weight` → factual dispute. Highest-recovery category. *(UPS-PDF-only today.)*
- **Residential/Commercial misclassification** — carriers flag businesses as residential
  (residential surcharge + DAS-residential stack). We validate addresses, so a known-commercial
  address billed residential = dispute. **Likely the #2 recovery category and unique to us.**
  (We already see UPS *crediting* these back as "Residential/Commercial Adjustments".)
- **DAS creep** — Delivery Area Surcharge ZIP lists expand yearly; billed-vs-contracted DAS is
  auditable if the contract references a specific ZIP table.
- **Address-correction fees on valid addresses** (~$21+ each) — we have the before/after pairs.
  A "correction" that is cosmetically different but semantically identical (ST→STREET, suite
  reorder) is disputable. Nobody else has this structured.
- **Additional Handling / Large Package** — scanner-triggered on dims/weight; same dispute
  pattern as DIM.
- **Fuel surcharge compounding** — fuel % applies to most accessorials, so a wrong surcharge
  carries a fuel multiplier; disputing it recovers the fuel too. Recovery math must include
  this or it undercounts ~15–16%.
- **Duplicate billing** — same tracking on the weekly invoice + a rebill. Detection exists.
- **GSR / late-delivery refunds** — **KILLED for now** (owner decision). Both carriers largely
  suspended ground GSR post-2020 / waive it in contracts for discounts, and it needs
  tracking-API delivery/commitment timestamps we don't have. Revisit only if the contract still
  grants GSR rights.

---

## Decisions (locked)

- **Recoup basis = (actual − quote)**, full stop — not sum-of-accessorials (some accessorials
  were in the quote; audited-weight base bumps aren't accessorials). Compute the true delta
  always; apply a **per-customer recoup policy layer** for what actually bills (goodwill,
  materiality thresholds like ">$2 or >5%", contract pass-through terms). The delta you *don't*
  bill is still intelligence — it shows where quoting is systematically wrong.
- **Refund engine = detect-and-export first.** Detection + dispute queue + human approve +
  export (UPS Billing Center / FedEx Billing Online). Build the **state machine** now
  (detected → queued → submitted → approved/denied → credited, with recovered-$ + denial-reason)
  — denial reasons are the feedback loop that tunes detection. Auto-submit only per category
  after a proven >90% win rate. Carriers track dispute quality and slow-walk chronic bad filers.
- **UPS-PDF-only DIM is fine for v1** — but the dashboard must show coverage explicitly ("DIM
  audit coverage: X% of parcel spend"). FedEx dims extraction becomes priority #1 after v1
  proves the recovery rate.
- **Correction chain: store the event, not the genealogy.** `correction_event(original_address_id,
  corrected_address_id, invoice_line, fee, detected_at)` + a binary re-correction flag that trips
  `is_active`. The KPI that matters is the **re-correction rate** (% of our corrections a carrier
  re-corrects) — the validation engine's accuracy score, graded by an adversary with money on the
  line.

## "What we're not thinking of" — high-value additions

- **Rate-shopping / carrier arbitrage** — we have both carriers' actual billed costs for
  comparable lanes: "shipments where the other carrier would've been cheaper." Prevention beats
  recovery 10:1; feed it upstream into Process Shipper.
- **Surcharge forecast at quote time** — per-ZIP/per-customer historical probability + avg $ of
  residential/DAS/correction/handling → "addresses like this historically incur $4.30 in
  accessorials." Closes recoup → never-underquote.
- **Contract-renegotiation ammunition** — accessorial-load-% by category, trended + won disputes
  + DAS exposure. What consultants charge 20–30% of savings to produce; moves discount tiers.
- **GL-level surcharge allocation** — charge accessorials back to the right customer/job via the
  `pace_job_number` linkage (byproduct of recoup).

---

## Data assets (verified) & feasibility

- `carrier_charges` — ~532k rows, 14 canonical categories, ~0.7% uncategorized, source_type,
  ship_date, zone, weight, tracking. Rollup tables present. **Cost Intelligence: GREEN.**
- `carrier_shipments` — dims/audited_dims/billed_weight/is_third_party/message_codes.
  **UPS-PDF-only** — FedEx + all CSV don't populate it. DIM/third-party analytics partial.
- Address correction cache + `is_active` "Do Not Use" + validation engine. **Prevent: GREEN.**
- Recoup scaffolding: `carrier_invoice_lines` already carries `pace_job_number`,
  `pace_customer_id`, `billed_to_pace`, `billed_at` (built for corrections; extend to all charges).
- No commitment/expected-delivery dates; actual delivery dates ~absent → GSR not feasible.

---

## Sequencing

0. **Prerequisites** — ✅ correction-fee source bug fixed (fees now sourced from carrier_charges
   category "Address Correction"); ✅ GSR contract check → killed. **TODO: confirm tracking→Pace
   job/customer linkage for all charges (recoup depends on it).**
1. **Cost Intelligence dashboard** (Zones 1 + 4) — fast, GREEN, visible value, the denominator
   for everything else.
2. **Refund engine v1** — detect + dispute queue + state machine + export, on what needs no
   external data: **duplicates + DIM over-audits + residential/commercial misclassification**
   (the last is unique to us and likely #2 by $). Show DIM coverage %.
3. **Customer recoup** — quote-vs-actual via Pace + per-customer policy layer.
   **Foundation built (2026-07-05):** Process Shipper records a single actual ship cost at ship
   time (no separate estimate), so recoup basis = **carrier-invoiced total per tracking −
   carton.ship_cost**. `carton_costs` mirrors the Pace **Carton** object (per package/tracking:
   `ship_cost`, `ship_date`, `pace_job_number`, `pace_customer_id`, `recouped_at`), granular at
   the carton level so multi-package master trackings don't lump costs. `RecoupService`
   (candidates / summaryByCustomer / totalRecoupable / unmatchedTrackings) does the delta math;
   `CartonCostSyncService` populates the mirror from the **Pace REST API**
   (`PaceApiClient::loadValueObjects('Carton', …)`) — XPaths: `@trackingNumber`, `@cost`,
   `@actualDateTime`, `/JobShipment/job/@id` (job), `/JobShipment/job/@customer` (customer). **The
   carton source is the Pace API only — there is no SQL pathway to Pace.** The read is
   **import-triggered**: `finalizeInvoices()` dispatches the `SyncInvoiceCartonCosts` job for the
   invoices just imported (at import time the shipment already exists in Pace — ship-then-bill —
   so the read always hits). `recoup:sync-cartons` remains for manual backfill. **TODO:** build
   the recoup Filament report/zone, the per-customer policy layer, and the Pace write-back that
   sets `recouped_at`.
4. **Re-correction detection** — after Pace write-back is live; event + re-correction-rate KPI.
5. Deferred: FedEx dims extraction (after v1 proves rate), rate-shopping, surcharge forecast,
   contract-renegotiation report. GSR killed unless contract says otherwise.

## Open (owner-side)

- **Pace cost-center / carrier attribution** — owner investigating whether a cost recording can
  reference a *vendor* or needs separate activity codes per carrier. Shapes the recoup/GL design.

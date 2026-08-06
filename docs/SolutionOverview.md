# Shipping & Carrier‑Cost Platform — Capabilities Overview

**RAND Graphics** · Address Validation & Carrier‑Cost Intelligence · August 2026

---

## What it is

A single platform that makes shipping **more accurate, cheaper, and self‑auditing**. It began as an
address‑validation tool and has grown into a carrier‑cost intelligence system that:

1. **Validates and cleans mailing addresses** before packages ship — so fewer are corrected, delayed, or returned.
2. **Reads every carrier invoice** (UPS and FedEx) and turns it into structured, queryable data.
3. **Recovers money** — billing customers for carrier fees they caused, and surfacing carrier overcharges to dispute.
4. **Connects in real time to the Pace ERP**, cleaning shipment addresses automatically as jobs are created.

Everything runs in one secure internal web application. No spreadsheets, no manual invoice keying.

---

## At a glance

- **Two carriers, four ingestion paths.** UPS and FedEx invoices arrive by email, watched folder, network share, or manual upload — all parsed automatically.
- **Ten‑plus years of invoice history** ingested and reconciled (2009–present), millions of charge lines classified by *what* the fee is and *why* it was billed.
- **A proprietary correction cache** — every address the carriers have corrected us on, learned once and reused so we stop paying for the same mistake twice.
- **Automatic write‑back to Pace** — corrected addresses and cost chargebacks flow back into the ERP without staff re‑keying.
- **Guardrails throughout** — nothing is double‑billed, no already‑shipped order is altered, and every automated action is logged and reversible.

---

## Capabilities

Each capability below is marked **● Live** (in production today), **◐ In progress**, or **○ Planned**.

### 1. Address validation & cleansing · ● Live

Validate a single address on demand, or import a spreadsheet of thousands and validate them in bulk against
UPS and FedEx.

- **What it does:** checks each address against the carriers, returns the corrected form, flags residential vs. commercial, and highlights exactly what changed.
- **Why it matters:** a bad address means a correction fee, a delay, or a returned package. Catching it *before* shipping avoids all three.
- **Extras:** save reusable field‑mapping templates for recurring import formats; export the cleaned results straight back into the original file's own columns; a "check both sources" mode compares our own data against the carrier and flags disagreements for a person to resolve.

### 2. The correction cache — learned carrier accuracy · ● Live

The platform's proprietary asset: a growing memory of every address a carrier has charged us to correct.

- **What it does:** mines correction fees out of the invoices to build a "bad address → the form the carrier accepts" lookup, and applies it to future shipments automatically.
- **Why it matters:** the goal isn't the textbook‑correct address — it's the address **the carrier won't charge a fee on**. That's often carrier‑specific, and it's knowledge we already paid to acquire. We reuse it instead of re‑buying it.
- **Intelligence on top:** severity scoring (cosmetic vs. genuinely wrong data), correction "hotspots," a carrier‑bias audit (which carrier corrects more aggressively), and self‑verification that re‑checks stored addresses over time and flags when a carrier's preferred form has drifted.

### 3. Carrier invoice intelligence · ● Live

Every UPS and FedEx invoice becomes clean, structured data.

- **What it does:** ingests CSV and PDF invoices from email, network folders, or upload; extracts charges, per‑shipment detail, dimensional‑weight audits, and message codes; and reconciles UPS invoices **to the penny**.
- **Why it matters:** invoices are where the money is hiding. Once they're structured, every fee can be counted, categorized, trended, billed back, or disputed.
- **Robustness:** invoice identity is recycling‑safe (carriers reuse invoice and tracking numbers over the years), duplicates are prevented, and each charge is tagged with **what** it is (fuel, residential, handling, correction…) and **why** it happened (address correction, dimensional re‑rate, return, third‑party billing…).

### 4. Cost analytics — understand the spend · ● Live

Dashboards and reports that turn the invoice data into a clear picture of where money goes.

- **What it does:** shows accessorial "bleed" (the surcharges on top of base freight), fee mix by type and carrier, year‑over‑year trends, and inflation‑adjusted comparisons.
- **Why it matters:** you can't cut or recover a cost you can't see. This is the "where are we bleeding" view, sliced by carrier, year, and fee type.
- **Fair comparisons:** UPS vs. FedEx are normalized (per shipment, per fee, fee‑load %) so the numbers aren't misleading.

### 5. Cost recovery — get the money back · ● Live / ◐ In progress

Three ways the platform turns cost data into recovered dollars:

- **Recoup — bill customers for fees they caused · ● Live.** Eligible carrier charges (address corrections, etc.) are pushed back into Pace as job costs so the customer is billed for the extra cost their order created. Fully double‑bill‑safe, and base freight the customer already paid is **never** charged back.
- **Recoup — overage detection · ● Live.** Compares what the carrier billed against the quoted ship cost per package to surface where we were billed more than expected.
- **Recover — carrier disputes · ○ Planned.** A refund engine to detect disputable fees (dimensional re‑rates, residential misclassifications) and export evidence packs — an edge unique to us because we hold our own dimensions and validated addresses.

### 6. Real‑time Pace ERP integration · ● Live

The platform is wired directly into the Pace MIS/ERP so shipments are cleaned as they're created.

- **What it does:** when a shipment is created in Pace, the platform automatically validates the address, writes the corrected version back to the order, and flags the record as corrected.
- **Why it matters:** address accuracy happens invisibly, at the source, with no staff step — and the fee is avoided before the package ever ships.
- **Guardrails:** only shipments still in the **Planned** stage are touched (nothing already shipped is altered), corrections are recorded on both the ERP and our side for a full audit trail, and the integration can't loop or double‑write.

### 7. BestWay — just‑in‑time shipping optimization · ● Live

- **What it does:** recommends the **cheapest carrier service that still arrives on the required in‑store date**, shipping on the same plant and billing account as the original order, and writes the recommendation into the export file.
- **Why it matters:** it stops packages from going out faster (and pricier) than they need to, while protecting the in‑hands date — and it never spends another party's money by jumping billing accounts.

---

## How it fits together

```
   Pace ERP (job/shipment created)
        │  real-time webhook
        ▼
   Address validation ──────► corrected address written back to Pace
        │                     (+ flagged as corrected)
        │
        ▼
   Package ships
        │
        ▼
   Carrier invoice (UPS / FedEx)  ──►  ingested, parsed, reconciled
        │                               │
        │                               ├─► fee analytics (understand)
        │                               ├─► charge back to customer in Pace (recoup)
        │                               ├─► overcharges flagged (recover)
        │                               └─► new corrections learned (correction cache)
        ▼
   The correction cache feeds the next validation ──►  fewer fees over time
```

The loop is the point: shipments feed invoices, invoices feed the correction cache and the recovery engines,
and the correction cache makes the *next* shipment cleaner. The system gets smarter and cheaper the longer it runs.

---

## Platform & security

- **Built on** Laravel and Filament — a modern, maintainable web stack; single secure admin interface.
- **Carriers:** UPS and FedEx today (USPS/Smarty capable); designed so additional carriers can be added.
- **ERP:** live two‑way integration with Pace via its native API.
- **Security:** carrier and ERP credentials are stored encrypted; administrative areas are gated to admins via the company's existing LDAP groups; background work runs on a reliable queue so large jobs never block users.
- **Auditability:** every automated push (address correction, cost chargeback) is logged with a timestamp and is traceable end‑to‑end.

---

## Where it's headed

- **Refund / dispute engine** — detect disputable carrier fees and export evidence packs (dimensional re‑rates and residential misclassifications first).
- **Dimensional evidence** — CubiScan measurement ingestion and fuller FedEx dimension capture to strengthen dispute claims.
- **Per‑customer recovery policy** — materiality thresholds and pass‑through terms so chargebacks match each customer's agreement.
- **Deeper analytics** — quote‑vs‑billed comparison and salesperson/customer attribution on recovered dollars.

---

*This overview describes current capabilities and direction as of August 2026. For technical detail see the
developer and invoice‑ingestion guides in the same folder.*

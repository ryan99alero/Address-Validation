# Future Enhancements

Deferred ideas that came out of design discussions but are intentionally **not built yet**. Each entry states the trigger, the intended behavior, and why it's deferred, so a future session can pick it up without re-deriving the context.

---

## Re-Correction flag on cache-vs-carrier drift (address validation)

**Status:** Deferred (2026-09-15).

**Context.** The unified address-validation engine looks an input address up in the local correction cache; on a hit it sends the **cached** (fee-free) form to the carrier's validation API. If the carrier then **corrects that cached address** (i.e. the live carrier and the cache disagree — e.g. a ZIP was re-mapped or a street renamed), the carrier's version wins for the shipment, but the cache entry is now potentially **stale**.

**Intended behavior.** When the live carrier corrects a *cached* address during validation, **flag that address into the Re-Corrections / supersession review queue** so a human can decide whether the cached entry should be superseded. This is a validation-time signal, distinct from the existing invoice-driven re-correction detection.

**Why deferred.** The current Re-Corrections queue (`AddressSupersession` + `RecorrectionRules` + `CorrectionThreader`, surfaced in the Re-Corrections UI) is fed only by **invoice** re-corrections. Adding a validation-time trigger needs a new hook into that queue (and possibly a new source/reason enum). Per direction, we don't build new plumbing for this now — the consolidation ships without it, and this is picked up later.

**When picked up, check:** whether `AddressSupersession` can ingest a validation-time item as-is or needs a new `source`/`reason`; that we don't double-flag an address the invoice path already queued; and that the flag is suppressed in `dry_run`/audit-only modes.

---

## Validation-engine driver onboarding (USPS, DHL)

**Status:** partially addressed by the consolidation (the carrier→driver lookup becomes data-driven so the engine doesn't need reworking to add a carrier).

**Remaining, per new carrier:** an actual driver class implementing the shared `CarrierInterface` (the real API integration for that carrier), plus a `Carrier` record in Carrier Accounts. The engine and the shared flow do **not** change — a new carrier is discovered from the registry once its driver + carrier record exist. USPS first, DHL possibly after.

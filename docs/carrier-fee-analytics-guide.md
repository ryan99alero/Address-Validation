# Reading the Carrier Fee Analytics: A Field Guide

A guide to the **Carrier Fee Summary** and **Carrier Comparison** reports — how they're
built and how to read them so you draw correct conclusions instead of being misled by raw
numbers.

## The problem it solves

Naively comparing two carriers' fees is almost always wrong, for four reasons:

1. **Unequal volume** — we shipped vastly more on UPS some years, FedEx others. Raw totals
   just measure who we used more.
2. **Unequal package size** — FedEx ships our heavier/pricier parcels; UPS the lighter ones.
   Any *dollar* figure is partly measuring package size, not the carrier's pricing.
3. **A moving carrier mix over time** — UPS-primary through ~2013, FedEx after ~2023.
   Comparing a UPS-heavy year to a FedEx-heavy year compares eras, not carriers.
4. **Inflation** — a $10 fee in 2010 isn't a $10 fee in 2026.

The system is built to **strip each of these distortions out**, so what's left is the
carrier's actual pricing behavior.

## The foundation: a normalization layer

Every charge line from every invoice (millions of them, 2009–2026, from both CSV and PDF
formats) is mapped to a **canonical category** — Fuel Surcharge, Delivery Area Surcharge,
Residential, Additional Handling, Oversize/Large Package, Address Correction, Peak/Demand,
etc. So when UPS calls something "ADC" and FedEx calls it "ADDCOR," they both roll up to
**Address Correction**. *That mapping is the whole game* — without it you're comparing two
carriers' marketing vocabularies, not their fees.

## The five lenses — pick the metric to match the question

There is no single "who's cheaper" number. Each metric answers a different question, and
four of the five divide out volume so unequal shipment counts can't distort them.

| Metric | Question it answers | Formula |
|---|---|---|
| **Avg $ per charge** | "When this fee fires, how big is it?" | fee $ ÷ times charged |
| **$ per shipment** | "Spread across all my packages, what does this fee cost me each?" | fee $ ÷ total shipments |
| **Incidence %** | "How *often* does this fee get applied?" | shipments hit ÷ total shipments |
| **Fee load %** | "Per $1 of base shipping, how much of this fee?" *(the effective rate)* | fee $ ÷ base transport $ |
| **Total $** | "Raw absolute dollars" *(not normalized — volume-driven)* | sum of the fee |

## The cardinal rule (and the trap everyone hits)

**Match the lens to the fee's mechanics:**

- **Rate-based fees** (Fuel, DAS, Residential — charged as a % of the shipment cost) → use
  **Fee load %**. It's the true rate and it's automatically inflation-proof.
- **Flat fees** (Address Correction, Additional Handling, Oversize — a fixed dollar amount
  per event) → use **Avg $ per charge**.

The classic trap, with real numbers (2023):

> **Fuel — Avg $ per charge:** UPS $0.94 vs FedEx $2.38 → *looks like FedEx is 2.5× pricier.*
> **Fuel — Fee load %:** UPS **14.2%** vs FedEx **10.2%** → *UPS actually charges the steeper fuel rate.*

Both are correct; they measure different things. FedEx bills more fuel *dollars* only
because its packages are bigger — the same surcharge mechanic on a larger base. On the
metric that reflects **pricing policy** (the rate), **UPS is the more aggressive fuel
biller.** Use the wrong lens and you reach the opposite conclusion.

## How to answer the three real questions

### 1. "Who charges more on fee X?"

Open Carrier Comparison, pick the right lens for that fee (rate → Fee load %, flat →
Avg $ per charge), read the row. The **Costlier** badge and **Gap** (×) column call the
winner and the magnitude.

### 2. "Which carrier is cheaper overall?"

Use the **▸ ALL AUXILIARY FEES** row (top of the table) — it aggregates every surcharge into
one verdict, excluding base transport and discounts. Read it as **Fee load %** for the
cleanest "total surcharge burden per dollar shipped," or **$ per shipment** for "total
surcharge cost per package." *But see the next point — "overall best" is rarely the right
question.*

### 3. "Which carrier is best for a *given type* of package?" (the sophisticated use)

A package's fee exposure is determined by its profile, and each profile triggers different
fees:

- **Heavy / large** → Fuel (rate), **Additional Handling**, **Oversize/Large Package**.
- **Residential / rural delivery** → **Residential Surcharge** + **Delivery Area Surcharge**.
- **Messy address data** → **Address Correction**.

So the move is: identify which fees *your* package mix actually triggers, then compare
**only those rows**. Example — for **bulky residential shipments**, ignore the overall
verdict and compare Oversize + Additional Handling + Residential + DAS. The carrier that
wins *those* rows is your carrier for *that* lane, even if it loses the overall average.
**There is no globally "best" carrier — only best-for-a-profile**, and this tool is built to
answer it per-fee.

## Controls that keep comparisons honest

- **Year from / to** — confine to genuinely overlapping years (e.g. 2013–2018, 2023–2026) so
  you're comparing carriers, not eras. Single year, range, or all.
- **Dollars: Nominal vs Real** — "Real" restates older years into constant base-year dollars
  (CPI), so a multi-year flat-fee trend reflects real price hikes, not inflation. (Rate/%
  metrics are already inflation-neutral.)
- **Fee Summary page** — same normalization, but a per-category breakdown for one carrier (or
  both) with totals and each category's share of the bill — the "where is the money going"
  view, versus Comparison's "who's pricier" view.

## The one-sentence version

> We normalized every fee to a common vocabulary, then exposed five volume-adjusted lenses so
> you can ask the right question with the right yardstick — and the headline skill is matching
> the lens to the fee (rate-based fees by % of base, flat fees by average dollars) and to your
> actual package mix, because the "best" carrier is the one that wins on the fees your
> shipments actually incur.

## Notes & caveats

- **CPI ≠ shipping/fuel inflation.** The "Real $" adjustment uses general CPI-U, a rough
  proxy. It's most meaningful on flat fees; rate-based fees are already inflation-neutral as a
  percentage, so they don't need it.
- **Data coverage varies by year** — some years are UPS-only or FedEx-only (see the year
  classification). Head-to-head comparisons are only meaningful in years where both carriers
  have real fee volume.
- **Weight** is captured on charges going forward (standard CSV imports) for future
  per-pound analysis; it does not backfill historical charges.

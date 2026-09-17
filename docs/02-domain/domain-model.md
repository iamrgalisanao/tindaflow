# Domain Model — TindaFlow POS

## Status
APPROVED — Stage 2 baseline (`stage-2-baseline`, tag to be moved to the
pass-3 commit), amended 2026-09-16 for a third time. Builds on the
approved Stage 1 baseline (`stage-1-baseline`, commit `ac907e1`). Pass 1
incorporated: removal of persisted `DRAFT` sales, the FiscalDay/Shift
split with separate X-Reading/Z-Reading models, an explicit Void
eligibility policy, a clarified Money/Quantity model, capability-based
authorization, an expanded Invoice snapshot, and FiscalInstallation
cardinality left open for Stage 3. Pass 2 corrected order-level discount
allocation: discounts are deterministically distributed per `sale_item`
(not a single sale-level deduction), so partial refunds, mixed-tax
baskets, and audit reconstruction resolve to exact historical figures —
see §2.7 and §3.1. **Pass 3 (this pass) is a narrow, targeted amendment**
raised by Stage 3's concurrency architecture review, not a broad domain
revision: `void` and `refund` gain their own processing-context fields
(`terminal_id`/`fiscal_day_id`/`shift_id`, distinct from the original
sale's context — see §2.8/§2.9), and a new `refund_settlement` entity
records how a refund's money is actually returned. Both were genuine
gaps — Stage 3 could not correctly lock a void/refund's *processing*
fiscal day against a concurrent Z-Reading closure without a field to lock,
and `shift.refunds_total` (already a Stage 2 field) had no way to be
computed without refund carrying a `shift_id`. Neither changes any
existing entity's shape, any state machine's transitions, or any prior
invariant's meaning — both are additive. This is again the final Stage 2
correction; no further domain revision is anticipated before Stage 4.

See also: [erd.md](../03-architecture/erd.md), [invariants.md](invariants.md),
[state-machines.md](state-machines.md). All four documents were revised
together in this pass specifically to keep entity names, cardinalities,
statuses, and cross-references consistent — see the **Cross-document
consistency pass** note at the bottom of this file.

---

## 1. Deviations from the brief's suggested table list (with rationale)

| Brief suggested / earlier draft | This model | Rationale |
|---|---|---|
| `stores.next_invoice_number` | `invoice_series` aggregate | Unchanged from revision 1 — a single mutable counter cannot safely serve concurrent multi-terminal checkout. |
| (not explicitly named) | `terminal`, `fiscal_installation`, `terminal_fiscal_installation` (join/history) | Terminal and fiscal/software identity are separate concepts (unchanged). **Revised in this pass:** `fiscal_installation` is now `store`-scoped, not `terminal`-scoped, associated to one or more terminals via `terminal_fiscal_installation` — see §2.1. This is because BIR's own eAccReg system distinguishes deployment models (POS Standalone vs. POS with SERVERCONS/server-connected terminals), where one accredited installation can legitimately serve multiple terminals. Forcing a 1:1 Terminal↔FiscalInstallation relationship at the domain level would misrepresent that. |
| `roles`, `permissions` (separate tables) | Fixed `role` enum on `user` + a **centralized capability catalog** checked via `can(user, CAPABILITY)`, backed by a code-level role→capability map | Confirmed in this pass: fixed V1 roles (ADMIN/MANAGER/CASHIER), **but** authorization logic is now expressed through named capabilities (`SALE_VOID`, `SALE_REFUND`, `PRICE_OVERRIDE`, `DISCOUNT_OVERRIDE`, `STOCK_ADJUST`, `CASH_OUT`, `REPORT_VIEW`, `STORE_SETTINGS_MANAGE`, ...) rather than scattered `if role == MANAGER` checks — see §2.2. No dynamic DB-backed RBAC table in V1; documented as the future migration path. |
| `suppliers`, `system_settings` | Deferred entirely | Unchanged — no V1 feature uses either. |
| `sale.status` including `DRAFT` | `DRAFT` **removed** from the persisted Sale lifecycle | **New in this pass.** An unfinished cart is not a Sale. The authoritative `sale` row does not exist until checkout finalization commits. See §2.7 and [state-machines.md](state-machines.md) §1. |
| (not modeled) | `fiscal_day`, `x_reading`, `z_reading` | **New in this pass.** RMO 24-2023 requires the electronic journal to include invoices, adjustment documents (voids/refunds), X-Readings, and Z-Readings, and describes X-Reading as the cashier's accountability/end-of-shift report and Z-Reading as the end-of-day report — two genuinely different reporting boundaries (shift/cashier vs. terminal/business-day) that the revision-1 model conflated into `shift` alone. See §2.5. |
| `invoice.structured_json` | `invoice.invoice_snapshot_json` (renamed/expanded) + a small set of flat snapshot columns | **Revised in pass 1** — the payload must preserve the full historical seller/fiscal identity used at issuance (not just an e-invoice-readiness convenience), so a later config change or reprint can never alter what an old invoice shows. See §2.7. |
| `sale_item.line_discount`/`line_total` as a single un-prorated sale-level discount | `sale_item.gross_line_amount`, `line_discount_amount`, `order_discount_eligible`, `allocated_order_discount_amount`, `net_line_amount`, `taxable_base`, `tax_amount`, `line_number` | **New in pass 2.** "Never prorated across lines" (pass 1's wording) is retracted — a partial refund on a discounted, mixed-tax basket must resolve to exact historical per-line figures, not a recalculation under current rules. See §2.7's Order-level discount allocation subsection and §3.1. |
| `void`/`refund` with no processing-context fields; no representation of how a refund's money is returned | `void`/`refund` gain `terminal_id`/`fiscal_day_id`/`shift_id`; new `refund_settlement` entity | **New in pass 3 (this pass, 2026-09-16) — Stage 2 amendment approved following a Stage 3 remediation gap report.** Stage 3's concurrency architecture (Z-Reading vs. Refund/Void locking) and `shift.refunds_total`'s computability both required these fields to already exist; they did not. See §2.8/§2.9. |

---

## 2. Aggregates and entities

### 2.1 Store & fiscal identity

**`store`** — the business entity operating TindaFlow. V1 is single-store;
every table below is `store_id`-scoped so multi-store (Phase 2) is
additive.

**`store_settings`** — 1:1 with `store`. Business identity fields
(`business_name`, `registered_name`, `business_address`, `tin`,
`branch_code`, `invoice_header`, `invoice_footer`, `telephone`, `email`) per
Module B. Holds **no** tax-registration or per-terminal fiscal data (see
below) — those live in their own effective-dated entities specifically so a
change to one doesn't require touching this table.

**`tax_registration`** — effective-dated history of a store's tax
registration: `store_id`, `registration_type` (`VAT`|`NON_VAT`),
`effective_from`, `effective_to` (nullable = current). Exactly one row per
store has `effective_to IS NULL` at any time. A registration change inserts
a new row and closes the previous one; it never mutates
`registration_type` on an existing row. Unchanged from revision 1.

**`terminal`** — a physical or logical POS station. `store_id`,
`terminal_code`, `status` (`ACTIVE`|`INACTIVE`|`DECOMMISSIONED`),
`activated_at`. Unchanged from revision 1.

**`fiscal_installation`** — **revised in this pass.** Represents an
accredited software/hardware installation: `store_id` (not `terminal_id`),
`deployment_model` (`STANDALONE`|`SERVER_CONNECTED` — matching BIR
eAccReg's own "POS Standalone" / "POS with SERVERCONS" distinction),
`machine_serial_number` (nullable), `software_version`, `min` (nullable,
Machine Identification Number), `ptu_number`/`ptu_date` (nullable),
`accreditation_number`/`accreditation_date` (nullable), `installed_at`,
`superseded_at` (nullable = current). All fields nullable/mostly-empty in
V1 since TindaFlow is not accredited; a real accreditation event inserts a
new row rather than mutating history (mirrors RMO 24-2023's
major-enhancement → re-accreditation distinction —
[BIR-007](../01-research/bir-reference-register.md)).

**`terminal_fiscal_installation`** — **new in this pass.** The join/history
table resolving the terminal↔installation relationship without forcing a
cardinality: `terminal_id`, `fiscal_installation_id`, `effective_from`,
`effective_to` (nullable = current). A `STANDALONE` deployment produces one
terminal ↔ one installation over time (effectively 1:1, expressed through
this table rather than a schema constraint); a `SERVER_CONNECTED`
deployment can have several `terminal_id`s pointing at the same
`fiscal_installation_id` concurrently. **Stage 3 will decide the concrete
infrastructure implications** (e.g., whether V1's default seed data models
a single STANDALONE terminal); Stage 2 only commits to the shape being
capable of both, and to `fiscal_installation` metadata never being folded
into `store` or `shift`.

### 2.2 Identity & access

**`user`** — `store_id`, `name`, `email`/`username`, `password_hash`,
`role` (`ADMIN`|`MANAGER`|`CASHIER`), `active`.

**Capability catalog (code-level, not a database table).** Authorization is
expressed as named capabilities checked via a single helper —
conceptually `can(user, CAPABILITY)` — never as `if ($user->role ===
'MANAGER')` scattered through controllers/services. The V1 capability
catalog:

```
SALE_VOID
SALE_VOID_APPROVE      -- approve another user's void request
SALE_REFUND
SALE_REFUND_APPROVE
PRICE_OVERRIDE
DISCOUNT_OVERRIDE
STOCK_ADJUST
CASH_OUT
REPORT_VIEW
STORE_SETTINGS_MANAGE
FISCAL_DAY_CLOSE       -- trigger Z-Reading / end-of-day closure
CATALOG_MANAGE         -- create/edit product, category, brand records
AUDIT_VIEW             -- read audit_event records
JOURNAL_VIEW           -- read electronic_journal_entry records
USER_MANAGE            -- create/edit user accounts and roles
TERMINAL_MANAGE        -- enroll/decommission terminals
FISCAL_CONFIGURATION_MANAGE  -- edit tax_registration / fiscal_installation records
```

**Amendment (Stage 4 remediation, 2026-09-16):** the six capabilities
below `FISCAL_DAY_CLOSE` were added to close a gap found while writing
the Stage 4 API contract — every one of the contract's 78 operations
needed a named capability, and six administrative surfaces (catalog
management, audit/journal read access, user management, terminal
management, fiscal configuration) had no corresponding entry in the
original list. This is vocabulary completion only: no new authorization
*behavior*, no change to the fixed ADMIN/MANAGER/CASHIER role structure,
and no change to any financial invariant in this document. The
role→capability mapping for the full 17-capability catalog is maintained
in `docs/05-api/api-design.md` §4 (a Stage 2/6 code-level concern per
this section, not restated here to avoid two documents drifting again).

Each capability maps to a fixed set of roles in a single, centralized
code-level table (e.g., a PHP enum/config array, not a database table) —
this is the "future migration path" placeholder: if a later phase needs
per-store-configurable roles, that code-level map becomes a `role_capability`
database table without changing any call site, because call sites already
ask `can(user, CAPABILITY)` rather than inspecting `role` directly. **No
dynamic role/permission tables exist in V1.**

### 2.3 Catalog

**`category`**, **`brand`** — simple lookup tables, `store_id`-scoped.
Unchanged.

**`product`** — `store_id`, `sku`, `barcode` (nullable, unique per store),
`name`, `description`, `category_id`, `brand_id`, `unit_of_measure`,
`cost`, `selling_price`, `tax_class`
(`VATABLE`|`VAT_EXEMPT`|`ZERO_RATED`|`NON_VAT`), `track_inventory`,
`reorder_level`, `active`. Unchanged.

**`product_barcode`** — alternate/multipack barcodes per product.
Unchanged.

### 2.4 Inventory

**`inventory_location`**, **`stock_movement`**, **`stock_balance`** —
unchanged from revision 1. See [invariants.md](invariants.md) for the
append-only/derived-balance rules, restated and cross-referenced from the
new Refund disposition rules in §2.9 below.

### 2.5 Fiscal day, shift, and readings

This is the section most substantively changed in this pass. Two different
accountability boundaries exist and must not be conflated:

```
Terminal
├── FiscalDay  (terminal's operating/fiscal business day; Z-Reading boundary)
│   └── ZReading
│
└── Shift      (cashier/drawer accountability; X-Reading boundary)
    └── XReading
```

**`fiscal_day`** — a terminal's operating business day and the End-of-Day
(EOD) / Z-Reading closure boundary. `store_id`, `terminal_id`,
`business_date`, `opened_at`, `closed_at` (nullable until closed), `status`
(`OPEN`|`CLOSED`). **`business_date` is a business-day label, not a
calendar-date filter** — a store operating across midnight has a single
`fiscal_day` spanning both calendar dates; nothing in the system ever
infers "which fiscal day" a record belongs to from a calendar-date
comparison on a timestamp (see [invariants.md](invariants.md) and §2.7's
`sale.fiscal_day_id`).

**`shift`** — cashier/drawer accountability, scoped to one terminal and one
cashier: `terminal_id`, `fiscal_day_id` (the currently-open fiscal day this
shift occurred within — persisted explicitly, not inferred),
`cashier_id`, `opening_cash`, `opened_at`, `status` (`OPEN`|`CLOSED`),
`expected_cash`, `declared_cash`, `variance`, `cash_sales`,
`non_cash_sales`, `refunds_total`, `cash_in_total`, `cash_out_total`,
`closed_at`. A terminal's `fiscal_day` typically spans multiple shifts
(e.g., a morning and an evening cashier before end-of-day closure).

**`cash_movement`** — unchanged: `shift_id`, `type`
(`CASH_IN`|`CASH_OUT`), `amount`, `reason`, `authorized_by` (nullable),
`created_at`.

**`x_reading`** — the Cashier's Accountability Report / End-of-Shift
Report. `terminal_id`, `shift_id`, `cashier_id`, `from_at`/`to_at` (the
transaction range covered), `generated_at`, `generated_by`,
`totals_snapshot` (JSONB — the computed accountability figures at
generation time: cash/non-cash sales, refunds, cash in/out, expected cash,
etc.). An X-Reading can be pulled **on demand**, any number of times during
an open shift (real cash-register practice — an X-Reading does not close or
reset anything), and is also generated automatically at shift close.
**Append-only**, like `audit_event`.

**`z_reading`** — the End-of-Day (EOD) Report. `terminal_id`,
`fiscal_day_id`, `business_date`, `from_at`/`to_at`, `generated_at`,
`generated_by`, `totals_snapshot` (JSONB — fiscal closure totals: gross
sales, VAT breakdown, voids, refunds, accumulated grand total sales as of
this closure, etc.). Exactly **one** `z_reading` row is created, at the
moment a `fiscal_day` transitions `OPEN → CLOSED`, and never again for that
`fiscal_day`. **Append-only.**

**Reproducibility, not independent authority (both readings).** Neither
`x_reading` nor `z_reading` is an independently editable source of
financial truth. Their `totals_snapshot` is a point-in-time computed
capture — useful because a generated report is itself a compliance
artifact worth preserving exactly as shown at that moment — but nothing
downstream (accumulated totals, next period's figures, other reports) is
ever computed *from* a stored reading. Everything is always recomputable
from `sale`, `payment`, `void`, `refund`, and `cash_movement` directly. If a
`z_reading` row were deleted and regenerated from the same source data, the
result must be identical.

### 2.6 Invoice numbering

**`invoice_series`** — `store_id`, `series_code`, `prefix`,
`current_number`, `starting_number`, `ending_number` (nullable),
`status` (`ACTIVE`|`CLOSED`), `version`, plus **`fiscal_installation_id`
(added by Stage 6B implementation-discovered amendment, 2026-09-17 —
see "Series-to-installation binding" below)**. See
[invariants.md](invariants.md) for the full allocation invariant set,
expanded slightly in this pass (§ Invoice numbering, items on refund/void
non-allocation and series-reset prohibition).

**Counter bootstrap semantics (Stage 6B amendment, 2026-09-17).**
`current_number` holds the last allocated serial (ADR-004's own
"increment `current_number`; use the new value" mechanic, unchanged).
`starting_number` is the **first serial actually issuable** from the
series — a fresh, never-used series must therefore be configured with
`current_number = starting_number - 1`, so its first real allocation
(`current_number + 1`) yields `starting_number` exactly, never skipping
it. `starting_number >= 1` always (a series' first issuable serial is
never zero or negative — `0` exists only as internal pre-allocation
counter state for a series whose `starting_number = 1`, never as an
issuable `invoice_number` itself).

**Series-to-installation binding (Stage 6B amendment, 2026-09-17).**
Stage 6B's implementation of `InvoiceSeriesAllocator` discovered that
`invoice_series` was `store`-scoped only, while
`constraint-register.md`'s `DB-INV-018` already established — and
`invoices`' own composite-FK design already assumed — that **a store
may legitimately have more than one `invoice_series` row**. Nothing in
the frozen model told the allocator which one applies when a store has
more than one simultaneously `ACTIVE` series; `store_id` alone is
therefore an incomplete resolution key. `fiscal_installation` is
already the frozen model's own answer to "which numbering/fiscal
identity applies for this transaction" for every OTHER fiscal-identity
concern (`terminal_fiscal_installation`'s effective-dated join resolves
a terminal to its current installation for exactly this reason;
`invoice.fiscal_installation_id` already records which installation was
in effect at issuance) — `invoice_series` was the one place this
identity chain was missing a link. The rule, corrected here:

- **`invoice_series` belongs to `fiscal_installation`, not directly to a
  bare `store` alone** — `invoice_series.fiscal_installation_id` is
  required (never null), and `invoice_series.store_id` is retained
  alongside it (denormalized, matching this schema's existing pattern
  for `terminal_fiscal_installation`/`invoices`) so store-level
  coherence can still be enforced by a composite FK against
  `fiscal_installations(store_id, id)`, without removing the direct
  `store_id` column store-scoped reporting/administration already
  relies on.
- **A store may have multiple `invoice_series` rows through multiple,
  or historical, `fiscal_installation`s** — this is not new; it is
  exactly what `DB-INV-018` already anticipated. What's new is that
  resolution now has an unambiguous key: **at most one `ACTIVE`
  `invoice_series` per `fiscal_installation`** (mirroring the same
  "current/active singleton, history preserved" pattern this schema
  already applies to `shift`/`fiscal_day`/`terminal_fiscal_installation`).
- **Resolution chain for a finalizing sale**: `terminal` → (via
  `terminal_fiscal_installation`'s effective-dated mapping, resolved at
  `sold_at`) → the terminal's currently-effective `fiscal_installation`
  → its one `ACTIVE` `invoice_series` → allocate the next serial. The
  browser/cashier never selects a `fiscal_installation` or
  `invoice_series` directly; both are server-resolved from the
  authenticated terminal's own current context.
- This directly supports both deployment shapes §2.1 already commits
  to without a further schema change: a `STANDALONE` deployment (one
  terminal, one installation, one series) and a `SERVER_CONNECTED`
  deployment (several terminals sharing one installation, and therefore
  one series, concurrently) — the exact asymmetry
  `terminal_fiscal_installation`'s own partial unique index ("one
  *current* installation per terminal, never one terminal per
  installation") was already designed to express.

### 2.7 Sale, invoice, payment

**`sale`** — the transaction aggregate root. **The `sale` row does not
exist until checkout finalization commits** — there is no persisted
`DRAFT` status (see [state-machines.md](state-machines.md) §1 for the
revised lifecycle). Fields: `uuid`, `transaction_number`, `store_id`,
`terminal_id`, `fiscal_day_id` (persisted explicitly at finalization, never
inferred later from `sold_at`), `shift_id`, `cashier_id`, `sold_at`,
`subtotal`, `order_level_discount_amount` (**new in this pass** — the
single sale-wide discount amount, before allocation; see **Order-level
discount allocation** below), `discount_total` (= `SUM(sale_item.
line_discount_amount) + order_level_discount_amount` — the aggregate figure
used for Module L's Discount Report; not itself the allocation input),
`taxable_sales`, `vat_exempt_sales`, `zero_rated_sales`, `vat_amount`,
`non_vat_sales` (**added by Stage 6A implementation-discovered
amendment, 2026-09-17** — see §4's "Non-VAT tax-summary model" below for
the full rule this field exists to satisfy), `grand_total`, `status`
(`COMPLETED`|`VOIDED`|`PARTIALLY_REFUNDED`|`REFUNDED` — **`DRAFT`
removed**), `idempotency_key`, and optional buyer-capture fields
(`buyer_name`, `buyer_address`, `buyer_tin`, `buyer_business_style` —
all nullable, populated only when a buyer requests their details on the
invoice per Module G).

**`sale_item`** — **expanded in this pass** to preserve enough immutable
financial detail to reconstruct each line's original economics without
ever recomputing from current product/tax/discount configuration. Identity
snapshot fields (unchanged): `product_name_snapshot`, `sku_snapshot`,
`barcode_snapshot`, `unit_of_measure_snapshot`. Sequencing: `line_number`
(**new** — the line's stable 1-based position within the sale, assigned at
finalization; used as a deterministic tie-breaker in discount/tax
allocation — see below — and incidentally gives display ordering for
free). Financial snapshot fields:

- `quantity` — **Quantity**-typed (not `Money`), see §3.2.
- `unit_price_snapshot` — the unit price in effect at sale time.
- `gross_line_amount` — `quantity × unit_price_snapshot`, rounded to 2
  decimals (a materialization point — see §3.1).
- `line_discount_amount` (renamed from `line_discount`) — a discount
  applied to this specific line only (e.g., a promo price override),
  independent of any order-level discount.
- `order_discount_eligible` (**new**, boolean, default `true`) — whether
  this line participates in the order-level discount's proportional
  allocation. V1 has no rules engine that computes this automatically; it
  is a flag set explicitly by whoever applies the order-level discount
  (see **Do not overbuild** note below) and defaults to eligible for every
  line.
- `allocated_order_discount_amount` (**new**) — this line's deterministic
  share of `sale.order_level_discount_amount`, computed once at
  finalization by the allocation algorithm below. Always `0` for lines
  with `order_discount_eligible = false`, and always `0` for every line if
  `sale.order_level_discount_amount = 0`.
- `net_line_amount` (**new**, replaces the old ambiguous `line_total`) —
  `gross_line_amount − line_discount_amount − allocated_order_discount_amount`.
  This is this line's final, all-discounts-applied, VAT-**inclusive**
  economic amount: what the customer actually paid for this line, and the
  figure every downstream calculation (tax decomposition, refund maximum)
  is derived from. **Design note:** the brief's requested `net_line_amount`
  and `final_line_amount` concepts are the same value in this design —
  TindaFlow uses one column, not two, to avoid a redundant duplicate field;
  every semantic need either name implies is satisfied by
  `net_line_amount`.
- `tax_classification_snapshot` (`VATABLE`|`VAT_EXEMPT`|`ZERO_RATED`|
  `NON_VAT`), `tax_rate_snapshot` — unchanged.
- `taxable_base` (**new**) and `tax_amount` (**new**) — this line's share
  of the sale's tax decomposition, always satisfying `taxable_base +
  tax_amount = net_line_amount`. For a `VATABLE` line, `tax_amount` is
  allocated from `sale.vat_amount` by the same allocation algorithm (see
  below); for `VAT_EXEMPT`/`ZERO_RATED`/`NON_VAT` lines, `tax_amount = 0`
  and `taxable_base = net_line_amount` directly (nothing to decompose).
- `unit_cost_snapshot` — unchanged, nullable.

### 2.7a Order-level discount allocation (deterministic algorithm)

Rejected wording from the previous pass ("an order-level discount is
applied once... never prorated across lines") is **retracted**. A sale-wide
discount must be traceable to exact per-line amounts, because a later
partial refund, a mixed VATable/exempt basket, or an audit reconstruction
must never re-derive historical discount attribution from *today's* rules —
only from what was actually recorded at finalization.

**The algorithm (the "Deterministic Proportional Allocation" method — the
same procedure is reused for tax allocation further below):**

Given a total amount `T` to distribute across a set of eligible items, each
with a basis value `b_k` (here, for discount allocation: `T =
sale.order_level_discount_amount`; eligible items = `sale_item` rows with
`order_discount_eligible = true`; `b_k` = that line's `gross_line_amount −
line_discount_amount`, i.e., its net-of-line-discount amount before the
order-level discount is applied), and `B = SUM(b_k)` over eligible items:

1. Compute each item's **exact** share `s_k = T × b_k ÷ B` at intermediate
   decimal precision (≥6 decimal places) — no rounding yet.
2. Compute `floor_k` = `s_k` truncated **down** to 2 decimal places (not
   round-half-up — specifically rounded down, so the sum of floors never
   exceeds `T`).
3. Compute the residual `r = T − SUM(floor_k)`, expressed as a whole number
   of centavos. By construction, `r` is a non-negative integer strictly
   less than the number of eligible items.
4. Sort eligible items by descending fractional remainder `(s_k −
   floor_k)`; break ties **deterministically** by ascending
   `sale_item.line_number` — never by iteration order, insertion order, or
   any non-reproducible tiebreaker.
5. Distribute one additional centavo to each of the first `r` items in that
   sorted order.
6. Each item's final allocated amount = `floor_k` (+1 centavo if selected
   in step 5).

By construction, `SUM(final allocated amounts) = T` **exactly** — this is
the classic "largest remainder" (Hare–Niemeyer) apportionment method,
chosen specifically because it is deterministic, reproducible, and exact by
construction rather than requiring a corrective fudge afterward.

**Degenerate case:** if there are no `order_discount_eligible` lines, or
their combined basis `B = 0`, then `sale.order_level_discount_amount` must
also be `0` — finalization rejects a non-zero order-level discount with no
valid basis to allocate it against, rather than silently dropping it or
assigning it arbitrarily.

**Second use of the same algorithm — per-line tax attribution:** after
`sale.vat_amount` is computed for the VATable category via sum-then-decompose
(§3.1), it is allocated back down to individual VATable `sale_item` rows
using the identical procedure: `T = sale.vat_amount`, eligible items = the
`VATABLE`-classified lines, `b_k` = each such line's `net_line_amount`, `B`
= their sum (the VAT-inclusive VATable subtotal). Each VATable line's
`tax_amount` is its allocated share; `taxable_base = net_line_amount −
tax_amount` follows automatically (no separate rounding needed) and sums
correctly to `sale.taxable_sales` by construction.

**Do not overbuild:** this correction authorizes exactly one thing — making
V1's single order-level discount deterministic and refund-safe. It does
**not** authorize promotion stacking, coupons, loyalty rewards,
buy-one-get-one, mix-and-match, campaign scheduling, or statutory discount
logic (e.g., automated Senior Citizen/PWD computation) — all of those
remain out of scope for V1 unless separately approved. `order_discount_eligible`
is a plain boolean a human sets when applying the one discount V1 supports,
not a rules engine.

**`payment`** — unchanged: `sale_id`, `method`, `amount`,
`reference_note`, `recorded_at`.

**`invoice`** — **expanded in this pass.** 1:1 with a `COMPLETED` sale.
`sale_id`, `invoice_series_id`, `invoice_number`, `issued_at`, `terminal_id`,
`fiscal_installation_id` (nullable — which accredited installation, if any,
was in effect at issuance), plus:

- **Flat snapshot columns** (promoted for indexing/list-display/report
  convenience only): `seller_registered_name_snapshot`,
  `tax_registration_type_snapshot`, `terminal_code_snapshot`.
- **`invoice_snapshot_json`** (JSONB, renamed from `structured_json`) — the
  complete, immutable, canonical record of everything relevant at
  issuance: full seller identity (registered/trade name, address, TIN,
  branch code, VAT/Non-VAT status), buyer information (if captured),
  invoice identifiers (UUID, transaction number, invoice number, series),
  terminal/fiscal identifiers, accreditation/PTU/MIN/software-version
  metadata as of issuance (or explicitly null/inactive fields if
  unaccredited), and the full item/tax/payment representation. This is
  the same canonical structured-invoice concept the governing brief calls
  for in Section 5.10, but its defining property here is **historical
  immutability**, not just e-invoicing readiness.

Only the three flat columns above exist outside the JSON payload — every
other field that "necessarily insufficient sale_item snapshots" would have
needed lives in `invoice_snapshot_json` rather than as dozens of additional
flat columns, to avoid schema bloat for data that is only ever read as a
whole document (see [invariants.md](invariants.md) for the "reprint reads
only the snapshot" rule).

### 2.8 Void

**`void`** — **expanded in this pass (Stage 2 amendment, approved
2026-09-16 — see the Stage 3 remediation gap report).** One row per void
**attempt**: `sale_id`, `requested_by`, `reason`, `status`
(`REQUESTED`|`APPROVED`|`REJECTED`|`VOIDED`), `approved_by` (nullable),
`requested_at`, `resolved_at`, plus three **new processing-context
fields**: `terminal_id`, `fiscal_day_id`, `shift_id` (all non-nullable
once the void reaches `VOIDED`; populated at the moment of **execution**
— the `APPROVED → VOIDED` transition — not at request time, since a
request and its approval/execution are not guaranteed to happen at the
same terminal).

**Processing context is independent of the original sale's context.**
`void.terminal_id`/`fiscal_day_id`/`shift_id` identify *where and when the
void itself was executed* — never confused with, and never required to
match, `sale.terminal_id`/`fiscal_day_id`/`shift_id` (the *original
sale's* context). A void executed today against a sale from a prior,
already-closed fiscal day is attributed to **today's** processing
`fiscal_day`/`shift` for journaling, audit, and shift-accountability
purposes — it is never retroactively inserted into the original,
now-closed `fiscal_day`. (In practice, per the eligibility policy below,
a void's *original* sale's fiscal day must still be `OPEN`, so the two
fiscal days will often coincide — but they are not the same field, and a
multi-terminal store can process the void from a different terminal, and
therefore a different `fiscal_day` row, than the one the sale was rung up
on.)

**What changed in the prior pass: eligibility.** A void may
only be requested/approved while the sale's own eligibility conditions
hold — see the **Void eligibility policy** below and
[invariants.md](invariants.md).

**Void eligibility policy (TindaFlow product policy — see explicit
disclaimer).** A `COMPLETED` sale may be voided only when **all** of the
following hold at the moment of the request/approval:

1. `sale.status = COMPLETED` (not already `VOIDED`, `PARTIALLY_REFUNDED`, or `REFUNDED`).
2. The **original sale's** `fiscal_day` (via `sale.fiscal_day_id`) has `status = OPEN` — i.e., no Z-Reading/EOD closure has occurred for that terminal's business day yet.
3. No `refund` row exists against the sale with `status = COMPLETED` (any accepted refund permanently forecloses voiding — see §2.9).
4. The requesting/approving user holds the `SALE_VOID`/`SALE_VOID_APPROVE` capability as applicable.
5. A non-empty `reason` is supplied and is audited.
6. **(new)** At the moment of **execution**, the **executing terminal's own** `fiscal_day` has `status = OPEN`, and the executing user has an `OPEN` `shift` at that terminal — this is the processing-context precondition, distinct from condition 2's check on the *original* sale's fiscal day, and is what makes `void.terminal_id`/`fiscal_day_id`/`shift_id` valid, attributable values rather than a reference to something already closed.

Once a sale's `fiscal_day` has closed, corrections must go through the
**Refund** workflow instead of Void — there is no "late void."

**This is TindaFlow product policy, not a claim about a universal
statutory BIR void rule.** No BIR issuance reviewed in
[bir-reference-register.md](../01-research/bir-reference-register.md)
specifies a void-eligibility deadline in these terms; this policy is a
deliberate business rule chosen to keep Void semantically distinct from
Refund (a full, same-fiscal-day reversal vs. a post-closure correction) and
must never be presented to a store owner, in documentation or UI, as "the
BIR rule for voids." **Note on cross-midnight operation:** because
eligibility is keyed to `fiscal_day.status`, not a calendar-date
comparison, a store that stays open past midnight is unaffected — the
`fiscal_day` remains `OPEN` (and therefore voidable) until its own explicit
EOD closure, regardless of what calendar date the wall clock shows.

### 2.9 Refund

**`refund`** — **expanded in this pass (Stage 2 amendment, approved
2026-09-16).** `sale_id`, `requested_by`, `reason`, `status`
(`REQUESTED`|`APPROVED`|`REJECTED`|`COMPLETED`), `approved_by`
(nullable), `refunded_at`, `refund_total`, plus three **new
processing-context fields**: `terminal_id`, `fiscal_day_id`, `shift_id`
(non-nullable once `COMPLETED`; populated at the `APPROVED → COMPLETED`
transition — the moment of execution). **New cross-invariant:** a
`refund` cannot be requested against a `sale` with `status = VOIDED` — a
voided sale is fully reversed already and is not a valid refund target.

**Processing context, independent of the original sale's context (same
principle as Void, §2.8).** Unlike Void, Refund has **no** eligibility
condition requiring the original sale's `fiscal_day` to still be `OPEN` —
a refund is explicitly the *post-closure* correction path. Its own
`terminal_id`/`fiscal_day_id`/`shift_id` will therefore routinely differ
from the original sale's: exactly the brief's own example — an invoice
sold Monday and refunded Friday references Monday's `sale` unchanged, but
the `refund` row is attributed to Friday's processing `fiscal_day` and
shift/cashier accountability, never inserted into Monday's (closed)
`fiscal_day`. At the moment of execution, the *executing* terminal's own
`fiscal_day` must be `OPEN` and the executing user must have an `OPEN`
`shift` at that terminal — the same structural precondition as Void's new
condition 6 and Sale's existing invariant #35, applied here to Refund for
the first time.

**`refund_item`** — `refund_id`, `sale_item_id`, `quantity_returned`,
`disposition` — **expanded in this pass** from two values to four:
`RETURN_TO_STOCK` | `DAMAGED` | `EXPIRED` | `DISPOSED` — plus
`unit_refund_amount`.

**Refund basis — derives only from the original `sale_item` snapshot.**
The maximum refundable consideration for a `sale_item` is its
`net_line_amount` (the fully-discount-allocated, post-order-discount,
VAT-inclusive amount actually charged — see §2.7's Order-level discount
allocation). A refund **never** recomputes discount attribution using
current promotion/discount configuration, and **never** re-divides the
original sale-level discount again at refund time — both figures are
already fixed, per line, in `sale_item.allocated_order_discount_amount` and
`net_line_amount` since finalization.

**Partial-quantity refund rounding (deterministic, cumulative-recompute
rule):** for a `sale_item` with original `quantity` and `net_line_amount`,
a refund event returning some (possibly not all) of that line computes:

1. `owed_after_this_event = round(net_line_amount × cumulative_quantity_returned ÷ quantity, 2 decimals)` — where `cumulative_quantity_returned` includes this event's quantity plus every prior accepted refund's quantity for this same `sale_item`.
2. `this_event's unit_refund_amount = owed_after_this_event − SUM(unit_refund_amount of all prior accepted refund_item rows for this sale_item)`.

This "recompute the running total, then subtract what's already been paid
out" pattern (rather than computing each partial increment independently)
guarantees that once a line is fully returned across one or more partial
refunds, the sum of all its `unit_refund_amount` values equals
`net_line_amount` **exactly** — any rounding residual is absorbed into
whichever refund event completes the line, never left to drift across
partial refunds.

**Disposition-to-stock-movement rule (generalized):** only
`RETURN_TO_STOCK` generates a `SALE_RETURN` stock movement (+quantity to
sellable stock). `DAMAGED`, `EXPIRED`, and `DISPOSED` all generate **no**
stock movement — the unit was already deducted from sellable stock at the
original sale (`SALE` movement) and none of these three dispositions
return it to sellable inventory. They exist purely to record *why* a
returned unit is not restocked, for shrinkage/damage/loss reporting and
audit purposes — not to select among different stock-movement types.
**The system never silently assumes `RETURN_TO_STOCK`** — disposition is a
required field on every `refund_item`, chosen explicitly at refund time,
never defaulted.

**`refund_settlement`** — **new entity (Stage 2 amendment, approved
2026-09-16).** Records how money is actually returned to the customer,
closing the gap the Stage 3 remediation review identified: `refund_total`
alone said *how much* but never *how*. Fields: `id`, `refund_id` (FK),
`payment_method` (`CASH`|`GCASH`|`MAYA`|`CARD`|`OTHER` — the same enum as
`payment.method`), `amount`, `processed_at`, `external_reference`
(nullable — e.g. a GCash refund reference number). A single `refund` may
have more than one `refund_settlement` row (e.g., a refund partially
returned as cash and partially as a GCash transfer), created together,
atomically, in the same transaction as the refund's `APPROVED →
COMPLETED` transition.

**Deliberately not duplicated onto `refund_settlement`:** `terminal_id`,
`shift_id`, and `processed_by` are **not** repeated here — a refund's
settlement rows are created as part of that single refund's completion
transaction, so they inherit their processing context from the parent
`refund` (§2.9's new fields) rather than carrying redundant copies. This
is the "smallest correction" version of the concept; a future phase could
promote these to `refund_settlement`'s own columns if a real need for
per-settlement-row attribution independent of the parent refund ever
arises, but no such need exists in V1 (a refund's settlement always
happens in one execution context).

**Cash-drawer effect, explicit per method.** A `CASH`-method
`refund_settlement` reduces the processing shift's expected physical
drawer cash (it factors into `shift.refunds_total`/`expected_cash` at
close, per §2.5, alongside cash sales and cash movements). A non-`CASH`
`refund_settlement` (`GCASH`/`MAYA`/`CARD`/`OTHER`) does **not** affect
physical drawer cash — it affects only the store's overall refund
reporting (Module L's Refund Report), not the cashier's counted-cash
accountability. This is also what makes `shift.refunds_total`
(already a Stage 2 field, previously impossible to compute without a way
to attribute a refund to a shift at all) an actually-computable value.

### 2.10 Audit & electronic journal

**`audit_event`** — the broad security/operations log. Event-type catalog
(extended in this pass to cover the new Fiscal Day / Reading / approval
workflows): `SALE_FINALIZED`, `SALE_VOID_REQUESTED`, `SALE_VOIDED`,
`SALE_VOID_REJECTED`, `REFUND_REQUESTED`, `REFUND_APPROVED`,
`REFUND_REJECTED`, `REFUND_CREATED` (= completed), `PRICE_OVERRIDE`,
`DISCOUNT_APPLIED`, `CASH_DRAWER_OPENED`, `SHIFT_OPENED`, `SHIFT_CLOSED`,
`FISCAL_DAY_OPENED`, `FISCAL_DAY_CLOSED`, `X_READING_GENERATED`,
`Z_READING_GENERATED`, `STOCK_ADJUSTED`, `USER_LOGIN`, `USER_LOGOUT`,
`INVOICE_REPRINTED`, `SETTINGS_CHANGED`. Other fields unchanged:
`actor_user_id`, `terminal_id`, `entity_type`/`entity_id`,
`before_metadata`/`after_metadata`, `reason`, `request_id`, `occurred_at`.
**Append-only.**

**`electronic_journal_entry`** — the fiscally-scoped, export-shaped
subset. **Field names clarified in this pass** to make the link back to
the originating fact explicit and unambiguous: `store_id`, `terminal_id`,
`event_type` (`INVOICE`|`VOID`|`REFUND`|`X_READING`|`Z_READING`|
`SHIFT_OPENED`|`SHIFT_CLOSED`|`CASH_IN`|`CASH_OUT`|`STOCK_ADJUSTED`),
`source_type`/`source_id` (the authoritative originating record — e.g.
`source_type = 'sale'`, `source_id = <sale.id>` for an `INVOICE` entry),
`audit_event_id` (nullable FK — links to the corresponding `audit_event`
row when one exists, so the two logs are traceably related rather than
independently-maintained copies of the same fact), `payload_json` (a
snapshot sufficient to reconstruct the entry without joining back to
mutable tables), `occurred_at`. Written in the **same database
transaction** as the domain event it records. **Append-only.**

**Relationship between the two logs (clarified, not changed in kind):**
`electronic_journal_entry` is a **projection/representation** of
authoritative domain events — it is never a second, independently
authoritative financial ledger. Every fiscally-journalable event produces
exactly one `electronic_journal_entry` row (enforced by a uniqueness
constraint on `(source_type, source_id, event_type)` — see
[invariants.md](invariants.md)), optionally cross-referencing the
`audit_event` row for the same fact. `audit_event` remains the broader log
(it also covers non-fiscal events like `USER_LOGIN`/`SETTINGS_CHANGED`
that never appear in the journal).

### 2.11 Explicitly deferred: CheckoutSession / CartSession (not built in V1)

**No `checkout_session`/`cart_session` table exists in V1.** An unfinished
shopping cart lives in React state and/or browser `localStorage` for
recovery convenience — it is not a server-side entity, has no aggregate,
and is not part of this domain model's persisted schema.

This subsection exists only to record constraints for whoever implements a
future server-side cart-recovery feature, so it is never accidentally built
as a variant of `sale`: a future `CheckoutSession`/`CartSession` aggregate
must **not** consume an invoice number, affect inventory, appear in
accumulated sales, create fiscal journal entries, appear in X/Z-Reading
totals, create `payment` records, or create audit history equivalent to a
completed sale. If/when built, it is a wholly separate, lower-stakes
aggregate that the finalize-sale operation *reads from* to build a real
`sale` — it is never itself promoted in place into a `sale` row.

### 2.12 Future discount extensibility (not built in V1)

If a future phase needs multiple overlapping or statutory discount
programs (coupons, loyalty rewards, buy-one-get-one, mix-and-match,
campaign scheduling, automated Senior Citizen/PWD computation, etc.), that
is the point at which explicit `DiscountApplication`/`DiscountAllocation`
aggregates would be introduced to represent *which* program applied *what*
amount to *which* lines, as a richer alternative to today's single
`order_level_discount_amount` + per-line allocation snapshot. **Do not
introduce those aggregates now.** For V1, the immutable per-`sale_item`
allocation fields defined in §2.7 (`allocated_order_discount_amount`,
`net_line_amount`, `taxable_base`, `tax_amount`) are sufficient, because
they already satisfy every invariant a richer model would also need
(exact reconciliation, immutability, refund-safety) for the one discount
mechanism V1 actually supports.

---

## 3. Money & Quantity model

### 3.1 Money — representation and precision

- **Single currency for V1:** Philippine Peso only; every `Money`-typed
  field still carries an explicit currency code so multi-currency is
  additive later.
- **No binary floating-point arithmetic anywhere** for an authoritative
  monetary calculation — frontend or backend.
- **Intermediate calculation precision (revised in this pass):** earlier
  guidance said "integer centavos internally," which breaks down wherever
  a percentage (VAT decomposition, a percentage discount) produces a
  fraction of a centavo before the final rounding step. **Intermediate
  monetary calculations use decimal-safe arithmetic at a working scale of
  at least 6 decimal places** (e.g., PHP `bcmath`/`brick/money`'s
  arbitrary-precision decimal type — never a native float, and not a bare
  integer-centavo accumulator for intermediate steps). Only the **final,
  materialized** value at each defined materialization point (below) is
  rounded to 2 decimal places for storage/display, using the rounding mode
  specified below (round-half-up in general; the discount/tax allocation
  algorithm's intermediate floor step is a deliberate, narrower exception —
  see the algorithm in §2.7).
- **Storage precision — reviewed per field category, not applied
  uniformly:**
  - Per-line and per-sale monetary fields (`sale_item.gross_line_amount`,
    `sale_item.net_line_amount`, `sale.subtotal`, `sale.grand_total`,
    `payment.amount`, `refund.refund_total`, etc.): `NUMERIC(12,2)`. A
    single convenience-store transaction will never approach the ₱10
    billion ceiling this allows.
  - Any future **cumulative/lifetime counter** that might be materialized
    as a stored running total (e.g., a per-terminal "accumulated grand
    total sales" counter, if one is ever persisted rather than always
    computed via `SUM()` over `sale`) uses `NUMERIC(18,2)` instead, since a
    lifetime counter accumulating over years has materially different
    range needs than a single transaction. V1 has no such stored counter
    yet — accumulated totals are computed on demand from `sale` — but the
    wider precision is specified now so it isn't chosen carelessly if one
    is added later.
- **Rounding mode:** round-half-up, applied only at a materialization
  point (never mid-calculation).
- **Materialization points** (where a value is rounded to 2 decimals and
  becomes the number of record) — **revised in this pass** to reflect
  per-line discount and tax allocation:
  1. `sale_item.gross_line_amount` (`quantity × unit_price_snapshot`,
     round-half-up).
  2. `sale_item.line_discount_amount` (entered directly, or computed from a
     percentage and rounded, at the point it's applied).
  3. `sale_item.allocated_order_discount_amount` for every
     `order_discount_eligible` line, via the **Deterministic Proportional
     Allocation** algorithm (§2.7) — exact by construction, not
     independently rounded per line.
  4. `sale_item.net_line_amount` requires **no separate rounding** — it is
     the exact subtraction `gross_line_amount − line_discount_amount −
     allocated_order_discount_amount` of three already-2-decimal values.
  5. The store-level tax decomposition fields (`taxable_sales`,
     `vat_exempt_sales`, `zero_rated_sales`, `vat_amount`) on `sale`, via
     sum-then-decompose (below).
  6. `sale_item.tax_amount` for each `VATABLE` line, via the same
     Deterministic Proportional Allocation algorithm, allocating
     `sale.vat_amount` back down from step 5. `sale_item.taxable_base =
     net_line_amount − tax_amount` again requires no separate rounding.
  7. `refund_item.unit_refund_amount` and `refund.refund_total`, via the
     cumulative-recompute-then-subtract rule (§2.9).
- **Tax rounding policy — sum-then-decompose, not decompose-then-sum
  (explicit, per owner request):** VAT is **not** computed and rounded
  independently on every line and then summed (that compounds rounding
  error across many lines). Instead: `net_line_amount` values (already
  exact, materialization point 4) for all `VATABLE` lines are summed first,
  at full decimal precision (no further rounding needed), and **that
  single VATable-sales sum** is decomposed once into net sales + VAT (net =
  gross ÷ 1.12; VAT = gross − net) at materialization point 5. The same
  category-then-decompose approach applies to `vat_exempt_sales`/
  `zero_rated_sales` (simple sums of `net_line_amount`, no decomposition
  needed since no VAT applies). The resulting `sale.vat_amount` is then
  allocated back to individual lines at materialization point 6 — this is
  what makes the sale-level figure and the per-line `tax_amount` figures
  agree exactly, rather than being two independent (and potentially
  inconsistent) computations.
- **Discount allocation policy (corrected in this pass — see §2.7 for full
  detail):** V1's single order-level discount is **deterministically
  allocated across eligible lines** using the Deterministic Proportional
  Allocation algorithm, **not** treated as an un-prorated single deduction
  at the sale level. This was a deliberate change from the previous pass,
  made specifically so that a partial refund, a mixed VATable/exempt
  basket, or an audit reconstruction always resolves to exact historical
  per-line figures instead of requiring a recalculation under current
  rules. A **line-level** discount (applied to one specific product line)
  is applied directly at that line, independent of the order-level
  allocation (materialization point 2).
- **Reconciliation invariant (by construction, not a fudge factor):**
  `SUM(sale_item.net_line_amount)` must equal `sale.grand_total` **exactly**
  — not `SUM(line amounts) − discount_total`, since the order-level
  discount is now already baked into each line's `net_line_amount` rather
  than subtracted once at the sale level. This holds in every case because
  of how materialization points 1–4 compose; there is no code path that
  "adjusts the last line by a centavo to make it balance." See
  [invariants.md](invariants.md) DISC-006 for the enforced form of this
  rule.
- **API serialization:** decimal strings (`"120.00"`), never a bare
  JSON number and never a formatted currency string with a ₱ symbol or
  thousands separator.
- **One canonical `Money` value object** wraps all of the above; no other
  part of the codebase performs monetary arithmetic directly on primitive
  types.

### 3.2 Quantity — a distinct value object from Money

- `Quantity` is **not** `Money` and must not share its type or its
  centavo-scale assumptions.
- Representation: a decimal value, non-negative, with a defined precision
  of up to **3 decimal places** (`NUMERIC(10,3)` at rest), paired with the
  product's `unit_of_measure`.
- V1's initial catalog (packaged convenience-store goods) will
  overwhelmingly use whole-number quantities (1 can, 2 packs), but the
  type itself supports fractional quantities (`0.500 kg`, `1.250 L`) from
  day one, so weighted/volume goods (a Phase 2 candidate requiring scale
  integration) are an additive feature later, not a schema migration or a
  `Money`-vs-`Quantity` type confusion to untangle then.
- `sale_item.quantity`, `stock_movement.quantity`, and
  `refund_item.quantity_returned` are all `Quantity`-typed, not integers
  and not `Money`.

## 4. Tax model

Unchanged in substance from revision 1, restated with the Invoice-snapshot
implication made explicit:

- `TaxRegistrationType` (`VAT`|`NON_VAT`) is store-level and effective-dated
  (`tax_registration`), never defaulted.
- `TaxClassification` (`VATABLE`|`VAT_EXEMPT`|`ZERO_RATED`|`NON_VAT`) is
  product-level, snapshotted onto `sale_item.tax_classification_snapshot`.
- **Confirmed explicitly in this pass:** a finalized `sale` **and its
  `invoice`** must never recompute historical tax using the store's
  *current* `tax_registration` — both the `sale_item` snapshots and the
  `invoice.invoice_snapshot_json`/`tax_registration_type_snapshot` column
  are populated once, at finalization/issuance, from whichever
  `tax_registration` row was effective at that moment, and are never
  refreshed afterward. A change from `NON_VAT` → `VAT` (or vice versa)
  affects only sales finalized after its `effective_from` date; every
  historical invoice remains exactly as issued.
- Non-VAT percentage-tax liability computation remains explicitly out of
  scope for the POS (accounting/bookkeeping concern, not a transaction-
  recording concern) — unchanged from revision 1.
- Product policy vs. legal minimum (TindaFlow invoices every completed
  sale, VAT or Non-VAT, regardless of amount) — unchanged from revision 1.

### 4a. Non-VAT tax-summary model (amendment, 2026-09-17 — implementation-discovered gap)

**Status of this amendment:** a genuine frozen-corpus contradiction was
found while implementing Stage 6A's `FinancialCalculator` against this
document: `TaxClassification` already includes `NON_VAT` as a full,
first-class value (§4 above, unchanged since revision 1), but `sale`'s
tax-summary fields (`taxable_sales`/`vat_exempt_sales`/
`zero_rated_sales`/`vat_amount`) had no representation for it, and per
BIR RR 7-2024/RMC 77-2024, a Non-VAT seller's sales are a distinct
concept from a VAT seller's `VAT_EXEMPT` category — a Non-VAT seller
who issues a VAT-shaped representation of a sale can incur real VAT and
surcharge liability, so folding `NON_VAT` into `VAT_EXEMPT` for
summary convenience would not be a harmless simplification. This
amendment closes that gap additively. The exact field name
(`non_vat_sales`) is TindaFlow's own modeling decision, not something
BIR mandates by that name — the regulatory basis is only the underlying
distinction between a VAT Invoice and a Non-VAT Invoice.

**The rule, keyed on the `tax_registration.registration_type` effective
at the sale's finalization (never the store's *current* registration —
per this section's own "never recompute historical tax" rule above):**

- **VAT-registered store.** Every `sale_item.tax_classification_snapshot`
  must be `VATABLE`, `VAT_EXEMPT`, or `ZERO_RATED` — `NON_VAT` is not a
  valid classification for a line finalized under a VAT registration.
  `sale.non_vat_sales = 0.00` always. The existing reconciliation holds
  unchanged: `taxable_sales + vat_amount + vat_exempt_sales +
  zero_rated_sales = grand_total`.
- **NON_VAT-registered store.** Every `sale_item.tax_classification_snapshot`
  must be `NON_VAT` — `VATABLE`/`VAT_EXEMPT`/`ZERO_RATED` are not valid
  classifications for a line finalized under a Non-VAT registration.
  `sale.taxable_sales = sale.vat_exempt_sales = sale.zero_rated_sales =
  sale.vat_amount = 0.00` always, and `sale.non_vat_sales = grand_total`
  — the entire finalized consideration is the Non-VAT sales figure,
  since V1 has no partial-VAT/partial-Non-VAT registration concept (a
  store's registration is one effective-dated value at a time, never
  split per-sale).
- **A finalized `sale` may never simultaneously have `non_vat_sales >
  0` and any of `taxable_sales`/`vat_exempt_sales`/`zero_rated_sales`/
  `vat_amount > 0`.** These two groups of buckets are mutually
  exclusive by construction, not merely by convention — see
  invariants.md TAX-NV-002/003 for the enforced form.
- **Historical sales are never reinterpreted.** If a store's
  registration later changes (`VAT → NON_VAT` or vice versa), every
  already-finalized `sale`'s tax-summary bucket assignment remains
  exactly as computed at finalization — a `NON_VAT` sale never becomes
  retroactively `VAT_EXEMPT`-shaped, and vice versa (TAX-NV-004).

## 5. Aggregate boundary summary

| Aggregate root | Owns (child entities modified only through the root) |
|---|---|
| `sale` | `sale_item`, `payment` (created together, atomically, at finalization; never edited afterward) |
| `invoice` | its own row only (1:1 with a completed `sale`; references `invoice_series` and, optionally, `fiscal_installation`, but does not own either) |
| `invoice_series` | `current_number` (mutated only via the atomic allocation operation) |
| `void` | itself (references `sale`, triggers `stock_movement` rows as a side effect) |
| `refund` | `refund_item`, `refund_settlement` |
| `fiscal_day` | `z_reading` (at most one, created at closure) |
| `shift` | `cash_movement`, `x_reading` (zero or more) |
| `stock_balance` | derived — not an aggregate root; a projection of `stock_movement` |
| `store` | `store_settings`, `tax_registration` (history), `terminal` (→ `terminal_fiscal_installation` → `fiscal_installation`, all history) |

No aggregate reaches into another aggregate's internals to mutate it
directly. This is unchanged from revision 1 and now additionally holds for
`fiscal_day`/`z_reading` and `shift`/`x_reading`: a reading is generated
*from* its owning aggregate's state and the underlying ledgers, never
edited after the fact, and never itself writes back into `sale`, `payment`,
`void`, `refund`, or `cash_movement`.

---

## Cross-document consistency pass

### Pass 1 (performed 2026-09-16)

This revision of `domain-model.md` was written together with
[invariants.md](invariants.md), [state-machines.md](state-machines.md), and
[erd.md](../03-architecture/erd.md) in the same pass, specifically to avoid
the failure mode of updating one document and leaving the others stale.
Verified together across all four documents:

- Entity names match exactly (e.g., `fiscal_day` not `FiscalDay`/`business_day` interchangeably; `x_reading`/`z_reading` singular table names throughout).
- `sale.status` enum is `COMPLETED`|`VOIDED`|`PARTIALLY_REFUNDED`|`REFUNDED` everywhere — no lingering `DRAFT` reference in any of the four documents.
- The Void eligibility policy (§2.8 above) is the same five-condition list in `invariants.md`'s Void section and reflected in `state-machines.md`'s Void lifecycle diagram.
- `fiscal_day_id` appears as a persisted column on both `sale` and `shift` consistently in this document, `erd.md`, and is referenced (not re-derived) in the relevant `invariants.md` entries.
- `refund_item.disposition`'s four values (`RETURN_TO_STOCK`|`DAMAGED`|`EXPIRED`|`DISPOSED`) match across this document, `erd.md`, and `invariants.md`.
- `invoice_series` allocation behavior (atomic, transaction-scoped, no reuse/reassignment/renumbering, no allocation for void/refund, no administrative rewind) is stated identically in this document's §2.6/§2.7 and in `invariants.md`'s Invoice numbering section.
- `electronic_journal_entry`'s field names (`source_type`/`source_id`/`event_type`/`audit_event_id`) match across this document, `erd.md`, and the uniqueness invariant in `invariants.md`.
- Every state shown in `state-machines.md`'s diagrams (Sale, Void, Refund, Fiscal Day, Shift) corresponds to an enum value defined in this document; no diagram introduces a status this document doesn't define, and vice versa.

### Pass 2 — focused validation (performed 2026-09-16, discount allocation correction)

Per the owner's instruction, this pass made **one surgical correction**
(order-level discount allocation) via targeted edits, not a rewrite of any
of the four documents. Focused validation performed across all four,
scoped to the areas this correction touches:

- **Discount allocation:** the Deterministic Proportional Allocation algorithm (§2.7 here) is stated identically in `invariants.md`'s new Discount allocation section (DISC-001–DISC-006) and referenced (not restated differently) from `state-machines.md`'s Refund lifecycle notes.
- **`sale_item` financial snapshot fields** (`gross_line_amount`, `line_discount_amount`, `order_discount_eligible`, `allocated_order_discount_amount`, `net_line_amount`, `taxable_base`, `tax_amount`, `line_number`) match exactly between this document's §2.7 and `erd.md`'s `SALE_ITEM` entity block — same names, same types, same nullability.
- **Money rounding:** the revised materialization-point list (§3.1, seven points) matches `erd.md`'s field annotations; no document still states the retracted "line_total"/un-prorated-discount model.
- **Mixed-tax baskets:** the per-line `tax_amount`/`taxable_base` allocation (scoped only to `VATABLE` lines, using the same algorithm as discount allocation) is described identically here and in `invariants.md`'s new tax-allocation invariant; `erd.md`'s `SALE_ITEM` block reflects the same field set for every tax classification (with `tax_amount = 0` for non-VATable lines noted, not a different schema shape).
- **Partial and full refunds:** the cumulative-recompute-then-subtract rounding rule (§2.9 here) is the same rule referenced in `invariants.md`'s DISC-004/DISC-005 and in `state-machines.md`'s Refund lifecycle notes — no document proposes computing a partial refund's amount independently of prior refunds against the same line.
- **Sale grand-total reconciliation:** every document now states the same formula, `SUM(sale_item.net_line_amount) = sale.grand_total`, replacing the retracted `SUM(line_total) − discount_total` formula everywhere it appeared (checked via full-text search across all four documents — no stale occurrence remains).
- **Tax snapshots:** unchanged from pass 1 — still confirmed never recomputed from live configuration; this pass only added the per-line allocation of the already-snapshotted sale-level amounts, which does not alter that guarantee.
- **Refund maximums:** `invariants.md` DISC-004's "eligible finalized consideration" now explicitly resolves to `sale_item.net_line_amount`, consistent with this document's Refund basis paragraph (§2.9) and `erd.md`'s notes.

Full-text search for the retracted phrase "never prorated across lines" and for the old field names `line_discount`/`line_total` confirms zero remaining occurrences outside of explicitly-labeled "retracted"/"renamed from" historical notes in this document.

### Pass 3 — focused validation (performed 2026-09-16, Void/Refund processing context and Refund settlement)

Per the owner's approval of the Stage 3 remediation gap report, this pass
added two genuinely new concepts (not present in any prior pass): `void`/
`refund` processing-context fields, and the `refund_settlement` entity.
Because these are additive fields/entities rather than a correction to
existing behavior, the validation below confirms **presence and
consistency**, not retraction of anything prior:

- **Processing-context fields:** `terminal_id`/`fiscal_day_id`/`shift_id` appear identically named on both `void` and `refund` in this document (§2.8/§2.9), in `erd.md`'s `VOID`/`REFUND` blocks (with matching FK types), and are the subject of new invariants #67–#69 in `invariants.md` — no document introduces a different name (e.g., `processing_terminal_id`) for the same concept.
- **Distinction from the original sale's context:** every one of the four documents that mentions this pass's fields explicitly distinguishes `void`/`refund`'s own `terminal_id`/`fiscal_day_id`/`shift_id` from `sale.terminal_id`/`fiscal_day_id`/`shift_id` — none conflates "the sale's context" with "the correction's context," which was the entire point of the amendment.
- **`refund_settlement`:** appears in this document (§2.9), `erd.md` (new entity block + relationship), `invariants.md` (#70–#72), and `state-machines.md` (Refund lifecycle notes) with the same field list (`payment_method`, `amount`, `processed_at`, `external_reference`) and the same explicit decision not to duplicate `terminal_id`/`shift_id`/`processed_by` onto it.
- **Void's new eligibility condition 6** and **Refund's equivalent new requirement** are stated with matching substance (open processing fiscal day + shift at execution) in this document's §2.8/§2.9, `invariants.md` #69, and `state-machines.md`'s Void/Refund lifecycle notes — none of the three describes a different precondition.
- **No prior invariant, entity shape, or state transition was altered** by this pass — confirmed by diffing this pass's edits against passes 1–2: every change is either a new field on an existing entity, a new entity, or a new invariant; no existing field was renamed, removed, or redefined, and no state machine gained or lost a transition.
- **Aggregate boundary table** (§5 above) updated to list `refund_settlement` under `refund`'s owned children, consistent with `erd.md`'s new `REFUND ||--o{ REFUND_SETTLEMENT` relationship.

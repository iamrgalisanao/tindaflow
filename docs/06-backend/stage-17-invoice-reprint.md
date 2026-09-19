# Stage 17 — Invoice view and reprint

## Status

**Done and tested**, backend and admin screen. **No change to the frozen contract**: both operations were
already in `openapi.yaml`, and their `404` maps onto the already-registered `INVOICE_NOT_FOUND` (unlike the
audit/journal `Get` operations, there is no missing-code question here). No baseline tag moved and
`scripts/validate-baselines.sh` stayed green.

## 1. Scope

| Operation | Route | Notes |
|---|---|---|
| `invoiceGet` | `GET /invoices/{id}` | session only, store-scoped; always the **plain original** |
| `invoiceReprint` | `POST /invoices/{id}/reprints` | session + **enrolled terminal**, `Idempotency-Key` required, **no dedicated capability** (operation-inventory.md: any store staff) |

## 2. How it works

- **Rendering is a pure function of the stored snapshot** (ADR-006, invariant #16). `HtmlInvoicePrinter`
  (the ADR-007 `InvoicePrinter` seam, bound in `AppServiceProvider`) dispatches on the snapshot's own
  `schema_version` to a renderer; only version 1 exists (the shape checkout writes). An unknown version
  throws rather than guessing. Nothing is read from today's store settings, prices or fiscal installation.
- **The document** is a standalone HTML page for an 80mm roll: fixed `72mm` width, monospace, black on
  white, dashed rules, long names wrap. Every typed value (seller, buyer, product names) is HTML-escaped and
  the page contains no script. Money prints as `PHP 1,234.50` because thermal fonts often lack `₱`.
- **A reprint** carries a visible `REPRINT — COPY` box at the top and bottom and a "Reprinted <time>. No new
  invoice number was issued." line (invariant #15); it is otherwise the same document, and `GET` returns the
  same invoice fields with no knowledge that a reprint ever happened.
- **A reprint changes nothing fiscal**: no invoice, sale, invoice-series counter or reading is touched and
  no number is allocated (a test compares the rows before and after two reprints). It records one
  `INVOICE_REPRINTED` audit event — whose id **is** the response's `reprint_event_id` — and one journal entry.
- **Idempotent per (terminal, key)**: the result is rebuilt from the stored audit event (id, actor, time), so
  a retry returns the same occurrence, byte for byte, and records it once. The same key for another invoice
  is `409 IDEMPOTENCY_KEY_REUSED`. Each distinct reprint is its own auditable occurrence.

## 3. Decisions the frozen documents left open

1. **How a reprint appears in the journal.** The contract requires an `electronic_journal_entry` for every
   reprint, but `ElectronicJournalEventType` is a closed list (enforced by a CHECK) with no reprint member,
   and the journal is unique on `(source_type, source_id, event_type)`. A reprint is therefore journalled as
   an **`INVOICE`** entry whose source is the reprint occurrence (`source_type = invoice_reprint`, `source_id`
   = the audit event id) with `payload_json.is_reprint = true`. The original invoice's own `INVOICE` entry
   (`source_type = invoice`) is never disturbed. The journal screen labels these "Invoice reprint". A
   dedicated event type would be a contract change.
2. **No legal wording was invented.** The renderer prints what the snapshot recorded and adds no statements
   such as "official receipt" or input-tax text: which statements a BIR-registered invoice must carry is the
   open review item BIR-006.
3. **A reprint of a voided sale's invoice is allowed** and reprints the original document unchanged; nothing
   in the contract says otherwise. Whether such a copy should be marked as voided is a business/BIR question.
4. **The screen can only print marked copies.** It shows the plain original as a preview (a read), but the
   only Print action is the reprint operation, so every physical copy produced from the app is audited and
   marked.

## 4. A gap to know about (not fixed here)

The snapshot checkout stores today is a **flat version 1** that differs from ADR-006's illustrative shape: it
has no `payments` block (so no tendered/change or payment method on the printout) and none of the fiscal
installation fields the ADR lists (`min`, `ptu_number`, `accreditation_number`, branch code). Seller name,
TIN and address are also blank on invoices issued while the store's settings are unset (there is no settings
screen yet, Module B), so the printed header shows only "VAT REGISTERED". Because the snapshot is immutable,
completing it is what `schema_version` exists for: a version 2 snapshot with its own renderer for *new*
invoices, keeping renderer 1 for the ones already issued. That should wait for the BIR field review
(BIR-006) rather than be guessed.

## 5. Admin screen

On a sale's page an **Invoice** button opens a slide-over with the invoice in a sandboxed frame
(`sandbox="allow-same-origin allow-modals"`: no scripts run in it, and it is never inserted into the app's
own page). **Print a copy** calls the reprint operation, then prints the returned marked document through the
browser's print dialog, and shows "Copy recorded <time>". Each print is a new copy with its own key; a
dropped connection retries with the same key. Without an enrolled terminal the invoice can be viewed but not
printed, and the panel says why.

## 6. Verification

- `InvoiceSnapshotV1RendererTest` (9): content of the original, the reprint mark and moment, deterministic
  output, escaping of hostile input (`<script>`, attribute injection, quotes), optional buyer/discount/seller
  parts, the non-VAT breakdown, the 80mm layout, an unknown version refused.
- `InvoiceHttpTest` (11): plain original and field shape, store scoping, `401`, the unchanged-invoice
  wrapper with mark, the audit event and journal entry, no change to invoice/sale/series/counts after
  repeated reprints, one occurrence per reprint, idempotent replay (identical body, one event), key conflict,
  missing key and terminal, unknown and foreign invoice, a voided sale's invoice.
- Full regression: Unit 120 + Feature 1 = 121, Database 444, Pint clean, baselines hold.
- **Not driven end to end in a browser** (the session available to me was signed out). What was checked in a
  browser: a real dev invoice rendered through the printer and shown in the same sandboxed frame — the
  layout reads as an 80mm receipt, a planted `<script>` did not run, and `print()` is reachable from the
  parent. The Invoice panel and the actual print dialog on the sale page remain to be looked at.

## 7. Not built

Printing at the POS right after checkout (the receipt step still only shows on screen), the store-settings
screen that would fill the seller block, snapshot schema version 2 (§4), `auditEventGet`/`journalEntryGet`
(still awaiting an owner decision, see stage 16), product CSV import/export, barcode lookup, and the
deferred shift and fiscal-day read endpoints.

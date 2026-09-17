# ADR-007: Browser-Based Printable HTML Behind a ReceiptPrinter Seam

## Status
Accepted — Stage 3, 2026-09-16.

## Context
The governing brief specifies V1 printing as "browser printing / thermal-
friendly HTML," explicitly deferring ESC/POS direct printer integration,
and requires a hardware abstraction boundary for that future work. It also
requires that printing failure never rolls back an already-finalized sale.

## Decision
V1 renders the invoice as print-optimized HTML/CSS sized for an 80mm
thermal roll (a dedicated print stylesheet: fixed narrow width, large
legible monospace/condensed font, no color dependency, page-break rules
suited to receipt-length content) and triggers the browser's native print
dialog (`window.print()`). No vendor printer SDK, no ESC/POS byte-stream
generation, and no printer driver integration exists in V1.

A single interface seam, `InvoicePrinter` (conceptually: `render(Invoice
$invoice): Html` / a corresponding frontend `printInvoice(invoiceId)`
function), is the only thing the rest of the application calls to produce
a printable representation. Today it has exactly one implementation
(browser HTML). This seam exists specifically so that a future ESC/POS
implementation, a cash-drawer-trigger-on-print implementation, or a
customer-display implementation can be added **behind the same seam**
without touching `Sale`, `Invoice`, or any Checkout/Sales domain logic —
the seam's only job is "given an invoice (via its snapshot, per ADR-006),
produce something printable," and how that "something" reaches paper is
entirely the printer implementation's concern.

**Failure semantics (Stage 3 confirmation of the brief's requirement):**
printing happens strictly **after** the checkout transaction (ADR-003) has
committed. A print failure — browser dialog dismissed, printer offline,
paper jam — has no path back to the `sale`/`invoice` rows: they already
exist, unconditionally, once checkout succeeded. The user-facing recovery
is: the sale is done, the invoice exists, and the user reprints (Stage 2's
reprint rule applies unchanged — no new invoice number, an
`INVOICE_REPRINTED` audit event, and a visible "REPRINT"/"COPY" mark on
the output) as many times as needed until a physical copy is produced.

## Alternatives Considered
- **ESC/POS direct printing now** — rejected for V1 per the governing
  brief's explicit "do not write vendor-specific printer drivers until
  needed" instruction; premature for a product whose V1 hardware story is
  "any browser-capable machine with any printer the browser can print to."
- **Print as part of the checkout transaction (block commit until print
  succeeds)** — rejected: this would make a jammed printer or a closed
  print dialog capable of rolling back a financially-valid, already-
  recorded sale, directly contradicting the brief's requirement and Stage
  2's immutability guarantees. Printing is a downstream side effect of a
  fact that already exists, never a precondition for that fact existing.
- **Generate a PDF server-side and stream it to the browser** — deferred,
  not rejected: this remains a reasonable *future* enhancement behind the
  same `InvoicePrinter` seam (e.g., for emailing an invoice, once that
  feature is ever wanted) but is unnecessary complexity for V1's
  requirement, which is "print to an 80mm thermal receipt printer attached
  to the cashier's machine," a job the browser's own print pipeline
  already does adequately.

## Consequences / Trade-offs
- **Positive:** zero printer-driver installation/maintenance burden for
  the store's IT technician — any printer the operating system and browser
  can already print to (which, for most 80mm thermal printers on Windows,
  means the manufacturer's standard OS-level driver) works.
- **Positive:** the abstraction seam means a future ESC/POS or cash-drawer-
  trigger feature is additive, not a rewrite — satisfying the brief's
  explicit requirement for this seam to exist.
- **Trade-off:** browser print dialogs offer less fine control over exact
  paper cut, cash-drawer kick, or print-queue behavior than a native ESC/
  POS integration would — an accepted V1 limitation, explicitly deferred
  by the brief itself, not an oversight.
- **Trade-off:** reprint frequency is entirely operator-driven (there is no
  server-side confirmation that a physical copy was actually produced) —
  consistent with how physical receipt printers work in general, not a
  regression specific to this architecture.

## Stage / Scope Affected
Stage 3 (this ADR), Stage 6 (`InvoicePrinter` interface + HTML
implementation), Stage 7 (print-stylesheet UI work), later phases (ESC/POS,
cash-drawer, customer-display implementations behind the same seam).

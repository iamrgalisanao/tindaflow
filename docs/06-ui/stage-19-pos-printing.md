# Stage 19 — Printing the invoice at the POS

## Status

**Built; frontend only.** No backend, contract or baseline change: it uses `invoiceGet` and `invoiceReprint`
from Stage 17. The POS receipt step itself was not driven in a browser (the session available to me was
signed out); the mechanics it relies on were checked in one (§3).

## 1. What it does

After a sale completes, the receipt step gains a **Print invoice** button.

- **First print = the plain original.** It reads `GET /invoices/{id}` (a read, recorded nowhere) and sends the
  returned `render_html` to the browser's print dialog through a hidden frame. This is the invoice being handed
  to the customer, so it carries no mark.
- **Every print after that = a reprint.** The button becomes **Print another copy** and calls
  `POST /invoices/{id}/reprints`, so the copy is marked `REPRINT — COPY` and recorded (audit event and journal
  entry, no new number). The screen says so under the button. The point (ADR-007): the app can never produce a
  second *unmarked* copy of an invoice.
- A dropped connection keeps the same `Idempotency-Key`, so pressing again cannot record two copies; any other
  outcome issues a fresh key, and a successful print does too (the next copy is a new copy, not a retry).
- **Printing never affects the sale.** It happens after checkout has committed. If the invoice cannot be
  prepared the receipt shows a plain message ("the sale is complete; you can print it from Sales history"),
  and a browser that is not an enrolled terminal is told it cannot record a copy.
- "New sale" resets the print state.

## 2. Decisions

- **The first print is not audited**, because the contract makes `invoiceGet` a plain read and the original is
  the document issued at the moment of sale. If the cashier cancels that first dialog, the app cannot know, so
  the next press is a marked, recorded copy: an honest trail rather than a guess.
- **No auto-print.** A print dialog after every sale would be intrusive on shared machines and cannot be tested
  here; it is a reasonable later toggle.
- **A shared hook, `lib/usePrintFrame`**, mounts a fresh sandboxed off-screen frame per print (new key), so
  printing the same document twice reloads it and reopens the dialog. The frame allows no scripts
  (`sandbox="allow-same-origin allow-modals"`; `allow-same-origin` only so it can be printed), and the document
  is never inserted into the app's own page. The admin Invoice panel keeps its own visible preview frame.

## 3. Verification

- Checked in a browser: a zero-size sandboxed frame with the same attributes loads its document, fires `load`
  on each of two consecutive prints, exposes `print()`, and does not run a planted `<script>`.
- **Not verified**: the button on the real receipt step and the actual print dialog. Please ring a sale, press
  **Print invoice**, then **Print another copy**, and confirm the second one is marked and appears in the audit
  log as "Invoice reprinted".

## 4. Not built

Auto-print after checkout, direct thermal-printer (ESC/POS) or cash-drawer support (the ADR-007 seam allows it
later), and printing from the shift and fiscal-day close screens.

# Personas

## Status
APPROVED — Stage 1 baseline (`stage-1-baseline`), conditionally approved 2026-09-16. No corrections required for this document.

These personas drive UI and workflow priority decisions throughout the
project, especially the cashier screen (Module D) and reporting (Module L).

---

## Persona 1 — Cashier ("Aling Baby" / front-liner)

**Role in system:** CASHIER

**Context:** Works the counter of a convenience store or minimart, typically
during an 8–12 hour shift. May be the store owner, a family member, or a
hired employee. Often multitasking (restocking shelves, answering customers)
between transactions. Comfort with technology ranges from basic to
moderate — this is not a technical user.

**Goals:**
- Get each customer through the line as fast as possible.
- Never lose track of how much cash should be in the drawer.
- Avoid doing anything that requires calling the manager mid-transaction.
- Correctly handle scanned items, quantity changes, and common payment
  methods (cash, GCash) without confusion.

**Pain points with existing tools:**
- Systems that require multiple clicks/screens per item.
- Barcode scanners that don't return focus to the right input field.
- Unclear whether a sale actually completed (no confirmation) leading to
  double-charging or double-printing.
- Being blamed for cash variances caused by system confusion rather than
  actual error.

**What TindaFlow must give this persona:**
- Scan → scan → scan → pay → print, with no unnecessary steps in between.
- Large, unambiguous visual feedback per scan (item added, quantity, price).
- A clear, unobtrusive path for "product not found" that doesn't stall the line.
- Simple shift open/close with cash counting that doesn't feel like an audit.
- The ability to request (not perform) a void/refund when authorization is required.

---

## Persona 2 — Store Manager / Owner ("Mang Tony" / the operator)

**Role in system:** MANAGER (or ADMIN, if owner-operator)

**Context:** Owns or manages the store. Responsible for the business's cash,
stock, and staff. May also work the counter personally during peak hours or
staff shortages. Cares about the store's profitability and about not being
cheated by staff or short-changed by customers, but is not a career
bookkeeper or IT administrator.

**Goals:**
- Know, at a glance, what sold today and whether the cash matches.
- Keep the shelves stocked and know what's running low before it's out.
- Trust that the system enforces controls (voids, refunds, price overrides)
  rather than relying on staff honesty alone.
- Eventually be able to prove to BIR/auditors/accountants that the numbers
  are accurate, without having done anything to prepare for that until it's
  actually required.
- Spend minimal time on "administering the software" — this is a means to
  run the store, not a project to manage.

**Pain points with existing tools:**
- Reports that don't reconcile with what's physically in the drawer or on
  the shelf.
= "Backdoor" edits that let staff or the software silently change sales
  history, making shrinkage/theft investigation impossible.
- Compliance-related fields and behaviors that are either entirely absent or
  falsely claimed as ready, leaving the owner exposed if BIR ever asks.
- Needing a consultant just to install/run the system.

**What TindaFlow must give this persona:**
- Fast, trustworthy Daily Sales Summary and Cash Variance reports.
- Full visibility into any correction (void/refund/adjustment) with who/why/when.
- Confidence that the software will not misrepresent its BIR compliance status.
- Manager approval gates for sensitive actions (void, refund, discount,
  price override, large stock adjustment, cash out).
- Straightforward Docker-based install/backup that a competent local IT
  person (not necessarily the owner) can run.

---

## Persona 3 (implicit, future-facing) — Bookkeeper / Accountant

**Role in system:** Not a V1 login role, but a consumer of V1 outputs
(reports, CSV exports, and eventually the structured invoice JSON).

**Context:** Prepares the store's books, possibly remotely, possibly only
periodically (monthly/quarterly). Needs numbers that reconcile and a clear
paper trail for any correction. Will be the first person to notice if
transaction data is inconsistent or missing.

**Why this persona matters for V1 even without a login:**
The reporting module (L) and the immutability/audit guarantees exist
primarily to satisfy this persona's needs, even though they interact with
the system indirectly (via reports/exports) rather than logging in. Designing
reports "as if a bookkeeper will scrutinize them" keeps the reporting module
honest.

---

## Persona 4 (implicit, future-facing) — IT Installer / Store Technician

**Role in system:** No application login role; operates at the
infrastructure level (Docker host, LAN, printer/scanner setup).

**Context:** Installs and maintains the on-premise server or VPS, sets up
the LAN, connects the barcode scanner and receipt printer, and is the first
call when "the POS is down." May be a local computer shop technician, not a
software engineer.

**What TindaFlow must give this persona:**
- A small number of well-documented Docker Compose services.
- Clear health checks, logs, backup, and restore procedures (Stage 9).
- No dependency on proprietary/vendor-specific printer drivers for the
  baseline HTML/browser-print flow.

---

## Persona relevance to design decisions

| Decision area | Primary persona driving it |
|---|---|
| POS cashier screen layout & speed | Cashier |
| Shift open/close & cash counting flow | Cashier, Manager |
| Void/refund/approval workflow | Manager (approver), Cashier (requester) |
| Reports | Manager, (future) Bookkeeper |
| BIR-readiness fields & honesty about compliance status | Manager (liability), (future) Bookkeeper |
| Deployment/Docker/backup | IT Installer |

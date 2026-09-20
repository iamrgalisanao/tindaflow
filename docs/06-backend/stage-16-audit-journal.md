# Stage 16 — Audit log and electronic journal

## Status

**Done and tested** for the two list operations, backend and admin screens. **No change to the frozen
contract.** The two `Get` operations are deliberately **not built yet** and are the one open question
(§3). `scripts/validate-baselines.sh` stayed green and no baseline tag moved.

## 1. Scope

| Operation | Route | Notes |
|---|---|---|
| `auditEventList` | `GET /audit-events` | `AUDIT_VIEW`; filters `event_type`, `actor_user_id`, `entity_type`, `entity_id`, `from`, `to` |
| `journalEntryList` | `GET /electronic-journal-entries` | `JOURNAL_VIEW`; filters `event_type`, `terminal_id`, `from`, `to`; `Accept: text/csv` for the export |
| ~~`auditEventGet`~~ | `GET /audit-events/{id}` | **not built** (§3) |
| ~~`journalEntryGet`~~ | `GET /electronic-journal-entries/{id}` | **not built** (§3) |

Session only, no terminal. ADMIN and MANAGER hold both capabilities; CASHIER neither (`403
AUTHORIZATION_DENIED`). Scoped to the actor's store, newest first, `per_page` 1..100. Unknown filter
values match nothing rather than being ignored. Neither log has any create, update or delete route
(`405`), matching the contract's "read-only" statement and the append-only rule for both tables.

## 2. Decisions

- **The journal CSV** follows `csv-export-contract.md` exactly: header row, columns `occurred_at,
  event_type, source_type, source_id, terminal_id, audit_event_id`, RFC 3339 timestamps, an empty field
  (never `null`) for a missing terminal or audit event. It exports **the whole filtered set**, not one
  page (paging parameters apply to the JSON form only), and is streamed from a database cursor so a long
  journal is never held in memory. Order is the same as the JSON list (newest first); the contract does
  not pin one.
- `before_metadata`, `after_metadata` and `payload_json` are always JSON **objects** (or `null`), never
  `[]`, as the schemas require.
- The screens render each event as a plain sentence from what it recorded (for example "Refund of
  ₱36.00 completed", "Void of invoice 000007 · ₱110.00") and show every field under **Details**. Ids are
  shown by their random tail (a UUIDv7's prefix is a timestamp). Actors appear as short ids because a
  manager cannot list users.
- Audit event types offered in the filter are the ones the application actually writes today; the
  frozen catalog also names `PRICE_OVERRIDE`, `DISCOUNT_APPLIED`, `USER_LOGIN`, `INVOICE_REPRINTED` and
  others that nothing emits yet. Any type still displays (humanised) if it ever appears.

## 3. The open question: `auditEventGet` and `journalEntryGet`

**Decided in stage 24 (D1): leave both unbuilt**, with the names `AUDIT_EVENT_NOT_FOUND` and `ELECTRONIC_JOURNAL_ENTRY_NOT_FOUND`
reserved if they are ever wanted; see `docs/06-backend/stage-24-owner-decisions.md`. The analysis below is kept as written.

Both declare a `404` with no registered error code, and `error-catalog.md` has no
`AUDIT_EVENT_NOT_FOUND` / `JOURNAL_ENTRY_NOT_FOUND`. Registering one completes a gap in an
**already-frozen response**, which the governance note reserves for reconstruction, and the Stage 13
exception for `USER_NOT_FOUND` was explicitly "one-time; the next such code reopens the question".
That question (reconstruct and force-push `main` and the baseline tags, forward-commit as a second
recorded exception, or leave the two operations unbuilt) belongs to the owner, so it is not decided
here. Nothing is blocked: a list row already carries the whole event, so the screens do not need a
single-record read. Choosing either of the first two options would add two small controller methods and
tests; the operations are otherwise identical to the lists.

## 4. Admin screens

New **Records** sidebar section (shown to holders of either capability, with each link gated by its own)
and Dashboard links; the Dashboard's "no screens yet" note is gone.

- `/admin/audit` — filter by what happened and date range; one sentence per event with actor, terminal
  and reason; **Details** shows every field and the before/after metadata.
- `/admin/journal` — filter by type and date range; the same row pattern with the recorded snapshot under
  **Details**; **Export CSV** downloads everything matching the current filters.

No Stitch mockup was generated: the screens reuse the established admin patterns.

## 5. Verification

- `AuditLogHttpTest` (6) and `ElectronicJournalHttpTest` (6): ordering and store isolation on real
  checkout/void events, the full event shape, every filter (including values that can match nothing),
  paging and the `per_page` clamp, metadata as objects, CSV header/columns/timestamps/empty fields, CSV
  covering the whole set regardless of paging, CSV honouring filters, capability gating (including CSV),
  no write routes, and `401`.
- Browser: the two screens were built and compiled but **not driven by me in a browser** (the session
  available to me was signed out and could not be signed in). A screenshot of the dashboard supplied by
  the owner showed the new Audit log / Journal buttons and an overflow of the button row past its card,
  which was fixed (the row is now a wrapping grid). The pages' visual behaviour and the CSV download in a
  real browser remain to be looked at.

## 6. Not built

`auditEventGet` and `journalEntryGet` (§3), names instead of short ids for actors, deep links from a sale
or refund to its audit trail, invoice reprint, product CSV import/export, barcode lookup, and the deferred
shift and fiscal-day read endpoints.

# BIR Reference Register (Preliminary) — TindaFlow POS

## Status
APPROVED — Stage 1 baseline (`stage-1-baseline`), conditionally approved
2026-09-16 with corrections applied (see Revision log at the bottom of this
document). Research conducted 2026-09-16 via web research against BIR's own
issuance PDFs (bir-cdn.bir.gov.ph) and full statutory text (lawphil.net)
where retrievable, cross-checked against reputable secondary sources (Big 4
tax alerts) where primary text could not be fetched.

**This register does not establish that TindaFlow is, or is close to being,
BIR-accredited.** It exists to track *what the law/regulations currently say*
so that architecture decisions in Stage 2 onward are traceable to a real
source rather than an assumption. Every entry has a `Needs professional
confirmation?` field — entries marked YES must be confirmed by a tax lawyer
or accredited CAS/POS consultant before being relied on for an actual
accreditation filing. This is a living document; update entries as
regulations change or as primary sources are confirmed/refuted.

## Confidence key
- **HIGH** — primary BIR issuance text read directly.
- **MEDIUM** — corroborated by multiple reputable secondary sources, primary text not independently read.
- **LOW / UNVERIFIED** — could not be confirmed; treat as `BIR-REVIEW-REQUIRED`.

---

### BIR-001
**Requirement:** "Invoice" (not "Official Receipt") is now the principal
document for both sale of goods and sale of services. Official Receipts are
demoted to supplementary documents and can no longer support input VAT
claims.

**Source:** RA 11976 (Ease of Paying Taxes Act), effective 2024-01-22;
implemented via RR 7-2024 and clarified by RMC 77-2024.

**Source section:** RA 11976 amendments to NIRC Sec. 113/237; RMC 77-2024 ¶ on OR-to-Invoice conversion.

**Confidence:** HIGH (RMC 77-2024 primary text read directly; RA 11976 itself MEDIUM).

**System implication:** TindaFlow's principal sales document is named
"INVOICE" throughout the product (already reflected in Module G / scope.md),
never "Official Receipt." Historical/legacy OR terminology must not appear
as the primary label anywhere in invoice templates or UI.

**Status:** DESIGNED (naming convention already adopted in scope).

**Implementation reference:** Module G (Invoice), invoice template naming.

**Needs professional confirmation?** NO (well-corroborated, low ambiguity).

---

### BIR-002
**Requirement:** RMC 77-2024's full text (not just its digest) confirms: a
VAT-registered seller issues a VAT Invoice for every transaction, regardless
of amount. A Non-VAT-registered seller issues a Non-VAT Invoice for
transactions of ₱500 or more; for transactions below ₱500, a Non-VAT seller
must issue an Invoice when the buyer requests one. (The ₱500 figure is
subject to CPI adjustment every 3 years from 2024-01-22, per RA 11976 —
monitor for a future adjustment, but the current baseline value itself is
directly confirmed in primary text, not merely inferred.)

**Source:** RR 7-2024 (as amended by RR 11-2024), clarified and confirmed by RMC 77-2024.

**Source section:** RMC 77-2024 full text (quotes RR 7-2024 §6(B) and states the ₱500 threshold rule directly). Primary text: https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2077-2024.pdf

**Confidence:** HIGH (RMC 77-2024 primary full text confirms the exact threshold rule, not only its digest).

**System implication:** The invoice-generation logic (Module G) must not
gate invoice issuance on a minimum sale amount for VAT-registered stores;
for non-VAT stores it needs a ₱500 threshold check plus a
buyer-requested-invoice override below that amount. Store Settings
(Module B) needs a `TaxRegistrationType` (VAT / NON_VAT) driving this
behavior — see scope.md's tax-model decision.

**Important distinction — legal minimum vs. product policy:** The ₱500
threshold above is the *legal minimum* below which a Non-VAT seller is not
otherwise obligated to issue an Invoice unless asked. **TindaFlow's own
product policy is to generate an Invoice record for every completed POS
sale, regardless of value, for both VAT and Non-VAT stores.** This is a
deliberate application-level simplification (it removes a conditional branch
from the checkout/audit-trail model) and must never be described in
documentation or UI as "the BIR threshold" — it is stricter than the legal
minimum, not a restatement of it.

**Status:** DESIGNED — `TaxRegistrationType` field to replace the earlier
`vat_status` boolean framing (see scope.md tax-model decision); "invoice
every sale" product policy confirmed as an application rule layered on top
of, not a restatement of, the legal minimum.

**Implementation reference:** Module B (`TaxRegistrationType`), Module G (invoice issuance rule: always issue, independent of the legal ₱500 floor).

**Needs professional confirmation?** NO for the current ₱500 baseline value
(now confirmed against RMC 77-2024 primary text). Re-verify only if/when a
CPI adjustment cycle is publicly announced (next possible adjustment point:
2027-01-22).

---

### BIR-003
**Requirement:** Finalized sales/invoice data must be protected against
tampering: the system must have "Tamper-free" characteristics, a
non-volatile Activity/Transaction Log, and an E-journal/Audit Journal
(explicitly named as required for CRM/POS), verified via live demonstration
during accreditation.

**Source:** RMO 24-2023 (Revised Policies on Accreditation of CRM/POS/Sales Machines).

**Source section:** RMO 24-2023, list of 13 attested system specifications (items b, c, e).

**Confidence:** HIGH (primary text read directly).

**System implication:** No direct editing/deletion of completed sales;
append-only audit_events and electronic_journal_entries tables; all
corrections via explicit reversal transactions (VOID/REFUND). This directly
matches the project's Section 2.B/2.C mandatory architecture principles.

**Status:** DESIGNED — core to the domain model (see Section 2 of the governing brief; to be formalized in Stage 2 invariants).

**Implementation reference:** `audit_events`, `electronic_journal_entries` tables; Sale aggregate immutability + reversal workflow.

**Needs professional confirmation?** YES before formal accreditation filing (confirm the live-demonstration criteria BIR's TWG will actually test against).

---

### BIR-004
**Requirement:** Accumulated Grand Total Sales must be maintained as a
non-resettable (or reset-logged) running total; if any reset mechanism
exists, it must be reflected/logged and shown on receipts.

**Source:** RMO 24-2023.

**Source section:** RMO 24-2023, attested specification (a) "Accumulated Grand Total Sales."

**Confidence:** HIGH.

**System implication:** No UI exposes a "reset totals" function to ordinary
users; the accumulated total is derived append-safe from completed
transactions, matching Section 5.1 of the governing brief.

**Status:** DESIGNED.

**Implementation reference:** Reporting/ledger layer — accumulated total derivation, not a stored+editable counter.

**Needs professional confirmation?** YES before accreditation (exact display/format requirements on receipts).

---

### BIR-005
**Requirement:** Invoice numbering must be sequential, a minimum of 6
digits, running per accountable-document series; SI/Invoice series distinct
from any internal transaction-number series.

**Source:** RMO 24-2023 (attested spec (h)); cross-referenced against RR 16-2018/RR 6-2022/RR 11-2004 §6.0 required invoice fields.

**Source section:** RMO 24-2023 attested specification (h) "Sequential series of accountable forms."

**Confidence:** HIGH.

**System implication:** Matches Section 2.F of the governing brief exactly:
transaction UUID, human-readable transaction number, invoice number, and
invoice series must be tracked as separate concepts, with DB-level atomic
sequence generation to prevent duplicates/reuse under concurrency.

**Status:** DESIGNED — already a named architectural principle in the brief.

**Implementation reference:** `invoice_sequences` table, atomic sequence allocation at checkout.

**Needs professional confirmation?** NO for the general rule; YES to confirm the minimum-digit rule hasn't changed for a specific taxpayer category.

---

### BIR-006
**Requirement:** Required invoice header/footer content includes: registered
name, business address, VAT/Non-VAT-registered TIN, Machine Identification
Number (MIN), hardware/software serial number, invoice number, date, buyer
details (where applicable), line items with quantity/description/unit
cost/total/VAT, applicable discount fields (Senior Citizen/PWD/National
Athletes/Solo Parent), and a footer showing the supplier's accreditation
number/date and the taxpayer's PTU number or Authority to Generate (ATG)
control number.

**Source:** RMO 24-2023, cross-referencing RR 16-2018 / RR 6-2022 / RR 11-2004 §6.0.

**Source section:** RMO 24-2023 invoice/receipt content requirements.

**Confidence:** HIGH for the field list from RMO 24-2023; the underlying RR 16-2018/6-2022/11-2004 text itself was not independently re-read this pass.

**System implication:** Matches Module B (store settings incl. MIN/PTU/accreditation fields) and Module G (invoice field list) as already scoped. All of these fields must exist in the invoice template and canonical JSON model, but remain **empty/inactive** until the business is actually accredited (see scope.md BIR posture).

**Status:** DESIGNED — fields already present in Module B/G scope.

**Implementation reference:** `store_settings` BIR-readiness fields; invoice template field list; canonical structured-invoice JSON (Section 5.10 of the brief).

**Needs professional confirmation?** YES — exact required field set and layout should be confirmed against the current version of RR 16-2018/6-2022/11-2004 before final invoice template design in Stage 2/7.

---

### BIR-007
**Requirement:** Two distinct approvals exist and must not be conflated: (1)
**Accreditation** of the CRM/POS supplier/developer/software (yielding an
Accreditation Number), issued via the Enhanced eAccReg System after a live
TWG demonstration, free of charge, within 20 working days (7 for
reseller/identical-software cases); and (2) a **Permit to Use (PTU)** issued
to the taxpayer-user of that accredited CRM/POS. PTU is still required for
CRM/POS taxpayer-users (unlike CAS/CBA, which moved to an
Acknowledgement-Certificate registration model under RMC 5-2021).

**Source:** RMO 24-2023.

**Source section:** RMO 24-2023, accreditation process and PTU sections.

**Confidence:** HIGH for RMO 24-2023's own text; secondary sources claiming "PTU is gone for POS" appear to conflate the CAS/CBA rule with CRM/POS and should be treated as likely inaccurate.

**System implication:** Store Settings (Module B) must model these as
**two separate optional fields**: `accreditation_number`/`accreditation_date`
(supplier-level) and `permit_to_use_number`/`permit_date` (taxpayer-level) —
already present in the brief's Module B field list; do not merge them.

**Status:** DESIGNED (fields already distinct in scope).

**Implementation reference:** Module B fields `accreditation_number`, `accreditation_date`, `permit_to_use_number`, `permit_date`.

**Needs professional confirmation?** YES before any real accreditation filing — confirm current PTU requirement status has not changed since RMO 24-2023 (June 2023).

---

### BIR-008
**Requirement:** Electronic sales-data transmission to BIR's Electronic
Invoicing System (EIS) is mandatory now (deadline **2026-12-31**, per RR
26-2025) for: e-commerce/internet-transaction taxpayers (Micro exempt),
Large Taxpayer Service (LTS) taxpayers, Large Taxpayers under RA
11976/RR 8-2024, and CAS/CBA-with-e-invoicing users. RR 26-2025 lists
**standalone POS-system users** (alongside exporters and RBEs with tax
incentives) among the taxpayer categories whose e-invoicing requirement will
apply **once BIR's receiving system is capable and a future, separate
Revenue Regulation is issued** — no activation date is fixed as of this
research.

**Important qualifier — this is not a permanent, POS-usage-based exemption.**
"Uses a POS system" is one classification among several in RR 11-2025/RR
26-2025's taxpayer categories, and a given TindaFlow-using business may
*independently* fall into one of the already-mandated categories (e.g., it
is separately an LTS taxpayer, a Large Taxpayer under RA 11976, an
e-commerce/internet-transaction seller, or a CAS/CBA-with-e-invoicing user)
regardless of the fact that it also happens to run a POS system. EIS
applicability must therefore be evaluated per-taxpayer, not inferred from
"this business runs TindaFlow" or "TindaFlow is a POS product." Do not
encode "POS users are exempt from e-invoicing" as a domain rule anywhere in
the system.

**Source:** RR 11-2025 (2025-02-27), amended by RR 26-2025 (2025-10-16).

**Source section:** RR 11-2025 §3(A)(1)-(5); RR 26-2025 amended Transitory Provisions §6. Primary text: https://bir-cdn.bir.gov.ph/BIR/pdf/RR%20No.%2026-2025%20Digest.pdf

**Confidence:** HIGH (both primary texts read directly — the single
strongest-sourced finding in this register).

**System implication:** TindaFlow does **not** need to implement direct EIS
transmission for V1, matching Section 5.9 of the governing brief
(`SalesTransmissionProvider` abstraction with `NullSalesTransmissionProvider`
today, `FutureBIREISProvider` as a placeholder). The store's specific EIS
obligation (if any, today or in the future) is a **taxpayer-specific
compliance configuration** — modeled as an attribute of the store/taxpayer,
never as a fixed property of "being a POS product" — so that a store owner
who separately becomes an LTS/Large Taxpayer can have EIS transmission
enabled for their installation without a product-wide architecture change.
The abstraction must remain genuinely swappable per-store, not a single
global on/off switch.

**Status:** DESIGNED — matches existing architectural mandate (Section 5.9); refined to model EIS applicability as taxpayer-specific configuration, not a POS-wide exemption. No urgency to build a real EIS adapter for V1.

**Implementation reference:** `SalesTransmissionProvider` interface, `NullSalesTransmissionProvider` (V1 default); EIS-applicability flag modeled per store/taxpayer in Stage 2, not hardcoded to "false because this is a POS."

**Needs professional confirmation?** YES — a given store's actual EIS
obligation depends on facts specific to that taxpayer (LTS jurisdiction,
Large Taxpayer status, e-commerce activity, CAS usage) that TindaFlow cannot
determine on its own; store owners should confirm their own classification
with BIR/their accountant. Re-check this entry before every release, since
BIR may issue the POS-activating RR at any time and the Commissioner can
shorten timelines.

---

### BIR-009
**Requirement:** RA 11976 (EOPT Act), **Section 33**, directly amended NIRC
Section 235 and expressly establishes a **five-year** preservation period
for books of accounts and other accounting records (down from the prior
10-year rule), counted from the day after the filing deadline (or actual
filing date if late) for the relevant taxable year; extended while a
protest/refund claim/audit involving those records remains unresolved. This
is a statutory provision in the RA itself, not a separate implementing
regulation.

**Source:** RA 11976 (Ease of Paying Taxes Act), Section 33, amending NIRC Sec. 235.

**Source section:** RA 11976 §33 (primary text). https://lawphil.net/statutes/repacts/ra2024/ra_11976_2024.html

**Confidence:** HIGH — the "5 years" rule is directly stated in RA 11976 §33
itself (a primary, citable legal text), not merely inferred from secondary
commentary or a separately-numbered implementing RR. The earlier uncertainty
in this entry was based on searching for a non-existent standalone
"retention RR" — the correct citation is simply the RA's own Section 33.

**System implication:** The project brief's stated 5-year minimum retention
target (Section 5.8) is confirmed as the correct statutory floor. Do not
build any automated hard-delete of transaction/audit records under any
retention policy shorter than 5 years. **TindaFlow V1 shall contain no
automatic hard-deletion of financial, invoice, journal, or audit records at
all** — expiry of the statutory minimum retention period is not, by itself,
grounds for the software to delete anything; any purge is a deliberate,
manual, documented administrative action outside the application's normal
operation (if ever implemented), not an automated feature.

**Status:** CONFIRMED — brief's 5-year floor is statutorily grounded; no automated deletion of any kind will be implemented in V1 regardless of record age.

**Implementation reference:** No hard-delete code path for `sales`, `sale_items`, `payments`, `invoices`, `audit_events`, `electronic_journal_entries` tables — not even after 5 years; retention policy documentation in Stage 2/Stage 9 backup design.

**Needs professional confirmation?** NO for the 5-year figure and its
statutory source (now directly confirmed). Confirmation is only warranted if
RA 11976 is later amended.

---

### BIR-010
**Requirement:** Registration/accreditation is processed through the
**Enhanced eAccReg System**; sales-data reconciliation for accredited
CRM/POS still references the pre-EIS **eSales (Electronic Sales Reporting
System)**; **ORUS (Online Registration and Update System)** is a broader,
separate taxpayer self-service portal that also now surfaces
CAS/CBA/ESS/middleware registration and CRM/POS/SPM PTU applications online.
These are three coexisting systems, not sequential replacements of each other.

**Source:** RMO 24-2023 (eAccReg/eSales); ORUS official site and secondary BIR-update sources (system landscape).

**Source section:** RMO 24-2023 (accreditation/PTU process); ORUS service listing.

**Confidence:** MEDIUM for eAccReg/eSales continuity (grounded in RMO 24-2023 primary text); LOW/UNVERIFIED for the specific claim that ORUS's CAS/POS-registration functionality dates to a particular 2025 date — treat that date as unconfirmed.

**System implication:** No direct system-integration implication for V1
(TindaFlow does not integrate with eAccReg/eSales/ORUS in V1 — these are
manual, business-side registration processes the store owner completes
outside the software). Relevant only to Phase 3 accreditation-preparation
documentation and to correctly informing store owners what registration
steps remain manual today.

**Status:** DOCUMENTED (informational; no V1 architectural action required).

**Implementation reference:** N/A for V1; reference material for Phase 3 (`docs/07-compliance/`).

**Needs professional confirmation?** YES before writing any user-facing guidance about which BIR portal to use for what.

---

### BIR-011
**Requirement:** Penalty exposure exists for continuing to issue "Official
Receipt" as if it were still the primary document post-transition (₱1,000–
₱50,000 fine + 2–4 years imprisonment under Sec. 264(a) Tax Code, treated as
non-issuance of Invoice); a non-VAT seller mistakenly issuing a VAT Invoice
becomes liable for VAT without input credit, plus a 50% surcharge.

**Source:** RMC 77-2024.

**Source section:** RMC 77-2024, penalty provisions.

**Confidence:** HIGH (primary text read directly).

**System implication:** Reinforces BIR-001/BIR-002 — the invoice-type logic
(VAT vs. Non-VAT, "Invoice" not "Official Receipt") is not cosmetic; getting
it wrong has direct penal/tax exposure for the store owner. Store Settings'
`vat_status` must be a required, not optional, field with no silent default.

**Status:** DESIGNED (informs field validation requirements for Stage 2/5).

**Implementation reference:** Module B `vat_status` validation; onboarding/setup flow must force this choice.

**Needs professional confirmation?** NO (well-sourced); reinforces urgency of BIR-002's confirmation.

---

### BIR-012
**Requirement:** A POS/CRM software developer/dealer/supplier whose
Certificate of Accreditation expires within the covered period must apply
for a new accreditation following RMO No. 24-2023. Separately — and this is
the operative clarification — an existing taxpayer's **Permit to Use (PTU)
does not automatically expire solely because the software's Certificate of
Accreditation expires.** Accreditation (a supplier/software-level status)
and PTU (a taxpayer/installation-level status) are confirmed as
independent lifecycles, not one collapsed status.

**Source:** RMC No. 72-2025.

**Source section:** RMC 72-2025, full text (owner-supplied citation:
https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20NO.%2072-2025.pdf).

**Confidence:** HIGH — independently verified by the product owner against
the primary RMC 72-2025 text (bir-cdn.bir.gov.ph), confirming the substance
recorded above.

**System implication:** Reinforces the Stage 2 decision (domain-model.md
§2.1) to keep `fiscal_installation` (accreditation-related fields) as its
own entity, separate from any taxpayer-level PTU concept. **Stage 3 should
review** whether `fiscal_installation` needs independently effective-dated
sub-histories for accreditation identity/status/validity versus PTU
identity/status, rather than a single flat field set — see the forward
note in [erd.md](../03-architecture/erd.md). This is a Stage 3 architecture
question; it does not change the Stage 2 aggregate boundaries.

**Status:** DOCUMENTED — informational for Stage 3; no Stage 2 domain
behavior changed based on this entry alone.

**Implementation reference:** N/A for V1 (TindaFlow is not accredited);
forward-looking reference for Stage 3 `fiscal_installation` design and
Phase 3 accreditation preparation.

**Needs professional confirmation?** NO for the substance recorded here
(independently verified against primary text); YES only at the point of an
actual accreditation/PTU filing, as a final currency check.

---

### BIR-013
**Requirement:** BIR's eAccReg system currently exposes distinct
registration/deployment patterns for POS systems, including "POS
Standalone" and "POS with SERVERCONS" (server-connected terminals) —
confirming that a single accredited installation can legitimately serve
multiple physical terminals under a server-connected deployment model.

**Source:** BIR eAccReg system (help/reference pages).

**Source section:** eAccReg help documentation (owner-supplied citation:
https://eaccreg.bir.gov.ph/ACCREG/help.html).

**Confidence:** HIGH for the substance (the two deployment labels currently
exposed in the live eAccReg help system, confirmed by the product owner) —
still MEDIUM as a *regulatory citation* in the strict sense, since this is
operational system documentation rather than a numbered issuance and could
change if BIR updates the system; re-verify against the live system if a
future regulatory issuance formalizes or changes these categories.

**System implication:** Directly supports the Stage 2 decision (revision 1
of this correction) to model `fiscal_installation` as `store`-scoped and
associated with one or more `terminal`s via `terminal_fiscal_installation`,
rather than forcing a 1:1 `terminal`↔`fiscal_installation` relationship —
see [domain-model.md §2.1](../02-domain/domain-model.md). No further
Stage 2 change needed; this citation confirms the existing decision rather
than requiring a new one.

**Status:** CONFIRMED — supports an already-made Stage 2 decision.

**Implementation reference:** `fiscal_installation.deployment_model`
(`STANDALONE`|`SERVER_CONNECTED`), `terminal_fiscal_installation` join
table.

**Needs professional confirmation?** NO for the Stage 2 modeling decision
this supports; re-verify against the live eAccReg system if it is ever
superseded by a formal numbered issuance, or before Stage 5 designs the
concrete registration/onboarding flow.

---

### BIR-014
**Requirement:** The Z-Reading must cover the entirety of the sales
operation/business day, and transactions or adjustments for that same
operation date must not occur after that Z-Reading has been generated.

**Source:** RMO No. 24-2023.

**Source section:** RMO 24-2023 (Z-Reading/end-of-day closure provisions),
independently verified by the product owner.

**Confidence:** HIGH — independently verified by the product owner against
the primary text.

**System implication:** Directly confirms the Stage 2
`fiscal_day → z_reading` closure semantics — see
[domain-model.md §2.5](../02-domain/domain-model.md) and
[invariants.md](../02-domain/invariants.md) #36 ("a fiscal day cannot close
while it has an open shift") and #42 ("exactly one Z-Reading per fiscal
day"). No Stage 2 change needed; this is additional evidentiary support for
an already-made decision, and directly informs Stage 3's requirement that
the architecture must prevent any new financial transaction from being
attributed to a `fiscal_day` after its Z-Reading has been generated (not
merely discouraged by convention).

**Status:** CONFIRMED — supports an already-made Stage 2 decision; informs
Stage 3 concurrency architecture (see
[architecture.md](../03-architecture/architecture.md) §8).

**Implementation reference:** `fiscal_day.status`, `z_reading` generation
transaction; Stage 3 concurrency controls preventing post-closure
attribution.

**Needs professional confirmation?** NO for the modeling implication;
re-verify the exact statutory wording if this is ever cited verbatim in
customer-facing or legal documentation.

---

## Items explicitly marked BIR-REVIEW-REQUIRED (do not treat as settled)

Several items previously listed here have since been resolved against
primary sources and are no longer review-required: the BIR-002 ₱500
threshold value (RMC 77-2024 full text), the BIR-009 retention-period
citation (RA 11976 §33), BIR-012 (RMC 72-2025, independently verified by
the owner), and BIR-013 (eAccReg deployment labels, independently verified
by the owner) — see those entries above for the confirmed citations.

| Item | What's uncertain |
|---|---|
| BIR-006 field list completeness | Full required-field list should be re-confirmed against current RR 16-2018/RR 6-2022/RR 11-2004 text directly, not only via RMO 24-2023's summary of it. |
| BIR-007 PTU status | Secondary-source claims that PTU is no longer required for POS (conflating it with the CAS/CBA Acknowledgement-Certificate change) should be explicitly rejected pending confirmation, per RMO 24-2023's own text. |
| BIR-008 EIS activation date and per-taxpayer applicability | No fixed date for the standalone-POS-usage-triggered EIS mandate exists yet; monitor for a new activating RR. Separately (and regardless of that date), each store owner's own EIS obligation must be confirmed against their individual taxpayer classification (LTS, Large Taxpayer, e-commerce, CAS/CBA) — TindaFlow cannot determine this on the store's behalf. Do not assume V1's `NullSalesTransmissionProvider` remains sufficient indefinitely, and do not encode "POS users are exempt" as a domain rule. |
| BIR-010 ORUS feature date | The specific date attributed to ORUS gaining CAS/POS registration functionality is unverified and may be a re-publicization of a pre-existing 2020–2022 capability rather than a genuinely new 2025 feature. |
| Numeric specifics under BIR-003/BIR-004 | RMO 24-2023's digest names "Data Retention," "Sales Data Transmission," etc. as required specification *categories* but the digest read did not yield the exact numeric retention window or transmission-timing figures BIR's TWG tests against during accreditation demonstration — these should be pulled from the full (non-digest) RMO 24-2023 annexes before a real accreditation attempt. |

## Sourcing note

Primary sources read directly (HIGH confidence basis): RMC 77-2024 (full
text, not just digest), RMO 24-2023, RR 11-2025, RR 26-2025 — fetched from
`bir-cdn.bir.gov.ph` — and RA 11976 (full text, via lawphil.net), which
directly supplied the Section 33 retention citation (BIR-009) and confirmed
the ₱500 threshold rule (BIR-002) referenced by RMC 77-2024.
RR 7-2024's own PDF returned an HTTP 403 on fetch attempt during this
research pass and was not independently read; its operative content used
here is corroborated via RMC 77-2024 (which quotes it) and reputable
secondary tax-advisory sources (Grant Thornton, CloudCFO). Re-attempt a
direct fetch of RR 7-2024 (and RR 11-2024) in a future research pass and
upgrade its confidence rating accordingly.

## Revision log

- **2026-09-16 (initial):** Register created from BIR compliance research pass.
- **2026-09-16 (correction pass, per owner review):** BIR-002 threshold and
  BIR-009 retention-period citation resolved against primary sources (RMC
  77-2024 full text; RA 11976 §33) and removed from BIR-REVIEW-REQUIRED.
  BIR-008 EIS conclusion refined to explicitly prohibit encoding "POS users
  are exempt from e-invoicing" as a permanent domain rule, since EIS
  applicability is taxpayer-specific and independent of POS usage alone.
- **2026-09-16 (Stage 2 discount-allocation correction pass):** Added
  BIR-012 (RMC 72-2025 — Accreditation vs. PTU are independent lifecycles)
  and BIR-013 (BIR eAccReg's POS Standalone / POS with SERVERCONS
  deployment models, supporting the Stage 2 `fiscal_installation`
  cardinality decision). Both cited directly by the product owner; not
  independently re-fetched by this session, so both carry MEDIUM
  confidence and a `Needs professional confirmation? YES`/re-verify note
  pending an independent read of the primary sources.
- **2026-09-16 (pre-Stage-3 verification):** BIR-012 and BIR-013 upgraded
  to HIGH confidence — the product owner independently checked both
  against BIR's primary/live sources and confirmed the recorded substance.
  Added BIR-014 (RMO 24-2023's Z-Reading business-day-coverage rule —
  no post-closure transactions for that operation date), independently
  verified by the owner, supporting Stage 2's `fiscal_day`/`z_reading`
  closure invariants and informing Stage 3's concurrency requirements.

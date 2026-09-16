# Market Comparison — TindaFlow vs. Reference POS Products

## Status
APPROVED — Stage 1 baseline (`stage-1-baseline`), conditionally approved
2026-09-16 with a correction applied to the competitor BIR-accreditation
conclusion (see Key Takeaways §1 and Confidence/sourcing notes). Based on
web research conducted 2026-09-16.
Third-party claims (especially BIR-accreditation claims) are marked with
confidence levels; none should be repeated as fact in marketing material
without independent verification against BIR's own accredited-system list
(https://www.bir.gov.ph/index.php/bir-accreditations.html).

## Reference products researched

- **Loyverse POS** — free/freemium, cloud-first, mobile-native SaaS.
- **Odoo POS** — open-source core (Community) + paid Enterprise/cloud tiers, full ERP suite.
- **ERPNext POS** — open-source (Frappe Framework), full ERP suite, self-hosted or Frappe Cloud.
- **uniCenta oPOS** — open-source (GPLv3), legacy Java desktop POS, self-hosted.

## Comparison matrix

| Dimension | Loyverse | Odoo POS | ERPNext POS | uniCenta oPOS | **TindaFlow (planned)** |
|---|---|---|---|---|---|
| Deployment | Cloud SaaS only, native mobile app | Self-host (Community) or Odoo-managed cloud (Enterprise) | Self-host or Frappe Cloud | Self-host desktop (Java), optional paid hosted/browser mode | Self-host Docker Compose (on-prem mini-PC, VPS, or store LAN server) |
| Pricing | Freemium; paid add-ons per store/employee | Free core (Community); tiered per-user SaaS pricing for Enterprise | Free/GPLv3; Frappe Cloud hosting from ~$5/mo | Free/GPLv3 core; paid support subscriptions | Self-hosted, no per-seat licensing (owner-operated infra cost only) |
| Tech stack | Undisclosed (native Android/iOS apps) | Python + PostgreSQL + OWL (JS) | Python (Frappe) + MariaDB + JS/Bootstrap | Java (Swing), Derby/HSQLDB/MySQL/PostgreSQL | Laravel (PHP) + PostgreSQL + React/TypeScript |
| True LAN-only / no-internet operation | **No** — offline is per-device cache, no shared multi-terminal LAN mode | Partial — offline order queue on browser cache is for *temporary* connectivity loss, not designed offline-first; IoT Box bridges hardware on LAN | Partial — offline browser-cache sync (~1 min) for gaps; true LAN-only self-hosting possible but not a purpose-built "offline mode" | **Yes** for single terminal w/ local DB; multi-terminal LAN setups fail if a terminal loses the DB link (no queue/sync) | **Yes by design (V1 requirement)** — checkout has zero functional dependency on internet; LAN-first architecture from day one |
| Inventory depth | Basic on free tier; full ledger (POs, transfers, adjustments) behind paid "Advanced Inventory" add-on | Full double-entry stock ledger, multi-warehouse, automated reordering — a core strength | Full stock ledger, multi-warehouse, batch/serial, integrated procurement | Stock levels, reorder thresholds, multi-warehouse, transfers — no full ERP-grade purchasing | Movement ledger (Module I) from V1; full purchasing/PO module deferred to Phase 2 |
| Multi-store support | Native, free tier | Native multi-company; true POS shop-switching often needs 3rd-party modules | Native multi-warehouse/multi-company | Native but manual/config-fragile per user reports | Deferred to Phase 2 (single-store V1 by design, per project brief) |
| Reporting | Real-time dashboard + Back Office reports; historical export is a paid add-on | Built-in pivot/KPI dashboards; advanced BI via 3rd-party (e.g., Power BI) connectors | Built-in configurable dashboards + drag-and-drop report builder; separate Frappe Insights BI tool | JasperReports canned reports across 6 categories; no modern BI dashboard | 15 authoritative reports (Module L) generated from the transaction/inventory ledgers, not frontend calculations, with CSV export |
| BIR (Philippines) accreditation | **No official product-level claim found.** Third-party competitor sources (StoreHub, HashMicro) state Loyverse is not on BIR's accredited list (as of a 2023-dated citation) — unverified against BIR's live list, third-party-sourced | Odoo has an official **Philippines fiscal localization** (tax/COA/BIR Form 2307/SLSP), but no evidence Odoo Inc. itself or generic Odoo POS holds product-wide CAS/PTU accreditation. Odoo's **own official customer/partner directory** (odoo.com/customers, Philippines listing) does carry Philippine implementation partners marketing BIR-accredited Odoo POS builds (e.g., Courtesy Point Technologies' "PRESTO" product) — these are accredited *implementations/configurations*, not a statement that upstream Odoo POS is universally accredited | **No evidence of BIR accreditation or Philippine localization** — Philippines is absent from Frappe's official supported-country compliance list | **No evidence found** of any BIR accreditation or Philippines-specific localization | **Not accredited (explicitly, honestly stated)**; V1 ships with BIR-readiness *architecture* (immutable ledger, audit trail, sequential invoicing, inert PTU/MIN fields) so a real accreditation effort is additive, not a rewrite — see [bir-reference-register.md](bir-reference-register.md) |
| Target fit for a PH sari-sari/minimart | Good low-cost entry, but no shared-LAN multi-register and no confirmed compliance path | Powerful but heavyweight — setup complexity and hardware/IoT Box needs exceed a single small store's capacity | Same heavyweight/self-hosting-skill concern as Odoo, plus resource requirements exceed low-end store hardware | Very low hardware requirements (a real strength), but legacy UX, fragile multi-terminal support, no compliance path | Purpose-built for this segment: modern web UI, cashier-speed-first UX, low hardware needs, genuine LAN-first operation, and an honest, extensible compliance story |

## Key takeaways for TindaFlow's positioning

1. **This research did not establish universal, product-level Philippine
   BIR accreditation for any of the four generic upstream products.**
   Localized implementations, partner solutions, or specific accredited
   configurations may exist and must be independently verified against
   their corresponding BIR accreditation before any comparison relies on
   them — this is confirmed for Odoo, where Philippine implementation
   partners (via Odoo's own official customer/partner directory) publicly
   claim BIR-accredited POS builds, though these are partner-level/
   configuration-specific accreditations, not a statement about generic
   upstream Odoo POS. **Do not use competitor accreditation status as a
   product differentiator unless supported by independently verified
   evidence** — TindaFlow's differentiation is being honest about its own
   *not-yet-accredited* status today while building the architecture that
   makes a real accreditation effort tractable later, not a claim that
   competitors universally lack any path to accreditation.
2. **True LAN-only, no-internet, multi-terminal operation is a genuine gap**
   across Loyverse (cloud-dependent for multi-device sync), Odoo/ERPNext
   (offline is "tolerate a gap," not "designed to run this way"), and even
   uniCenta (single-terminal only; multi-terminal LAN setups have no
   resilience if the DB link drops). This is a core differentiator for
   TindaFlow to protect architecturally (see [scope.md](../00-product/scope.md)
   LAN-first requirement) — do not regress it under feature pressure later.
3. **Odoo's own offline implementation has a documented, open data-loss bug**
   (browser localStorage commit delay vs. power-loss/crash timing —
   `odoo/odoo#125037` on GitHub) — a cautionary example for TindaFlow: any
   client-side caching for resilience must be evaluated against real
   power-loss/brownout scenarios common in Philippine stores, not just
   network blips.
4. **Full ERP suites (Odoo, ERPNext) are the wrong shape** for this
   customer, not because they lack features but because they carry setup and
   operational complexity (multi-warehouse routing, IoT Box config, server
   sizing) disproportionate to a single sari-sari/minimart. TindaFlow's
   "modular monolith, no microservices, boring stack" principle
   (product-vision.md) is a deliberate reaction to this.
5. **uniCenta validates the low-hardware-requirement end of the market** —
   confirms that a self-hosted Filipino minimart POS on commodity/older
   hardware is a proven, viable model; TindaFlow should validate its own
   minimum hardware spec against similarly modest targets in Stage 3.

## Confidence and sourcing notes

- Most BIR-accreditation-status claims above are sourced from third-party web
  content (competitor marketing, reseller blogs, community forums), **not**
  from BIR's own accredited-system registry. The Odoo partner-accreditation
  claim is sourced from Odoo's own official customer/partner directory
  (odoo.com/customers), which is stronger evidence than a reseller blog but
  still describes a partner-level/configuration-specific accreditation, not
  an independent BIR-registry confirmation. None of these claims — for any
  product, including TindaFlow's own future accreditation status — should be
  repeated as settled fact without checking BIR's live accredited-system
  list directly. They are included here to describe the *market positioning
  landscape*, not as authoritative compliance findings. Any claim TindaFlow
  makes about its own (non-)compliance status must be sourced independently
  — see [bir-reference-register.md](bir-reference-register.md).
- Pricing figures for all products are promotional/first-year or
  third-party-aggregated at the time of research (2026-09-16) and should be
  re-verified before being used in any customer-facing comparison.
- Full per-product research notes (with primary source URLs) are retained in
  this session's agent transcripts and can be re-derived on request; this
  document summarizes rather than exhaustively cites every source line.

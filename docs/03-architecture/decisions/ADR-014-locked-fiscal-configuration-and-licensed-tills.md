# ADR-014: Locked Fiscal Configuration and a Licensed Till Count

## Status
Accepted — 2026-10-03, at the owner's request ("store setup should only be available to the vendor, not the client's admin, so tills can't be created beyond what was deployed"), after the architect's review of that request. Not part of the frozen Stage 1–6C corpus; amends the capability table in `api-design.md` and `openapi.yaml` in place, following the precedent of the store-setup and users passes. `docs/PROJECT-MANIFEST.md` records the exception.

## Context
Store Setup was one screen set, all gated by `FISCAL_CONFIGURATION_MANAGE`, which every `ADMIN` held. It mixed three different things: the shop's own data (business details, stock locations), the **fiscal identity** (tax registration, fiscal installation, invoice series, which terminal sits under which installation) and, since the "Add terminal" button, the number of tills. The owner wants the vendor, not the client, in control of the last two.

Facts that shaped the answer:
- TindaFlow is **self-hosted, one store per server** (ADR-001, `architecture.md` §22). The shop controls the machine, its database and its environment. Any rule enforced only in the application is a guardrail against honest mistakes, not protection against someone determined.
- The fiscal items are the ones where a casual edit hurts: a second invoice series, a changed tax registration or a reassigned terminal can break invoice numbering and what prints on receipts.
- Business details and stock locations are the owner's own data. Routing every address fix or new shelf through the vendor makes the vendor a bottleneck and the owner a tenant in their own shop.
- A standing vendor super-admin account inside each client's system is a permanent credential into every client's sales and personal data (Data Privacy Act exposure), and the code scopes every query by `store_id`, so a cross-store role cuts against the architecture.

## Decision
1. **Split Store Setup in two.**
   - **Stays with the client's `ADMIN`** (`STORE_SETTINGS_MANAGE`): business details and stock locations. Creating or editing a stock location moves from `FISCAL_CONFIGURATION_MANAGE` to `STORE_SETTINGS_MANAGE`.
   - **Locked by default** (`FISCAL_CONFIGURATION_MANAGE`): tax registration, fiscal installation, invoice series, assigning a terminal to an installation.
2. **Reading stays open.** `fiscalInstallationList` and `invoiceSeriesList` become session-only (as `taxRegistrationList` already was), so a shop can always see what is configured; the screens show it read-only with a notice. The readiness checklist keeps working for the admin.
3. **No vendor account. A time-boxed unlock instead.** `FISCAL_CONFIGURATION_MANAGE` is no longer part of any role. An `ADMIN` holds it only while the server operator has opened a window for that store: `php artisan tindaflow:fiscal-setup unlock --minutes=N` (1–480, default 60), `lock` to close early, `status` to look. The window lives in the cache, so a flushed cache fails closed; `RoleCapabilityCatalog::forUser()` adds the capability to login/me and the Gate, from one source, so the two cannot disagree. MANAGER and CASHIER never get it. Opening and closing are audited (`FISCAL_CONFIGURATION_UNLOCKED` / `_LOCKED`, actor `null`, `via: console`).
4. **The number of tills is a signed license, not a role check.** A license is `base64url(payload).base64url(Ed25519 signature)` over `{deployment, max_terminals, issued_at, expires_at}`. The vendor keeps the private key and signs on their own machine (`tindaflow:license-keygen`, `tindaflow:license-issue`). The installation verifies with a **public key that is a constant in `config/tindaflow.php`**, deliberately not an environment variable: a shop that could swap the key could sign its own license. The license names the installation's deployment code (`DeploymentId`, ADR-008 addendum 10), so it does not verify on another server. `tindaflow:license-status` prints the code to sign for.
5. **What counts and what is checked.** A seat is a terminal that is neither revoked nor decommissioned, so a stolen till can be revoked and replaced. The cap is checked, under a lock on the store row, in `terminalCreate` (`POST /terminals`) and the console `tindaflow:create-terminal`; and, because re-enrolling a revoked terminal clears its revocation and so takes a seat back, when issuing an enrollment token for a revoked terminal and again when the credential is issued. The refusal is `409 TERMINAL_LIMIT_REACHED` with `max_terminals` and `in_use`. `GET /terminals` reports `meta.license` (`null` when no cap applies) and the screen shows "n of m tills in use".
6. **A license never stops selling.** It only blocks adding a till or re-enrolling a revoked one. An expired, missing or forged license when a public key is configured means "no new tills", never "the shop cannot sell".
7. **Opt-in.** With no public key in the build there is no cap and nothing changes for existing deployments. The vendor turns enforcement on by generating a key pair, committing the public key, and issuing licenses.

## Consequences
- A client's administrator can no longer open a second invoice series or change the tax registration on their own. Installation and support visits now start with `tindaflow:fiscal-setup unlock`; the Docker README says so. Every test or script that configures the fiscal side as an administrator must unlock first (`PostgresSchemaTestCase::fiscalAdmin()`; `scripts/help-shots/run.sh`).
- **What this does not do, said plainly.** Whoever controls the server can run `tindaflow:fiscal-setup unlock`, edit the database, or rebuild the image with their own public key. The unlock is a guard against mistakes by the shop's staff; the license is as strong as the build is trustworthy. Stopping a determined operator is a legal matter (the license agreement), not a code matter, consistent with the deployment watermark (ADR-008 addendum 10).
- The vendor now holds a private signing key. Losing it means no new licenses can be issued for builds carrying its public key; leaking it means anyone can issue licenses. Custody and backup of that key are the owner's responsibility, and `tindaflow:license-keygen` refuses to overwrite an existing key file for that reason.
- Existing tills keep working under any license state, so a lapsed license cannot stop a shop trading.

## Alternatives considered
- **A vendor `SUPER_ADMIN` role in each client's system.** Rejected: a standing credential into every client's data, a cross-store role in a store-scoped design, and the vendor as a runtime dependency.
- **Lock all of Store Setup.** Rejected: it takes the owner's own data away and makes the vendor a support bottleneck for edits that carry no fiscal risk.
- **Remove "Add terminal" and keep the console command only.** Rejected: a shop that buys a second register would need a visit. A capped button serves both goals.
- **A till count in `.env`.** Rejected as enforcement: the shop edits `.env`. Kept the idea only as the absence of a key (no cap).
- **A role check on `terminalCreate` alone.** Rejected: it limits who clicks, not how many tills exist, and does nothing about re-enrolling a revoked terminal.

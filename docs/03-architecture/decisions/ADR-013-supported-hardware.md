# ADR-013: Supported Hardware Floor — Specifications and Tiers, Not a Bill of Materials

## Status
Accepted — 2026-09-27, as a decision the owner **delegated to the architect** ("let the software architect decide on the checklist").
Not part of the frozen Stage 1–6C corpus (this is a new file); `deployment.md` (Stage 3, frozen) gains a one-line pointer to it, an
exception recorded in `docs/PROJECT-MANIFEST.md`.

## Context
`docs/01-research/hardware-requirements-and-pricing.md` (research, 2026-09-25) proposed a minimum and a recommended specification
for the store server and the cashier terminal, and asked three things of the owner: approve the matrix, say where it lands
permanently, and decide whether to publish a recommended-hardware list at all. What hardware is supported constrains installation,
performance testing and what a shop is told to buy. The numbers in it that describe the *software* (browser floor, keyboard-first
design, PostgreSQL's fsync-bound commit path) were read from the repository; the memory budget and storage growth are
**engineering estimates, not measurements**.

## Decision
1. **The matrix in §3.2 (server) and §4 (terminal) of the research document is the supported floor.** In short: an x86-64 server with
   2 physical cores, **4 GB RAM** (8 GB for three or more terminals, or when the server is also a till), a **128 GB SATA or NVMe SSD**,
   64-bit Linux with Docker Engine, wired Ethernet with a fixed address, a **UPS on the server**, and a **working real-time clock**; a
   terminal with any dual-core x86-64, 4 GB RAM, an SSD, **Chrome or Edge 111+ / Firefox 128+**, a physical keyboard, and the receipt
   printer installed as an operating-system printer.
2. **Explicitly not supported:** a spinning disk, SD card or eMMC as the database disk; a Windows host with Docker Desktop under 8 GB;
   Raspberry Pi-class ARM boards (untested, not "never"); a server without a UPS; Windows 7 and 8.1 terminals.
3. **We publish specifications and tiers (§6 of the research document), not a brand-specific bill of materials.** A named list invites
   "but you recommended this printer" support conversations for a product that cannot test every unit, and prices in this market move
   weekly. Peripherals are specified by behaviour: a USB keyboard-wedge scanner configured with an Enter suffix and no prefix; an
   80 mm receipt printer with a driver the operating system can print through (terminals launched with `--kiosk-printing` so a
   receipt prints without a dialog); a cash drawer with an RJ11 "printer-kick" port even though V1 cannot open it; and, for backup
   media, a reputable-brand drive (marketplace "SSDs" at implausible capacities are the one purchase where cheapest is dangerous).
4. **Memory and storage: a first measurement exists (below), the pilot's measurement supersedes it.** Re-measure with `docker stats` and
   `df` on the pilot store's server after two weeks of real use; if that contradicts this ADR, this ADR is revised, not the
   measurement. The 4 GB floor is deliberately kept: it is set by the operating system, Docker, a browser on the same machine and
   growth headroom, not by what the three containers use today.
5. **Clock integrity:** the requirement is a working RTC battery, checked at installation (an install-checklist item). A software
   plausibility check that refuses to open a fiscal day on an implausible clock is **not built now**; it waits for pilot evidence of a
   clock problem. (The stale-business-day notice already shows the till when the open day's date is behind the till's clock.)
6. **Backups do not depend on this server's disk.** The off-machine copy (ADR-008 as implemented in `docker/README.md`,
   "Off-machine copy and alerts") means the one drive a shop must not economise on is protected by a second copy elsewhere; the
   backup destination is separate from `pgdata` and from the server.

## First measurements (2026-09-27, development laptop, the production Docker stack; not a load test and not a store)
- **Memory, the four containers together: about 78 MiB idle** (PostgreSQL 31, PHP-FPM app 35, nginx 10, backup 1.5) **and about 85 MiB
  after 600 authenticated requests in 6 seconds** (6 at a time, every one answered 200). The research document estimated 1.5–2.5 GB
  typical and about 4.5 GB worst case for the same components, so the estimate was conservative by an order of magnitude for a
  light workload. It is not thrown away: a real store adds resident database cache, more PHP workers under a checkout burst, the
  backup's transient dump, and growth; only the pilot's own measurement can move the floor.
- **Storage: 7.28 KB per sale, all-in** (two thousand sales of five lines each through the real checkout: every row, index, journal
  and audit entry, then `VACUUM ANALYZE`), against the estimated 6-10 KB. At 300 sales a day that is about 2.2 MB a day, **about 0.8 GB
  a year**, so storage capacity is not a constraint; storage type is. Invoices, the electronic journal, sale items and the stock
  ledger are the four largest tables. An empty database is about 10 MB; the `pgdata` directory of a fresh install is about 49 MB.
- **Checkout cost: about 23 ms per sale** on the service code path (no HTTP), one at a time.
- **Images:** app 869 MB, web 105 MB, backup 746 MB (PostgreSQL 17 plus curl and rclone), so budget a couple of gigabytes for
  images before any data.

## Consequences
- Installation has a checklist to hold a machine against, and performance testing has a floor to test on: the 4 GB / 2-core
  server is the machine to measure, not the recommended one.
- We accept the support risk of not publishing a shopping list, in exchange for not being answerable for a supplier's stock and
  prices. A shop that wants a list can be given the reference tiers with the price evidence marked as observed on a date.
- Nothing here changes the software. It changes what is promised to a shop and what the installer checks.

## Alternatives considered
- **Publish a brand-specific bill of materials.** Rejected: support liability and stale prices (above).
- **Support ARM boards now.** Rejected: nothing has been tested there and the default storage is an SD card, which the database
  disk rule already excludes. Revisit if someone tests it.
- **Require a software clock check now.** Rejected until a pilot shows a real clock problem; a working RTC battery at install is the
  cheap, sufficient control, and a wrong refusal to open a day would stop a working shop.
- **Put the matrix in `deployment.md`.** Rejected: that file is frozen and describes the topology; a supported-hardware decision
  that will be revised after measurement belongs in its own decision record.

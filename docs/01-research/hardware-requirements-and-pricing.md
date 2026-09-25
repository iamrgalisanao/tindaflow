# Hardware Requirements & Philippine Pricing — TindaFlow POS

## Status

DRAFT — research, 2026-09-25. Web research and price observation conducted
**2026-09-25**; FX reference ₱62.82 = US$1 (xe.com / wise.com, same date).

Two things need owner attention before any part of this is treated as settled:

1. **The minimum/recommended spec matrix (§3–§5) is a proposal**, not an
   approved support boundary. Once approved it belongs in `deployment.md` (a
   Stage-3-owned file) or a dedicated ADR, because "what hardware we support"
   constrains Stage 8 performance testing and Stage 9 installation work.
2. **Governance note:** this file is *new* content in `docs/01-research/`, a
   directory owned by the frozen `stage-1-baseline` under the manifest's
   path-ownership map. It **modifies no file in the tagged Stage 1 tree**, and
   `scripts/validate-baselines.sh` still passes all checks with it present
   (the script validates tags, not the working tree). If the owner prefers
   post-Stage-1 research to live outside a sealed stage directory, relocate
   this file; the content is directory-independent.

**Prices are volatile.** Every figure is a single observation on the date
above from Philippine online retail (Lazada/Shopee listing prices, which
include promotional discounts that expire) or from a vendor's own published
price page. Re-check before quoting any of it to a store owner. Nothing here
is a negotiated quotation.

---

## 1. Why this research exists

The governing brief targets three deployment environments (`deployment.md`
§1): an on-premise mini-PC, a VPS, and a store LAN server with several
terminals. Nothing in the repository has ever stated what hardware actually
satisfies that, and the architecture's own performance targets
(`architecture.md` §21) are explicitly qualified as holding "on modest
hardware (the mini-PC/VPS class the brief targets)" — a class that was never
defined. This document defines it, from the stack the repository actually
ships, and prices it in the market the product is for.

## 2. What the software actually demands

Derived from the repository, not assumed:

| Fact | Source |
|---|---|
| Three containers: `nginx` (or the `web` image), PHP-FPM `app`, `postgres` 16 | `docker/compose.yaml`, `docker/app/Dockerfile`, `deployment.md` §2 |
| PHP 8.4-FPM, `memory_limit = 256M` per worker, opcache 128 MB shared | `docker/app/php.ini`, `Dockerfile` `ARG PHP_VERSION=8.4` |
| No Redis, no queue worker, no search engine in V1 | `deployment.md` §2, `architecture.md` §3 |
| Terminals are **browsers only** — no installed client, no direct DB access | `architecture.md` §16, ADR-002 |
| Frontend is React 19 + Tailwind CSS v4, built by Vite 8 | `package.json` |
| Receipt printing is `window.print()` on an 80 mm print stylesheet — **no ESC/POS, no cash-drawer kick in V1** | ADR-007 |
| Barcode scanners are USB HID keyboard-wedge devices; no driver, no server-side integration | `architecture.md` §14 |
| POS layout verified with zero page scroll at **1500×850**; responsive down to 375 px; touch targets ≥44 px | `docs/06-ui/stage-30-pos-layout.md` |
| Targets: barcode lookup p95 ≤150 ms, checkout ≤500 ms, ≥4 concurrent terminals | `architecture.md` §21 |
| Backups are scheduled `pg_dump` to a **physically separate disk**, then encrypted | ADR-008, `deployment.md` §5 |

Two consequences fall straight out of this list and drive most of §3–§5:

- **Tailwind CSS v4 sets a hard browser floor: Chrome 111+, Safari 16.4+,
  Firefox 128+** ([tailwindcss.com/docs/compatibility](https://tailwindcss.com/docs/compatibility)).
  Chrome's last release on Windows 7/8.1 was 109. **Windows 7/8.1 machines
  cannot run the TindaFlow POS screen correctly** — not a performance
  question, a rendering one.
- **ADR-007's browser-print decision excludes tablets as V1 terminals.**
  Printing an 80 mm receipt from the browser requires the terminal's
  operating system to expose the thermal printer as a printer. Windows and
  Linux do this with the vendor driver; Android and iOS need Mopria/AirPrint
  support, which budget thermal printers overwhelmingly lack. A V1 terminal
  is therefore a Windows/Linux/macOS machine. This is a real cost consequence
  of ADR-007 and is revisited in §8.

## 3. Server (Docker host) — proposed minimum and recommended

### 3.1 Memory budget (engineering estimate, not measured)

| Component | Typical steady | Worst case |
|---|---|---|
| PostgreSQL 16 (`shared_buffers` 256 MB + backends) | 400–700 MB | ~1 GB |
| PHP-FPM pool (6–10 workers; each may reach the 256 MB limit) | 400–800 MB | ~2.5 GB |
| nginx | <50 MB | 100 MB |
| Docker Engine + minimal Linux host | 300–500 MB | 700 MB |
| Backup job (`pg_dump` → gzip → gpg), transient | 100–200 MB | 300 MB |
| **Total** | **~1.5–2.5 GB** | **~4.5 GB** |

This is a structural estimate from the configured limits, **not a measured
figure** — no load test has been run yet (Stage 8's job). Treat 4 GB as a
floor that works and 8 GB as the number that stops the backup job and a
checkout burst from competing for page cache.

### 3.2 Proposed matrix

| | Minimum (supported floor) | Recommended |
|---|---|---|
| CPU | x86-64, 2 physical cores (Intel N100/N150, Celeron J-series, any i3 from ~2015) | 4 cores (N150, i3/i5 8th gen+, Ryzen 3) |
| RAM | **4 GB** — Linux host, ≤2 terminals | **8 GB** — 3–4 terminals, or if the server also runs a cashier browser |
| Storage | **128 GB SATA or NVMe SSD** | 256 GB NVMe SSD + a separate disk/partition for `/backups` |
| OS | 64-bit Linux with Docker Engine (Debian 12 / Ubuntu 22.04+) | same |
| Network | 100 Mbps wired to the switch, static IP or DHCP reservation | Gigabit wired |
| Power | UPS on the server, always | 1000 VA / 600 W line-interactive with AVR |
| Clock | Working RTC/CMOS battery | RTC + a time source on the LAN |

**Explicitly not supported (proposed):**

- **A spinning HDD, SD card, or eMMC as the `pgdata` disk.** PostgreSQL's
  commit path is fsync-bound; an SD card or eMMC also wears out under WAL
  writes. This is a data-integrity position, not a speed preference.
- **A Windows host with Docker Desktop below 8 GB RAM.** The WSL2 VM reserves
  gigabytes before a container starts. Windows hosting is workable at 8–16 GB
  but it is the expensive way to run this stack.
- **Raspberry Pi-class ARM boards.** ARM64 images exist for every component,
  but nothing has been tested there and the default storage is an SD card
  (see above). Not a "no forever" — a "not in the supported set until
  someone tests it."
- **No UPS on the server.** In this deployment model, sudden power loss to
  the PostgreSQL host is the single largest threat to the store's financial
  data, and ADR-008 addresses backup, not power. A ₱2,650 UPS is the cheapest
  line item in this document and the one most likely to save a day's sales.

### 3.3 Storage growth (estimate, unmeasured)

Per completed sale the system writes: the `sale` row, its `sale_items`,
payment rows, an `invoice` with a canonical snapshot JSON (`InvoiceSnapshot*`
renderers — a structured document carrying store/terminal/line/tax/payment
detail, order of 1–3 KB for a typical 5-line sale), stock-ledger movements,
an electronic-journal entry, and audit events, plus index overhead. Call it
**6–10 KB per sale, all-in**.

- 300 sales/day → ~2–3 MB/day → **≈0.7–1.1 GB/year**
- Backups (30 daily + 12 monthly per ADR-008's proposed retention, compressed)
  → budget roughly 2–3× the live database size, on a separate disk

A 256 GB SSD therefore holds roughly a decade of a busy minimart. Storage
capacity is not a constraint on this product; storage *type* (§3.2) is. The
dev database (`tindaflow_stage5_test`) has no sales volume to measure
against, so this remains an estimate — worth confirming during Stage 8 with a
seeded volume test.

## 4. Cashier terminal — proposed minimum and recommended

| | Minimum | Recommended |
|---|---|---|
| CPU | any dual-core x86-64 (~2015 onward) | quad-core |
| RAM | 4 GB | 8 GB |
| Storage | 120 GB SSD | 240 GB SSD |
| Display | 1366×768 | ≥1500×850 logical (the width the two-column POS layout was verified at) |
| Browser | **Chrome/Edge 111+ or Firefox 128+** | current stable Chrome/Edge |
| OS | Windows 10/11, or a Linux desktop | same |
| Input | **Physical keyboard required** | keyboard + optional touchscreen |
| Printing | the 80 mm printer installed as an OS printer | same, plus kiosk printing (§5.6) |

Notes:

- **The keyboard is not optional.** The design is barcode-first and
  keyboard-first (personas.md, `architecture.md` §14): a scan is a keystroke
  burst ending in Enter, and the cart input must hold focus. Touch works
  (targets are ≥44 px) but a touch-only device fights the intended workflow.
- **Windows 7/8.1 terminals are excluded** — see §2.
- **The server can also be the terminal.** For a single-register store, one
  mini PC running Docker *and* a browser is a legitimate deployment (Target A
  in `deployment.md` §1 permits "a single machine acting as both terminal and
  server"). Give that machine 8–16 GB, since the browser and the stack now
  share it.

## 5. Peripherals

### 5.1 Barcode scanner

USB HID keyboard-wedge, **configured with a CR/Enter suffix and no prefix** —
that suffix is what `architecture.md` §14 relies on to distinguish a scan from
manual typing. 1D laser or CCD is sufficient for retail EAN-13/UPC; pay for
2D imaging only if the store will scan QR or GS1 DataMatrix. A stand matters
more than brand for counter speed.

### 5.2 Receipt printer

80 mm, auto-cutter, with a Windows/Linux driver the OS can print through.

- **USB** is the simplest: one printer per terminal.
- **Ethernet/LAN** costs a little more and lets two terminals share one
  printer; it is also the least-regret choice ahead of a future ESC/POS
  implementation behind the `InvoicePrinter` seam.
- Cutter longevity is a non-issue at this scale: budget units advertise ~1.5 M
  cuts and Epson's TM-T82IV claims 2.0 M, against ~110 k receipts/year at 300
  sales/day. What the branded tier actually buys is build quality, parts, and
  service — not cut count.

### 5.3 Cash drawer

Buy an **RJ11 ("printer-kick") drawer even though V1 cannot kick it.**
ADR-007 defers the drawer trigger, so in V1 the drawer opens by its key or
manual release. An RJ11 drawer costs the same as one without and means the
Phase 2 kick feature needs no second purchase.

### 5.4 Network

Wired Ethernet for any multi-terminal store. Wi-Fi is acceptable for a single
terminal but note the target is a p95 ≤150 ms scan round-trip
(`architecture.md` §21): Wi-Fi contention and consumer-AP brownouts are the
usual root cause of "the POS is slow" complaints in this segment. The server
needs a fixed address (static IP or DHCP reservation) because terminals are
pointed at it by name/address and the LAN TLS certificate is issued for it
(`deployment.md` §4).

### 5.5 Backup media

Per `deployment.md` §5, backups must land on a **physically separate** disk
from `pgdata`. Two drives rotated off-site is the intended pattern.

⚠️ **Caution on marketplace "SSDs":** listings advertising 500 GB–2 TB
portable SSDs for ₱300–700 are common and frequently mislabeled or fake
(controller reports a capacity the flash does not have — data loss appears
only when the drive fills). For backup media specifically, buy a
reputable-brand portable SSD or two branded USB drives. This is the one line
item where the cheapest option is actively dangerous.

### 5.6 Terminal configuration finding: silent printing

With ADR-007's `window.print()` path, every receipt raises the browser's
print dialog — one extra keystroke per sale, at the exact moment the line is
waiting. Chrome and Edge launched with **`--kiosk-printing`** print silently
to the default printer instead. Recommend this as the standard terminal
launch configuration (kiosk/fullscreen + `--kiosk-printing`, default printer
set to the 80 mm unit, paper size configured to the roll). Worth reflecting in
the Stage 7/30 UI documentation and the Stage 9 installation checklist.

### 5.7 Clock integrity — a fiscal risk specific to internet-offline stores

Invoice timestamps, fiscal days, and shift boundaries are financial facts. In
an internet-offline store (the designed-for case, ADR-002) there is no NTP
reachable, so the server's own RTC is the sole time authority. A mini PC with
a dead CMOS battery can boot with a badly wrong clock and stamp invoices and
Z-readings with it — silently. Two mitigations, both cheap: require hardware
with a working RTC battery (and check it during installation), and consider a
startup sanity check that refuses to open a fiscal day if the clock is
implausible relative to the last closed one. **Flagged as an open item (§9)**;
it is a correctness concern the current documents do not address.

## 6. Reference configurations and prices

All figures are Philippine online retail, observed 2026-09-25 (§7 for
sources). VAT-inclusive marketplace prices. Shipping, installation labour, and
cabling runs are excluded except where stated.

### Tier A — "counter in a box": one register, server and terminal on one machine

For a sari-sari store or small minimart with a single till.

| Item | ₱ (typical) |
|---|---|
| Mini PC, N100/N150, 16 GB RAM, 512 GB SSD | 10,500 |
| Monitor, 22" | 2,000 |
| Keyboard + mouse | 500 |
| Barcode scanner, 1D USB with stand | 900 |
| Thermal printer, 80 mm, auto-cutter, USB+LAN | 2,700 |
| Cash drawer, RJ11-ready | 1,900 |
| UPS, 650 VA with AVR | 2,650 |
| Backup media (branded, 2 drives rotated) | 1,200 |
| Thermal paper, 1 box × 50 rolls | 1,200 |
| **Total** | **≈ ₱23,550** (range ₱19,000–29,000) |

### Tier B — one register, dedicated server (the "we will add a second till" store)

| Item | ₱ |
|---|---|
| Server: mini PC, 16 GB / 512 GB SSD | 10,500 |
| Server UPS, 1000 VA / 600 W | 5,000 |
| Terminal: mini PC or refurbished SFF desktop | 8,500 |
| Terminal monitor + keyboard/mouse | 2,500 |
| Terminal UPS, 650 VA | 2,650 |
| Printer / scanner / drawer | 5,500 |
| 8-port gigabit switch + cabling | 2,400 |
| Backup media + paper | 2,400 |
| **Total** | **≈ ₱39,450** (range ₱35,000–46,000) |

### Tier C — three registers on a store LAN (Target C)

| Item | ₱ |
|---|---|
| Server: 4-core, 16 GB, 256 GB NVMe + separate backup disk | 15,000 |
| Server UPS, 1000 VA / 600 W | 5,000 |
| 3 × terminal (PC + monitor + keyboard/mouse + 650 VA UPS ≈ ₱13,150) | 39,450 |
| 3 × printer (₱2,700) | 8,100 |
| 3 × scanner (₱900) | 2,700 |
| 3 × cash drawer (₱1,900) | 5,700 |
| Switch + structured cabling/labour | 4,200 |
| Branded backup SSD + 3 boxes of paper | 6,100 |
| **Total** | **≈ ₱86,250** (range ₱78,000–95,000) |

### Tier D — VPS (Target B)

No server hardware or server UPS; terminals as Tier B/C. A 2 vCPU / 4 GB /
80 GB NVMe VPS runs **₱946/month** from a PH-facing host (Hostking), and
roughly ₱950–1,900/month across common providers at that size.

⚠️ **State the trade-off plainly when recommending this:** on a VPS, the
terminals reach the authoritative server over the internet, so **an internet
outage stops checkout** — the exact property ADR-002 exists to protect
on-premise. VPS is the right answer for a store with reliable connectivity
that values not owning a server; it is the wrong answer for a store whose
internet is the flaky part.

### Recurring costs (any tier)

| Cost | Per register per month |
|---|---|
| Thermal paper at 300 receipts/day (≈24–45 rolls; ₱17–36/roll) | ₱500–1,300 |
| Electricity: ~32 W × 12 h/day ≈ 11.5 kWh (apply your own tariff; illustratively ₱12–13/kWh — **rate not verified in this research**) | ₱140–150 |
| Software licensing | **₱0** |

Paper, not power or software, is the dominant recurring cost of running
TindaFlow. Worth saying out loud to owners who expect the software to be the
expensive part.

## 7. Price evidence

Observed 2026-09-25. Marketplace prices are promotional and move weekly.

| Item | Observed | Source |
|---|---|---|
| Mini PC N100, 16 GB / 512 GB | ₱8,527 (MLLSE G3); ₱11,871–12,671 (Hasee) | lazada.com.ph `/tag/intel-n100-mini-pc` |
| Mini PC N150, 16 GB / 512 GB | ₱8,392 | lazada.com.ph `/tag/intel-n100-mini-pc` |
| Laptop, N150, 16 GB / 512 GB | ₱18,999 | Facebook retailer (Antipolo) — single-seller, low confidence |
| Refurbished Dell OptiPlex SFF, i5 8th gen | ₱4,999 (used, lightly used listing) | carousell.ph — used market, no warranty implied |
| Monitor, 19–24" | ₱1,561–1,999 | lazada.com.ph (Nvision and assorted) |
| Keyboard + mouse, wired | ₱199–558 | lazada.com.ph |
| Barcode scanner, 1D USB | ₱499–1,199 | lazada.com.ph `/tag/usb-barcode-scanner` |
| Barcode scanner, Honeywell Voyager 1250g | ₱7,500 | lazada.com.ph |
| Thermal printer, Xprinter XP-T80A (USB/LAN, auto-cut) | ₱2,623–2,750 | lazada.com.ph |
| Thermal printer, Epson TM-T82III (LAN) | ₱9,400–13,500 | lazada.com.ph |
| Thermal printer, Epson TM-T20III (USB) | ₱11,400 | lazada.com.ph |
| Cash drawer, LogicOwl OJ-405E (5 bill/8 coin) | ₱1,890 | lazada.com.ph |
| Cash drawer, Poseidon CD4141 (RJ11, steel) | ₱3,150 | lazada.com.ph |
| UPS, APC Easy 650 VA with AVR | ₱2,650 | lazada.com.ph |
| UPS, APC BV1000I-MS 1000 VA / 600 W | ₱4,898–5,198 | lazada.com.ph |
| Switch, 8-port gigabit | ₱598–2,190 | lazada.com.ph |
| Thermal paper 80×80, box of 50 | ₱871–1,799 | lazada.com.ph |
| Thermal paper, single roll | ₱22–47 | lazada.com.ph |
| Portable SSD, Samsung 1 TB | ₱11,232 | lazada.com.ph |
| VPS, 2 vCPU / 4 GB / 80 GB NVMe | ₱946/month | hostking.host (PH page) |
| FX reference | ₱62.82 = US$1 | xe.com, wise.com |

## 8. Market reference — what Philippine POS vendors charge

Reference evidence only, per the project's competitor-research practice. Not
requirements, and not to be repeated as marketing claims.

**UTAK POS published hardware prices** ([utak.io/hardware](https://utak.io/hardware),
observed 2026-09-25, marked VAT-EX):

| Item | UTAK price | Comparable marketplace price (§7) |
|---|---|---|
| Barcode scanner | ₱5,000 | ₱499–1,199 |
| Cash drawer | ₱4,000 | ₱1,850–3,450 |
| Thermal receipt printer | ₱10,000 | ₱2,623 (budget) / ₱9,400+ (Epson) |
| Lenovo Tab M11 tablet | ₱19,999 | — |
| iPad 9th gen | ₱25,000 | — |
| Falcon all-in-one terminal | ₱21,999 | ₱21,038–29,800 (generic 15.6" AIO POS) |
| Dual-screen countertop POS | ₱40,000 | — |
| Steel security stand | ₱5,000 | — |

**UTAK packages** (third-party summary, divergentpos.com, 2026-07 —
lower confidence, not UTAK's own page): software-only 6 months ₱14,000;
tablet ₱35,999; mobile ₱29,999; complete bundle ₱49,999; dual screen ₱54,000;
premium ₱64,999.

**StoreHub Philippines** (storehub.com/ph, 2026-08): Starter ₱2,249/month
(₱26,988/yr), Advanced ₱4,499/month (₱53,988/yr), Pro ₱8,999/month
(₱107,988/yr), all billed annually; hardware purchased separately; BIR
accreditation at the system level, PTU registration assistance sold as
onboarding.

### What this implies

1. **Commodity peripherals carry a 2–5× vendor markup.** A scanner at ₱5,000
   against ₱500–1,200, a drawer at ₱4,000 against ₱1,900. That margin is how
   a bundled vendor funds support, training, and warranty — a legitimate
   trade, but it means a **published "bring your own hardware" bill of
   materials is a real, quantifiable TindaFlow differentiator** for
   price-sensitive owners, entirely consistent with the self-hosted,
   no-per-seat-licensing positioning already in `market-comparison.md`.
2. **Three-year comparison, stated honestly.** Tier A hardware is ≈₱23,500
   once, with ₱0 licensing. StoreHub Starter is ₱80,964 of subscription over
   three years *plus* hardware; a UTAK complete bundle is ₱49,999 plus
   renewals. **But this is not an apples-to-apples comparison and must never
   be presented as one:** those vendors' prices include BIR accreditation,
   vendor support, warranty, and training, and TindaFlow has none of
   those — ADR-002 explicitly moves backup and hardware reliability onto the
   store owner. The comparison is "lower cost, more owner responsibility,"
   not "same thing, cheaper."
3. **Every vendor above runs on tablets; TindaFlow cannot (§2).** Their apps
   speak ESC/POS to the printer directly, so a ₱19,999 tablet is the whole
   terminal. TindaFlow's browser-print V1 needs a PC, monitor, and keyboard
   (≈₱13,000, so the line item is competitive) but it forecloses the
   tablet/handheld form factor entirely. If tablet or mobile terminals ever
   become a product requirement, **the ESC/POS implementation behind ADR-007's
   `InvoicePrinter` seam is the prerequisite** — which is precisely the kind of
   additive change that seam was designed to absorb. Recording it here so the
   hardware consequence of that deferral is visible, not rediscovered later.

## 9. Open items

1. **Owner approval of the §3–§5 spec matrix**, and a decision on where it
   lands permanently (`deployment.md` vs. a new ADR).
2. **Clock-integrity risk (§5.7)** — needs a decision: RTC requirement in the
   installation checklist only, or also a software plausibility check before a
   fiscal day opens. No document currently covers this.
3. **Storage-growth estimate is unmeasured (§3.3)** — confirm with a seeded
   volume test during Stage 8 rather than carrying the estimate forward as
   fact.
4. **Memory budget is unmeasured (§3.1)** — Stage 8 load testing against the
   proposed 4 GB floor is the honest way to confirm or move it.
5. **Whether TindaFlow publishes a recommended-hardware BOM at all** — a
   product/positioning decision (§8.1), with a support-expectation consequence:
   a published BOM invites "but you recommended this printer" conversations.
6. **BIR PTU / accreditation costs are deliberately excluded.** BIR
   accreditation work is parked by owner decision (2026-09-25) and this
   research does not resume it. Note only that competitor pricing bundles
   accreditation and TindaFlow's does not, which is already stated honestly in
   `market-comparison.md`.

## 10. Sourcing and confidence notes

- **High confidence:** everything in §2 (read directly from this repository);
  the Tailwind v4 browser floor (vendor documentation); UTAK's hardware prices
  and StoreHub's plan prices (each vendor's own published page).
- **Medium confidence:** marketplace prices in §7 — real listings, but single
  observations, promotional, and seller-dependent. Ranges are given for this
  reason.
- **Low confidence:** the refurbished/used PC figures and the single-seller
  laptop price (used market, no warranty, availability varies); the
  third-party summary of UTAK's package pricing; the illustrative electricity
  tariff, which was not researched.
- **Estimates, explicitly not measurements:** the memory budget (§3.1),
  storage growth (§3.3), paper consumption, and the three-year comparison
  arithmetic in §8.2.

## Revision log

| Date | Change |
|---|---|
| 2026-09-25 | Created. Hardware requirement matrix, PH price research, reference configurations, vendor market comparison. |

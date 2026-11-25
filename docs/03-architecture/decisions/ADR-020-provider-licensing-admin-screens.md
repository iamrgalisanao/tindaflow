# ADR-020: Web Screens for the Provider's Licensing Administration

## Status

Accepted — 2026-11-25. The owner answered the questions under "Owner decisions" and approved the build plan the same day.
**Design only: no code accompanies this ADR.**

**Amended the same day after an engineering review (end of this record): building the screens is on hold, and signing
from them is not approved for building. Where the amendment differs from the text below, the amendment wins.**

It amends one sentence of ADR-018 decision 8 (who may ask for a signature) and revisits, for licensing screens only, what
ADR-019 listed as not to be built yet. It changes nothing in ADR-014 or in ADR-018 decisions 9 and 10.

## Context

- The owner asked for the admin feature to sit under the provider's account, separate from the manager and the till.
- In a shop, `ADMIN`, `MANAGER` and `CASHIER` use one application, separated by capabilities. Only an `ADMIN` can manage
  tills, store settings, the employee credit policy and CSV import, other `ADMIN` accounts and, while the server operator
  has unlocked it, the fiscal configuration.
- ADR-018 decision 8 already describes a control plane with an admin UI (MFA, reachable through a VPN). That UI is not
  built: the provider's service (the private repository `tindaflow-control-plane`) is run by console commands and has no
  operator sign-in.

## Owner decisions (2026-11-25)

1. **The provider manages licensing and clients online, and nothing else.** No shop sales, staff or customer data is added
   to the provider's service. The aim is to manage all clients from one place.
2. **Each shop keeps its own `ADMIN`**, who still does what only an `ADMIN` can do today: tills, business and sale
   settings, and employee credit limits. `MANAGER` and `CASHIER` do not change.
3. **The admin web screens may request signatures, with safeguards** (decision 4 below).

An alternative in which the provider holds each shop's `ADMIN` account, or manages shop settings and data online, was not
chosen. It would have reversed ADR-014's rule of no vendor account in client data and changed what reaches the cloud.

## Decision

### 1. What does not change

- No provider account exists inside client data (ADR-014; ADR-018 decision 10).
- The shop application needs no code change for these screens.
- ADR-018 decision 9: sales, shifts, stock, audit records, users, employees and credit limits never reach the cloud.
- Only the machine API faces the internet. The admin screens are reachable only through a VPN.
- The shared licence contract (`app/Services/License/Contract`) and the machine API's wire formats.

Operator accounts, their e-mail addresses, IP addresses and sign-in records are the **provider's own** personal data. They
belong in the privacy notice ADR-018 calls for. The statement that the control plane holds no personal data of client
staff or customers stays true.

### 2. Shape

- Server-rendered Laravel pages and a hand-written stylesheet in the provider's repository, with **no new package**.
  ADR-019's deferral of a React admin portal stands.
- A screen and its console command call the same service, so they apply the same rules and leave the same records. Logic
  that today lives only inside a command moves into a service first.
- **Console only:** creating and managing operators, recovery after restoring a backup (`license:after-restore`), the key
  check (`license:check`), and key changes.
- Not included, because no command does them today, and each would need its own decision: editing client details, adding
  a second store to a client, setting a term end date, and switching a shop from report-only to enforcing.

### 3. Surfaces, operators and safeguards

- **Two surfaces.** A setting `LICENSING_SURFACE` takes `api` or `admin`, defaults to `api`, and any other value serves
  neither. Each process serves only its own routes, plus the health check. The admin surface also checks its host name and
  refuses unsafe production settings. This setting is **not** a security boundary against someone who can run code on the
  host. The VPN, separate server users and settings files, and database roles must be enforced where it is hosted.
- **Operators** are named accounts created only at the server console: a password and a time-based one-time code
  (RFC 6238, written in the service and tested against the RFC's published values). No sign-up, e-mail reset or
  "remember me". Sign-in is rate limited; sessions end after 15 idle minutes and after 8 hours in all. The sign-in
  implementation gets a separate security review before it is merged.
- **Every action records who did it.** The registry's `ip` column keeps its meaning, the shop's address; the operator's
  address is stored separately.
- **A fresh code** is asked for whatever grants, restores or ends a licence. Revoking also needs the deployment code
  typed. Reconnecting a certificate chain and approving changed hardware need the certificate number or hardware
  reference typed from what the shop's owner reports, never filled in by the screen. While the service is paused,
  neither is possible and no activation code is issued.
- A form acts once however often it is sent. An activation code is shown once, and only its hash is stored.
- **A restore** of the service also disables every operator and ends every session; each is re-enabled at the console
  with a new password and a new one-time code secret.
- **Making an offline renewal reply for a revoked shop cannot be undone**, because the service records the revocation as
  served. The screens ask for confirmation before the reply is made, not after.

### 4. Signing from the admin screens

**On hold: see the amendment. The risk below is understated.**

**Amends ADR-018 decision 8.** The sentence "the signing service accepts calls from that API only" is replaced: signatures
may be requested by the machine API and, with these safeguards, by the admin screens.

- Off unless `LICENSING_ADMIN_SIGNING=true`; while off, the signing screens do not exist.
- Reached only through the VPN, by a signed-in operator who gives a fresh code for each signing action.
- Every signing action is recorded with the operator.
- Refused while the service is paused.

This covers offline extension and time-reset codes (which also use the second, code-signing key), activation bundles,
computer replacement and renewal by file. The screens that need no key are built first and must work with no key
configured.

**The risk accepted:** whoever takes over the admin process while signing is on can ask for signatures. The console
commands could already sign on that host; a web process is easier to attack than a console. Hardware-held keys would stop
a key being copied, not a taken-over process from asking for signatures. There is no second-person approval.

## Not before real use

Until hosting, the VPN and production keys are decided and the hosting checks pass: no admin surface on any network beyond
the development computer, not even through a temporary tunnel; no real client data or operator password in it; no
production key in any settings file; no signing from the screens outside development; and no shop enforced. ADR-019's
gates still apply, including hardware-held production keys (for which no signer is built), backups, recovery drills,
off-site alerts, and designing certificate format 3 before the first production key. ADR-019 owner confirmation 5 (who
hosts, holds the keys and supports shops) **stays open**.

## Build order

**Replaced by the amendment's "Revised order".**

Separate from ADR-019's slices, and it satisfies none of ADR-019's gates. Each slice is merged on the owner's approval.

0. This record.
1. Shared services, and the actor on every registry event.
2. Separate the two surfaces.
3. Operators and sign-in.
4. Read-only screens.
5. Register client, activation code, till limit.
6. Suspend, resume, revoke.
7. Reconnect a certificate chain, approve changed hardware.
8. Offline extension and time-reset codes (the first signing from the screens).
9. Offline activation and computer replacement.
10. Offline renewal by file.
11. Hosting runbook and checks.

## Consequences

- The provider can run day-to-day licensing without typing commands, once the service is hosted.
- The provider's service gains sign-in, session and web security code that has to be maintained and reviewed.
- The admin screens show what the registry records. Of the alerts ADR-018 decision 8 lists, those for one fingerprint
  under two deployments, a renewal from a new IP country and repeated clock anomalies are not recorded by any code found
  while planning, so these screens do not provide them.
- Reconnection and hardware approval still cannot tell a shop from a copy of it (ADR-019). Easier buttons make a careless
  approval easier; how the provider verifies a shop's owner remains an owner decision.

## Amendment, 2026-11-25: after an engineering review

The owner asked for an engineering review the same day. Separate security, architecture and operations reviews read this
record, the build plan and the service's code; the owner then approved these changes. **Where this amendment differs from
the text above, this amendment wins.** Still design only.

### A correction: what taking over the admin surface could do

The owner approved signing from the screens after the risk was **understated**.

- With signing keys held in the process's settings, as the code does today, whoever runs code in that process can **copy the
  keys**, not only ask for signatures. A fresh one-time code, the signing switch, the pause and the audit trail do not
  stop that. A fresh code still helps against a stolen session or a forged request.
- **Turning signing off is not enough.** The machine API signs according to what the registry says. A process that can
  write the registry, which the screens without keys must also do, can cause the machine API to sign, including the
  revocation of a shop, which cannot be undone once delivered.
- Hardware-held keys would stop a copy being taken, but without limits on what may be signed, a taken-over process could
  still obtain documents that stay valid for far longer than any licence should.

### What changes

1. **Building the screens is on hold.** Licensing is dormant, nothing is hosted, and the screens advance none of ADR-019's
   gates. The pilot PC (ADR-019 slice 0, and copying its backup, `.env` and certificates off it if it holds real sales),
   the canteen go-live, the restore drill and the off-machine backup come first. Work on the screens starts again when the
   owner has chosen the hosting, who runs it (ADR-019 owner confirmation 5) and a target date.
2. **Signing from the screens is not approved for building.** Section 4 is on hold, and ADR-018 decision 8's sentence "the
   signing service accepts calls from that API only" **stands**. Signing stays with the machine API and the console
   commands, as it works today. Taking it up again needs the signer design below, certificate format 3, and the owner's
   fresh approval.
3. **The signer to be designed:** its own server user with no connection from the internet, the only thing that can reach
   the key hardware. It accepts named operations, never raw bytes, and enforces limits itself: the longest life of a
   certificate, the control-plane addresses, till counts, offline codes (time resets above all) and daily volume. It keeps
   its own log off the host. It cannot trust what the registry says, so its limits and the alerts are the bound. A
   revocation asked for from the web is not delivered until it is confirmed at the console, or is delayed with an alert.
4. **Sign-in before real use must resist phishing:** passkeys or security keys (WebAuthn, with the user verified), two of
   them for each operator, recovered at the console, through a maintained library and never hand-written. Naming that
   library needs the owner's approval of a new dependency. If one-time codes are used as well, prefer a maintained library
   to the in-house version in section 3; settle it when sign-in is designed. A passkey does not protect a taken-over server.
5. **Server-rendered pages and one operator role stay.** A second approval by the same person would add nothing; alerts
   that leave the server, and the limits above, make up for it.

### Needed before the first production key, with or without screens

- The shop application's certificate reader refuses a certificate that lives longer than a set limit. Today it requires
  only that it ends after it starts. The limit is to be chosen from the 30-day offline tolerance, and goes into the shared
  contract in both repositories.
- A time-reset code gets a limit on how far back its time may reach. No such limit was found. Test the case of a wrong
  server clock at the same time; the reviews raised it and it is **not verified**.
- Builds carry spare public keys for both key families, with the private halves kept offline, so that replacing a key does
  not wait for every shop to update.
- The registry's history is append-only **by database privilege** and copied off the host. Today a comment says so and
  nothing in the repository enforces it.
- An offline activation or replacement request is not signed: compare a key or hardware reference with the shop through
  another channel before issuing.

### Needed before real use, console commands included

- Backups with a recovery target, a restore drill, and an off-host record of every change of state, because a restore
  relies on later changes being applied again.
- Alerts that leave the server (forks, hardware changes, refused keys, revocations, sign-ins, signing) and watching of the
  machine API, backups, the clock and certificate expiry. The registry only records alerts today.
- A kept-correct server clock.
- A way to carry on if the one operator, their device or the server is lost, rehearsed.
- Before two processes or two copies of the service run: the pause is held in the database and checked where signing
  happens. Today it is a per-process setting that only the machine API's middleware enforces.
- Before operators exist: a restore must lock operators out even if `license:after-restore` is never run, for example with
  a marker kept outside the database.
- Exact trusted-proxy addresses if a proxy is used, and separate release or cache folders if two processes run from one
  machine.

### Revised order

This replaces "Build order" above.

1. **First:** the pilot PC, then the canteen go-live with its restore drill and off-machine backup.
2. **Small licensing work worth doing now,** one change at a time, none delaying step 1: make a lost offline activation
   file re-issuable (the same computer and key, an active deployment, never under a compromised key, recorded) and make
   `license:issue-bundle` fail when it cannot write the file; a console command that lists every client with its licence
   status, expiry and when it was last seen; stop `DatabaseSeeder` creating a login; remove the welcome page.
3. **Before real keys:** the two lists above, certificate format 3, the signer design, and the hosting design (moved here
   from the end of the plan).
4. **Screens, once the start conditions are met:** separate the surfaces, operators with the operator's name on each
   event, and read-only screens; then stop and reassess. After that, one action per change: register, till limit,
   suspend, resume, revoke. Reconnecting a chain waits for how the owner of a shop is verified. Hardware approval,
   activation and replacement screens wait for certificate format 3. Signing screens wait for decision 2 above.

Shared services are extracted when the first screen needs them, not before.

**Gaps against "manage clients centrally":** nothing can edit a client, add a second store to a client, set a term end
date or switch a shop from report-only to enforcing. ADR-019 expects a report-only installation before enforcement, so
that switch needs its own decision before one is installed.

**Where the reviews differed:** the operations review would have built the surface split and read-only screens now, and
the security review would have allowed everything but signing to be built on a development computer. They are on hold
because of the order of priorities and the designs still missing, not because development builds would be unsafe.

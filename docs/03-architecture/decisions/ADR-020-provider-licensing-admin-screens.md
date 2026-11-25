# ADR-020: Web Screens for the Provider's Licensing Administration

## Status

Accepted — 2026-11-25. The owner answered the questions under "Owner decisions" and approved the build plan the same day.
**Design only: no code accompanies this ADR.**

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

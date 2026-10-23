# ADR-015: Remote Till Approval (an Administrator Approves a Till From Anywhere)

## Status
Accepted — 2026-10-23, at the owner's request ("the admin can be in a remote location and still perform the enrollment, so that no one can deploy or duplicate the system without the admin's authorization"). Amends the *where* of ADR-011 step 2, not its *who*: ADR-011 had the administrator submit the enrollment token "on the target workstation", which meant an administrator physically (or by screen share) at the till computer. The authority to make a browser a till stays exclusively an administrator's.

## Context
A shop with several locations, or an owner who is not at the till computer, could not enroll a till without sharing an administrator password or screen. The token flow forces one of two bad choices: send the token to someone and give them an administrator sign-in to redeem it (the redeem step requires `TERMINAL_MANAGE`), or hand over the administrator's own credentials. The owner wants the opposite property: the person at the till computer must never need administrator rights, and no computer may become a till unless an administrator specifically allows that computer.

## Decision
Add a device-authorization flow (RFC 8628's pattern, the one TVs and printers use to pair with an account), alongside the existing token flow, which stays for an administrator who is at the machine.

1. **The till computer asks.** A person signed in (any role, usually the cashier) at an un-enrolled browser clicks "Ask an admin to approve this computer". The server creates a pairing request and returns, once, a short **code** (8 characters, no 0/O/1/I, shown as `ABCD-EFGH`) and a long **secret** that stays in that browser. Both are stored only as hashes.
2. **The administrator approves from anywhere.** The Terminals screen lists waiting requests with who asked, from which address and browser, and when. The code is **deliberately not listed**: the administrator must type the code read to them from the till computer's own screen (a phone call is enough) and choose which terminal it becomes. Approving without having matched the request to a person is therefore not possible by clicking.
3. **The till computer collects its credential.** It polls with its secret; once approved it receives the ordinary terminal credential (`TerminalEnrollmentService::issueCredential`, the same step the token flow ends in) as the HttpOnly cookie, exactly once, and the page reloads as the till.

Safeguards:
- Requesting and claiming need a signed-in user; the claim must come from the **same user** that asked, with the secret. No unauthenticated endpoint exists.
- Five wrong codes decline the request. A request lives 15 minutes (plus 10 to collect after approval). A person has one live request at a time (asking again replaces it). At most 20 wait per store. Asking is limited to 10 an hour per person.
- Approving a revoked terminal takes a licensed seat back, so it is checked against the license (ADR-014); approving an already-enrolled terminal replaces its credential (the screen warns: the old browser stops working).
- Audited with no secrets: `TERMINAL_PAIRING_REQUESTED`, `TERMINAL_PAIRING_APPROVED`, `TERMINAL_PAIRING_DECLINED`, then `TERMINAL_ENROLLED`.
- One new table, `terminal_pairing_requests`; two new error codes, `PAIRING_REQUEST_INVALID` (409, one non-enumerating answer, like `ENROLLMENT_TOKEN_INVALID`) and `PAIRING_REQUEST_LIMIT` (429).

## What this does and does not guarantee
- **Guarantees:** a browser cannot become a till unless an administrator approved that specific request by its code. A stolen enrollment token cannot be redeemed without an administrator sign-in either. Everything is attributable.
- **Does not guarantee:** it cannot stop someone who already holds a legitimate till credential from copying the cookie (ADR-011's accepted residual risk), and it cannot stop someone who controls the server from copying the whole system. Those are limited by the signed till license and the deployment watermark (ADR-014, ADR-008 addendum), and ultimately by the license agreement, not by this flow.
- **Social engineering is the remaining weak point:** an administrator who approves a code they were not expecting defeats the control. The screen says so, and the code-must-be-typed rule is what makes a careless approval take effort.

## Alternatives considered
- **Let the administrator redeem a token for another computer's browser.** Impossible: the credential cookie is set on the browser that submits the token.
- **Give the remote person a temporary administrator account.** Works today, but hands over full administrator rights for a one-minute job; this ADR removes the need.
- **List the code to the administrator and approve with one click.** Rejected: it turns approval into a reflex. Typing the code read from the till is the check.
- **An unauthenticated pairing request.** Rejected: anonymous endpoint to abuse, and no accountability. Requiring a signed-in user means every request names a person.

## Related controls added with it
- **Shared-credential tripwire.** Approval controls who *becomes* a till, not what happens to the credential afterwards. `TerminalUsageTracker` flags a till whose credential is used from two different computers in turn (`shared_use_suspected_at`, an audit event, a badge on the Terminals screen); re-enrolling it clears the flag and kills every copy. It is a tripwire with a stated blind spot (two same-browser machines on one shop network look identical), not a lock; the real fix, a device-bound credential, is parked.
- **Every wrong code is recorded** (`TERMINAL_PAIRING_CODE_REFUSED`, with who tried), and the attempt count is atomic, so simultaneous guesses cannot slip past the limit of five.

## Parked (a later session)
Device-bound credential; an optional network allow-list for approving tills; a Telegram alert on approval, revocation or a shared-use flag; a status check in the credential resolver; a trusted-proxy setting for real recorded addresses.


# ADR-019: A Native Device Agent, and the Path to Enforcement

## Status

**Decided by the engineering team** on 2026-11-25, under the owner's delegation of that date, after separate architecture, security and operations reviews. The items under "Owner confirmations" are business decisions and are **still open**. When decided, nothing here had been built; see "Slice 1" for what has been since.

This record extends ADR-018, which stays in force as the licensing design. It changes ADR-018's direction for hardware binding (decision 4) and the order of work before enforcement (rollout).

## Context

- No build carries a vendor key, so every build is LEGACY and enforces nothing.
- **Two of two PCs checked cannot be activated.** The development PC reports a placeholder BIOS serial; the pilot PC reports a placeholder base board serial (SMBIOS UUID and BIOS serial are usable). A certificate requires all three. The three-value requirement is wrong for real hardware.
- The pilot PC's `Get-Tpm` output shows a present, ready TPM, with fields marked "Not Supported for TPM 1.2". It is **probably** TPM 1.2; the specification version has not been confirmed.
- The pilot PC has an install copied on 2026-11-23, before any licensing code, and its backup folder was written on 2026-11-25. Whether it holds real sales is **not known**.
- The owner proposes a separate, reusable Windows application (agent) that checks the hardware and protects the device identity.

## Decisions

### 1. The pilot runs unenforced, and is not upgraded for licensing's sake
The pilot keeps a keyless (LEGACY) build while the foundations below are built. This is not technical copy protection; the written agreement is the protection.

**Never give an existing install a build that carries a vendor key until it can be activated.** With a key and no certificate the state is NOT_ACTIVATED and enforcing: new shifts are refused and open ones stop after 18 hours. Report mode is a claim inside a certificate, so it cannot help an install that has none. Today activation refuses a database with sales or with a different store, `--adopt` and `--force` are unbuilt, and the control plane always mints a new store id, so an existing store cannot be activated at all.

A test already fails if a certificate key is committed to `config/tindaflow.php`. It must also cover `code_keys`.

### 2. Fix the defects in what is built, before building more
Found in review and confirmed by reading the code on 2026-11-25. None affects an install today, because nothing is enforced.

| # | Defect | Required behaviour |
|---|---|---|
| 1 | The control plane returns the stored successor for a repeated `request_id` **before** its fork and fingerprint checks, and the client keeps that id after a failure. A copy taken then gets the same certificate with no fork. | Replay only for the same presented certificate and fingerprint, only for a limited time, and record it. Identical copies that replay inside that window still need another signal. |
| 2 | When two of three hardware values match, the reported values **replace the registered ones**. Successive renewals can move a license to different hardware. | The approved baseline stays in the registry and the certificate. One changed value is tolerated and recorded; a new baseline needs the provider's approval. |
| 3 | The license gate does not compare the stored certificate with this installation's identity or store. | A certificate for another installation or store counts as none. |
| 4 | Activation does not compare this PC's hardware with the certificate, which ADR-018 decision 9 requires. | Refuse a mismatch. |
| 5 | Nonce use is not atomic (`Cache::pull` reads, then deletes). | One consumption can succeed. |
| 6 | A hardware file dated in the future counts as fresh, and the heartbeat then pushes trusted time forward. | Reject a stamp more than the 1 hour tolerance ahead. |
| 7 | The installation's private key file is restricted only after it is written. | Create it already restricted. |

Related gaps: `docker/.env.example` omits `TINDAFLOW_HOST_DIR` (the default misses the helper's folder); the control plane's tests, **including the cross-repository registry**, run on SQLite, so its row locking is unproven.

### 3. The agent reports evidence and protects a key; it does not decide
- **It does:** create one machine key under a least-privilege service account, and sign only a versioned, purpose-tagged structure that it builds itself (a nonce, the installation, the certificate hash, a request digest and its hardware evidence).
- **It never:** signs bytes it is handed, listens on the network, exports or silently replaces a key, runs commands, holds a vendor key, answers "licensed" or "matches", or stops Windows or containers.
- **Who decides:** the control plane when online; the application when offline, against a certificate the vendor signed.
- **Binding:** the certificate names the device key or hardware evidence, and a **binding type the provider assigned**. Renewal never changes either. What an agent says about itself is a claim: call it `tpm_claimed` unless the provider witnessed or attested it.

This replaces the earlier suggestion that the agent should sign only when its own hardware check passes. A single yes or no from a program on the customer's PC is the easiest thing to fake.

**Limits that stay true:** an administrator can patch the application; a copy can relay challenges to the original PC while it can reach it; a fake agent enrolled at activation passes. A software key probably survives a full disk clone (not confirmed from a primary source). The agent is **deterrence and detection**, as ADR-018 decision D5 already rates this design. The claims "copying the folder, the license file or the disk is blocked" hold only with an unmodified application, enforced fresh proof, no relay and, for a disk clone, a genuine hardware-held key.

### 4. TPM 1.2 and PCs without a TPM are not rejected by default
A PC without a usable TPM 2.0 may be bound by a weaker method only through a **per-installation exception the provider approves**, recorded in the signed certificate and audited. The security review reports that Microsoft documents TPM 1.2 as limited to RSA and SHA-1, which rules out ECDSA P-256 there; whether an RSA key on the pilot PC can sign usefully must be proven by the spike. Hardware-backed attestation is not part of the first release, and the risk of a fake enrollment is accepted and recorded. Vendor signing stays Ed25519.

### 5. The certificate format changes once, after the spike and before the first production key
No temporary two-value change now. Certificate v3 will carry one `binding` object that can describe hardware values (two or three, with a signed minimum number that must match; two require both) or a device key. It is designed from the spike's results. Because no certificate has ever been issued with a production key, the change is still cheap; after the first one it is not. Readers ship before anything issues the new form.

### 6. When proof is missing
Activation, renewal and re-binding require valid proof. A wrong key or bad signature refuses new shifts. An agent that is merely unavailable first warns, then refuses new shifts only after a bounded grace period, and clears itself on a good proof. Closing a shift, reports, reprints, voids, refunds and exports are never blocked, and ADR-018's open-shift rule stands. Whether proof is required is a signed certificate claim, never a file or an environment setting.

### 7. The signed-off periods stand
30 days of offline life and a warning 7 days before expiry (ADR-018). The 7 and 3 days in the new plan are not adopted unless the owner changes the decision.

## Slice 1, built 2026-11-25: the defects and the key guard

Written test first: each defect had a test that failed before its fix, except where stated. The client and `tindaflow-control-plane` changes belong together.

| # | Fix |
|---|---|
| 1 | A stored successor is handed out again only for the same certificate and the same hardware hashes, while it is still the latest, and for **72 hours** (`licensing.replay_hours`; the length is an engineering choice, long enough for a weekend offline). Each replay is recorded (`RENEWAL_REPLAYED`). Anything else is `409 FORKED` with `CHAIN_FORKED`. A certificate issued before this has no record of its request and is never replayed. |
| 2 | A renewal never changes the approved hashes; the registry and every certificate keep them. Two of three is accepted and recorded once per distinct set reported (`FINGERPRINT_CHANGED`: component names and a digest, no values). A changed component no longer forces an early certificate. |
| 3 | One method decides which stored certificate counts, for the gate, the License screen and offline codes: signature accepted, installation equal to this computer's identity file, and a store in the database with the certificate's store id. No identity file counts as none. |
| 4 | A first activation needs a fresh hardware file matching at least two of the certificate's three hashes. Running it again on an activated install does not. |
| 5 | A nonce is read and deleted under a lock that does not wait. Its test here showed only that a held lock refuses a second consumer; slice 2 has the real two-process test. |
| 6 | A hardware file stamped more than 1 hour ahead is `INVALID`: no fingerprint, and the heartbeat ignores it. |
| 7 | The identity file is created under `umask 0077`, never into an existing file. This also exposed and fixes a second fault: `docker compose exec app php artisan tindaflow:activate` runs as root, so the file was readable by root only and the web workers and scheduler (`www-data`) could not use it. When root writes it, it is now given to the owner of `storage/app`. The earlier code already ended with the same file mode, so the test guards the new method (nothing sets the mode afterwards) and does not fail on the old one. File-mode tests run on Linux only, and the change of owner has **not been run as root in a container**. |

The key guard test now also fails on a committed `code_keys` or `revoked_code_keys` entry, and `docker/.env.example` documents `TINDAFLOW_HOST_DIR`.

**What these fixes do not do, and what they cost:**
- A copy that replays inside the 72 hours, before either side renews again, still receives the same certificate (as decision 2 already notes).
- A shop that loses an answer and then stays offline more than 72 hours, or has one hardware value change in between, is reported as forked and needs the provider. **There is no re-chain tool until slice 3.**
- A PC with one replaced part keeps working on two of three, but a second change refuses renewal. **Approving a new baseline is not built until slice 3.**
- The check of defect 3 does not stop a copy of the whole installation, identity file included, and asks only that the licensed store exists, not that no other store does.

## Slice 2, built 2026-11-25: the control plane on PostgreSQL

The control plane's tests now run on PostgreSQL as well as SQLite, in a CI job whose server is deliberately on a zone other than UTC. The cross-repository test keeps the registry in a PostgreSQL database of its own. Both refuse to wipe any database not named `tindaflow_cp..._test`; ending in `_test` is not enough, since a developer's everyday database may.

**SQLite had hidden these defects**, now fixed:

- **Stored times were wrong.** The control plane did not set its database session's time zone, so on a server not set to UTC every stored instant was off by the server's offset (8 hours here). That moved the 24-hour renewal window, the 72-hour replay window and activation code expiry. Signed certificates were not affected. The client already set this.
- **Two computers activating one deployment together:** one got a server error instead of "already activated".
- **A revocation could complete in the middle of an activation**, which then issued a 30-day certificate afterwards. Activation now locks the deployment row, as suspension and revocation do.
- **A malformed key id on a signed request** caused a server error instead of the usual 401.
- Client details longer than their columns, and a computer already registered to another deployment (the latter also failed on SQLite), now get a refusal instead of an error.

**What is now proven**, with two real processes made to collide on a held database lock: a computer and its copy renewing together get one successor between them; one request sent twice gets one certificate; a renewal queued behind a revocation is answered with it; one activation code serves one computer, and gives the same computer the same bundle; one computer activates a deployment; offline codes keep the per-certificate limit and distinct numbers; and one nonce is accepted once, which closes the gap left under slice 1's defect 5. Removing the renewal, activation code, offline code or nonce protection makes its tests fail; the two activation tests failed before their fix.

**Not proven:** more than two at once, other pairs of operations, load, any PostgreSQL version but 17, and a production server. The registry's "append-only by database privilege" is still only a comment.

## Slice 3, part 1, built 2026-11-25: restoring a whole shop

**The restore did not work where it was needed.** `restore.sh` kept the dump's grants to the application's database role. That role does not exist on a new server, so the restore stopped there (reproduced with a throwaway role). Privileges are now left out of the restore and set again by the `migrate` step, as on a first install.

- **A second archive beside every dump** holds what a dump does not: `docker/.env` with `APP_KEY` and the passwords, the HTTPS certificate, and `storage/app`. It is written only when `BACKUP_PASSPHRASE` is set, so none of this is ever on disk in a backup unencrypted. It is uploaded and pruned with the dump, and failing to write it is a failed backup. This means a shop's secrets now travel with its off-machine copy: whoever holds the passphrase and that copy holds the shop.
- **`docker/README.md`, "Restoring a whole shop"**, is the procedure. It also corrects the older go-live step, which moved only `app` to the restored database, and warns that the fixed Compose project name makes a second folder use the live volumes.
- **Drill:** `scripts/drill-whole-shop-restore.sh`, a CI job. It builds a shop on the production stack, sells, backs up, destroys it, restores from the two files and the passphrase, and checks that every record, the certificate and the license files are unchanged, that the enrolled till still works, that slip numbers continue, that the application's role still cannot rewrite sales, and that the backups and scheduler use the restored database. It also checks slice 1's open item: an identity file written by `artisan` as root is private to `www-data` in a real container.

**Limits:** the drill runs on Linux; the steps have **not** been run on Windows or on the pilot PC. It does not exercise the off-machine copy (rclone) or a public host's certificate renewal. A shop with no `BACKUP_PASSPHRASE` still has only database backups, and says so only in its log. Moving a license to other hardware is a later part of slice 3.

**The rest of slice 3, when part 1 was built:** a way back to trading for an expired, locked or revoked shop (part 2, below); adopting an existing store; replacing the PC; re-chaining after a restored backup; rebuilding the control plane and rotating keys.

## Slice 3, part 2, built 2026-11-25: ways back to trading

Every way a shop can be refused a new shift, and its way back. "Drilled" means the cross-repository test runs the real client against the real control plane on a database holding a sale, and checks that nothing but licensing state changed and that the audit log only gained licensing entries.

| Refused because | Way back | State |
|---|---|---|
| Expired, with internet | Online renewal | worked already; now drilled |
| Expired while suspended | The provider resumes; online renewal | worked already; now drilled |
| Expired, no internet | Extension codes (two in a row); then **renewal by file** | file renewal is new, drilled |
| Clock behind the recorded time, with internet | Correct the clock; online renewal | **was broken for a day after each renewal**, fixed, drilled |
| Clock behind the recorded time, no internet | Correct the clock; time-reset code | worked already, drilled |
| Revoked | **None. It needs the owner's decision.** A revocation can now also arrive by file | unchanged |
| Hardware changed (2 of 3 or more) | None yet: "replacing the PC" and approving a new baseline | not built |
| Chain forked (copy, or an old backup restored) | None yet: "re-chaining" | not built |
| The build no longer accepts the stored certificate, or the identity file is lost | None yet: "rotating keys" and "replacing the PC" | not built |

- **The defect.** The control plane answers a certificate under 24 hours old with that same certificate, and the client resets its trusted time only when it receives a new one. So an online renewal could not clear a clock lock until a day after the last certificate, although ADR-018 decision 3 says it does. A locked client now says so (`clock_locked`), and the control plane then issues a new certificate if the last one is over **1 hour** old (the length is an engineering choice, to bound how fast certificate numbers can grow).
- **Renewal by file.** `tindaflow:license-renew --request` writes the renewal request signed with the installation's own key; `license:renew-file` on the control plane applies every rule of the machine API and writes the reply; `--apply` takes it with the same checks as an online answer. The provider may answer the same request again after the 72 hours, since a file is easily lost.
- **A reply file never moves trusted time back.** It can be kept and applied weeks later, so moving the mark to its time would give that time again. It only moves the mark forward, and is refused if it is dated more than an hour ahead of the PC's clock. A clock lock is cleared by an online renewal or a time-reset code.
- **A decision to confirm:** a renewal by file counts as a renewal, so it also restarts the count of extension codes. A shop that never has internet can therefore go on indefinitely, one file at a time. The provider decides each one, and suspension and revocation apply. ADR-018 decision 6 says "2 in a row without an online renewal"; the engineering reading is that its purpose is to make the PC check in with the provider, which the file does.

**Limits:** no screen for the files (console only, as activation is). A reply applied after its certificate has run out is refused, and a reply that is made but never applied leaves the registry one certificate ahead: the same request gets the same reply again, but once the PC asks something new it is a fork, which has no way back until re-chaining is built. The drill makes a license old by re-signing it, with the test key, as 40 days old; it does not wait. Nothing here has run on a real shop computer.

## Order of work

Each slice is a small change that can be merged alone and has an exit test.

| Slice | Work | Done when |
|---|---|---|
| 0 | Read-only inventory of the pilot PC: real use, installed version, TPM specification version | recorded |
| 1 | The defects of decision 2, and the key guard for `code_keys` | each has a failing test that now passes, in both repositories |
| 2 | Control plane tests on PostgreSQL | concurrent renewals and activations are proven safe |
| 3 | Recovery, one capability per change, in this order: whole-shop restore (documented and rehearsed, with `.env`, `APP_KEY`, certificates and the app storage, which database backups do not contain); a way back to trading for an expired, locked or revoked shop; adopting an existing store; replacing the PC; re-chaining after a restored backup; rebuilding the control plane and rotating keys | each is drilled with sales intact, changing only licensing state and audit records |
| 4 | Till limit read from the certificate | a till over the limit is refused; enrolled tills keep working |
| 5 (may run beside 2 to 4) | A time-boxed agent spike on real hardware | a written go or no-go against the gates below |
| 6 | Certificate v3, then the agent, application and control plane | shared vectors and the cross-repository test pass |
| 7 | Hosting, production keys held in hardware, signed installer, update and rollback, off-site alerts; hardware rehearsal of the scheduler and agent | restore and key-recovery drills pass |

**Spike gates:** the TPM version on the pilot PC; a non-exportable key whose signature PHP verifies, with its hardware backing confirmed; the key survives restart, power cut, and Windows and BIOS updates, and what a TPM clear does is known; a disk clone on other hardware fails; a Linux container reaches the Windows service reliably under Docker Desktop on Windows 10 without exposing it to the network (compare a shared folder with no listener against `host.docker.internal`, treating every local process as a possible caller).

**Before any shop is enforced:** the recovery drills pass; at least 31 days on real hardware; 20 restarts including 3 power cuts and a Windows update, with no unexplained hardware change or stale proof; an offline rollback is proven; an activated report-mode install shows no false "would refuse".

**Not to be built yet:** the React admin portal; attestation; a multi-product licensing abstraction (no second product has been named); a standby cluster; reinstatement of a revoked PC (needs a decision); the production agent and its installer before the spike says go.

## The existing pilot install

Do not upgrade it now. Licensing is no reason to, and its version and use are unknown. **Independently of licensing, if it holds real sales:** copy its newest backup, `docker/.env` (with `APP_KEY`) and certificates off that PC. A database backup contains none of the latter, and the default backup folder is on the same disk.

Before any later upgrade: confirm the installed version and roles; restore a backup on another computer and compare counts and totals; rehearse the upgrade and the way back; keep the old folder and images under their own tag. The operations review also reported, still to be verified in that rehearsal: the fixed Compose project name means a second folder on the same PC uses the **live** data volumes; manually made dumps can be pruned; and the documented database-switch step may leave the backup and scheduler on the old database. Never run `down -v`.

## Owner confirmations (still open)

1. Is the pilot PC in real daily use, and who may approve downtime?
2. May it run unenforced, until what review date, under what written terms?
3. How long may an enforced shop keep opening shifts while the agent gives no proof? The security review suggests 72 hours.
4. Will a PC without TPM 2.0 be accepted under an exception, or replaced?
5. Who hosts the control plane, holds the keys, supports the shop, and pays for the agent's development and code signing?

## Consequences

- The pilot is unprotected by software for as long as this takes. A review date in the agreement keeps that from becoming permanent.
- Recovery and the defect fixes come before the agent. They are needed whatever the binding is, though recovery may need rework once the device identity moves.
- ADR-018's host helper remains until the agent replaces it; it cannot activate the pilot PC.

## Alternatives rejected

- **Wait for the agent before piloting:** ties the shop's operation to software that does not exist.
- **Accept two hardware values now:** it still cannot license an existing store, and changes the signed format twice.
- **The agent decides whether the hardware matches:** moves the decision to the easiest place to fake and hides the evidence from the provider.
- **Require TPM 2.0:** would probably reject the pilot PC.
- **A new central license server:** `tindaflow-control-plane` already is one.

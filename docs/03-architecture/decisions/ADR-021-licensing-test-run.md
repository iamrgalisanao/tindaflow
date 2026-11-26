# ADR-021: A Test Run of the Licensing Service on the Planned Host

## Status

Accepted — 2026-11-26. The owner asked for licensing to be available before 2 December 2026, chose a test run when the
limits of that date were explained, and asked the engineering team to carry it through, with a software architect leading
and independent code review. **It approves a test run and nothing more.**

It allows, for this test only, a deployment that ADR-020's planned-host amendment did not approve. ADR-019's gates, and
ADR-020's lists of what is needed before the first production key and before real use, **stay unmet and unchanged**.

## What the test run is

By 1 December 2026, on the planned host (a rented virtual server that also runs a **test** version of the shop
application and is maintained by the owner):

1. the licensing service runs from a recorded commit;
2. a made-up client is registered, and a throwaway test shop is activated with an offline bundle;
3. the test shop renews online, gets a second certificate, is refused while suspended, renews again after it is
   resumed, and locks after it is revoked;
4. the service's data and secrets are backed up, encrypted, off the server, and the registry restore is tried once;
5. the existing test shop is unchanged, and the steps to remove the service are written and checked.

## Limits

- **Test keys only,** made on the server for this run. Never a production key, and never the published test-vector keys,
  whose private halves anyone can derive from this repository.
- **Made-up data only.** No real client, shop, sale, staff member or customer.
- **No real shop is enforced.** The throwaway test shop uses enforcement mode so that suspension and revocation can be
  seen. It is discarded afterwards.
- **No admin screens.** The existing console commands are used.
- **No build with licensing keys leaves the test.** The test shop's build gets the public keys as an uncommitted change in
  a throwaway copy. `vendor_keys` and `code_keys` stay empty in the repository; a test guards this.
- The deployment is **removed or reviewed by 15 December 2026**.

## Decisions taken by the engineering team

Under the owner's request that the team carry the work through. The owner can change them.

- **Access only through an SSH tunnel.** The service listens on the server's loopback address; nothing is opened to the
  internet, no domain name is needed and the existing test shop is not touched. This does **not** show that renewal works
  over a public address with a public certificate.
- **The test shop runs on a development computer,** not on the server: a second build with keys on the same server could
  replace the existing shop's image or reuse its data, after which that shop would refuse new shifts.
- If the development computer cannot give the three hardware values, a **simulated hardware file** is used. That proves
  nothing about hardware binding; of the two computers checked before (ADR-019), neither could be activated.

## Accepted risk: a shared server

The service and the test shop share a kernel, a container engine, an administrator, a disk and an address. Whoever takes
over the server, including through the test shop, or may use the container engine on it, can read the signing keys and
change the registry. A separate container project is **not** a security boundary. That is acceptable only because the keys
and the data are discarded. It is not acceptable for real use; before that, whether licensing gets its own server has to
be decided, with the other items ADR-020 lists.

## What this run does not show

Hardware binding on a real shop computer, the shop's clock protection on Linux, renewal over the public internet,
protection of real signing keys, alerts, scheduled backups, recovery from the loss of the server, or anything ADR-019
requires before a shop is enforced (among it at least 31 days on real hardware).

## A correction to ADR-020

ADR-020's amendment describes the work on `license:issue-bundle` as making it fail when it cannot write the file, and an
earlier report said the command could report success after a failed write. **It does not report success:** it ended with
an error, but only after the computer had been registered, so that computer's one first bundle was lost. The change for
this run checks the file before registering and shows the bundle if the final write fails. Re-issuing a lost first bundle
is still not possible and is not part of this run.

## Amendment, 2026-11-26: what else the server runs

The owner ran read-only checks and listed what is in use. **This corrects the description above:** besides the test shop,
the planned host runs several other applications, **some of them in regular use** and some for demonstration, behind a web
router that reads Docker. They are not named here, because this repository is public. Where this amendment differs from
the text above, this amendment wins.

**The risk is wider than the one accepted above.** The statement that sharing is acceptable because the keys and data are
discarded covers only the licensing keys and data. It does not cover the other applications: a mistake during the test,
or the load of building it, could disturb them, and removing the test later does not undo that.

**Added before the first run** (in the service's repository):

- the test's containers are closed to the web router, are the first ended if memory runs out, and the image is built
  one compile job at a time;
- every other container is compared before and after: recreated, restarted, stopped or changed health;
- the instructions cover every application that matters, stop on a problem in any of them, and forbid rebooting,
  upgrading or restarting the container engine as part of the test.

These lower the risk and do not remove it. A matching comparison does not show that an application works.

**Still required before the run:** a recent backup stored off the server for each application in use, the test shop
included; a way to check that each one works; and **the owner's go-ahead to run the test on this server**, knowing the
risk above. The go-ahead has not been given yet.

**Owner's go-ahead, 2026-11-26.** After being told the risk above, the owner stated that none of the applications on the
server is mission-critical and asked for the test to run on it. **This is the go-ahead.** For this test only, the owner
does not require off-server backups to be confirmed first; this replaces that requirement here and in the service's
instructions. Without confirmed backups, damage to an application or its data may not be recoverable. Checking that the
applications work before and after, comparing the other containers, and stopping on any problem still apply.

## Result, 2026-11-26

The service ran from its repository's commit `5148e4b` on the planned host, with the throwaway test shop on a development
computer using a **simulated hardware file**. The owner ran the server steps and checked the applications.

**Passed:**

- the build, the start and every route check;
- activation by offline file; an online renewal through the SSH tunnel; a second certificate after the till limit changed;
- renewal refused while suspended, and working again after resumption;
- an encrypted backup that passed the restore check, and an off-server copy whose checksum matched;
- revocation: the shop reported itself locked, and the service then refused to resume it. A sale was not attempted.

**The other applications:** the owner reported that those in use, the demonstration one checked, and the test shop on the
server worked after the run. Of the other containers, the comparison showed changes only in two that were already
restarting about hourly before anything was built. The shared database image was unchanged. Neither check proves that
nothing else was affected.

**Found during the run:**

- while suspended, the shop shows only an unexpected-answer error with HTTP 403;
- the instructions given to the owner showed the stop command, meant for problems only, as a block to copy; it was run
  twice, and the service was started again each time;
- the backup was once reported as passed when no backup file existed. The copy step showed this; the backup and restore
  check were then run and their output read.

**Removed the same day**, at the owner's request, well before 15 December. On the server the test's containers, images,
volume, networks and files are gone. After the containers were removed, the owner reported that the five applications
checked still worked, and the comparison again showed only the two restarting containers, with the shared database image
unchanged. The comparison files went with the test's files, so no later comparison was made. Docker's build cache was left
on purpose. On the development computer the test shop, its database and the test files were deleted; the encrypted backup
was kept for the owner to move or delete.

Everything under "What this run does not show" still stands, and **no gate of ADR-019 or ADR-020 is met by this run**.

# Two-way Google Calendar synchronization

The Google Naptár tab uses the existing Kimai login. Google authorization is separate
from the calendar connector in chat: the server needs its own OAuth client and offline
refresh token. Until configured, the screen shows setup instructions and sync stays off.

## Google setup

1. Create a Google Cloud project and enable **Google Calendar API**.
2. Configure Google Auth Platform consent for your account. During testing add your
   Google account as a test user. Request `calendar.events` and
   `calendar.calendarlist.readonly`. External applications left in Testing generally
   receive refresh tokens that expire after seven days; choose the appropriate
   publishing configuration before expecting unattended long-term operation.
3. Create an OAuth client of type **Web application**. Set this exact authorized
   redirect URI: `https://bgamgee.ddns.net/kimai/hu/shifts/google/callback`.
4. Put `GOOGLE_CALENDAR_CLIENT_ID`, `GOOGLE_CALENDAR_CLIENT_SECRET` and
   `GOOGLE_CALENDAR_KEY` in the private `deploy/.env`. Generate the last value with
   `openssl rand -base64 32`. Recreate the app to load the environment. Keep this key
   with private recovery configuration: database backups alone cannot decrypt tokens.
5. In Google Naptár, connect the intended account and approve both requested scopes.
   Select writable calendars and the destination for new Kimai records. A dedicated
   Helyettesítések calendar is recommended. Saving policy pauses synchronization.
6. Choose the inclusive Budapest date range (default today through next year), define
   aliases, then request Preview. Approve/link incoming matches or ignore them. Approve
   outgoing records with Use Kimai version. Enable automatic changes only after review.

Reauthorization always pauses automatic writes. Disconnect removes saved authorization
and stops access from this installation; it leaves local records, remote events and
identity tombstones. Google account settings can revoke the OAuth grant itself.
Never replace this connection with another Google account without reconciling links
and selecting that account's calendars again.

## Phone entry and recognition

Apple Calendar can create/edit these events when their selected account/calendar is
Google; iCloud-only entries are outside this integration. Enter actual start/end times.
No Kimai schema is required on the phone. In the dedicated Helyettesítések calendar:

- `Víztorony` plus start/end times creates a shift with that workplace's saved defaults.
- `Víztorony Számla` or `Víztorony EFO` overrides the accounting category.
- A known workplace in Location works too. Aliases remain configurable.
- `Szabadság`, `Családi szabadság` or `Szabi` creates named time off, whole-day or timed.
- Other titles create ordinary events when **Also import ordinary events** is enabled.
- Optional `4200 Ft/óra` in a title or note overrides the rate; fractional rates require review.

Enable ordinary-event import only for calendars whose unmarked entries should appear
in Kimai. It is enabled for the dedicated calendar and remains opt-in for other selections.
Accents, case and punctuation are ignored for workplace/category matching. Ambiguous
workplaces, all-day paid shifts and explicit Helyettesítés without a known workplace
remain in review, excluded from earnings. Defaults apply on first creation; later omitted
fields preserve saved rates/categories. Ordinary events and holidays never affect earnings.

Exported descriptions now contain only the user's notes. Type, saved rate, category and
color are retained as private Google event metadata and local synchronization state.
An unchanged `Ft/óra` amount in notes does not reset a newer saved Kimai rate, even
when other note text is edited. Change that amount or use the Kimai editor for an override. Names can
be edited normally on the phone; an existing link never disappears merely because a
name no longer identifies a workplace. Existing linked event types remain fixed.

Legacy `[Kimai]` blocks and explicit key/value fields are still understood for backwards
compatibility, but are neither required nor generated. Whole-day Google end dates are
exclusive. Arbitrary holiday/event colors survive in private metadata; Google palette
colors do not map one-to-one to application colors.

## Reconciliation and operation

The host timer runs `app:shifts:calendar-sync` once per minute after the previous run
finishes. A database advisory lock serializes manual/background passes per owner.
Local snapshots detect edits/deletions made through both the custom editor and native
Kimai routes, staging durable operations before HTTP writes. This means outbound
changes normally wait up to one minute; Google/phone propagation can add delay.
No Redis, new Composer package or separate long-lived application runtime is required.

Incoming changes use paginated incremental tokens. Invalidated tokens rebuild only
remote sync state, never accounting history. Relevant recurring masters expand inside
the finite selected date range; moved/cancelled occurrences and shortened series are
reconciled separately. Kimai edits affect that occurrence, not the whole series.

Each link retains a last-synced baseline, Google ETag and pending operation. Independent
field edits merge; incompatible edits pause that entry and preserve the local version.
Google updates/deletes use If-Match. Stable insert IDs and private link metadata protect
retries after uncertain network completion. Temporary failures back off up to an hour;
authorization failures pause the connection. Other Google event fields, reminders and
attendees are not rewritten.

Google deletion cancels a linked paid shift, retaining accounting history; calendar-only
entries are removed. Local deletion removes the linked Google event. Cancellation in
Kimai is different: it keeps the event, labeled Törölt and excluded from earnings.
Deletion racing with an edit requires review. Ignored/deleted links remain tombstones,
so a later poll does not recreate them. Existing workbook/manual records with the same
kind, workplace and interval require explicit attachment using their displayed identity
(e.g. `shift:123`), never an automatic overwrite. Resolve incoming reviews before exporting
unlinked local records, to avoid creating duplicates while matches remain unresolved.

## Deployment, recovery and validation

Install `deploy/kimai-calendar-sync.service` and `.timer` in `/etc/systemd/system`,
then run `systemctl daemon-reload` and `systemctl enable --now kimai-calendar-sync.timer`.
The service uses the existing app container as www-data. It is harmless with no enabled
connections. Update the container name in the unit if deploying another Compose project.

Before migration/upgrades, pause sync and take a private database backup. Migration
Version20261027100000 adds only the two sync tables; it excludes known upstream schema
drift. Restore database and matching encryption/configuration together. After restoring
an old backup, leave the timer stopped and connections paused, preview current Google
state and resolve uncertain pending changes before resuming writes. A restore cannot
roll back edits already sent to Google. Retain links across image rollback; removing
sync tables discards duplicate-prevention history.

Focused tests cover recognition, whole-HUF overrides, DST instants, mapping round trips,
three-way merge, encrypted token integrity, refresh and conditional request errors.
Live connector tests use explicitly marked temporary events and remove them afterward.
A connector-based test does not establish unattended server OAuth operation; that final
acceptance needs the user's configured OAuth client and authorization.

Sources: [OAuth](https://developers.google.com/identity/protocols/oauth2/web-server),
[incremental sync](https://developers.google.com/workspace/calendar/api/guides/sync),
[ETags](https://developers.google.com/workspace/calendar/api/guides/version-resources),
[recurrences](https://developers.google.com/workspace/calendar/api/guides/recurringevents).

## Validation on 2026-10-27

Focused checks passed: 59 tests / 236 assertions, including stateful two-way lifecycle,
recurrence changes, missing deletion tombstones during cursor recovery, retry identity,
concurrent-write errors and CSRF rejection. The broader unit run passed 3,655 tests /
19,822 assertions with four skips. PHPStan core/tests, style, Twig/YAML/container and
Doctrine mapping checks passed. Public desktop/mobile setup and existing reports passed
browser checks. The additive migration rolled back/reapplied, and the pre-upgrade backup
restored all 784 native rows into a disposable database before reapplying the migration.

Two explicitly marked events in the user's Google calendar were created, read, edited
and deleted via the connected Calendar transport; real payloads exercised the new mapper
and native services in a disposable database. Cleanup was verified by deleted status
and an empty follow-up search. Production accounting remained 749 active records plus
18 complete cancelled historical records (784 total native rows including legacy drafts).
This initial validation preceded server authorization. Subsequent live activation is
recorded below; physical phone entry itself has not been observed.

Switching holidays/general events between whole-day and timed mode explicitly clears
the old Google date/dateTime/timeZone fields in PATCH requests. This prevents Google
from rejecting a valid change because both boundary representations remain present.

## Live activation on 2026-10-27

The dedicated private Helyettesítések calendar uses Europe/Budapest and is the only
selected source/destination. The initial preview exported 34 existing records within
2026-10-27 through 2027-12-31; historical records and other calendars were untouched.
The authenticated server transport passed live recognition, two-way rate/category/time
edits, zero rates, same-field conflict resolution, whole-day/partial-day conversions,
general events, unknown-workplace review, recurring DST occurrences and moved instances.
The actual host timer independently synchronized fresh events in both directions, and
offline token refresh succeeded. All marked fixtures were removed and original local
records remained unchanged. The date-boundary fix passed 60 focused tests / 242 assertions,
PHPStan core/tests and code style checks. Google Testing-mode refresh-token expiration
still applies until the OAuth application's publishing configuration is changed.

Cancelled recurring masters are removed from the polling set even when Google retains
their recurrence fields; deleted series do not generate repeated instance requests.

Schema-free phone entry and clean exported descriptions passed 63 focused tests /
266 assertions, PHPStan core/tests and Twig/YAML/style checks. The ordinary-event
fallback is enabled only for the dedicated calendar during activation.

Live schema-free acceptance passed for titles, locations, holidays, ordinary events and
plain hourly overrides. All 34 existing visible blocks were removed without changing
local accounting records, and all five temporary Google events were deleted.

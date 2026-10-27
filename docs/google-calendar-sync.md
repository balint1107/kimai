# Two-way Google Calendar synchronization

The Google Naptár tab uses the existing Kimai login. Google authorization is separate
from the calendar connector in chat: the server needs its own OAuth client and offline
refresh token. Until configured, the screen shows setup instructions and sync stays off.

## Google setup

1. Create a Google Cloud project and enable **Google Calendar API**.
2. Configure Google Auth Platform consent for your account. During testing add your
   Google account as a test user. Request `calendar.events` and
   `calendar.calendarlist.readonly`, plus `calendar.app.created` for automatic calendar
   creation (declare it in Google Auth Platform → Data Access). External applications
   left in Testing generally
   receive refresh tokens that expire after seven days; choose the appropriate
   publishing configuration before expecting unattended long-term operation.
3. Create an OAuth client of type **Web application**. Set this exact authorized
   redirect URI: `https://bgamgee.ddns.net/kimai/hu/shifts/google/callback`.
4. Put `GOOGLE_CALENDAR_CLIENT_ID`, `GOOGLE_CALENDAR_CLIENT_SECRET` and
   `GOOGLE_CALENDAR_KEY` in the private `deploy/.env`. Generate the last value with
   `openssl rand -base64 32`. Recreate the app to load the environment. Keep this key
   with private recovery configuration: database backups alone cannot decrypt tokens.
5. In Google Naptár, connect the intended account and approve all three requested scopes. On first
   connection,
   owned **Helyettesítések** and **Szabadság** secondary calendars are reused by name
   (accents/case optional) or created with Budapest time. They become the selected
   shift and time-off destinations. Existing selections are preserved on reconnect.
   You can still select calendars manually; saving policy pauses synchronization.
6. Choose the inclusive Budapest date range (default today through next year), define
   aliases, then request Preview. Approve/link incoming matches or ignore them. Approve
   outgoing records with Use Kimai version. Enable automatic changes only after review.

Reauthorization always pauses automatic writes. Disconnect removes saved authorization
and stops access from this installation; it leaves local records, remote events and
identity tombstones. Google account settings can revoke the OAuth grant itself.
Never replace this connection with another Google account without reconciling links
and selecting that account's calendars again.

## Automatic calendar setup

The narrow `calendar.app.created` scope permits creating secondary calendars without
requesting calendar sharing/property administration. Existing two-scope refresh grants
continue syncing; new consent requests the creation permission too. No client secret or
encryption-key change is needed. Setup only runs after owner-bound OAuth completion and
only when no calendar selections/destination exist. Preview/enable approval is still
required before importing/exporting events; calendar setup itself does not import data.
Primary calendars and shared writer calendars are never automatically adopted. Multiple
matching owned calendars require manual selection. Existing owned singular/accent-free
work-calendar names are supported. Ownership, all pages and hidden calendars are checked.

Setup uses the same advisory lock as synchronization and flushes each created ID before
starting another insert. Before each insert, an uncertainty marker is saved: after a lost
response or server error, a retry first looks for the owned calendar. If it still cannot
be found, setup stops for manual selection instead of risking a duplicate. Definite API
rejections (such as missing permission) permit retry after reconnection. Partial setup
and OAuth credentials remain saved; neither calendar nor events are deleted on failure.
Checkpoint fields live in the existing JSON settings and require no schema migration.

Validation passed 87 focused tests / 365 assertions, PHPStan core/tests, code style,
Twig/YAML and mobile/desktop settings checks. Insert/reuse/retry cases used simulated
Google responses. The deployed account's existing two-scope offline refresh and saved
calendar choices were verified live with all 767 native records preserved. Live calendar
creation awaits explicit consent to the new scope; no extra calendars were created.

See [Google's calendar creation API](https://developers.google.com/workspace/calendar/api/v3/reference/calendars/insert)
and [scope definitions](https://developers.google.com/workspace/calendar/api/auth).

## Phone entry and recognition

Apple Calendar can create/edit these events when their selected account/calendar is
Google; iCloud-only entries are outside this integration. Enter actual start/end times.
No Kimai schema is required on the phone. The default shift format is:

- Calendar: **Helyettesítések**.
- Title: workplace name, e.g. `Viztorony` (accents are optional).
- Note, first line: `EFO, 8800` or `Szamla 8800`. Comma or space separates the category and the saved HUF/hour rate.
- Additional free-text notes may follow on new lines. Start/end are ordinary calendar fields.

Missing category/rate use workplace defaults for new shifts and preserve saved values
for linked shifts. Zero rates and grouped whole forints (`8 800`) are valid; fractions,
ambiguous workplaces and malformed accounting headers require review. Legacy category
in the title, `4200 Ft/óra` overrides, aliases and explicit key/value fields remain readable.
Exports use workplace-only titles and a compact category/rate first note line; free notes
are retained below it. Internal identity/color/type details stay in private Google metadata.

Select the separately owned **Szabadság** calendar as the time-off destination and include
it among synchronized calendars. Every new entry there becomes time off regardless of
its name; both whole and partial days work. New Kimai holidays export there, while shifts
and ordinary events use the main destination. With separate calendars configured,
`Szabadság` in a main-calendar title alone does not classify a new event as time off.
Existing linked types remain fixed; do not silently convert paid shifts into holidays.

Ordinary-event import stays opt-in for selected calendars. Unmarked non-workplace entries
in the main calendar become ordinary events when enabled. Ordinary events and time off
never affect earnings. Changing workplace defaults does not modify saved rates. Google
whole-day end dates are exclusive; the Kimai editor presents inclusive holiday end dates.

Legacy `[Kimai]` blocks remain readable but are not required or generated. Color survives
in private metadata because Google's palette does not map one-to-one to arbitrary colors.

## Reconciliation and operation

The host timer runs `app:shifts:calendar-sync` once per minute after the previous run
finishes. A database advisory lock serializes manual/background passes per owner.
Local snapshots detect edits/deletions made through both the custom editor and native
Kimai routes, staging durable operations before HTTP writes. This means outbound
changes normally wait up to one minute; Google/phone propagation can add delay.
The icon-only refresh button uses a single clockwise arrow for clarity at small sizes.
Beside **New event**, it runs the same two-way sync immediately
and reloads visible records without changing the calendar view/date. It disables itself
while running, has an accessible label/tooltip, and displays sync failures in the calendar.
It respects paused/disconnected connections and uses the existing CSRF-protected endpoint
(JSON success or HTTP 409 for unavailable/failed sync; normal settings forms still redirect).
Verified through the public proxy on desktop/mobile: real sync, date preservation,
CSRF rejection and button recovery after a failed request; frontend lint/build and PHP/Twig checks passed.
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

### Record-by-record review

Open **Calendar review** from the navigation or Google settings. The queue shows incoming
reviews, conflicts and failed entries individually, with original Google data and the
current Kimai version. Correct the type, workplace, category, whole-HUF rate, dates/times,
name, note or cancellation; **Save and next** validates and saves one native record and
queues its corrected Google representation. Whole-day editor end dates are inclusive.
The next active sync sends the correction; paused sync stays paused. **Next** leaves an
entry unresolved, while **Ignore** retains its Google event without importing it.
Existing version choices remain available, including deletion conflicts. Linked types
stay fixed and the dedicated holiday calendar only accepts time off. Exact matching
records require explicit attachment rather than duplicate creation. Changed Google or
Kimai records invalidate an open correction form; synchronize/reload before reapplying.
Invalid submissions retain their entered fields. All review actions remain owner-scoped
and CSRF-protected. No schema migration or additional dependency is needed.

Validation: 76 focused tests / 317 assertions, PHPStan core/tests, code style,
Twig/YAML validation and the frontend lint/build passed. Browser acceptance through
the public prefix covered desktop/mobile layout, type-specific fields, retained invalid
input, stale forms, CSRF, shift/holiday correction and an empty queue after completion.
Both corrected fixtures reached Google and were then deleted together with their native
records; original records were unchanged.

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

The workplace-title/category-salary-note default and separate holiday routing passed
67 focused tests / 291 assertions, PHPStan core/tests and Twig/YAML/style validation.
Comma or space is accepted between category and hourly rate; exports use a comma.
Live acceptance verified both separators, zero rates, native rate changes, whole-day and
partial-day holiday routing, ordinary events and unknown-workplace review. All five
temporary Google events and their native records were removed; original records were
unchanged. The owned Szabadság calendar is selected and automatic sync is active.

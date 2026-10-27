# Shift accounting fork

Based on Kimai 2.71.0. The single-user Hungarian interface uses HUF, Budapest time,
Monday-first weeks and a calendar editor. Navigate between Calendar, Summary,
Workplaces and Import review. Workplace defaults apply only when creating a new
shift or explicitly selecting another workplace; saved hourly rates remain fixed.
Hourly rates and workplace rate defaults accept nonnegative whole forints only
(input step 1, enforced server-side). Fractional imported rates remain in review,
without silently rounding their source values. Earnings can still reflect partial hours.

Each workplace owns one managed `Shifts` project. Activities must be `EFO` or `Számla`.
Blank/Unknown/Ismeretlen workplaces and unknown categories cannot be saved. Legacy
missing-field entries are preserved as review-only drafts, excluded from the calendar,
reports, cards and exports until corrected through Import review. Native timesheets remain authoritative. Shift
metadata stores cancellation, review warnings and source provenance. Rates use
Kimai's classic elapsed-seconds calculation without duration rounding. Reports
display whole HUF while exports retain numeric precision. Each record counts once;
the Budapest start date determines its report month/year, including overnight work.

Planned/in-progress earnings are expected income. After the end timestamp passes,
the whole shift automatically becomes completed. Cancellation excludes a record
from all accounting aggregates. The UI refreshes every minute and after edits.
The Completed/Planned headline cards show only the current calendar month in
Europe/Budapest and include the month in their labels. Selected filters still apply;
historical report tables and exports retain their selected date ranges. Calendar
navigation does not change the headline month. The cards advance automatically
when the month changes during a minute refresh.
This is an earnings register; categories do not implement taxes or statutory invoices.

## Summary layout

Összegzés and Munkahelyek initially filter January 1–December 31 of the current
Europe/Budapest year. Reset restores this range while clearing other filters (including
the workplace selection). Dates remain editable or clearable for historical/all-time
reports and exports. Calendar filters still start unrestricted. Minute refreshes
preserve the user's chosen range; reopening either report uses the current year.

Monthly and yearly summaries have one row per period. EFO and Számla are major
column groups, each with Alkalom, Óra and Összeg subcolumns. Every cell displays
`completed / total`, where total includes completed plus planned/in-progress work.
The final column combines both categories' earnings in the same format. A zero
category displays `0 / 0`; cancellation never contributes. Existing report filters
apply to both sides of the pair. The XLSX Összegzés sheet uses the same grouped
headers and paired values; numeric per-shift detail remains available separately.
Both summary tables use compact cell spacing and horizontal scrolling on narrow
screens. The Időszak header and first-column cells stay pinned to the left with
opaque backgrounds while the remaining columns scroll. Scroll regions support
keyboard focus as well as touch/trackpad scrolling.

The current Budapest month is highlighted green in Summary and Workplace monthly
tables, including the sticky period cell. Other periods keep their normal background.
Green highlighting is limited to these report rows; the calendar has no green today outline.

## Calendar day markers and personal holidays

When the calendar area is narrower than 768 CSS pixels, week/month views display
three day columns at once. Drag horizontally (mouse or touch) to reveal the remaining
days; the toolbar and week time labels stay in place. The grid also supports normal horizontal trackpad
scrolling and keyboard scrolling when focused. The initial mobile view is now week.
Tap/click and release without moving opens the event editor. A short hold followed by
dragging scrolls horizontally and/or vertically; after the grid reaches its vertical
edge, vertical movement scrolls the page. On week views, hold still for 500ms, then
drag vertically to highlight a time interval in 15-minute steps; releasing opens the
editor with that range. Cancelled gestures do not open the editor. In compact views,
swiping an event scrolls instead of moving it; tap to edit its dates. Desktop
drag-to-move/resize and range selection remain available,
and day view retains its full-width layout. Resizing switches between these modes
without replacing the selected date/view or changing saved records.

Validated through the public proxy at 390/700/1280px: three-column week/month and
seven-column desktop layouts, mouse/touch horizontal and vertical scrolling,
500ms-hold vertical interval selection, release-only creation, existing-event taps,
cancelled holds, day view and date preservation on resize. Frontend lint/build passed;
the browser checks made no saved-record writes.

Day/week time grids use one-hour slots and labels. Each hourly row is at least
2.5rem (40px at the default font size); remaining vertical space expands all rows
evenly instead of leaving an oversized blank area below 23:00. Headers and body
columns stay aligned, and the all-day axis label can use its full width. Drag/resize retains 15-minute
precision, and saved shift times are unchanged. Event labels wrap inside their cards,
including long workplace names and personal-holiday notes; short cards clip vertically
rather than spilling outside, with the existing tooltip showing the full label.
Shift titles show only the workplace. EFO/Számla and the saved hourly rate appear in
the event description/tooltip, followed by any free note; the editor keeps these fields
separate. Google exports use the same workplace-only title convention.
Calendar events and tooltips omit planned/completed/in-progress labels and use the
same border style for those states. Cancellation stays labeled and struck through;
report totals and their automatic state calculation are unchanged.

The calendar uses gray for weekends, red for Hungarian public holidays, amber for
substitute workdays, blue for substitute rest days and purple for personal time off.
A text legend and date tooltips explain each color. Substitute working Saturdays
use the workday marker rather than weekend shading. Public holidays use fixed dates
and Gregorian Easter offsets. Verified annual swaps cover 2023–2026; other years show
a notice and no guessed swaps. Refresh the annual data in `assets/shifts-calendar.js`
when a new official schedule is published.

Sources: [Labour Code, section 102](https://net.jogtar.hu/jogszabaly?docid=a1200001.tv),
[2024 swaps](https://njt.jog.gov.hu/jogszabaly/2023-15-20-8P),
[2025 schedule](https://nmhh.hu/cikk/184586/Szamatadasi_idoablakok), and
[2026 swaps](https://net.jogtar.hu/jogszabaly?docid=a2500010.ngm).

Új esemény opens one editor with Helyettesítés, Szabadság and Általános esemény
choices. New entries can switch type while retaining their chosen dates. Saved
entries/import-review drafts keep their type; the selector is locked to avoid
converting accounting records into unrelated calendar entries. Shift fields include
workplace, whole-HUF rate, EFO/Számla and cancellation with an amount preview. Holiday and general-event fields include a required name, color picker, optional note
and inclusive all-day date ranges or partial days. Holiday names default to Szabadság; switching away clears this automatic name,
while user-entered names remain;
existing entries without a saved name retain their notes. Colors persist per entry,
with purple/teal defaults for existing holidays/general events.
Hidden fields are disabled, so their validation and accounting values cannot leak
into another type. Click an event to edit/delete it in the same dialog.
Purple marks holidays; teal marks general events. Time off
remains visible independently of shift filters; it never creates timesheets, blocks
shift entry, changes rates or contributes to hours/counts/earnings/exports. Both calendar-only types are stored
in owner-scoped `shift_time_off` rows, included in ordinary database backups. The API
retains the CSRF-protected `/shifts/time-off/save` and `/shifts/time-off/delete`
endpoints for compatibility. `kind` is `time_off` (default) or `event`; general events
require `title` (150 characters). Holidays also save an optional API title (the UI
requires one); both allow `note` (200 characters) and optional `color` (#RRGGBB).
`event_title` exposes the explicit name independently of the legacy title/note fallback. IDs are
owner-checked, dates validated and whole-day exclusive ends derived server-side.

## Workplace reports and defaults

Munkahelyek requires a single workplace selection; there is no All option. Until
selected, reports/cards stay empty and export links are disabled. Monthly and yearly
tables reuse the compact EFO/Számla completed/total layout with a pinned Időszak column.
Reset clears the selection and restores the current-year date range. Other tabs retain their optional workplace filter.
Új munkahely opens an empty creation dialog; Edit workplace opens the selected
workplace's defaults. Saving selects the saved workplace and refreshes its reports.
Validation failures stay in the dialog without losing input. No existing shift rates
change when workplace defaults are edited.

## Workbook import

Upload XLSX (20 MB compressed / 100 MB expanded limit), review the preview, then
confirm import. Only monthly sheets matching `YYYY.MM.` are read. Header positions
identify shift blocks. Formula totals are not evaluated. Rate-only placeholders
are ignored. Missing workplace/category/time/rate data stays in the review queue
without creating an active shift. Historical placeholder entries remain linked to
their source for correction, never guessed or discarded.
Date cells are retained even when they disagree with sheet names. Correct entries
using Import review; original normalized source values are retained permanently.

Import batches are unique per owner/checksum; source coordinates are unique within
a batch. The full import runs under a transaction and batch lock. Uploading the
same file again does not overwrite edits. A changed file is a new import, so do not
upload revised overlapping workbooks without first reconciling duplicate history.
The original workbook is not retained by web upload; keep a private original backup.

The reconciliation download compares independent raw time/rate controls with linked
timesheets, by month. Later corrections deliberately show differences. Deleting an
imported shift preserves its source item and must be reviewed. XLSX exports include
three summary views and unlimited per-month detail rows; CSV exports contain filtered
shift details. Both protect user-entered strings against spreadsheet formula injection.

## Developer interfaces

Authenticated locale-prefixed `/shifts` routes serve the UI, filtered data, workplace
defaults, shift save/delete, upload/preview/commit, reconciliation and XLSX/CSV export.
JSON mutations require the existing session plus an `X-CSRF-Token` issued for `shifts`.
Shift data accepts workplace ID, ISO/local begin/end, nonnegative HUF/hour rate,
canonical category, note and cancellation. Owner checks apply to shift/import IDs.
Public auth remains Kimai's own; the fork does not introduce another login mechanism.

`app:shifts:import PATH USERNAME [--commit]` supports a private server-side import.
Without `--commit` it only previews; it also initializes the selected user's Hungarian
locale and calendar landing page. Optional two-way Google Calendar synchronization is documented in [Google Calendar sync](google-calendar-sync.md). It stays disabled until the server OAuth client and user authorization are configured.

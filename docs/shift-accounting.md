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

Új szabadság creates whole-day ranges (inclusive end date) or partial days in Budapest
local time, with an optional note. Click a purple event to edit/delete it. Time off
remains visible independently of shift filters; it never creates timesheets, blocks
shift entry, changes rates or contributes to hours/counts/earnings/exports. It is stored
in owner-scoped `shift_time_off` rows, included in ordinary database backups. The API
uses CSRF-protected `/shifts/time-off/save` and `/shifts/time-off/delete`; IDs are
owner-checked, dates validated and whole-day exclusive ends derived server-side.

## Workplace reports and defaults

Munkahelyek requires a single workplace selection; there is no All option. Until
selected, reports/cards stay empty and export links are disabled. Monthly and yearly
tables reuse the compact EFO/Számla completed/total layout with a pinned Időszak column.
Reset clears the selection. Other tabs retain their optional workplace filter.
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
locale and calendar landing page. No calendar service integrations are included.

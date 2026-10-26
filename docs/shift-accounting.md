# Shift accounting fork

Based on Kimai 2.71.0. The single-user Hungarian interface uses HUF, Budapest time,
Monday-first weeks and a calendar editor. Navigate between Calendar, Summary,
Workplaces and Import review. Workplace defaults apply only when creating a new
shift or explicitly selecting another workplace; saved hourly rates remain fixed.

Each workplace owns one managed `Shifts` project. Activities encode `EFO`, `Számla`
and import-only `Unknown`. Native Kimai timesheets remain authoritative. Shift
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

## Workbook import

Upload XLSX (20 MB compressed / 100 MB expanded limit), review the preview, then
confirm import. Only monthly sheets matching `YYYY.MM.` are read. Header positions
identify shift blocks. Formula totals are not evaluated. Rate-only placeholders
are ignored. Timed records with missing workplace/category become explicit Unknown
records with review flags. Missing times/rates remain source items without shifts.
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

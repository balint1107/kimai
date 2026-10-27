# Demo deployment and upgrades

Run the isolated `deploy/compose.yaml` project on server-backup. It uses a dedicated
MariaDB 11.4 and native production Kimai 2.71.0 runtime. Frontend compilation occurs
inside the build, with `/kimai` as Webpack public path. Do not commit generated assets.
Create protected `deploy/.env` containing APP_SECRET, DB_PASSWORD, DB_ROOT_PASSWORD,
ADMIN_EMAIL and ADMIN_PASSWORD. Use long random hexadecimal secrets. Never track it.

Before building, run `bash deploy/package-source.sh` to package the public fork source
as `deploy/source.tar.gz`, excluding Git state, secrets, dependencies, runtime state and macOS resource metadata.
The image serves it as `/kimai/fork-source.tar.gz` to provide the exact deployed source.
Keep this archive ignored. Build with `docker compose -f deploy/compose.yaml build`.

The backend binds `192.168.50.150:8093`. The database has no host port. Existing nginx
on server-rpi terminates TLS and forwards `/kimai/`, stripping that prefix and setting
X-Forwarded-Prefix, Host, Proto, Port and client IP explicitly. Trust only that proxy.
Set DEFAULT_URI to the full public URL; session cookies are scoped to `/kimai/`.
KIMAI_COOKIE_PATH sets the remember-me cookie path in the existing security config;
do not split the firewall map into environment-specific configuration files.
Use request-aware Symfony URL generation and the compiled prefix for assets. Do not
rewrite HTML/JS response bodies. Validate `nginx -t` before reloading the proxy.
The tested proxy snippet is `deploy/nginx-kimai.conf`.

The upstream runtime declares an anonymous volume for all of `var/`. Mount its
`var/cache` separately as tmpfs so compiled Twig/container state cannot survive an
image replacement and mix old templates with new JavaScript. Data remains persistent.

Start with `docker compose -f deploy/compose.yaml up -d`. The upstream entrypoint
creates the database, applies migrations and creates the initial admin account.
Registration is disabled. Keep credentials private. Import only after a database backup.

## Fast development iterations

Scope checks to the change and batch related edits before deploying once. For
layout-only changes, use the frontend build and a focused mobile/desktop browser
check through `/kimai/`. For PHP changes, run the relevant PHPUnit tests and the
repository-required style/static checks; add import, accounting or database checks
when those behaviors change. Run broader regression checks for shared configuration,
dependencies, migrations and release upgrades rather than repeating them after each
visual edit. Documentation-only changes need no image rebuild.

Sync only changed source files and refresh the public source archive before building.
Keep Docker's layer cache: frontend dependencies install before copying assets, so
CSS/JavaScript and lint/build configuration edits reuse them. PHP/Twig-only changes reuse the entire frontend
stage. Recreate only the app (`docker compose -f deploy/compose.yaml up -d --no-deps app`)
when database configuration is unchanged. Keep deployment images reproducible; use
the backup procedure below before imports, upgrades or database changes. Report the
focused checks actually run and any remaining validation limits.

## Backup, restore and rollback

Use `mariadb-dump --single-transaction --routines --triggers` inside the database
container, with its existing MARIADB_ROOT_PASSWORD environment variable. Store dated
compressed dumps in the protected sibling `private/` directory. Never commit dumps.

### Daily database snapshots

The host's `kimai-database-snapshot.timer` runs at **03:00 Europe/Budapest**, including
DST changes, and catches up after downtime. Its oneshot service retries failures after
five minutes, up to three attempts per hour. This is a server systemd job; no running
desktop session is required. Install/update it from the source checkout:

```sh
install -d -m 700 /home/balint/projects/kimai-shifts/private/database-snapshots
sudo install -m 644 deploy/kimai-database-snapshot.service deploy/kimai-database-snapshot.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now kimai-database-snapshot.timer
sudo systemctl start kimai-database-snapshot.service
```

`deploy/kimai-database-snapshot.py` streams a transaction-consistent `kimai` dump from
the existing MariaDB container, including routines/triggers/events, into gzip. All
current tables use InnoDB. Passwords stay in the container environment. Files are
mode 600 under the mode-700 sibling directory, named `kimai-YYYY-MM-DD.sql.gz` using
Budapest dates. A same-day rerun atomically replaces that day's snapshot only after
dump success, a nonempty result, gzip CRC validation and disk flush. An exclusive
job lock prevents concurrent writers. No app/container restart is needed.

Retention keeps **365 calendar dates including today**; files older than that window
are deleted only after a new snapshot succeeds. Manual backups elsewhere and unrelated
files are untouched. At the measured ~274 KB per snapshot, one year is roughly 100 MB.
Check status with `systemctl list-timers kimai-database-snapshot.timer` and
`journalctl -u kimai-database-snapshot.service`. Test the script with
`python3 -m unittest discover -s deploy -p test_database_snapshot.py`.

Restore a selected snapshot into a separately created disposable database first,
using:

```sh
gzip -dc SNAPSHOT | docker exec -i kimai-shifts-db-1 sh -c \
  'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" DISPOSABLE_DATABASE'
```

Never point this verification command at the production database. Compare restored
table/record counts and accounting totals before performing a production restore;
retain the original private Compose/OAuth encryption configuration for recovery.

Initial validation passed the three retention/publication/failure tests on both the
Mac and server, systemd unit validation, and a real daily-service run. The first
273,885-byte snapshot restored all 40 tables successfully; 38 durable tables matched
production row counts and data checksums, including 784 timesheets. Live sessions and
sync-status timestamps were excluded from data comparison. The temporary database was
removed; snapshot/directory permissions were verified as 600/700.

### Upgrade rollback

Before upgrades: retain the current image ID/tag, dump the database and save the
Compose/environment configuration. Build/pin the replacement image and restart app.

Test restore by creating a separate disposable database/container, loading the dump
and comparing native timesheet counts and import/control totals. For an incompatible
migration rollback, stop app and restore the matching database backup before starting
the prior image. Never roll back against a newer schema without validation. Named
volumes persist through ordinary restarts; do not use `down -v` on the demo project.

Follow upstream security releases by merging tagged releases into the feature branch,
rebuilding and rerunning import, calculation, prefix and browser regression tests.
ARM64 packaging is a later step; the Pi's nearly full root volume prevents deployment.

## Optional Google Calendar sync

See [setup and recovery](google-calendar-sync.md) for private OAuth credentials,
the encryption key, additive migration and one-minute host timer. The application
continues working without these settings; no calendar access occurs until authorized.
Pause synchronization before restoring a database or changing the Google account.

## Private-account upgrade

Migration `Version20261027150006` adds `shift_workplace`, `shift_calendar_claim` and a
nullable unique Google account identity. It preserves native customer/project/timesheet
IDs, explicit shift rates, import provenance and calendar links. Workplaces with one
historical timesheet owner are assigned to that owner; unreferenced workplaces use the
single unambiguous legacy owner. Ambiguous ownership, duplicate normalized names or
cross-account Google calendars abort before DDL. Resolve such cases explicitly; never
assign arbitrary owners. New installations with no workplaces need no backfill.

Before deploying, pause the sync timer and stop application writes, retain the previous
image, and create a private full database dump. Apply the migration and compare native
record counts, hours/earnings/rates and calendar-link counts. Resume the worker after
verification; the first successful run binds the existing Google account without
creating events solely because of this upgrade. Daily snapshots now cover all users;
restore into a disposable database before production recovery. A pre-upgrade database
backup is required when returning to the single-user image. Never run that old image
against a database containing multiple private accounts.

Account provisioning uses the integrated `/shifts/account/users` screen and native
user services; the upstream browser administration is no longer exposed. Existing password hashing, login throttling, CSRF, sessions and 2FA
remain in force. Account disable rejects sessions and skips synchronization; workplace
ownership plus a native deletion voter prevents hard deletion/transfer of a populated
private account before native deletion can mutate its history. The server operator
retains console access; application administrators use the integrated account screens. Private-account HTTP
restrictions are a fork feature, not upstream Kimai's general team-sharing model.

Legacy Docker builders do not honor `deploy/Dockerfile.dockerignore`. Build from an
isolated source staging directory with that file copied to its root `.dockerignore`;
never replace the repository's upstream ignore file or include private environment
files. Modern BuildKit uses the Dockerfile-specific ignore file directly.

Private-account preflight: 107 focused tests (448 assertions), 3,708 broader unit
tests (four skipped), core/test PHPStan, code style, container/Twig/YAML/mapping
validation passed. Restore plus migration down/up preserved all 784 legacy timesheets,
accounting sums, saved hourly rates and 36 Google links; 11 workplaces received
explicit owners. The generated migration excludes unrelated upstream schema drift.

Browser snapshots/state are ignored under `.playwright-cli/` and `output/playwright/`
and excluded by the public-source packager's standard Git ignore rules. Keep any
verification credentials outside the repository and remove temporary test accounts.

## Standalone account frontend upgrade

This code-only upgrade needs no database migration. Retain the current image and take
a private backup before replacement. The frontend build now has only `shift-shell`
and `shifts` entries; the image removes inherited `public/build`/`public/bundles`
artifacts before copying the new build. Never publish old entrypoints alongside the
new shell. Calendar/report assets and all prefix-aware URLs are retained.

Check login/logout, account list/create/edit/disable, mandatory and ordinary password
changes, own TOTP setup, legacy GET redirects, native business/API denial for both
ordinary/admin users, and a return to Calendar from every account screen. Calendar
creation/editing and Google settings/review must still work without the original
web loader. Application code remains unchanged by frontend-only browser artifacts.

Standalone preflight passed 189 focused tests (779 assertions), 3,714 broader unit
tests (four skipped), core/test PHPStan, code style, container/Twig/XLIFF validation
and the reduced frontend lint/build. Account acceptance covers real routed creation,
weak-password rejection, disable/re-enable, forced reset, old-password enforcement,
2FA setup rendering, legacy redirects and native frontend denial. Existing ownership
and two-way sync tests remain included. Browser acceptance uses the public prefix.

## Authorized identity handover

For a split between a data owner and a separate empty administrator, retain the
existing owner's user ID and rename its identity rather than rewriting accounting
foreign keys. Create the new administrator with a fresh ID and no workplaces,
records, imports or calendar connection. Demote the data owner to ordinary User.
An explicitly authorized compatible password-hash copy is a one-time credential
migration, not continuing password synchronization or single sign-on.

Rehearse against a disposable restored database, back up before the live transaction,
pause the worker and stop web writes, validate identities and uniqueness through the
native user model, and verify every accounting/import/sync table fingerprint plus
owner-scoped counts. Expire sessions, remember-me signatures and legacy API access.
Transfer an existing Google grant only with explicit approval when its Google identity
differs from the new application account. Keep imported hashes/environment payloads
private, delete temporary files/databases afterward, and confirm the new administrator
can manage accounts while its accounting data is empty. Resume and verify sync.

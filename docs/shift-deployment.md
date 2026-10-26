# Demo deployment and upgrades

Run the isolated `deploy/compose.yaml` project on server-backup. It uses a dedicated
MariaDB 11.4 and native production Kimai 2.71.0 runtime. Frontend compilation occurs
inside the build, with `/kimai` as Webpack public path. Do not commit generated assets.
Create protected `deploy/.env` containing APP_SECRET, DB_PASSWORD, DB_ROOT_PASSWORD,
ADMIN_EMAIL and ADMIN_PASSWORD. Use long random hexadecimal secrets. Never track it.

Before building, run `bash deploy/package-source.sh` to package the public fork source
as `deploy/source.tar.gz`, excluding Git state, secrets, dependencies and runtime state.
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
CSS/JavaScript edits reuse them. PHP/Twig-only changes reuse the entire frontend
stage. Recreate only the app (`docker compose -f deploy/compose.yaml up -d --no-deps app`)
when database configuration is unchanged. Keep deployment images reproducible; use
the backup procedure below before imports, upgrades or database changes. Report the
focused checks actually run and any remaining validation limits.

## Backup, restore and rollback

Use `mariadb-dump --single-transaction --routines --triggers` inside the database
container, with its existing MARIADB_ROOT_PASSWORD environment variable. Store dated
compressed dumps in the protected sibling `private/` directory. Never commit dumps.
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

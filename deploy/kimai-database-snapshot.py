#!/usr/bin/env python3
"""Create atomic, private Kimai SQL snapshots and retain 365 Budapest calendar days."""

import fcntl
import gzip
import os
import re
import shutil
import subprocess
import tempfile
from datetime import date, datetime, timedelta
from pathlib import Path
from zoneinfo import ZoneInfo


DIRECTORY = Path('/home/balint/projects/kimai-shifts/private/database-snapshots')
DUMP_COMMAND = [
    '/usr/bin/docker', 'exec', 'kimai-shifts-db-1', 'sh', '-c',
    'exec mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" '
    '--single-transaction --quick --routines --triggers --events --hex-blob '
    '--default-character-set=utf8mb4 kimai',
]


def create_snapshot(directory: Path, today: date) -> Path:
    """Return today's gzip SQL path in directory; publish atomically only after a successful, nonempty dump and CRC check."""
    destination = directory / f'kimai-{today.isoformat()}.sql.gz'
    fd, temporary = tempfile.mkstemp(prefix='.snapshot-', suffix='.tmp', dir=directory)
    partial = Path(temporary)
    try:
        with os.fdopen(fd, 'wb') as raw:
            with subprocess.Popen(DUMP_COMMAND, stdout=subprocess.PIPE) as process:
                try:
                    with gzip.GzipFile(fileobj=raw, mode='wb', mtime=0) as compressed:
                        shutil.copyfileobj(process.stdout, compressed)
                except BaseException:
                    process.terminate()
                    raise
                if process.wait() != 0:
                    raise RuntimeError('Database dump failed; existing snapshots retained')
            raw.flush()
            os.fsync(raw.fileno())
        size = 0
        with gzip.open(partial, 'rb') as check:
            while chunk := check.read(1024 * 1024):
                size += len(chunk)
        if size == 0:
            raise RuntimeError('Empty database dump; existing snapshots retained')
        os.replace(partial, destination)
        directory_fd = os.open(directory, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory_fd)
        finally:
            os.close(directory_fd)
        return destination
    finally:
        partial.unlink(missing_ok=True)


def prune_snapshots(directory: Path, today: date, keep_days: int = 365) -> int:
    """Remove dated snapshots outside keep_days inclusive calendar dates ending today; return count, ignoring unrelated files/symlinks."""
    if keep_days < 1:
        raise ValueError('Retention must be at least one day')
    cutoff = today - timedelta(days=keep_days - 1)
    removed = 0
    for path in directory.iterdir():
        match = re.fullmatch(r'kimai-(\d{4}-\d{2}-\d{2})\.sql\.gz', path.name)
        if match is None or path.is_symlink() or not path.is_file():
            continue
        try:
            snapshot_date = date.fromisoformat(match[1])
        except ValueError:
            continue
        if snapshot_date < cutoff:
            path.unlink()
            removed += 1
    return removed


def main() -> None:
    """Serialize the host job, create today's Budapest snapshot, then prune only after success; failures exit nonzero for systemd."""
    os.umask(0o077)
    DIRECTORY.mkdir(parents=True, exist_ok=True, mode=0o700)
    today = datetime.now(ZoneInfo('Europe/Budapest')).date()
    with (DIRECTORY / '.snapshot.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        snapshot = create_snapshot(DIRECTORY, today)
        removed = prune_snapshots(DIRECTORY, today)
        print(f'Snapshot saved: {snapshot} ({snapshot.stat().st_size} bytes); expired snapshots removed: {removed}')


if __name__ == '__main__':
    main()

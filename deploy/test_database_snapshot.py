"""Verify retention and preservation of existing backups when a dump fails."""

import gzip
import importlib.util
import io
import tempfile
import unittest
from datetime import date, timedelta
from pathlib import Path
from unittest.mock import MagicMock, patch


spec = importlib.util.spec_from_file_location('snapshot', Path(__file__).with_name('kimai-database-snapshot.py'))
snapshot = importlib.util.module_from_spec(spec)
spec.loader.exec_module(snapshot)


class SnapshotTest(unittest.TestCase):
    """Exercise atomic publication, failure cleanup and the 365-date retention boundary."""

    def test_failed_job_preserves_expired_backups(self):
        """The real job never prunes even an expired backup if today's dump fails."""
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            old = directory / 'kimai-2020-01-01.sql.gz'
            old.write_bytes(b'existing backup')
            process = MagicMock()
            process.__enter__.return_value = process
            process.stdout = io.BytesIO(b'partial')
            process.wait.return_value = 1
            with patch.object(snapshot, 'DIRECTORY', directory), patch.object(snapshot.subprocess, 'Popen', return_value=process):
                with self.assertRaises(RuntimeError):
                    snapshot.main()
            self.assertEqual(b'existing backup', old.read_bytes())

    def test_retention(self):
        """Keep exactly the newest 365 daily dates, including leap-year dates, without deleting manual backups or symlinks."""
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            today = date(2028, 3, 1)
            for age in range(370):
                (directory / f'kimai-{today - timedelta(days=age)}.sql.gz').touch()
            (directory / 'manual.sql.gz').touch()
            (directory / 'kimai-2020-99-99.sql.gz').touch()
            (directory / 'kimai-2020-01-01.sql.gz').symlink_to('manual.sql.gz')
            self.assertEqual(5, snapshot.prune_snapshots(directory, today))
            self.assertTrue((directory / f'kimai-{today - timedelta(days=364)}.sql.gz').exists())
            self.assertEqual(368, len(list(directory.iterdir())))

    def test_publish_and_failure(self):
        """A successful dump publishes mode-600 gzip SQL; a later failed or empty dump preserves that snapshot and removes temporary files."""
        with tempfile.TemporaryDirectory() as temporary:
            directory = Path(temporary)
            today = date(2026, 10, 27)
            for code, payload in [(0, b'CREATE TABLE example (id INT);'), (1, b'partial'), (0, b'')]:
                process = MagicMock()
                process.__enter__.return_value = process
                process.stdout = io.BytesIO(payload)
                process.wait.return_value = code
                with patch.object(snapshot.subprocess, 'Popen', return_value=process):
                    if code == 0 and payload:
                        result = snapshot.create_snapshot(directory, today)
                        self.assertEqual(payload, gzip.decompress(result.read_bytes()))
                        self.assertEqual(0o600, result.stat().st_mode & 0o777)
                        good = result.read_bytes()
                    else:
                        with self.assertRaises(RuntimeError):
                            snapshot.create_snapshot(directory, today)
                        self.assertEqual(good, result.read_bytes())
                self.assertEqual([result], list(directory.iterdir()))


if __name__ == '__main__':
    unittest.main()

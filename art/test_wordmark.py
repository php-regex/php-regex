"""Output safety checks: python3 -m unittest discover -s art -p 'test_*.py'."""
import os
from pathlib import Path
import tempfile
import unittest

from wordmark import output_directory, output_file


class OutputSafetyTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory()
        self.addCleanup(self.scratch.cleanup)
        self.root = Path(self.scratch.name)

    def test_default_is_fresh_and_private(self):
        with output_directory(None) as (first, _):
            self.addCleanup(os.rmdir, first)
            self.assertEqual(os.stat(first).st_mode & 0o777, 0o700)
        with output_directory(None) as (second, _):
            self.addCleanup(os.rmdir, second)
        self.assertNotEqual(first, second)

    def test_existing_output_links_do_not_modify_targets(self):
        target = self.root / 'target'
        target.write_text('keep')
        for link in ('symlink', 'hardlink'):
            with self.subTest(link=link):
                output = self.root / link
                output.mkdir()
                for name in ('scratch-banner.svg', 'scratch-social.svg', 'wordmark.json'):
                    destination = output / name
                    if link == 'symlink':
                        destination.symlink_to(target)
                    else:
                        os.link(target, destination)
                    with output_directory(output) as (_, fd):
                        with output_file(fd, name) as fh:
                            fh.write('new')
                    self.assertEqual(target.read_text(), 'keep')
                    self.assertFalse(destination.is_symlink())
                    self.assertEqual(destination.read_text(), 'new')

    def test_rejects_symlink_directory_and_ancestor(self):
        (self.root / 'link').symlink_to(self.root, target_is_directory=True)
        for path in (self.root / 'link', self.root / 'link' / 'child'):
            with self.subTest(path=path), self.assertRaises(OSError):
                with output_directory(path):
                    self.fail('accepted symlink')

    def test_rejects_writable_directory_and_ancestor(self):
        unsafe = self.root / 'unsafe'
        unsafe.mkdir(mode=0o777)
        unsafe.chmod(0o777)
        for path in (unsafe, unsafe / 'child'):
            with self.subTest(path=path), self.assertRaises(ValueError):
                with output_directory(path):
                    self.fail('accepted writable directory')
        self.assertFalse((unsafe / 'child').exists())

    def test_descriptor_stays_pinned_after_directory_rename(self):
        output = self.root / 'output'
        moved = self.root / 'moved'
        with output_directory(output) as (_, fd):
            output.rename(moved)
            output.symlink_to(self.root, target_is_directory=True)
            with output_file(fd, 'wordmark.json') as fh:
                fh.write('new')
        self.assertEqual((moved / 'wordmark.json').read_text(), 'new')
        self.assertFalse((self.root / 'wordmark.json').exists())

    def test_failed_write_preserves_existing_file_and_cleans_up(self):
        target = self.root / 'wordmark.json'
        target.write_text('keep')
        with output_directory(self.root) as (_, fd):
            with self.assertRaises(RuntimeError):
                with output_file(fd, target.name) as fh:
                    fh.write('partial')
                    raise RuntimeError('failed render')
        self.assertEqual(target.read_text(), 'keep')
        self.assertEqual(list(self.root.iterdir()), [target])

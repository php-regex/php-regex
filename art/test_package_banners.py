"""Run with: python3 -m unittest discover -s art -p 'test_*.py'."""
import importlib.util
from pathlib import Path
import subprocess
import sys
import unittest


SCRIPT = Path(__file__).with_name("package-banners.py")
spec = importlib.util.spec_from_file_location("package_banners", SCRIPT)
banners = importlib.util.module_from_spec(spec)
spec.loader.exec_module(banners)


class ExtractMarkTest(unittest.TestCase):
    opening = '<g transform="translate(100 100) scale(0.52)">'

    def test_repository_mark_is_copied_verbatim(self):
        with SCRIPT.with_name("banner.svg").open() as source:
            lines = list(source)
        self.assertEqual(banners.extract_mark(lines), lines[4].rstrip('\n'))

    def test_first_opening_and_last_closing_on_same_line(self):
        mark = self.opening + '<g></g></g>' + self.opening + '<g></g></g>'
        self.assertEqual(banners.extract_mark(['before' + mark + 'after\n']), mark)

    def test_incomplete_line_does_not_hide_later_mark(self):
        mark = self.opening + '<g></g></g>'
        self.assertEqual(banners.extract_mark([self.opening + '\n', mark]), mark)
        with self.assertRaises(ValueError):
            banners.extract_mark([self.opening + '\n', '</g></g>'])

    def test_repeated_unclosed_openings_finish_promptly(self):
        # A subprocess deadline prevents a regression from hanging the suite.
        code = (
            'import runpy\n'
            f'extract = runpy.run_path({str(SCRIPT)!r})["extract_mark"]\n'
            'try:\n'
            f'    extract([{self.opening!r} * 100000])\n'
            'except ValueError:\n'
            '    pass\n'
            'else:\n'
            '    raise AssertionError("accepted an unclosed mark")\n'
        )
        subprocess.run([sys.executable, '-c', code], check=True, timeout=5)

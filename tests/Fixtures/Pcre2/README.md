# PCRE2 official test suite fixtures

This directory carries the official PCRE2 test suite, pinned and vendored, as
the ground truth for the library's compile-verdict conformance test
(`Pcre2SuiteConformanceTest`) and the published conformance table
(`docs/reference/pcre2-conformance.md`).

## Contents

| path | what it is |
|---|---|
| `testdata/testinput1`, `testinput2`, `testinput4`, `testinput5` | raw pattern/subject input files of the official suite |
| `testdata/testoutput1`, `testoutput2`, `testoutput4`, `testoutput5` | the matching expected-output files, recorded from a real PCRE2 10.48 run |
| `testdata/LICENCE.md` | the PCRE2 project's licence file, copied from the source repository |
| `suite-cases.json` | the extracted compilation cases (pattern body, delimiter, PHP flags, expected verdict/offset/error and PCRE2 error number, what PCRE2 10.40 did with it, or skip category), one entry per pattern line, under a `meta` record naming the pin, the PHP/PCRE2 build and the floor release that extracted them, and `crossChecked`, a checksum of the engine-derived fields that catches accidental edits (it is no proof of provenance: `verify_pcre2_fixture.php` is) |
| `conformance-baseline.json` | the measured conformance baseline: every case whose `Regex::validate()` verdict or error offset disagrees with the expected outcome, with its defect class (`crash`, `false-reject`, `false-accept`, `offset-defect`) and what the library returned |

The raw files are never modified (`.gitattributes` marks them `-text`, so no
line-ending normalization touches their bytes). Both JSON files are
generated artifacts — do not edit them by hand; a test re-runs the extractor
on every suite run and fails on any drift.

## PHP compile context

Expected outcomes model what PHP does, not what pcre2test does. PHP compiles
every pattern with `PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK` (a php-src default since
PHP 8.1.0), so a handful of suite rejections (error 199, `\K` inside a
lookaround) compile in PHP. Those cases keep the suite's own record and carry a
`phpOverride` holding what `preg_match` observed at extraction; the known
differences are listed in `tests/TestUtils/Pcre2LiveCrossCheck.php`.

## Source

- Upstream project: <https://github.com/PCRE2Project/pcre2>
- Pinned tag: [`pcre2-10.48`](https://github.com/PCRE2Project/pcre2/tree/pcre2-10.48)
- Files, as vendored verbatim from that tag:
  - <https://github.com/PCRE2Project/pcre2/blob/pcre2-10.48/testdata/testinput1>
  - <https://github.com/PCRE2Project/pcre2/blob/pcre2-10.48/testdata/testoutput1>
  - <https://github.com/PCRE2Project/pcre2/blob/pcre2-10.48/testdata/testinput2>
  - <https://github.com/PCRE2Project/pcre2/blob/pcre2-10.48/testdata/testoutput2>
  - <https://github.com/PCRE2Project/pcre2/blob/pcre2-10.48/testdata/testinput4>
  - <https://github.com/PCRE2Project/pcre2/blob/pcre2-10.48/testdata/testoutput4>
  - <https://github.com/PCRE2Project/pcre2/blob/pcre2-10.48/testdata/testinput5>
  - <https://github.com/PCRE2Project/pcre2/blob/pcre2-10.48/testdata/testoutput5>
- Licence: <https://github.com/PCRE2Project/pcre2/blob/pcre2-10.48/LICENCE.md>

## Licence and attribution

PCRE2 is distributed under the BSD 3-Clause licence with the PCRE2 exception.
The full text is vendored next to the data it covers in
[`testdata/LICENCE.md`](testdata/LICENCE.md); see its final section for the
exception's terms. Keep that file with the data on any re-vendor.

## Size note

The eight raw files total about 1.3 MB and the two JSON artifacts about
2.8 MB more. The whole `/tests` tree is export-ignored from distribution
archives via `.gitattributes`, so consumers installing the package never
download them — but anyone cloning the repository does. That cost is accepted
deliberately: the suite is the ground truth the conformance claims rest on.

## Regenerating

After changing the extractor, the runner, the library, or the vendored files.
Extraction needs two engines: a PHP linked to PCRE2 10.48 (check
`php -r 'echo PCRE_VERSION;'`), and a `pcre2test` of PCRE2 10.40, the oldest
PCRE2 a supported PHP ships. Checking the committed fixture needs `pcre2test`
of both 10.40 and 10.48. Build each from its release tarball, outside the
repository; every step runs in its own subshell, so the `php` commands still
run from the repository root:

```console
(cd /tmp && curl -fsSL -O https://github.com/PCRE2Project/pcre2/releases/download/pcre2-10.40/pcre2-10.40.tar.gz && tar xzf pcre2-10.40.tar.gz)
(cd /tmp/pcre2-10.40 && ./configure --disable-shared --enable-unicode && make pcre2test)
(cd /tmp && curl -fsSL -O https://github.com/PCRE2Project/pcre2/releases/download/pcre2-10.48/pcre2-10.48.tar.gz && tar xzf pcre2-10.48.tar.gz)
(cd /tmp/pcre2-10.48 && ./configure --disable-shared --enable-unicode && make pcre2test)
php tests/Tools/extract_pcre2_testdata.php --floor-pcre2test=/tmp/pcre2-10.40/pcre2test
php tests/Tools/generate_pcre2_conformance_table.php --baseline
php tests/Tools/verify_pcre2_fixture.php --floor-pcre2test=/tmp/pcre2-10.40/pcre2test --pin-pcre2test=/tmp/pcre2-10.48/pcre2test
```

The extraction command rewrites `suite-cases.json`. It compiles every
assertable case with `preg_match` and refuses to write — exit code 1, with
the offending cases listed — when the engine is not the pinned release, or
when an expected verdict or offset differs from what `preg_match` does and no
known PHP compile-context difference explains it. It then compiles every
assertable case with the given `pcre2test`, under PHP's compile options. That
binary must report exactly PCRE2 10.40, and `pcre2test -C` must show the build
options PHP's bundled PCRE2 uses (LF newline, `\R` matching any Unicode
newline, link size 2, parentheses nest limit 250, Unicode support, `\C`
supported). A case the floor rejects while PHP compiles it is skipped as
`newer-than-floor`, one the floor compiles while PHP rejects it as
`stricter-than-floor`. Where the two releases report a shared rejection at
different offsets, the library's offset agrees when it matches either one.

The second command re-measures `conformance-baseline.json` from the current
library and runner (drop `--baseline` to keep the committed baseline), then
rewrites the generated sections of `docs/reference/pcre2-conformance.md`
(everything below its generation marker). It prints its wall-clock time and
peak memory; the page itself carries no date or timing.

The third command needs no particular PHP. It re-observes the committed
fixture on both engines — every recorded floor observation on 10.40, every
assertable case on 10.48 under PHP's compile options (a `phpOverride` must
match that observation) — and exits 1, listing each mismatch, if any record
differs from what the engines do. A CI job runs it, with both
engines built from the tarballs as above.

## Upgrading the pin

When a newer PCRE2 release should be measured instead:

1. Replace the eight files under `testdata/` with the new tag's versions and
   refresh `testdata/LICENCE.md` from the same tag.
2. Update `PCRE2_PIN` in `tests/TestUtils/Pcre2TestdataExtractor.php`, then
   re-run the extraction, generation and verification commands above on a
   PHP linked to the new release. In `.github/workflows/ci.yml`, update the
   `pcre2-fixture` job's version list, its binary paths and its cache key.
3. Check the cases adjusted to PHP's compile context. The consistency test
   (`tests/Integration/Pcre2ExtractorFidelityTest.php`) pins them by id —
   today `testinput2:6394`, `testinput2:6399`, `testinput2:6404` and
   `testinput2:6409` — and those ids move whenever lines are added to or
   removed from `testinput2` above them. Update the list to what the
   extraction now reports.
4. Commit everything together — raw files, `suite-cases.json`,
   `conformance-baseline.json`, and the regenerated docs page — as one commit
   that changes nothing else.
5. Add a `CHANGELOG.md` entry stating the old and new versions and why the
   counts moved (suite refresh, not regression).

Keeping the pin bump in its own commit lets downstream tools tell a
conformance-number shift caused by new suite content apart from one caused by
a library change.

## Baseline format

`conformance-baseline.json` is an internal, free-to-change format. It maps
each case id (`<testinput file>:<line>`) to its defect class and to the
verdict and offset `Regex::validate()` returned, and nothing else: no
message text, so rewording a library message never churns it. Only the counts
on the conformance page are a published interface;
nothing outside this repository's test suite should build on the baseline's
shape.

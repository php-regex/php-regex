---
description: "How often Regex::validate() reaches the same compile verdict as PHP's own PCRE2 engine, measured on PCRE2's official test suite, pinned to 10.49."
---

# PCRE2 Conformance

This page measures how often `Regex::validate()` reaches the same compile verdict as PHP's own PCRE2 engine, using the
PCRE2 project's official test suite. It is the measurement behind the
["Tested against the real engine"](https://github.com/php-regex/php-regex#tested-against-the-real-engine)
section of the README.

## What is measured

Each pattern of the suite files `testinput1`, `testinput2`, `testinput4` and `testinput5` is validated with default
options — static parsing, `runtimePcreValidation` off — and compared with the outcome PCRE2 recorded for it:

- **Compile verdict:** does `validate()` accept exactly the patterns PHP compiles?
- **Error offset:** when both reject, does `ValidationResult::$offset` point at the same byte of the pattern body as
  PCRE2's error?

Subject lines are ignored: this is a compile-level measurement, not a match-level one.

Why these four files: `testinput1` is the main suite, `testinput2` the error suite — its every case is a compile
verdict, the object of this page — and `testinput4` and `testinput5` the two Unicode suites. `testinput3` drives
locale-dependent matches through pcre2test's own locale machinery, so its outcomes ride on the host's locales rather
than the pattern text; no verdict reproducible everywhere can be pinned for it. The
[wider sweep below](#beyond-the-four-pinned-files) covers it with the rest.

## How to read the numbers

- **Counts, not percentages.** The denominator is the set of extractable cases. Every other case is skipped, with
  one of these reasons, and counted in the table below:
  - `pcre2test-api`: the case drives pcre2test machinery PHP does not expose, or a modifier that rewrites the pattern
    text (`hex`, `expand`);
  - `modifier-inexpressible`: a compile option with no PHP pattern modifier;
  - `newline-command`: a newline convention set by a pcre2test command;
  - `php-inexpressible`: a body no PHP pattern string can carry, such as one ending in a lone backslash;
  - `newer-than-floor`: PCRE2 10.40, the oldest engine a supported PHP ships (PHP 8.2.0), rejects a pattern PCRE2
    10.49 compiles, or the case needs a modifier PHP only gained later;
  - `stricter-than-floor`: PCRE2 10.40 compiles a pattern PCRE2 10.49 rejects;
  - `engine-skipped`: pcre2test itself skipped the case;
  - `length-limit`: the pattern is longer than the library's maximum pattern length;
  - `ambiguous`: the recorded output carries no readable compile verdict, or the body is not valid UTF-8 text.

  The two floor categories come from running every case through a real PCRE2 10.40 build as well: a verdict that
  depends on the engine version is not a fact about the library.
- **Offsets that moved between versions.** When 10.40 and 10.49 reject a pattern at different offsets, the library
  cannot match both, so its offset agrees if it matches either one; the headline says how many agreements are of that
  kind. An offset neither release reports is a defect.
- **PHP's compile context wins.** PHP does not compile patterns with pcre2test's defaults. Where the two differ, the
  expected outcome is what `preg_match()` does, and the affected cases are listed in the "PHP compile context" section.
- **False accepts come first.** For a static analyser, blessing a pattern that PHP refuses to compile is the worst
  outcome, so those are counted separately and lead the fix plan.
- **The pinned suite is authoritative.** The live-engine tests elsewhere in the test suite describe the PHP build they
  run on; this page describes PCRE2 10.49, pinned, whatever PHP build runs the test suite.

The case-by-case record lives in `tests/Fixtures/Pcre2/`. Its JSON format is internal and may change between releases
without notice; do not build on it.

## Beyond the four pinned files

Verdicts and offsets move between PCRE2 releases, and the library judges a pattern for the release a target names. So
the suite of every release from 10.32 to 10.49 is measured as well: every file that applies to PHP (`testinput1` to
`10`, `14` to `22`, `26` and `27`, where the release has them), some 5,300 cases once the suites are merged, each
compiled by a `pcre2test` of every release, under PHP's compile options, and for 10.32 to 10.35 also as PHP 7.3 and
7.4 compile them (`PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL`, unless the pattern has `X`). About 1,200 cases are compiled
another way by at least one release; the test suite holds what each release does with them, and judges the library
for each release against it: [the release table below](#every-releases-own-suite).

- **Compile verdict, error offset and code:** the library judged for each release agrees with that release on every
  one of those cases, apart from `\C` under `u`, which the library refuses on purpose (see
  [PCRE](../concepts/pcre.md)) and which no release treats differently.
- **Upcoming releases:** the development branch of PCRE2, measured at 10.50-DEV, was compiled the same way: it judges
  every case as 10.49 does, and the few cases its suite adds all compile. The extraction tool takes such a build
  with `--compare` and prints every case it compiles another way.
- **Analysis:** on the 7,500 or so patterns PHP compiles in the 10.49 suite, with the suite's own subject lines
  replayed through PHP, the recompiled tree and `optimize()` match exactly what the pattern matches, and the length
  range and `literals()` hold for every match they apply to. `generate()` finds a sample PHP matches for about 98% of
  them; for the rest, no subject matches the pattern, or its constraints are too tangled to guess.

The releases are built from their tarballs outside the repository, as `tests/Fixtures/Pcre2/README.md` describes; the
continuous integration runs the test suite on the PCRE2 each supported PHP bundles and on the latest release.

<!-- pcre2-conformance: generated below - do not edit -->

Against PCRE2 10.49's official test suite, under PHP's compile options: compile verdict agrees on **4426 of 4426** extractable cases, error offset agrees on **427 of 427** shared rejections (**218** of them match one of two version-dependent offsets); **0** patterns PHP rejects are accepted (4 suite verdicts adjusted to PHP, 745 cases skipped — see the breakdown below).

## Source

- Official PCRE2 test suite, tag `pcre2-10.49`, vendored under `tests/Fixtures/Pcre2/testdata`
- Files: `testinput1`, `testinput2`, `testinput4`, `testinput5` and their pinned `testoutput*` records
- License: BSD 3-Clause with the PCRE2 exception (`testdata/LICENCE.md`)
- Scope: compilation verdict and error offset only; subject-level behaviour is not measured
- Every expected verdict and offset was checked against `preg_match` on PHP linked to PCRE2 10.49 when the case set was extracted
- Every extractable case was also compiled by a real PCRE2 10.40 `pcre2test` (the oldest PCRE2 a supported PHP ships), under PHP's compile options; cases whose verdict differs between the two releases are skipped, and where a shared rejection's offset differs between them, the library's offset agrees when it matches either one

## Per-file counts

| file | cases | skipped | extractable | verdict agrees | shared rejections | offset agrees | of which one of two version-dependent offsets | false accepts |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| `testinput1` | 1379 | 82 | 1297 | 1297 | 0 | 0 | 0 | 0 |
| `testinput2` | 2325 | 441 | 1884 | 1884 | 389 | 389 | 203 | 0 |
| `testinput4` | 650 | 33 | 617 | 617 | 0 | 0 | 0 | 0 |
| `testinput5` | 817 | 189 | 628 | 628 | 38 | 38 | 15 | 0 |
| **total** | **5171** | **745** | **4426** | **4426** | **427** | **427** | **218** | **0** |

## Every release's own suite

The suites of PCRE2 10.32 to 10.49 hold **5259** compilation cases PHP can express (`testinput1` to `10`, `14` to `22`, `26` and `27`, where a release has them; a case several suites hold counts once). Each was compiled by a `pcre2test` of every release, under PHP's compile options, and on 10.32 to 10.35 once more as PHP 7.3 and 7.4 compile. **1223** of them are compiled another way by some release, or by PHP 7: for each release, the library judged for it agrees with every one of them on the verdict, the offset and the code (`tests/Integration/Pcre2ReleaseOutcomesTest.php`).

| release | cases measured | cases that move: compiled | refused |
|---|---:|---:|---:|
| 10.32 | 5192 | 9 | 1214 |
| 10.33 | 5192 | 54 | 1169 |
| 10.34 | 5192 | 76 | 1147 |
| 10.35 | 5192 | 84 | 1139 |
| 10.36 | 5192 | 84 | 1139 |
| 10.37 | 5192 | 89 | 1134 |
| 10.38 | 5192 | 89 | 1134 |
| 10.39 | 5192 | 94 | 1129 |
| 10.40 | 5192 | 641 | 582 |
| 10.41 | 5192 | 641 | 582 |
| 10.42 | 5192 | 641 | 582 |
| 10.43 | 5259 | 709 | 514 |
| 10.44 | 5259 | 717 | 506 |
| 10.45 | 5259 | 885 | 338 |
| 10.46 | 5259 | 885 | 338 |
| 10.47 | 5259 | 906 | 317 |
| 10.48 | 5259 | 919 | 304 |
| 10.49 | 5259 | 919 | 304 |
| 10.32, PHP 7.3 | 5187 | 716 | 507 |
| 10.33, PHP 7.4 | 5187 | 743 | 480 |
| 10.34, PHP 7.4 | 5187 | 760 | 463 |
| 10.35, PHP 7.4 | 5187 | 763 | 460 |

A case is not compiled as PHP 7 when PHP 7 cannot express it (a NUL byte in the pattern, the `n` modifier), nor with the `r` modifier before PCRE2 10.43, which added it.

## PHP compile context

The expected outcomes model PHP's compile context, not pcre2test's. Where the two differ in a way that changes a suite verdict, the case keeps the suite's record and is measured against what `preg_match` did instead.

| difference | suite error it explains |
|---|---:|
| `allow-lookaround-bsk`: PHP compiles every pattern with `PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK` (php-src default since PHP 8.1.0), so `\K` inside a lookaround compiles in PHP where pcre2test reports error 199. | 199 |

- The `u` modifier sets both `PCRE2_UTF` and `PCRE2_UCP` in PHP; pcre2test's `utf` modifier sets only `PCRE2_UTF`. The suite's `utf` cases are measured with `u`, and no compile verdict or offset in the suite depends on the difference.
- `#forbid_utf` at the top of `testinput1` and `testinput2` locks pcre2test out of UTF and UCP for every pattern that follows; PHP has no such lock. The cases are measured without it, and the live cross-check found no verdict or offset that depends on it.
- pcre2test's `/a` modifier (`ascii_all`) is dropped: it has no PHP equivalent, and at PCRE2 10.49 it only restricts what `\d`, `\s`, `\w` and POSIX classes match, never whether a pattern compiles.

Adjusted cases (4): `testinput2:6394`, `testinput2:6399`, `testinput2:6404`, `testinput2:6409`.

## Skipped cases by category

| category | meaning | cases |
|---|---|---:|
| `ambiguous` | the testoutput record carries no readable compile verdict, or the body cannot be stored in the fixture | 13 |
| `modifier-inexpressible` | a compile-visible pcre2test modifier with no PHP pattern-modifier equivalent | 343 |
| `newer-than-floor` | PCRE2 10.40 (the oldest a supported PHP ships) rejects a pattern PHP on 10.49 compiles, or the case uses a pcre2test modifier added after 10.40 | 246 |
| `newline-command` | the case sets a newline convention PHP pattern strings cannot express | 74 |
| `pcre2test-api` | the case drives pcre2test machinery PHP does not expose, or rewrites the pattern text | 53 |
| `php-inexpressible` | no PHP pattern string can carry the body | 8 |
| `stricter-than-floor` | PCRE2 10.40 compiles a pattern PHP on 10.49 rejects | 8 |

## Gap breakdown by defect class

_No known gaps: every extractable case agrees with the expected outcome._

## Fix plan

False accepts come first: a static analyser that blesses a pattern PHP refuses to compile is the worst outcome for its users. The other classes follow by case count, largest first. Cases whose verdict differs between PCRE2 10.40 (the oldest a supported PHP ships) and 10.49 are skipped as `newer-than-floor` or `stricter-than-floor` and excluded from this plan by design: no single verdict is right on both engines, so fixing them one way would make `validate()` wrong on the other.

### False accepts

_None._

## Regenerating this table

```
php tests/Tools/extract_pcre2_testdata.php --floor-pcre2test=/tmp/pcre2-10.40/pcre2test
php tests/Tools/generate_pcre2_conformance_table.php --baseline
php tests/Tools/verify_pcre2_fixture.php --floor-pcre2test=/tmp/pcre2-10.40/pcre2test --pin-pcre2test=/tmp/pcre2-10.49/pcre2test
php tests/Tools/extract_pcre2_release_outcomes.php --releases-dir=/tmp
```

The extraction runs every case through `preg_match` and refuses to write unless the PHP running it is linked to PCRE2 10.49 and every expected verdict and offset matches what that engine does. It also compiles every extractable case with the given `pcre2test`, which must be exactly PCRE2 10.40 (build it from the release tarball, see `tests/Fixtures/Pcre2/README.md`). The second command re-measures the baseline of known gaps, then rewrites the sections below the generation marker of this page; it prints its wall-clock time and peak memory. The third re-observes every recorded engine result on both releases, built from their tarballs, and fails on any difference.

The fourth compiles the cases of every release's suite with every release, PCRE2 10.32 to 10.49, each built from its tarball under `/tmp/pcre2-<release>`, and rewrites the fixture of the cases on which they do not all agree; with `--check` it writes nothing and fails when the committed fixture differs.

Per-case detail lives in `tests/Fixtures/Pcre2/conformance-baseline.json` and `tests/Fixtures/Pcre2/release-outcomes.json`, internal, free-to-change formats: only the counts on this page are published.

# PCRE2 Conformance

This page measures how often `Regex::validate()` reaches the same compile verdict as PHP's own PCRE2 engine, using the
PCRE2 project's official test suite. It is the audit behind the "Tested against the real engine" section of the README.

## What is measured

Each pattern of the suite files `testinput1`, `testinput2`, `testinput4` and `testinput5` is validated with default
options — static parsing, `runtimePcreValidation` off — and compared with the outcome PCRE2 recorded for it:

- **Compile verdict:** does `validate()` accept exactly the patterns PHP compiles?
- **Error offset:** when both reject, does `ValidationResult::$offset` point at the same byte of the pattern body as
  PCRE2's error?

Subject lines are ignored: this is a compile-level measurement, not a match-level one.

## How to read the numbers

- **Counts, not percentages.** The denominator is the set of extractable cases. Every other case is skipped, with
  one of these reasons, and counted in the table below:
  - `pcre2test-api`: the case drives pcre2test machinery PHP does not expose, or a modifier that rewrites the pattern
    text (`hex`, `expand`);
  - `modifier-inexpressible`: a compile option with no PHP pattern modifier;
  - `newline-command`: a newline convention set by a pcre2test command;
  - `php-inexpressible`: a body no PHP pattern string can carry, such as one ending in a lone backslash;
  - `newer-than-floor`: PCRE2 10.40, the oldest engine a supported PHP ships (PHP 8.2.0), rejects a pattern PCRE2
    10.48 compiles, or the case needs a modifier PHP only gained later;
  - `stricter-than-floor`: PCRE2 10.40 compiles a pattern PCRE2 10.48 rejects;
  - `engine-skipped`: pcre2test itself skipped the case;
  - `length-limit`: the pattern is longer than the library's maximum pattern length;
  - `ambiguous`: the recorded output carries no readable compile verdict, or the body is not valid UTF-8 text.

  The two floor categories come from running every case through a real PCRE2 10.40 build as well: a verdict that
  depends on the engine version is not a fact about the library.
- **Offsets that moved between versions.** When 10.40 and 10.48 reject a pattern at different offsets, the library
  cannot match both, so its offset agrees if it matches either one; the headline says how many agreements are of that
  kind. An offset neither release reports is a defect.
- **PHP's compile context wins.** PHP does not compile patterns with pcre2test's defaults. Where the two differ, the
  expected outcome is what `preg_match()` does, and the affected cases are listed in the "PHP compile context" section.
- **False accepts come first.** For a static analyser, blessing a pattern that PHP refuses to compile is the worst
  outcome, so those are counted separately and lead the fix plan.
- **The pinned suite is authoritative.** The live-engine tests elsewhere in the test suite describe the PHP build they
  run on; this page describes PCRE2 10.48, pinned, whatever PHP build runs the test suite.

> [!NOTE]
> `ValidationResult::$offset` is informational for now. Syntax errors inside the pattern body report a body-relative
> offset, while flag errors report an offset into the whole pattern string and delimiter errors report none. This page
> normalizes flag-error offsets to the body before comparing. A future release will make `$offset` body-relative
> everywhere — see [UPGRADING.md](../../UPGRADING.md).

The case-by-case record lives in `tests/Fixtures/Pcre2/`. Its JSON format is internal and may change between releases
without notice; do not build on it.

<!-- pcre2-conformance: generated below - do not edit -->

Against PCRE2 10.48's official test suite, under PHP's compile options: compile verdict agrees on **4233 of 4426** extractable cases, error offset agrees on **88 of 333** shared rejections (**33** of them match one of two version-dependent offsets); **94** patterns PHP rejects are accepted (4 suite verdicts adjusted to PHP, 745 cases skipped — see the breakdown below).

## Source

- Official PCRE2 test suite, tag `pcre2-10.48`, vendored under `tests/Fixtures/Pcre2/testdata`
- Files: `testinput1`, `testinput2`, `testinput4`, `testinput5` and their pinned `testoutput*` records
- License: BSD 3-Clause with the PCRE2 exception (`testdata/LICENCE.md`)
- Scope: compilation verdict and error offset only; subject-level behaviour is not measured
- Every expected verdict and offset was checked against `preg_match` on PHP linked to PCRE2 10.48 when the case set was extracted
- Every extractable case was also compiled by a real PCRE2 10.40 `pcre2test` (the oldest PCRE2 a supported PHP ships), under PHP's compile options; cases whose verdict differs between the two releases are skipped, and where a shared rejection's offset differs between them, the library's offset agrees when it matches either one

## Per-file counts

| file | cases | skipped | extractable | verdict agrees | shared rejections | offset agrees | of which one of two version-dependent offsets | false accepts |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| `testinput1` | 1379 | 82 | 1297 | 1273 | 0 | 0 | 0 | 0 |
| `testinput2` | 2325 | 441 | 1884 | 1742 | 309 | 86 | 33 | 80 |
| `testinput4` | 650 | 33 | 617 | 610 | 0 | 0 | 0 | 0 |
| `testinput5` | 817 | 189 | 628 | 608 | 24 | 2 | 0 | 14 |
| **total** | **5171** | **745** | **4426** | **4233** | **333** | **88** | **33** | **94** |

## PHP compile context

The expected outcomes model PHP's compile context, not pcre2test's. Where the two differ in a way that changes a suite verdict, the case keeps the suite's record and is measured against what `preg_match` did instead.

| difference | suite error it explains |
|---|---:|
| `allow-lookaround-bsk`: PHP compiles every pattern with `PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK` (php-src default since PHP 8.1.0), so `\K` inside a lookaround compiles in PHP where pcre2test reports error 199. | 199 |

- The `u` modifier sets both `PCRE2_UTF` and `PCRE2_UCP` in PHP; pcre2test's `utf` modifier sets only `PCRE2_UTF`. The suite's `utf` cases are measured with `u`, and no compile verdict or offset in the suite depends on the difference.
- `#forbid_utf` at the top of `testinput1` and `testinput2` locks pcre2test out of UTF and UCP for every pattern that follows; PHP has no such lock. The cases are measured without it, and the live cross-check found no verdict or offset that depends on it.
- pcre2test's `/a` modifier (`ascii_all`) is dropped: it has no PHP equivalent, and at PCRE2 10.48 it only restricts what `\d`, `\s`, `\w` and POSIX classes match, never whether a pattern compiles.

Adjusted cases (4): `testinput2:6394`, `testinput2:6399`, `testinput2:6404`, `testinput2:6409`.

## Skipped cases by category

| category | meaning | cases |
|---|---|---:|
| `ambiguous` | the testoutput record carries no readable compile verdict, or the body cannot be stored in the fixture | 13 |
| `modifier-inexpressible` | a compile-visible pcre2test modifier with no PHP pattern-modifier equivalent | 343 |
| `newer-than-floor` | PCRE2 10.40 (the oldest a supported PHP ships) rejects a pattern PHP on 10.48 compiles, or the case uses a pcre2test modifier added after 10.40 | 246 |
| `newline-command` | the case sets a newline convention PHP pattern strings cannot express | 74 |
| `pcre2test-api` | the case drives pcre2test machinery PHP does not expose, or rewrites the pattern text | 53 |
| `php-inexpressible` | no PHP pattern string can carry the body | 8 |
| `stricter-than-floor` | PCRE2 10.40 compiles a pattern PHP on 10.48 rejects | 8 |

## Gap breakdown by defect class

| defect class | cases |
|---|---:|
| `offset-defect` | 245 |
| `false-reject` | 99 |
| `false-accept` | 94 |

## Fix plan

False accepts come first: a static analyser that blesses a pattern PHP refuses to compile is the worst outcome for its users. The other classes follow by case count, largest first. Cases whose verdict differs between PCRE2 10.40 (the oldest a supported PHP ships) and 10.48 are skipped as `newer-than-floor` or `stricter-than-floor` and excluded from this plan by design: no single verdict is right on both engines, so fixing them one way would make `validate()` wrong on the other.

### False accepts

94 patterns PHP rejects are accepted by `validate()`. Add the missing compile-time check for each PCRE2 error below.

| PCRE2 error | first recorded message | cases |
|---:|---|---:|
| 130 | unknown POSIX class name | 10 |
| 107 | escape sequence is invalid in character class | 9 |
| 137 | PCRE2 does not support \\F, \\L, \\l, \\N{name}, \\U, or \\u | 7 |
| 106 | missing terminating ] for character class | 6 |
| 173 | disallowed Unicode code point (>= 0xd800 && <= 0xdfff) | 6 |
| 167 | non-hex character in \\x{} (closing brace missing?) | 5 |
| 113 | POSIX collating elements are not supported | 4 |
| 178 | digits missing after \\x or in \\x{} or \\o{} or \\N{U+} | 4 |
| 128 | atomic assertion expected after (?( or (?(?C) | 3 |
| 160 | (*VERB) not recognized or malformed | 3 |
| 162 | subpattern name expected | 3 |
| 164 | non-octal character in \\o{} (closing brace missing?) | 3 |
| 166 | (*MARK) must have an argument | 3 |
| 171 | \\N is not supported in a class | 3 |
| 103 | unrecognized character follows \\ | 2 |
| 108 | range out of order in character class | 2 |
| 126 | a relative value of zero is not allowed | 2 |
| 127 | conditional subpattern contains more than two branches | 2 |
| 155 | missing opening brace after \\o | 2 |
| 169 | \\k is not followed by a braced, angle-bracketed, or quoted name | 2 |
| 193 | \\N{U+dddd} is supported only in Unicode (UTF) mode | 2 |
| 194 | invalid hyphen in option setting | 2 |
| 112 | POSIX named classes are supported only within a class | 1 |
| 122 | unmatched closing parenthesis | 1 |
| 125 | length of lookbehind assertion is not limited | 1 |
| 141 | unrecognized character after (?P | 1 |
| 148 | subpattern name is too long (maximum 128 code units) | 1 |
| 154 | DEFINE subpattern contains more than one branch | 1 |
| 165 | different names for subpatterns of the same number are not allowed | 1 |
| 179 | syntax error or number too big in (?(VERSION condition | 1 |
| 182 | unrecognized string delimiter follows (?C | 1 |

### Offset defects

245 cases: both reject, at a body offset neither supported PCRE2 release reports. The library may be off by a few bytes, or it may have rejected the pattern for a different reason than PCRE2 did; the PCRE2 error below says which check PCRE2 hit first.

| PCRE2 error | first recorded message | cases |
|---:|---|---:|
| 115 | reference to non-existent subpattern | 51 |
| 150 | invalid range in character class | 23 |
| 125 | length of lookbehind assertion is not limited | 17 |
| 144 | subpattern name must start with a non-digit | 12 |
| 213 | unexpected expression in extended character class (no preceding operator) | 12 |
| 111 | unrecognized character after (? or (?- | 9 |
| 114 | missing closing parenthesis | 8 |
| 162 | subpattern name expected | 8 |
| 128 | atomic assertion expected after (?( or (?(?C) | 7 |
| 219 | syntax error in subpattern number (missing terminator?) | 7 |
| 134 | character code point value in \\x{} or \\o{} is too large | 6 |
| 142 | syntax error in subpattern name (missing terminator?) | 6 |
| 161 | subpattern number is too big | 6 |
| 216 | unexpected character in (?[...]) extended character class | 5 |
| 105 | number too big in {} quantifier | 4 |
| 108 | range out of order in character class | 4 |
| 137 | PCRE2 does not support \\F, \\L, \\l, \\N{name}, \\U, or \\u | 4 |
| 143 | two named subpatterns have the same name (PCRE2_DUPNAMES not set) | 4 |
| 217 | expected capture group number or name | 4 |
| 139 | closing parenthesis for (?C expected | 3 |
| 147 | unknown property after \\P or \\p | 3 |
| 158 | (?R (recursive pattern call) must be followed by a closing parenthesis | 3 |
| 179 | syntax error or number too big in (?(VERSION condition | 3 |
| 207 | extended character class nesting is too deep | 3 |
| 215 | terminating ] with no following closing parenthesis in (?[...] | 3 |
| 104 | numbers out of order in {} quantifier | 2 |
| 109 | quantifier does not follow a repeatable item | 2 |
| 122 | unmatched closing parenthesis | 2 |
| 130 | unknown POSIX class name | 2 |
| 181 | missing terminating delimiter for callout with string argument | 2 |
| 187 | lookbehind assertion is too long | 2 |
| 195 | (*alpha_assertion) not recognized | 2 |
| 214 | empty expression in extended character class | 2 |
| 102 | \\c at end of pattern | 1 |
| 103 | unrecognized character follows \\ | 1 |
| 107 | escape sequence is invalid in character class | 1 |
| 129 | digit expected after (?+ | 1 |
| 135 | lookbehind is too complicated | 1 |
| 138 | number after (?C is greater than 255 | 1 |
| 157 | \\g is not followed by a braced, angle-bracketed, or quoted name/number or by a plain number | 1 |
| 168 | \\c must be followed by a printable ASCII character | 1 |
| 171 | \\N is not supported in a class | 1 |
| 178 | digits missing after \\x or in \\x{} or \\o{} or \\N{U+} | 1 |
| 193 | \\N{U+dddd} is supported only in Unicode (UTF) mode | 1 |
| 194 | invalid hyphen in option setting | 1 |
| 209 | unexpected operator in extended character class (no preceding operand) | 1 |
| 218 | missing opening parenthesis | 1 |

### False rejects

99 cases: PHP compiles what validate() rejects; teach the lexer or parser the construct.

## Regenerating this table

```
php tests/Tools/extract_pcre2_testdata.php --floor-pcre2test=/tmp/pcre2-10.40/pcre2test
php tests/Tools/generate_pcre2_conformance_table.php --baseline
php tests/Tools/verify_pcre2_fixture.php --floor-pcre2test=/tmp/pcre2-10.40/pcre2test --pin-pcre2test=/tmp/pcre2-10.48/pcre2test
```

The extraction runs every case through `preg_match` and refuses to write unless the PHP running it is linked to PCRE2 10.48 and every expected verdict and offset matches what that engine does. It also compiles every extractable case with the given `pcre2test`, which must be exactly PCRE2 10.40 (build it from the release tarball, see `tests/Fixtures/Pcre2/README.md`). The second command re-measures the baseline of known gaps, then rewrites the sections below the generation marker of this page; it prints its wall-clock time and peak memory. The third re-observes every recorded engine result on both releases, built from their tarballs, and fails on any difference.

Per-case detail lives in `tests/Fixtures/Pcre2/conformance-baseline.json`, an internal, free-to-change format: only the counts on this page are published.

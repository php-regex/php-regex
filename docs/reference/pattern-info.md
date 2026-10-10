---
description: "The facts PatternInfo reads from a pattern — capture counts, names, limits, anchors, match lengths — and the PHP and PCRE2 versions it is valid on."
---

# Pattern Info and Compatibility

PCRE2 computes a handful of facts on every compiled pattern, through
`pcre2_pattern_info()`: how many groups it captures, their names, the highest
group a back reference names, the limits the pattern asks for. PHP exposes
none of them. `Regex::info()` reads them from the pattern alone, with the
length and the anchoring of what the pattern matches, and
`Regex::compatibility()` says on which PHP versions and PCRE2 releases the
pattern is valid at all.

```php
use PHPRegex\Toolkit\Regex;

$info = Regex::create()->info('/^(?<year>\d{4})-(?<month>\d\d)$/D');

$info->captureCount;   // 2
$info->names;          // ['month' => [2], 'year' => [1]]
$info->minMatchLength; // 7
$info->maxMatchLength; // 7
$info->anchoredStart;  // true
$info->anchoredEnd;    // true
```

A static analysis extension needs only `php-regex/regex-parser` (PHP 8.2+ with
`ext-mbstring`): the analyzer and its result live there. Until the 2.0.0 tag
publishes the split packages, install through the monorepo — see the
[Quick Start](../quick-start.md). It reads a tree the parser built:

```php
use PHPRegex\Parser\Analysis\PatternInfoAnalyzer;
use PHPRegex\Parser\RegexParser;

$regex = RegexParser::create()->parse('/(*LIMIT_MATCH=100)(*LIMIT_MATCH=50)(*CRLF)a\R/');
$info = (new PatternInfoAnalyzer())->analyze($regex);

$info->matchLimit; // 50: the last setting wins
$info->newline;    // NewlineConvention::CrLf
$info->bsr;        // null: the pattern does not say what \R matches
```

`PatternInfoAnalyzer` reads the tree as written, and does not validate it: a
pattern the validator refuses still gets an answer. `Regex::info()` validates
first, at the facade's target, and throws what `validate()` refuses: the
exception `parse()` throws, or a `SemanticErrorException` carrying the
validation error and its code. On PHP 8.5, which refuses `\K` in a
lookaround, `Regex::create(['php_version' => '8.5'])->info('/(?<=a\Kb)c/')`
throws a `SemanticErrorException` whose code is `regex.keep.in_lookaround`.

## The facts

`PatternInfo` is a read-only value; its constructor is internal. Its facts fall
into two groups.

**Exact facts** equal what PCRE2 reports for the same pattern. A difference is
a bug, fixed in a patch release.

| property | type | meaning |
|---|---|---|
| `captureCount` | `int` | The number of capturing groups, as PCRE2 numbers them: the branches of a branch reset `(?\|...)` share their numbers, so `(?\|(a)\|(b)(c))` has 3; under `n` only named groups capture |
| `names` | `array<string, list<int>>` | Each group name, sorted, with the group numbers it names, ascending. A name is given to several groups under `J` or `(?J)`: `(?J)(?<n>a)\|(?<n>b)` gives `['n' => [1, 2]]` |
| `maxBackreference` | `int` | The highest group number a back reference, a named back reference or a condition can name, `0` for none: `\1`, `\g{-1}`, `\k<n>`, `(?P=n)`, `(?(2)...)`, `(?(<n>)...)`. A name shared by several groups counts its highest. A recursion or a subroutine call is not a reference: `(?1)(a)` gives `0`. As in PCRE2, a named recursion condition `(?(R&n)...)` counts and a numbered one `(?(R1)...)` does not, unless a group is named `R1`: the condition then tests that group and counts it, `(a)(?<R1>x)?(?(R1)b\|c)` gives `2` |
| `usesBackslashC` | `bool` | Whether the pattern holds `\C`, the escape that matches one byte even in UTF mode |
| `matchLimit` | `?int` | The `(*LIMIT_MATCH=n)` the pattern asks for, `null` when it asks for none |
| `depthLimit` | `?int` | The `(*LIMIT_DEPTH=n)` it asks for, `(*LIMIT_RECURSION=n)` being the older name of the same setting |
| `heapLimit` | `?int` | The `(*LIMIT_HEAP=n)` it asks for |
| `newline` | `?NewlineConvention` | The newline convention a leading `(*CR)`, `(*LF)`, `(*CRLF)`, `(*ANY)`, `(*ANYCRLF)` or `(*NUL)` sets, `null` when the pattern sets none |
| `bsr` | `?BsrConvention` | What `\R` matches, as a leading `(*BSR_ANYCRLF)` or `(*BSR_UNICODE)` sets it, `null` when the pattern does not say |

When a pattern sets a limit, a newline or a `\R` convention more than once,
the last setting wins, as in PCRE2: `(*LIMIT_MATCH=100)(*LIMIT_MATCH=50)`
asks for 50, and `(*CR)(*LF)` sets `LF`.

The limits are requests. PHP passes `pcre.backtrack_limit` to PCRE2 as its
match limit and `pcre.recursion_limit` as its depth limit, and PCRE2 lets a
pattern lower a limit the caller set, never raise it: with
`pcre.backtrack_limit` at 100, `(*LIMIT_MATCH=1000000)` still stops at 100.

`NewlineConvention` and `BsrConvention` are string-backed enums in
`PHPRegex\Parser`; their values are the names of the options (`CRLF`,
`ANYCRLF`, `UNICODE`). A minor release may add a case.

**Sound bounds** hold for every match, but may be looser than the truth. A
minor release may narrow them, with `PatternInfoAnalyzer::ANALYSIS_VERSION`
raised: a pattern may then get a tighter length range, or a proven anchor it
did not have.

| property | type | meaning |
|---|---|---|
| `minMatchLength` | `int` | The fewest characters `$matches[0]` holds: code points in UTF mode (`u` or `(*UTF)`), bytes otherwise |
| `maxMatchLength` | `?int` | The most characters `$matches[0]` holds, in the same unit; `null` when unbounded, or not proven bounded |
| `maxLookbehind` | `int` | The longest lookbehind body, in the same unit, `0` for none |
| `anchoredStart` | `bool` | `true` when proven: every match attempt is tied to where the search starts |
| `anchoredEnd` | `bool` | `true` when proven: every successful match ends at the end of the subject |

The unit follows the mode: `/é{2}/u` has a length of 2, and `/é{2}/` of 3,
as without `u` the `é` is two bytes and `{2}` repeats the second.

`$info->minMatchLength === 0` says the pattern is not proven to need a
character: it may match the empty string. Any other value is proven.

The lengths describe `$matches[0]`, not the subject. When the pattern holds
`\K`, `$matches[0]` starts where the last `\K` stood, so `minMatchLength` is
`0` and `maxMatchLength` stays the longest text the match consumes. A `\K`
inside a lookbehind starts `$matches[0]` before that text:
`preg_match('/(?<=a\Kb)c/', 'abc', $m)` gives `$m[0] === 'bc'`, so a pattern
holding both `\K` and a lookbehind has a `maxMatchLength` of `null`.

`anchoredStart` has PCRE2's meaning: the `A` modifier, or each top-level
alternative whose first item is `\A`, `\G`, or `^` outside multiline mode,
looking inside groups and inside repeats that run at least once. As in PCRE2,
an option setting such as `(?i)`, a comment, a `(?(DEFINE)...)` group and the
options a pattern opens with are not items, while every other group is one,
even an empty one: `/(?i)\Aa/` is anchored, `/(?:)\Aa/`, `/()\Aa/`,
`/(?i:)\Aa/` and `/(?:(?i))\Aa/` are not. A `\b` or a lookaround before the
anchor is an item too. It says where every attempt starts, not that there is
one match: `preg_match_all()` finds two matches of `/\Ga/` and of `/a/A` in
`aab`, each starting where the previous one ended. `\K` does not change it:
`/\Aa\Kb/` is anchored, though
`$matches[0]` starts at the `b`.

A `(*SKIP)` breaks the `A` modifier under the JIT, which PHP uses by default
(`pcre.jit=1`): when the attempt fails past it, the next attempt starts where
the `(*SKIP)` stood, `A` notwithstanding. `preg_match('/aa(*SKIP)b|a/A',
'aaa', $m, PREG_OFFSET_CAPTURE)` matches the `a` at offset 2, where the
interpreter (`pcre.jit=0`) finds no match. A caller sees what the engine does,
so when `A` alone anchors the pattern and it holds a `(*SKIP)` of any form,
`(*SKIP:name)` included, wherever it stands, `anchoredStart` is `false`. An
anchor on every alternative still holds: `/\Aaa(*SKIP)b|\Aa/A`, `^` outside
multiline mode and `\G` stay anchored, and `(*PRUNE)`, `(*COMMIT)` and
`(*THEN)` do not move the start.

`anchoredEnd` is true when each top-level alternative ends with `\z`, or with
`$` under `D` outside multiline mode: `/a$/D` is anchored at the end, `/a$/`
is not, as its `$` also matches before a final newline. The end item is the
last item of the alternative, not under a quantifier that allows zero, not
inside an assertion. A reachable `(*ACCEPT)` ends a match anywhere, so
`/a(*ACCEPT)b\z/` is not anchored at the end.

## Mapping to `pcre2_pattern_info()`

The names are PHPRegex's own because some of PCRE2's would mislead: its
`MINLENGTH` is a lower bound on the subject's length, not on the match's.
This table maps each fact to the PCRE2 item and to the line `pcre2test` prints
under `/I`, and says where the two differ.

| `PatternInfo` | `PCRE2_INFO_*` | `pcre2test /I` line | difference |
|---|---|---|---|
| `captureCount` | `CAPTURECOUNT` | `Capture group count` | none |
| `names` | `NAMETABLE`, `NAMECOUNT`, `NAMEENTRYSIZE` | `Named capture groups` | an array keyed by name instead of PCRE2's packed table, each name's numbers ascending: PCRE2 lists a duplicate name's numbers in the order it met them, `b 2` then `b 1` for `(?J)(?\|(x)(?<b>y)\|(?<b>z))`, given here as `['b' => [1, 2]]` |
| `maxBackreference` | `BACKREFMAX` | `Max back reference` | none |
| `usesBackslashC` | `HASBACKSLASHC` | `Contains \C` | none |
| `matchLimit` | `MATCHLIMIT` | `Match limit` | `null` where PCRE2 answers `PCRE2_ERROR_UNSET` |
| `depthLimit` | `DEPTHLIMIT` (`RECURSIONLIMIT`, its older name) | `Depth limit` | `null` where PCRE2 answers `PCRE2_ERROR_UNSET` |
| `heapLimit` | `HEAPLIMIT` | `Heap limit` | `null` where PCRE2 answers `PCRE2_ERROR_UNSET` |
| `newline` | `NEWLINE` | `Forced newline is ...` | `null` when the pattern sets none, where PCRE2 answers the default of the build |
| `bsr` | `BSR` | `\R matches ...` | `null` when the pattern does not say, where PCRE2 answers the default of the build |
| `minMatchLength` | `MINLENGTH` | `Subject length lower bound` | `MINLENGTH` bounds the subject: `(?=a)b?` gives 1 there, as the lookahead needs an `a`, and `0` here, as `$matches[0]` may be empty. `MATCHEMPTY` (`May match empty string`) is close to `minMatchLength === 0` |
| `maxMatchLength` | none | none | PCRE2 does not compute it |
| `maxLookbehind` | `MAXLOOKBEHIND` | `Max lookbehind` | PCRE2 also counts the character `\b`, `\B` and `\A` look back at: `\bx` gives 1 there and `0` here. A group repeated zero times inside a lookbehind is read as PCRE2 10.43 and later read it, whatever the target: `(?<=(?:a\|bc){0})x` gives `0` |
| `anchoredStart` | `ALLOPTIONS` holding `PCRE2_ANCHORED` | `Overall options: anchored` | PCRE2 also anchors a pattern starting with `.*` under `s`, and drops an item repeated zero times before the anchor; neither is modelled: `/.*a/s` and `/(?:a){0}\Ab/` are anchored there and not here. PCRE2 reports `A` with a `(*SKIP)` as anchored, which the JIT does not honour (above); `false` here. A `true` here is always anchored in PCRE2 |
| `anchoredEnd` | none | none | PCRE2 does not infer it; `PCRE2_ENDANCHORED` is a compile option, not a fact |

The first code unit, the starting code units and the last required code unit
(`FIRSTCODEUNIT`, `FIRSTBITMAP`, `LASTCODEUNIT`) are not offered: PCRE2's
answers change between releases. `RequiredLiteralAnalyzer` lists the literals
every match holds (see [Prefilters](prefilters.md)). The sizes (`SIZE`,
`FRAMESIZE`, `JITSIZE`) and the options (`ALLOPTIONS`, `ARGOPTIONS`) depend on
the build or on the compile call, not on the pattern.

## Compatibility: where a pattern is valid

`Regex::compatibility()` runs the validator on the pattern at every PHP
version a rule of the library changes at, each with every PCRE2 release from
10.40 to the newest the library knows a change in. The target of the facade
does not matter. One answer comes from the running engine: a Unicode property
name in `\p{...}` or `\P{...}` is looked up there, at every point, unless the
library knows the PCRE2 release that added the name, in which case that
release decides at each point. An undated name is accepted at every point
when the running engine knows it, and refused at every point when it does
not.

```php
use PHPRegex\Toolkit\Regex;

$compatibility = Regex::create()->compatibility('/(?<=a\Kb)c/');

$compatibility->isValidEverywhere();      // false
count($compatibility->verdicts());        // 54
count($compatibility->invalidVerdicts()); // 18

$verdict = $compatibility->invalidVerdicts()[0];
$verdict->target->phpVersionId;           // 80500
$verdict->target->pcreVersion;            // '10.40'
$verdict->validation->errorCode;          // ErrorCode::KeepInLookaround
$verdict->validation->offset;             // 10
```

`PHPRegex\Parser\Validation\CompatibilityChecker::check()` gives the same
answer from `php-regex/regex-parser` alone.

`verdicts()` holds one `TargetVerdict` per point of the matrix, ordered by PHP
version, then by PCRE2 release. Each carries its `PcreTarget` and the
`ValidationResult` the validator gave there, with its error code and offset.
`invalidVerdicts()` keeps the ones that refuse the pattern, in the same order.

Today the matrix is 54 points: PHP 8.2, 8.3, 8.4, 8.4.25, 8.5 and 8.5.10,
each with PCRE2 10.40 to 10.48. Every PHP is paired with every release,
because PHP may be built against the PCRE2 of the system instead of the one it
bundles. A newer PCRE2, such as 10.49, is judged with the rules of the newest
release the library knows.

**The matrix widens.** Each PHP version or PCRE2 release whose rules the
library learns adds points, so a minor release may change the number of
verdicts, and `isValidEverywhere()` may turn false for a pattern a new point
refuses. Do not count on 54.

**Validity is not monotone.** `\K` in a lookaround is valid up to PHP 8.4 and
refused from PHP 8.5, which compiles without
`PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK`: the 18 invalid verdicts above are PHP 8.5
and 8.5.10, with every release. Along the PCRE2 axis, `/{,3}/` is valid up to
10.42, where `{,3}` is literal text, and refused from 10.43, where it is a
quantifier with nothing to repeat. That is why the result offers no "lowest
version": read the verdicts.

The library refuses `\C` under `u` at every point. PHP 8.4.25 and later 8.4
releases, and 8.5.10 and later, compile `u` patterns with
`PCRE2_NEVER_BACKSLASH_C`, the others compile `\C` there, but an engine that
compiles it can crash while matching, so `/a\Cb/u` gets 54 invalid verdicts.

A pattern is never a reason for `compatibility()` to throw. A pattern no
target can read, a missing delimiter included, gets a verdict refusing it at
every point: `/abc` gets 54 verdicts with the code `regex.delimiter.unclosed`
and no offset.

The lint command judges a project's patterns over the PHP versions its
`composer.json` allows in the same way: see
[Several PHP versions](../guides/cli.md#several-php-versions).

## How the facts are checked

Unit tests cover each fact on its true, false and `null` cases. The exact
facts and the sound bounds are also checked against PCRE2 itself:
`tests/Fixtures/PatternInfo/pcre2test.out` holds what `pcre2test` prints under
`/I` for every pattern of `patterns.txt`, over a hundred, compiled as PHP 8.4
compiles them (`u` is `utf,ucp`, and every pattern allows `\K` in a
lookaround). The test asserts that:

- every exact fact equals PCRE2's: capture count, names, max back reference,
  `\C`, the three limits, the newline and the `\R` conventions;
- a proven `anchoredStart` is one PCRE2 reports as anchored;
- a `minMatchLength` above 0 is one where PCRE2 does not say "May match empty
  string";
- `maxLookbehind` never exceeds PCRE2's.

The fixture was written by PCRE2 10.49; the
[Maintainers Guide](../maintainers.md#pattern-info-next-to-pcre2test)
says how to regenerate it. The compatibility verdicts are checked against
`preg_match()` wherever the running engine is a point of the matrix.

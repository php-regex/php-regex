---
description: "How PCRE2 relates to PHP and other engines: syntax by PCRE2 release, target versioning, a known JIT crash, and the engine comparison matrix."
---
# PCRE vs Other Engines

**PCRE** (Perl Compatible Regular Expressions) is the regex engine used by PHP's `preg_*` functions. Understanding PCRE helps you write better patterns and avoid compatibility issues.

<div class="page-filter">
  <label class="visually-hidden" for="page-filter-input">Filter the PCRE concepts page</label>
  <input type="search" id="page-filter-input" data-unit="sections" data-unit-level="any" placeholder="Filter by section or engine — e.g. lookbehind, unicode, RE2" autocomplete="off" spellcheck="false">
  <span class="page-count"></span>
</div>
<p class="page-empty" role="status" hidden>Nothing matches that filter — try a shorter fragment, or press Escape to see the whole page again.</p>

## What is PCRE?

PCRE is a regular expression engine that:

- Powers PHP's `preg_match()`, `preg_replace()`, etc.
- Is based on Perl's regex syntax
- Supports advanced features like lookarounds, recursion, and Unicode
- Uses backtracking for pattern matching

## PCRE in PHP

The `preg_*` family - what each function returns, with examples - is tabulated in the [Regex in PHP guide](../guides/regex-in-php.md#php-regex-functions).

### PCRE Version in PHP

```php
// Check your PCRE version
echo PCRE_VERSION; // e.g., "10.42 2022-12-11"
```

Some syntax depends on that release. PHP 7.3 bundles PCRE2 10.32, the first
PHP on PCRE2; PHP 7.4 bundles 10.33 (10.34 from 7.4.6, 10.35 from 7.4.12), PHP
8.0 bundles 10.35, PHP 8.1 bundles 10.39 (8.1.0 bundled 10.37), PHP 8.2 and 8.3
bundle 10.40 and 10.42; PHP 8.4 bundles 10.44, which reads PCRE2 10.43's
additions:

| Syntax | Example | Needs |
|---|---|---|
| Alphabetic names of the assertions and of the atomic group | `(*pla:a)`, `(*positive_lookbehind:a)`, `(*atomic:a)` | PCRE2 10.33, PHP 7.4 |
| A brace opening no quantifier, holding a number past 65535, read as text | `a{65536` | PCRE2 10.37, PHP 8.1 |
| A property name spelled loosely: another case, spaces, hyphens or underscores dropped | `\p{greek}`, `\p{Old Italic}`, `\p{lu}` | PCRE2 10.40, PHP 8.2 |
| Script and script extension tables, four-letter script codes | `\p{sc:Greek}`, `\p{scx:Greek}`, `\p{Grek}` | PCRE2 10.40, PHP 8.2 |
| Bidi classes and binary properties | `\p{bc=L}`, `\p{Bidi_Control}`, `\p{Alphabetic}` | PCRE2 10.40, PHP 8.2 |
| Variable-length lookbehind | `(?<=ab?)` | PCRE2 10.43, PHP 8.4 |
| Caseless restrict option | `(?r)` | PCRE2 10.43, PHP 8.4 |
| ASCII options: `a` alone, or with one of `D`, `S`, `W`, `P`, `T` | `(?aD)` | PCRE2 10.43, PHP 8.4 |
| Spaces inside braced escapes and references | `\x{ 41 }`, `\g{ 1 }`, `\k{ name }` | PCRE2 10.43, PHP 8.4 |
| Open minimum and spaces in a repeat count (literal text before) | `a{,2}`, `a{ 2 }` | PCRE2 10.43, PHP 8.4 |
| Unicode 15 script names | `\p{Kawi}`, `\p{Nag_Mundari}` | PCRE2 10.43, PHP 8.4 |
| Unicode 16 script names, and the binary properties PCRE2 10.45 added | `\p{Garay}`, `\p{IDS_Unary_Operator}` | PCRE2 10.45, bundled by no PHP release yet |
| Unicode 17 script names | `\p{Sidetic}`, `\p{Tolong_Siki}` | PCRE2 10.48, bundled by no PHP release yet |
| `^` after spaces in a property | `\p{ ^Lu}` | PCRE2 10.45, bundled by no PHP release yet |
| Casing settings at the start of the pattern | `(*CASELESS_RESTRICT)`, `(*TURKISH_CASING)` | PCRE2 10.45, bundled by no PHP release yet |
| `\k` read as the letter inside a class | `[\k]` | PCRE2 10.45, bundled by no PHP release yet |
| Substring scan assertions | `(*scan_substring:(1)abc)`, `(*scs:(1,<name>)abc)` | PCRE2 10.45, bundled by no PHP release yet |
| Perl extended classes | `(?[ \p{L} - [aeiou] ])`, `(?[ \d & ![3] ])` | PCRE2 10.45, bundled by no PHP release yet |
| Calls that return capture groups | `(?1(2,<name>))`, `(?R(1))`, `(?&name('id'))` | PCRE2 10.47, bundled by no PHP release yet |
| `\K` inside a lookaround | `(?=a\K)` | allowed up to PHP 8.4; PHP 8.5 compiles without `PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK` and refuses it |

#### PHP 8.1 and PCRE2 10.39

PCRE2 10.39 looks a property up by one exact name, as its table spells it:
`\p{Greek}`, `\p{Old_Italic}`, `\p{Lu}`, `\p{L&}`, `\p{Xan}`. Any other
spelling is an unknown property there, and a braced name of 31 characters or
more is a malformed `\p`. From 10.40, a bare script name also means another
set: `\p{Han}` holds the characters whose script extensions name Han, where
10.39 held those whose script is Han, so the ideographic comma U+3001 is
`\p{Han}` on PHP 8.2 and not on PHP 8.1. Patterns judged for PHP 8.1 read
both rules: the validator refuses what 10.39 refuses, and the automata read a
script name as the script. Other answers the automata take from the running
engine, such as the characters of a property, follow that engine's Unicode
version.

PHP 8.1.0 bundled PCRE2 10.37, which lacks five Unicode 14 scripts 10.39 knows
(`Cypro_Minoan`, `Old_Uyghur`, `Tangsa`, `Toto`, `Vithkuqi`): it is judged as
10.39, and so is any older release. PCRE2 10.40 also fixed matching bugs that
no verdict models: on 10.39, `/[Aa]{2}/` does not match `aa`.

#### PHP 7.3 to 8.0, and PCRE1

The library judges PHP 7.3 and later, the PHP versions on PCRE2. It knows the
rules of PCRE2 10.39 and later in full; a PHP that bundles an older release,
10.32 for 7.3, 10.33 for 7.4 and 10.35 for 8.0, is judged for that release
with the 10.39 rules, but for the older changes the library names (in the
table below: the alphabetic assertion names, script runs and group names past
ASCII from 10.33, a repeated `(*ACCEPT)` and the non-atomic lookarounds from
10.34, `(?*` and `(?<*` from 10.35, `a{65536` and `\214748364` read as text
from 10.37), and the lint says so in a note:

```text
Note: PCRE2 10.32 is judged with the PCRE2 10.39 rules, the oldest this library knows in full.
```

A PHP series is judged with the release its x.y.0 bundled, but for 8.1,
judged with the 10.39 of 8.1.1 onwards: PHP 7.4.6 moved to 10.34 and 7.4.12
to 10.35, and a 7.4 target is still judged as 10.33. The Unicode tables a
release reads are not modelled release by release: which characters a
`\p{...}` holds, and which script names an old release knows, are a blind
spot below 10.39. Neither are the matching bugs of an old release, such as a
`(?(DEFINE)...)` inside a lookbehind before 10.35, which some releases refuse
and others read with the wrong length.

PHP 7.3 and 7.4 compile every pattern with `PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL`,
unless it has the `X` modifier: an escape PCRE2 refuses as it reads it is the
character after the backslash, and what follows is read again as pattern text.
`\y` is `y`, `\U` is `U`, `\x{zz}` is the text `x{zz}`, `\p{Bogus}` the text
`p{Bogus}`, `\214748364` the digits `214748364`, `\g{-1}` with no group open
the text `g{-1}`, `\g{+65535}` after a group, past the 65535 groups PCRE2
numbers, `g`, then `{` repeated by the `+`, then the text `65535}`, and `\o{8}`
is `o` repeated eight times, as the braces read again make a count. PHP 8.0 compiles without the option and ignores `X`, so it
refuses all of them. The library judges a 7.3 or 7.4 target the same way, and
reads the escape as that character in the tree it builds:

```php
$regex = Regex::create(['php_version' => '7.4']);
$regex->validate('/\y/')->isValid;   // true: the letter "y"
$regex->validate('/\y/X')->isValid;  // false: "X" turns the option off
Regex::create(['php_version' => '8.0'])->validate('/\y/')->isValid; // false
```

What PCRE2 refuses after it has read the escape stays refused: a reference to
a group that does not exist (`\8`, `\g{9}`, `\k<x>`), a property in a class
(`[\p{Bogus}]`), a class escape before a hyphen (`[\d-z]`), a count past 65535
that the braces read again make (`\x{110000}` under `u`). PCRE2 10.32, PHP 7.3,
also reads an escape a class refuses once read as its letter: `[\B]`, `[\N]`,
`[\k]` and the `\d` of `[a-\d]`; 10.33 refuses them, the option set or not.
Over a range of PHP versions, `regex lint` reports such a pattern where PHP 8.0
refuses it, with a tip: write the character without the backslash, or `\\`
for a backslash before it.

PHP 7.2 and earlier ship PCRE1, a different engine that reads some patterns
otherwise; `regex lint` never judges for them as if they ran PCRE2. A
`composer.json` whose `require.php` also allows PHP 7.3 or later,
`^7.1|^8.0` for instance, is judged from the lowest such PHP, with a note
that the versions below 7.3 are not judged, even under a
`config.platform.php` below 7.3, which only pins the PHP Composer resolves
the dependencies for. A version below 7.3 named
outright (`--php-version`, `phpVersion`, a bridge setting), or a
`composer.json` that allows no PHP of 7.3 or later, is refused (see
[Target PHP and PCRE2](../guides/cli.md#target-php-and-pcre2)).

### Which PHP and which PCRE2 judge a pattern

A pattern is judged for one PHP version and one PCRE2 release, the *target*,
which need not be the engine that runs the analysis: PHPStan under PHP 8.4 may
analyse a project that runs on 8.2. The target is named once, when the `Regex`
instance is created, and every step reads it: tokenizing, parsing, validation,
error offsets and the cache key.

| options | target |
|---|---|
| none | the running PHP and the PCRE2 it links (`PCRE_VERSION`) |
| `php_version` | that PHP with the PCRE2 its sources bundle: 10.32 for 7.3, 10.33 for 7.4, 10.35 for 8.0, 10.39 for 8.1, 10.40 for 8.2, 10.42 for 8.3, 10.44 for 8.4 and 8.5 |
| `pcre_version` | the running PHP with that PCRE2 release |
| both | that PHP with that PCRE2 release |

The PCRE2 PHP links is not always the one it bundles: the PHP 8.4 packages of
Ubuntu 24.04, for one, link PCRE2 10.42 and refuse what 10.43 added. Name both
to judge for such a PHP:

```php
$regex = Regex::create(['php_version' => '8.4', 'pcre_version' => '10.42']);
$regex->validate('/(?aD)x/')->isValid; // false: "(?aD)" is PCRE2 10.43 syntax
$regex->target();                       // PcreTarget: PHP 80400, PCRE2 10.42
```

A release older than 10.39 is judged with the 10.39 rules, a release newer than
the library knows with the newest rules it has. The command line takes the same
pair as `--php-version` and `--pcre-version` (see [the CLI guide](../guides/cli.md)).

The PHPStan extension (see [the PHPStan guide](../guides/phpstan.md)) judges
for PHPStan's own `phpVersion`, with the PCRE2 that PHP bundles. PHPStan takes the PHP running it unless `phpVersion` is
configured; when it is a range, the lowest version, the one least likely to
know recent syntax.
Its parameters name another target:

```neon
parameters:
    phpRegex:
        phpVersion: runtime   # the PHP running PHPStan and the PCRE2 it links
        pcreVersion: '10.42'  # or: PHPStan's PHP version with this PCRE2 release
```

The test suite runs on every supported PHP with the PCRE2 its php-src
bundles (8.2 with 10.40, 8.3 with 10.42, 8.4 and 8.5 with 10.44), with the
10.42 Ubuntu 24.04 ships, and with the latest release, so the answers taken
from the running engine are checked against each of them.

A few answers still come from the running engine, because PCRE2 exposes no
other way to get them: whether a Unicode property or script name exists (the
running engine is asked, then a table of the names each release added corrects
the answer for another target; for PCRE2 10.39, the library's own table of the
names it knows decides), the samples `generate()` checks, the ReDoS
confirmation run, and `runtime_pcre_validation`, which compiles with the
running PHP and is refused with a target that is not that engine.

#### Verdicts that depend on the running PHP

Case folding is not read from PCRE2. Where a verdict needs a character's
other case under `iu`, it asks the running PHP's mbstring, whose Unicode
tables ship with the PHP release, not with the target's PCRE2:

- `regex.lint.flag.useless.i`: whether a letter has another case at all;
- `regex.lint.alternation.overlap`: two branches compared caseless under `u`;
- the ReDoS search-cost witness (`search_cost`): the other case a class may
  read under `iu`;
- the floor of the compiled size behind "Regular expression is too large": a
  class of two case variants counts as one caseless character.

PHP and PCRE2 update their Unicode data on their own schedules, so for a
character a recent Unicode version added, two machines may disagree on one of
these verdicts. The JSON outputs name the running PHP: `php_version` in a
ReDoS verdict, beside `pcre_version`, and `host` in the lint report. A
`\N{name}` is read with ICU when intl is loaded, but PCRE2 refuses
`\N{name}` (only `\N{U+hhhh}` compiles), so that only shapes the message of a
pattern the engine refuses.

Error offsets follow the same rule. PCRE2 10.47 reports most syntax errors past
the character at fault rather than on it (`/+/` at offset 1 rather than 0,
`\y` at 2 rather than 1), and 10.45 moved a few (an unknown POSIX class is
reported past its end, a property name such as `\p{L!}` past its first
character no name can hold). `ValidationResult::$offset` is the offset the
target's PCRE2 reports. When a pattern holds several errors, it is the offset of the
one PCRE meets first, reading left to right: `[z-a](?#` is refused on its
range, not on the comment left open.

One judgement is stricter than some PHP releases on purpose: `\C` with the `u`
flag. PHP 8.4.25 and 8.5.10 refuse it ("using \C is incompatible with the 'u'
modifier"); earlier releases compile it, but matching it can crash PHP
([GH-21134](https://github.com/php/php-src/issues/21134)). It is reported for
every target: at the `\C` for a PHP that refuses it, at the enclosing
lookbehind for one that compiles it, as that PHP refuses it only there.

The library reads patterns with PCRE itself, under limits of its own: a low
`pcre.backtrack_limit` in your configuration changes neither how a pattern is
read nor its verdict (see [PCRE settings](#pcre-settings)).

## What changed in which release

The rules that differ between releases each name the behaviour that changed,
and a target has it from the release it arrived in on
(`PcreTarget::supports(PcreFeature::ScanSubstring)`):

| behaviour | from | what changed |
|---|---|---|
| `AlphabeticAssertionNames` | 10.33 | The assertions and the atomic group take alphabetic names: "(*pla:", "(*positive_lookahead:", "(*nla:", "(*plb:", "(*nlb:", "(*atomic:" and their long forms. |
| `ScriptRuns` | 10.33 | Script runs: "(*script_run:", "(*sr:", "(*atomic_script_run:" and "(*asr:". |
| `NonAsciiGroupNames` | 10.33 | Under UTF a group name may hold letters and decimal digits of any script, as "(?<nämed>a)". |
| `BadEscapeInClassStaysRefused` | 10.33 | Under PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL, as PHP 7.3 and 7.4 compile, an escape a class refuses once it has read it, as "[\B]", "[\N]" or the "\d" of "[a-\d]", stays refused, where it was read as its letter. |
| `QuantifiedAccept` | 10.34 | "(*ACCEPT)" may be repeated, as "(*ACCEPT)??". |
| `PossessiveRepeatOnceIsAtomic` | 10.34 | A possessive "{1}+" on a group or a subroutine call makes it atomic, where it was ignored as "{1}" is. |
| `NonAtomicAssertionNames` | 10.34 | The non-atomic lookarounds: "(*napla:", "(*naplb:" and their long forms. |
| `NonAtomicAssertionShortSyntax` | 10.35 | "(?*" and "(?<*" spell the non-atomic lookarounds. |
| `GroupHoldingItsOwnReferenceBacktracks` | 10.35 | A group that holds a reference to itself may be backtracked into, where it was atomic. |
| `RepeatedAssertionKeepsItsCount` | 10.35 | A repeated assertion runs as many times as its count asks, where it ran once. |
| `UcpFoldsPastAsciiWithoutUtf` | 10.35 | Under "(*UCP)" without UTF, caseless matching folds the characters past ASCII by their Unicode properties. |
| `LargeOpenCountIsText` | 10.37 | A count past 65535 in a brace that opens no quantifier, as "a{65536", is text instead of a count too big. |
| `OverflowingBackslashNumberIsOctal` | 10.37 | A backslash and digits past 214748363, as "\214748364", is an octal escape and text instead of a number too big. |
| `LoosePropertyNames` | 10.40 | "\p{...}" names are matched loosely ("\p{greek}", "\p{Old Italic}") and take "sc:", "scx:", four-letter script codes, Bidi classes and binary properties; a bare script name means its script extensions. |
| `OpenAndPaddedRepeatCounts` | 10.43 | "{,2}" and a count padded with spaces, as "{ 2 }", repeat instead of matching as text. |
| `PaddedBracedEscapes` | 10.43 | Spaces are allowed inside braced escapes and references, as "\x{ 41 }" or "\g{ 1 }". |
| `VariableLengthLookbehind` | 10.43 | A lookbehind branch may match a range of lengths, up to 255 characters. |
| `CaselessRestrictModifier` | 10.43 | The "r" modifier and "(?r)": caseless matching restricted to one script. |
| `AsciiOptions` | 10.43 | "(?a)" and its "D", "S", "W", "P", "T" variants: ASCII-only classes. |
| `LongGroupNames` | 10.44 | A group name may be 128 code units long, not 32. |
| `ScanSubstring` | 10.45 | "(*scan_substring:(1)...)", or "(*scs:": a match read inside a capture. |
| `ExtendedCharClass` | 10.45 | Perl extended character classes, "(?[ ... ])". |
| `CasingSettingVerbs` | 10.45 | "(*CASELESS_RESTRICT)" and "(*TURKISH_CASING)". |
| `HugeBackreferenceNumberIsReference` | 10.45 | "\8" or "\9" followed by eight digits or more is a reference, not a digit and text. |
| `NumberTooBigPastWholeNumber` | 10.45 | A number past 65535 is reported past the whole number. |
| `LimitValueErrorOnFaultingCharacter` | 10.45 | An error in a "(*LIMIT_x=" value is reported on the faulty character. |
| `PosixItemErrorPastItsEnd` | 10.45 | An unknown POSIX class or collating element is reported past its end. |
| `MalformedPropertyName` | 10.45 | A character no property name holds makes "\p{...}" malformed at that character. |
| `RangeFromTypeReadToItsEnd` | 10.45 | A range from a type, a POSIX class or a property is read to its end before it is refused. |
| `EmptyQuoteSkippedAfterClassEscape` | 10.45 | "[\w\E-a]": the empty quote is skipped, and the hyphen makes a range. |
| `SpacedHyphenAfterClassEscapeMakesRange` | 10.45 | Under "(?xx)", a space between a class escape and a hyphen no longer keeps the hyphen a member: "[\d -z]" is the range "\d-z", which is refused. |
| `ClassBackslashKIsLetter` | 10.45 | "\k" in a class is the letter k, not an invalid escape. |
| `HexEscapeNeedsDigits` | 10.45 | "\x" with no hexadecimal digit is an error, not a NUL. |
| `EmptyQuoteOpeningClassIsUnclosed` | 10.45 | "[\E" or "[\Q\E" at the end of the pattern is a class left open, not a trailing backslash. |
| `ClassEscapeEndingRangeRefusedAsEscape` | 10.45 | An escape a class refuses, as "\N" or "\z", ending a range is refused as that escape, not as an invalid range. |
| `VersionConditionWholeNumbers` | 10.47 | The minor of "(?(VERSION>=10.xx)" is read whole, not as two digits. |
| `CallsReturnCaptureGroups` | 10.47 | A call may return capture groups, as "(?1(2,<name>))". |
| `GReferenceNumberReadBeforeClosing` | 10.47 | An unclosed "\g<3" or "\g{3" is refused after the number, not at "\g". |
| `NamedCodePointReadBeforeModeCheck` | 10.47 | "\N{U+...}" without UTF is read to its brace before it is refused. |
| `CalloutConditionErrorAtItemStart` | 10.47 | An error in a callout condition is reported at the start of the item. |
| `ErrorOffsetPastTheFault` | 10.47 | Most syntax errors are reported past the faulty character rather than on it. |
| `AlphaNameAtPatternEndIsUnclosed` | 10.47 | An alphabetic name the pattern ends in, as "(*pla", is a missing ")", not an unknown assertion. |
| `VersionConditionLeftOpenIsVersionError` | 10.47 | A character after the major of "(?(VERSION=10z)" is a version error, not a condition left open. |
| `BranchCountErrorOffTheConditionName` | 10.47 | A conditional on a name that holds more than two branches is not reported on that name. |
| `UnclosedBraceAtPatternEnd` | 10.48 | A braced escape left open at the end of the pattern is reported at the end, not past it. |

## A known JIT crash

PCRE2's JIT compiler, which PHP uses by default (`pcre.jit=1`), crashes the
process on this pattern and subject, found while testing sample generation:

```php
preg_match('/(?|(\*)(*napla:(.+))|()(?=\S_(\2?)))+_/', '*a_'); // SIGSEGV
```

Every part is needed: a repeated branch reset, a non-atomic lookahead
capturing group 2 in one branch, and in the other a lookahead where group 2
refers to itself (`(\2?)`). The machine code the JIT generates for the
reference `\2` reads the capture's text from an address that is no longer
valid. The interpreter answers "no match" without trouble.

| where | result |
|---|---|
| pcre2test 10.40, 10.42, 10.45, 10.47, 10.49 and the development branch of 2026-09-29, built with `--enable-jit` | crash (every release tried) |
| PHP 8.2 and 8.5 with PCRE2 10.42, PHP 8.4 with PCRE2 10.49 (arm64), PHP 8.4 with its bundled 10.44 (x86_64) | segmentation fault |
| any of them with `pcre.jit=0`, or `(*NO_JIT)` leading the pattern | no match, no crash |

What this library does about it:

- Every pattern the library is given and runs goes through
  `PHPRegex\Parser\Engine\PcreEngine`, which runs it with the interpreter: it
  puts `(*NO_JIT)` at the start of the pattern, a start option that changes
  no result. That covers `generate()` and its samples, runtime validation,
  the ReDoS confirmation (`--redos-mode=confirmed`), the PHPStan extension
  and the bridges.
- The `redos` command's benchmark alone runs the JIT, unless `--jit 0` says
  otherwise: it measures what production sees.

Code that runs untrusted patterns against generated subjects is exposed the
same way; `pcre.jit=0` or a leading `(*NO_JIT)` avoids it, at the cost of
the JIT's speed.

## PCRE settings

The library runs regexes of its own: to tokenize and parse a pattern, to
validate it, to scan the classes the ReDoS proof reads, and in the optimizer,
the transpiler and the explanations. Those run under at least PHP's default
limits, a `pcre.backtrack_limit` of 1 000 000 and a `pcre.recursion_limit` of
100 000, whatever your configuration says, so a pattern is read the same way
and gets the same verdict under a tiny limit as under the default.

- **Raised, never lowered.** A limit below its default is raised for the
  library's call only, and set back right after it, also when the call throws.
  A limit at or above its default, or -1 (unlimited), is kept. The values
  are read as PHP hands them to the engine: `1M` is 1 048 576, and only the low
  32 bits count, so `4294967296` is 0 and is raised like any tiny limit.
- **Nothing written when nothing is needed.** With both limits already high
  enough, which is the case under PHP's defaults, the library reads them and
  calls no `ini_set()`.
- **Only around the library's own regexes.** No code of yours runs while a
  limit is raised: no pattern you passed, no cache adapter, no callback.
- **Your pattern keeps your limits.** A pattern the library is given and runs
  (runtime validation, the samples `generate()` checks, `PcreEngine` without
  explicit limits) runs under the limits you set. The ReDoS confirmation runs
  under the limits of its `ConfirmationOptions`, and the `redos` command's
  benchmark under the ones its options name.
- **A refused raise changes nothing.** With `ini_set()` or `ini_get()`
  disabled, or the limits fixed by `php_admin_value`, the library runs under
  the current limits, as it did before it raised them.
- **`PcreEngine` with `ini_set()` disabled.** A call that asks for explicit
  limits (`PcreLimits`, as the ReDoS confirmation does) does not run: it
  returns a `PcreMatch` with a `null` result, the error `The PCRE limits could
  not be set: ini_set() is disabled` and the code `PREG_INTERNAL_ERROR`, and
  `matchAll()` returns `null`. A pattern the engine runs without the JIT by
  turning `pcre.jit` off (one whose delimiters leave no room for
  `(*NO_JIT)`) runs with your settings instead. The engine reads no setting
  with `ini_get()`: it restores the value `ini_set()` hands back, so a
  disabled `ini_get()` alone changes nothing. Limits fixed by
  `php_admin_value` are not detected: a call that asks for explicit limits
  then runs under the fixed ones.
- **`preg_last_error()`** after a library call reports the library's own last
  regex, not yours: read it right after your own `preg_*` call.
- **A fatal error inside such a call** ends the request before the limits are
  set back. PHP puts every `ini_set()` back at the end of a request, so under
  FPM the next request starts with its configured limits.

## PCRE vs other regex engines

| Feature               | PCRE (PHP) | JavaScript | Python | .NET |
|-----------------------|------------|------------|--------|------|
| Lookaheads            | Yes        | Yes        | Yes    | Yes  |
| Lookbehinds           | Yes        | Yes (ES2018) | Yes  | Yes  |
| Variable-length lookbehind | PCRE2 10.43+, up to 255 per branch | Yes (unbounded) | No | Yes |
| Recursion             | Yes        | No         | No     | Yes (balancing groups) |
| Atomic groups         | Yes        | No         | Yes    | Yes  |
| Possessive quantifiers| Yes        | No         | No     | Yes  |
| Unicode properties    | Yes        | Yes        | Yes    | Yes  |
| Named groups          | Yes        | Yes        | Yes    | Yes  |
| Branch reset          | Yes        | No         | No     | No   |

Two nuances that matter when transpiling to JavaScript: its lookbehind arrived with
ES2018 and is unbounded - `/(?<=a+)b/` compiles where PCRE2 would refuse a branch
longer than 255 characters - and .NET recursion means balancing groups, not `(?R)`
or `(?1)`.

## PCRE-specific features

### 1. Recursion

```php
// Match balanced parentheses
$pattern = '/\((?:[^()]++|(?R))*\)/';
preg_match($pattern, '(a(b)c)', $matches);
```

### 2. Branch Reset

```php
// Reset capture numbering per branch
$pattern = '/(?|(a)|(b)|(c))+/';
preg_match($pattern, 'abc', $matches);
```

### 3. Atomic Groups

```php
// Prevent backtracking
$pattern = '/(?>a+)b/';
preg_match($pattern, 'aaaa!', $matches); // Fails quickly
```

### 4. Possessive Quantifiers

```php
// No backtracking
$pattern = '/a++b/';
preg_match($pattern, 'aaaa!', $matches); // Fails quickly
```

For the PHP basics - delimiters, pattern modifiers and `preg_quote()` - see the [Regex in PHP guide](../guides/regex-in-php.md).

## Related concepts

- **[ReDoS Deep Dive](redos.md)** - PCRE's backtracking vulnerabilities
- **[Architecture](../architecture.md)** - How PHPRegex handles PCRE
- **[Regex in PHP Guide](../guides/regex-in-php.md)** - PHP-specific regex details

## Further reading

- [PCRE Documentation](https://www.pcre.org/) - Official PCRE docs
- [PHP Regex Functions](https://www.php.net/manual/en/book.pcre.php) - PHP manual
- [Regex101 PCRE Reference](https://regex101.com/) - Interactive tester

# PCRE vs Other Engines

**PCRE** (Perl Compatible Regular Expressions) is the regex engine used by PHP's `preg_*` functions. Understanding PCRE helps you write better patterns and avoid compatibility issues.

## What is PCRE?

PCRE is a regular expression engine that:

- Powers PHP's `preg_match()`, `preg_replace()`, etc.
- Is based on Perl's regex syntax
- Supports advanced features like lookarounds, recursion, and Unicode
- Uses backtracking for pattern matching

## PCRE in PHP

### PHP's Regex Functions

```php
// PCRE functions in PHP
preg_match('/pattern/', $subject);      // Match pattern
preg_replace('/pattern/', 'replacement', $subject); // Replace
preg_split('/pattern/', $subject);      // Split by pattern
preg_match_all('/pattern/', $subject, $matches); // Find all matches
```

### PCRE Version in PHP

```php
// Check your PCRE version
echo PCRE_VERSION; // e.g., "10.42 2022-12-11"
```

Some syntax depends on that release. PHP 8.2 and 8.3 bundle PCRE2 10.40
and 10.42; PHP 8.4 bundles 10.44, which reads PCRE2 10.43's additions:

| Syntax | Example | Needs |
|---|---|---|
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

### Which PHP and which PCRE2 judge a pattern

A pattern is judged for one PHP version and one PCRE2 release, the *target*,
which need not be the engine that runs the analysis: PHPStan under PHP 8.4 may
analyse a project that runs on 8.2. The target is named once, when the `Regex`
instance is created, and every step reads it: tokenizing, parsing, validation,
error offsets and the cache key.

| options | target |
|---|---|
| none | the running PHP and the PCRE2 it links (`PCRE_VERSION`) |
| `php_version` | that PHP with the PCRE2 its sources bundle: 10.40 for 8.2, 10.42 for 8.3, 10.44 for 8.4 and 8.5 |
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

A release older than 10.40 is judged with the 10.40 rules, a release newer than
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
the answer for another target), the samples `generate()` checks, the ReDoS
confirmation run, and `runtime_pcre_validation`, which compiles with the
running PHP and is refused with a target that is not that engine.

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
| `OpenAndPaddedRepeatCounts` | 10.43 | "{,2}" and a count padded with spaces, as "{ 2 }", repeat instead of matching as text. |
| `PaddedBracedEscapes` | 10.43 | Spaces are allowed inside braced escapes and references, as "\x{ 41 }" or "\g{ 1 }". |
| `VariableLengthLookbehind` | 10.43 | A lookbehind may hold branches of different lengths, up to 255 characters. |
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
| `ClassBackslashKIsLetter` | 10.45 | "\k" in a class is the letter k, not an invalid escape. |
| `HexEscapeNeedsDigits` | 10.45 | "\x" with no hexadecimal digit is an error, not a NUL. |
| `EmptyQuoteOpeningClassIsUnclosed` | 10.45 | "[\E" or "[\Q\E" at the end of the pattern is a class left open, not a trailing backslash. |
| `ClassNEndingRangeRefusedAsN` | 10.45 | "\N" ending a range in a class is refused as "\N", not as an invalid range. |
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
- **`preg_last_error()`** after a library call reports the library's own last
  regex, not yours: read it right after your own `preg_*` call.
- **A fatal error inside such a call** ends the request before the limits are
  set back. PHP puts every `ini_set()` back at the end of a request, so under
  FPM the next request starts with its configured limits.

## PCRE vs other regex engines

| Feature               | PCRE (PHP) | JavaScript | Python | .NET |
|-----------------------|------------|------------|--------|------|
| Lookaheads            | Yes        | Yes        | Yes    | Yes  |
| Lookbehinds           | Yes        | No         | Yes    | Yes  |
| Variable-length lookbehind | PCRE2 10.43+, up to 255 per branch | No | No | Yes |
| Recursion             | Yes        | No         | No     | Yes  |
| Atomic groups         | Yes        | No         | Yes    | Yes  |
| Possessive quantifiers| Yes        | No         | No     | Yes  |
| Unicode properties    | Yes        | Yes        | Yes    | Yes  |
| Named groups          | Yes        | Yes        | Yes    | Yes  |
| Branch reset          | Yes        | No         | No     | No   |

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

## PCRE best practices

### 1. Use Delimiters

```php
// Always include delimiters
$pattern = '/^hello$/i'; // Good
$pattern = '^hello$';     // Bad - missing delimiters
```

### 2. Specify Flags

```php
// Common flags
$pattern = '/hello/i';  // Case-insensitive
$pattern = '/hello/s';  // Dot matches newline
$pattern = '/hello/m';  // Multiline mode
$pattern = '/hello/u';  // Unicode mode
$pattern = '/hello/x';  // Extended (ignore whitespace)
```

### 3. Escape Special Characters

```php
// Escape regex metacharacters
$literal = preg_quote('user@input.com', '/');
$pattern = '/' . $literal . '/';
```

### 4. Use Raw Patterns

```php
// Use single quotes to avoid escaping
$pattern = '/\d{3}-\d{4}/'; // Good
$pattern = "/\d{3}-\d{4}/"; // Also works but harder to read
```

## Related concepts

- **[ReDoS Deep Dive](redos.md)** - PCRE's backtracking vulnerabilities
- **[Architecture](../ARCHITECTURE.md)** - How PHPRegex handles PCRE
- **[Regex in PHP Guide](../guides/regex-in-php.md)** - PHP-specific regex details

## Further reading

- [PCRE Documentation](https://www.pcre.org/) - Official PCRE docs
- [PHP Regex Functions](https://www.php.net/manual/en/book.pcre.php) - PHP manual
- [Regex101 PCRE Reference](https://regex101.com/) - Interactive tester

---

Previous: [ReDoS Deep Dive](redos.md) | Next: [Concepts Home](README.md)
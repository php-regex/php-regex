---
description: "The PHPRegex facade — Regex::create() and every method, validate to transpile — with executed examples, result objects and the exception map."
---
# API Reference

This reference documents the public API surface of PHPRegex: entry points, configuration options, return objects, and the exception hierarchy.

<div class="page-filter">
  <label class="visually-hidden" for="page-filter-input">Filter the API reference</label>
  <input type="search" id="page-filter-input" data-unit="methods" placeholder="Filter by method, class or return — e.g. parse, redos, equivalent" autocomplete="off" spellcheck="false">
  <span class="page-count"></span>
</div>
<p class="page-empty" role="status" hidden>Nothing matches that filter — try a shorter fragment, or press Escape to see the whole page again.</p>

The `Regex` facade lives in `php-regex/regex-toolkit` and needs PHP 8.1 or later with the `mbstring` extension. See [Quick Start](../quick-start.md) for a first tour.

{% include install-prerelease.html package="php-regex/regex-toolkit" %}

## Entry Points

### Regex::create(array $options = []): Regex

Creates a configured Regex instance. This is the primary entry point for all library operations.

Factory steps:
- Validate options.
- Create a configured instance.
- Return a ready-to-use `Regex`.

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create([
    'cache' => '/var/cache/regex',
    'max_pattern_length' => 100_000,
    'max_lookbehind_length' => 255,
    'runtime_pcre_validation' => false,
    'redos_ignored_patterns' => [],
    'max_recursion_depth' => 1024,
    'php_version' => '8.2',
    'pcre_version' => '10.40',
]);

$result = $regex->validate('/foo|bar/');
echo $result->isValid ? 'Valid' : 'Invalid';
```

The options are a closed shape, `OptionsArray` (imported from
`PHPRegex\Parser\ParserOptions`): PHPStan reports a misspelt key or a value
of the wrong type where the array is written, and `create()` checks the
ranges when it reads them. Options read from a configuration file go through
`ParserOptions::fromArray()`, which checks every key and value, then to
`Regex::fromOptions()` (or `RegexParser::fromOptions()`):

```php
use PHPRegex\Parser\ParserOptions;
use PHPRegex\Toolkit\Regex;

$regex = Regex::fromOptions(ParserOptions::fromArray(['php_version' => '8.2']));
```

---

### tokenize(string $regex): list<Token>

Lexes a regex into its tokens, as the parser reads them for the instance's target. Useful for custom analysis or debugging. Each `Token` exposes its `type`, its `value`, its `position` in the pattern body and `end()`, the byte offset just past it. The tokens come in source order, each one starting where the one before it ends, so together they cover the pattern body; the end-of-pattern token comes last, at the body's length.

How many characters one token covers is not part of the contract: `tokenize()` reads a literal character as one token today, and a later release may read a run of literal characters as one token. Rely on the positions, not on a count of tokens.

```php
use PHPRegex\Toolkit\Regex;

foreach (Regex::create()->tokenize('/foo|bar/i') as $token) {
    echo "{$token->type->value} '{$token->value}' at {$token->position}-{$token->end()}\n";
}
// literal 'f' at 0-1
// literal 'o' at 1-2
// ...
// eof '' at 7-7
```

---

### RegexParser: reading and judging a pattern

`RegexParser` does the reading: `parse()`, `parseTolerant()`, `validate()`,
`parsePattern()`, `tokenize()`, with the cache and the target. It takes the same
options as `Regex::create()` and gives the same answers; `Regex` hands its own
to anything else that reads patterns, through `parser()`:

```php
use PHPRegex\Parser\RegexParser;

$parser = RegexParser::create(['php_version' => '8.2']);
$parser->validate('/(?[ \d ])/')->isValid;  // false: PHP 8.2 bundles PCRE2 10.40

$regex = \PHPRegex\Toolkit\Regex::create(['cache' => null]);
$regex->parser()->parse('/a+/');              // the tree $regex->parse() gives
```

A library that only reads and validates patterns needs nothing else.

### clearCaches(): void

Empties every process-wide cache the library keeps: the validator's, the lexer's, the compiler's, the complexity scorer's, the sample generator's and the automata's, and the `validate()` results the instance kept. Each is bounded, so memory does not grow without end: a cache keyed by what patterns hold keeps 1000 entries and drops the older half when full, the others hold a fixed handful. A long-running process may still empty them between batches. The method is called on an instance, and `$regex->parser()->clearCaches()` does the same.

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

// Process many patterns...
foreach ($patterns as $pattern) {
    $regex->validate($pattern);
}

// Clear caches periodically
$regex->clearCaches();
```

### PcreEngine

`PHPRegex\Parser\Engine\PcreEngine` runs a pattern on the running PHP the way the
library runs every pattern it is given: without the JIT (`(*NO_JIT)` leads the
pattern), with its warning captured instead of raised, and with limits set for
the one call and put back after it.

```php
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Engine\PcreLimits;

$engine = new PcreEngine();

$engine->compile('/(?1)a/')?->message;  // "reference to non-existent subpattern at offset 3"
$engine->match('/a(b)/', 'xab')->groups; // ['ab', 'b']
$engine->match('/\Ga/', 'ba', null, 1)->matched; // true: the search starts at byte 1
$engine->match('/(a+)+$/', str_repeat('a', 20).'!', new PcreLimits(10, 100000))->error;
// "Backtrack limit exhausted"
```

`compile()` returns a `PcreError` (message and offset, as PHP reports them for
the pattern as written) or `null`; `match()` returns a `PcreMatch` whose
`matched` is `null` when the engine gave no answer. The offset `match()` takes
is the one `preg_match()` takes: the byte the search starts at in the whole
subject, so `\b` and a lookbehind still see what lies before it, and `^` does
not hold there; past the end of the subject the answer is `null`, with the
error `Internal error`. `test()` calls `preg_match()` without `$matches`, as
most code does, and takes no offset: `preg_match()` takes one only after
`$matches`.

### LanguageSolver

`PHPRegex\Automata\LanguageSolver` compares the languages of two patterns of
the regular subset: `intersection()`, `subsetOf()` and `equivalent()` each
return a result carrying the shortest string that proves the answer, and
`compile()` returns a pattern's DFA. Every character set is asked from the
running PCRE2, so the three results also carry the release that answered, in
`pcreVersion`.

```php
use PHPRegex\Automata\LanguageSolver;

$solver = new LanguageSolver();

$solver->intersection('/[a-c]+/', '/[b-d]+/')->example; // "b"
$solver->subsetOf('/\w+/', '/[a-zA-Z0-9]+/')->counterExample; // "_"
$solver->equivalent('/[0-9]+/', '/\d+/')->isEquivalent; // true
```

See [the logic solver reference](logic-solver.md#php-api) for the options, the
DFA cache and the classes of the namespace that are public.

---

## Configuration Options

All options are validated. Unknown keys throw `InvalidRegexOptionException`.

| Option                    | Type                                   | Default           | Description                    | Performance Impact              |
|---------------------------|----------------------------------------|-------------------|--------------------------------|---------------------------------|
| `cache`                   | `null` \| `string` \| `CacheInterface` | `ArrayCache`      | Cache for parsed ASTs: the latest 1024 in memory by default, files under a directory you name, a PSR-6/PSR-16 adapter, or `null` for none | High - speeds repeated patterns |
| `max_pattern_length`      | `int`                                  | `100_000`         | Maximum pattern length         | Low - prevents abuse            |
| `max_lookbehind_length`   | `int`                                  | `255`             | Maximum length of a variable-length lookbehind; a fixed-length one is only limited by PCRE's 65535 | Low - PCRE compliance           |
| `runtime_pcre_validation` | `bool`                                 | `false`           | Compile-check via preg_match() | Medium - extra compile step     |
| `redos_ignored_patterns`  | `array<string>`                        | `[]`              | Patterns to skip ReDoS         | Low - reduces false positives   |
| `max_recursion_depth`     | `int`                                  | `1024`            | Parser recursion guard         | Low - prevents stack overflow   |
| `php_version`             | `string` \| `int`                      | the running PHP   | PHP version judged, with the PCRE2 it bundles | Low - feature validation        |
| `pcre_version`            | `string`                               | the linked PCRE2  | PCRE2 release judged, `"10.42"` | Low - feature validation        |
| `locale`                  | `string`                               | the runtime's     | `LC_CTYPE` locale judged, `"tr_TR.UTF-8"` | Low - read once per process |

`php_version` is a version from `"7.0"` to `"9.99.99"`, `"8.2"` or `"8.2.1"`,
each part after the major at most 99, a PHP_VERSION_ID from 70000 to 99999
(`80200`, or `"80200"`), or `"runtime"`; `pcre_version` is a PCRE2 release,
`"10.00"` or later. Anything else throws `InvalidRegexOptionException`.

Without `php_version` nor `pcre_version`, patterns are judged for the running PHP
and the PCRE2 it links; without `locale`, under the locale of the running PHP,
`C` unless it called `setlocale()`. A locale the machine cannot set is refused
with `InvalidRegexOptionException`. `runtime_pcre_validation` compiles with the
running PHP, under its locale, so it is refused with a target that is not that
engine. See
[Which PHP and which PCRE2 judge a pattern](../concepts/pcre.md#which-php-and-which-pcre2-judge-a-pattern)
and [Which locale judges a pattern](../concepts/pcre.md#which-locale-judges-a-pattern).

### target(): PcreTarget

The PHP version (`$phpVersionId`), the PCRE2 release (`$pcreVersion`) and the
`LC_CTYPE` locale (`$locale`) the instance judges for. `PcreTarget::runtime()`
is the running engine, `PcreTarget::bundledWith(80400)` a PHP version with its
bundled PCRE2, and `new PcreTarget(80400, '10.42', 'tr_TR.UTF-8')` any of them,
under the runtime's locale when the third is left out. `withLocale()` gives the
same PHP and PCRE2 under another locale, and `key()` tells two targets apart,
as `'80400/10.42/tr_TR.UTF-8'`. `RegexParser` judges for one too (its
`target()`), and `ParserOptions` carries the one the `php_version`,
`pcre_version` and `locale` options name. `withTarget()`, on `Regex` and on
`RegexParser`, gives an instance judging for another target with every other
option and the same cache.

A rule that depends on the release asks the target for a behaviour, named in
`PcreFeature`, rather than for a release written by hand:

```php
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;

(new PcreTarget(80400, '10.44'))->supports(PcreFeature::ScanSubstring);  // false: it arrived in 10.45
PcreFeature::ScanSubstring->release();                                   // '10.45'
```

`pcreAtLeast('10.45')` stays for a release that comes from data; it refuses a
release spelled short, as `'10.4'`, which would read as 10.04.

`judgedWith()` names the release whose rules judge the target: its own, or
`'10.39'` for an older one. The library knows the rules of PCRE2 10.39 and
later in full; of an older release, such as the 10.32 of PHP 7.3, only the few
changes it names:

```php
(new PcreTarget(70300, '10.32'))->judgedWith(); // '10.39'
PcreTarget::bundledWith(70400)->pcreVersion;    // '10.33', PHP 7.4.0's
```

### PcreTarget::phpVersionBoundaries(): list<int>

The PHP versions, as `PHP_VERSION_ID`s in ascending order, at which a verdict
of the library may change: the lowest PHP it supports, then each version that
bundles a newer PCRE2 or where a rule changes. PHP 8.5 refuses `\K` in a
lookaround, and stops refusing `\C` under `u` until 8.5.10:

```php
use PHPRegex\Parser\PcreTarget;

PcreTarget::phpVersionBoundaries(); // [70300, 70400, 80000, 80100, 80200, 80300, 80400, 80425, 80500, 80510]
```

Judging a range of PHP versions at these points judges all of it:
`Regex::compatibility()` and the lint range read them. The list may grow in a
minor release, when the library learns a PHP release or a rule (see
[Backward Compatibility](backward-compatibility.md)).

## Parsing Methods

### parsePattern(string $pattern, string $flags = '', string $delimiter = '/'): RegexNode

Parses a pattern body plus flags/delimiter into a `RegexNode`. Use this when you have separate pattern components.

```php
use PHPRegex\Toolkit\Regex;

$pattern = 'foo|bar';
$flags = 'i';
$delimiter = '/';

$ast = Regex::create()->parsePattern($pattern, $flags, $delimiter);

echo $ast->flags;      // 'i'
echo $ast->delimiter;  // '/'
echo $ast->pattern::class;  // PHPRegex\Parser\Node\AlternationNode
```

---

### parse(string $regex): RegexNode

Parses a full PCRE string (`/pattern/flags`); `parseTolerant()` returns the
errors with a best-effort tree instead of throwing.

```php
use PHPRegex\Toolkit\Regex;

// Strict parsing
$ast = Regex::create()->parse('/foo|bar/i');
echo $ast->flags;      // 'i'
echo $ast->delimiter;  // '/'

// Tolerant parsing - returns AST even with errors
$result = Regex::create()->parseTolerant('/[unclosed/i');

echo $result->ast::class;             // PHPRegex\Parser\Node\RegexNode (partial)
echo $result->errors[0]->getMessage();  // First error
```

---

## Validation and Analysis Methods

### validate(string $regex): ValidationResult

Returns a structured validation result without throwing exceptions.

An instance keeps the results of the last 1024 patterns it validated: a pattern validated again returns the same `ValidationResult` object at the cost of a lookup (well under a microsecond), where it walked the tree again. The result depends only on the pattern and on the instance's options and target, which never change. A pattern answered this way does not reach the AST cache, so `getCacheStats()` counts no hit for it. `clearCaches()` empties the results.

```php
use PHPRegex\Toolkit\Regex;

$result = Regex::create()->validate('/foo|bar/');

var_export($result->isValid);       // true
echo $result->complexityScore;     // 7
var_dump($result->category);       // NULL: no error, no category
```

**ValidationResult Fields:**

| Field             | Type                    | Description              |
|-------------------|-------------------------|--------------------------|
| `isValid`         | bool                    | Whether pattern is valid |
| `error`           | string\|null            | Error message if invalid |
| `errorCode`       | ErrorCode\|null         | Stable error code        |
| `offset`          | int\|null               | Byte offset of the error, from the start of the pattern body |
| `caretSnippet`    | string\|null            | Snippet with caret       |
| `hint`            | string\|null            | Fix suggestion           |
| `complexityScore` | int                     | Pattern complexity       |
| `category`        | ValidationErrorCategory\|null | Error category, `null` when valid |

`render()` gives the message, the caret snippet and the hint (`Hint: …`) as
one block of plain text, each on its own line and left out when absent; an
empty string for a valid pattern.

---

### analyze(string $regex, ?RedosSeverity $threshold = null, RedosMode $mode = RedosMode::Theoretical, ?ConfirmationOptions $confirmOptions = null): AnalysisReport

Aggregates validation, lint, ReDoS analysis, optimization, and explanation into a single report.
The three optional arguments are those of `redos()`, below, and
`$report->redos` is what `redos()` returns with them.

```php
use PHPRegex\Toolkit\Regex;

$report = Regex::create()->analyze('/(a+)+b/');

var_export($report->isValid);     // true or false
echo count($report->errors);      // Validation errors
echo count($report->lintIssues);  // Lint warnings
echo $report->redos->severity->value;  // 'critical', 'safe', etc.
echo $report->explanation;        // Human explanation
echo $report->highlighted;        // Syntax-highlighted pattern
```

**AnalysisReport Fields:**

| Field           | Type                 | Description                    |
|-----------------|----------------------|--------------------------------|
| `isValid`       | bool                 | Pattern is syntactically valid |
| `errors`        | array\<string\>      | Validation error messages      |
| `lintIssues`    | list\<RuleViolation\> | Lint findings, as `lint()` gives them |
| `redos`         | RedosAnalysis        | ReDoS analysis result          |
| `optimization`  | OptimizationResult   | Suggested optimizations        |
| `explanation`   | string               | Human explanation              |
| `highlighted`   | string               | Highlighted pattern            |

---

### lint(string $regex): list<RuleViolation>

Runs the lint rules on the pattern, read for the instance's target: a rule that asks what a part of the pattern
matches reads it as the PHP and PCRE2 judged read it. It runs the rules only — no validation, ReDoS analysis,
optimization, `@regex-ignore` marker or baseline — so it is not what the `regex lint` command reports for a
project. A pattern that does not parse throws the `ParserException` `parse()` throws.

```php
use PHPRegex\Toolkit\Regex;

foreach (Regex::create()->lint('/(?=a)b/') as $violation) {
    echo $violation->id;       // 'regex.lint.lookaround.impossible'
    echo $violation->message;  // 'Lookahead "(?=a)" asks for what the pattern after it can never read: ...'
    echo $violation->offset;   // 0, in bytes from the start of the pattern body
}
```

Each `RuleViolation` (`PHPRegex\Linter\Rule\RuleViolation`) carries the rule's `id`, its `message`, the `offset`
and `end` of the text it names in the pattern body, a `hint` when the rule has one, and its `severity`
(`LintSeverity`). `analyze()` puts the same list in `$report->lintIssues`.

---

### redos(string $regex, ?RedosSeverity $threshold = null, RedosMode $mode = RedosMode::Theoretical, ?ConfirmationOptions $confirmOptions = null): RedosAnalysis

Analyzes ReDoS risk without an analysis report. Default mode is **theoretical**: the pattern is read, never run, and the verdict is proven where the backtracking model covers the pattern, heuristic elsewhere. **Confirmed** mode replays the attack on the running PCRE (see [the ReDoS guide](../guides/redos.md)).

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Redos\RedosMode;

$analysis = Regex::create()->redos('/(a+)+b/', mode: RedosMode::Theoretical);

echo $analysis->severity->value;          // 'critical'
echo $analysis->headline();               // 'Exponential backtracking (proven)'
echo $analysis->complexity->value;        // 'exponential'
echo $analysis->proof->value;             // 'proven'
echo $analysis->witness->render();        // '"a" x n . "!b"'
echo $analysis->score;                    // 10
echo $analysis->confidence->value; // 'medium' until replayed
echo $analysis->vulnerablePart;           // 'a+'
echo $analysis->recommendations[0];       // Suggested fix

// Optional: replay the attack on the running PCRE
$confirmed = Regex::create()->redos('/(a+)+b/', mode: RedosMode::Confirmed);
echo $confirmed->isConfirmed() ? 'confirmed' : 'theoretical'; // 'confirmed'
var_dump($confirmed->replayed);                               // bool(true)
```

**RedosAnalysis Fields:**

| Field              | Type              | Description                              |
|--------------------|-------------------|------------------------------------------|
| `severity`         | RedosSeverity      | Risk level                                |
| `score`            | int               | Risk score (0-10)                         |
| `mode`             | RedosMode          | off, theoretical, or confirmed            |
| `complexity`       | RedosComplexity    | linear, polynomial, exponential, or unknown when nothing was proven |
| `degree`           | int\|null         | Degree of a polynomial verdict (2 or more) |
| `proof`            | RedosProof         | proven, heuristic, budget_exceeded or not_analyzed |
| `witness`          | RedosWitness\|null | Attack input of a proven vulnerable verdict: `render()`, `build($n)`, `toArray()` |
| `replayed`         | bool\|null        | Whether the witness made the running PCRE fail; `null` when not replayed |
| `abstractions`     | list<string>      | What the model analysed differently from the pattern |
| `pcreVersion`      | string            | PCRE2 release the verdict was computed with |
| `analysisVersion`  | string            | `RedosAnalyzer::ANALYSIS_VERSION`         |
| `confidence`       | RedosConfidence       | Analysis confidence, `Low` when nothing raised it |
| `confirmation`     | Confirmation\|null | Bounded evidence details              |
| `vulnerablePart`   | string\|null       | Risky subpattern                          |
| `recommendations`  | array              | Suggested fixes (verify behavior)         |
| `hotspots`         | array              | Problem locations                         |
| `suggestedRewrite` | string\|null       | Suggested rewrite (verify behavior)       |

`headline()` is the verdict in a few words, the one every consumer prints; `isProvenSafe()` is true only for a proven linear verdict, while `isSafe()` is true for `safe` and `low`, proven or not.

---

### captureShape(string $regex): CaptureShape

Reads what a successful `preg_match()` writes into `$matches`, from the pattern alone. The pattern is parsed through
the facade's cache, and an invalid one throws what `parse()` throws. `$groups` is keyed by group number;
`matchShape()` writes the array as a PHPStan type and takes `PREG_OFFSET_CAPTURE` and `PREG_UNMATCHED_AS_NULL`;
`matchAllShape()` writes what `preg_match_all()` fills, under `PREG_PATTERN_ORDER` or `PREG_SET_ORDER`, with the same flags.
A `(?(VERSION>=10.45)...)` condition is read for the facade's target, its `pcre_version`.

```php
use PHPRegex\Toolkit\Regex;

$shape = Regex::create()->captureShape('/(GET|POST) (\S+)/');

$shape->groups[1]->values;  // ['GET', 'POST']
$shape->matchShape();       // "array{0: non-falsy-string, 1: 'GET'|'POST', 2: non-empty-string}"
$shape->matchAllShape();    // "array{0: list<non-falsy-string>, 1: list<'GET'|'POST'>, 2: list<non-empty-string>}"
```

A static analysis extension calls `CaptureShapeAnalyzer` from `php-regex/regex-parser` instead. See
[Capture Shapes](capture-shapes.md) for the facts, the flags and what a release may change.
For the group numbers alone, branch resets and duplicate names included, see
[Group numbers](capture-shapes.md#group-numbers).

---

### info(string $regex): PatternInfo

Reads the facts PCRE2 computes on the compiled pattern, which PHP does not expose, with the length and the anchoring of
what the pattern matches. The pattern is validated first at the facade's target: `info()` throws what `validate()`
refuses, the exception `parse()` throws or a `SemanticErrorException` carrying the validation error and its code.

```php
use PHPRegex\Toolkit\Regex;

$info = Regex::create()->info('/^(?<year>\d{4})-(?<month>\d\d)$/D');

$info->captureCount;   // 2
$info->names;          // ['month' => [2], 'year' => [1]]
$info->minMatchLength; // 7
$info->anchoredStart;  // true
```

`captureCount`, `names`, `maxBackreference`, `usesBackslashC`, `matchLimit`, `depthLimit`, `heapLimit`, `newline` and
`bsr` equal what PCRE2 reports. `minMatchLength`, `maxMatchLength`, `maxLookbehind`, `anchoredStart` and `anchoredEnd`
are sound bounds a minor release may narrow. A static analysis extension calls `PatternInfoAnalyzer` from
`php-regex/regex-parser` instead. See [Pattern Info](pattern-info.md) for each fact and its PCRE2 counterpart.

---

### compatibility(string $regex): PatternCompatibility

Judges the pattern on every PHP version a rule of the library changes at, each with every PCRE2 release from 10.32 to
the newest the library knows, whatever target the facade judges for, each under the facade's locale, recursion depth
and limits. It never throws for the pattern: one no target accepts, a missing delimiter included, gets a verdict
refusing it at every point.

```php
use PHPRegex\Toolkit\Regex;

$compatibility = Regex::create()->compatibility('/(?<=a\Kb)c/');

$compatibility->isValidEverywhere();                        // false: PHP 8.5 refuses \K in a lookaround
$compatibility->invalidVerdicts()[0]->target->phpVersionId; // 80500
```

`verdicts()` lists one `TargetVerdict` per point, ordered by PHP version, then by PCRE2 release: its `target` (a
`PcreTarget`) and its `validation` (a `ValidationResult`). `invalidVerdicts()` keeps the ones that refuse the pattern.
Validity is not monotone, and the matrix widens when the library learns a PHP or PCRE2 release, so a minor release may
add verdicts. `new CompatibilityChecker($parser)` gives the same answer from `php-regex/regex-parser`, each point judged
by `$parser->withTarget()`. See
[Pattern Info](pattern-info.md#compatibility-where-a-pattern-is-valid).

---

## Transform and Extract Methods

### optimize(string $regex, ?OptimizerOptions $options = null): OptimizationResult

Applies safe optimizations to the pattern. The options are an `OptimizerOptions` value, its arguments named;
null reads the defaults. Options read from a configuration, keyed in snake_case as `Regex::create()`'s are,
become one through `OptimizerOptions::fromArray()`, which throws `InvalidRegexOptionException` on an unknown
key or a value of the wrong type (`fromCamelCaseArray()` reads the camelCase keys of `regex.json`).

```php
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Toolkit\Regex;

$result = Regex::create()->optimize('/[0-9]+/', new OptimizerOptions(
    digits: true,                   // [0-9] -> \d
    word: true,                     // [A-Za-z0-9_] stays: \w follows the locale
    ranges: true,                   // Normalize ranges
    canonicalizeCharClasses: true,  // Normalize character class order/dedup
    possessive: false,              // Add possessive quantifiers
    factorize: false,               // Factor common prefixes of alternatives
    minQuantifierCount: 4,          // Use {n} only when repetition >= 4
    verifyWithAutomata: true,       // Offer only a rewrite the automata prove
));

// The same, read from a configuration array:
$result = Regex::create()->optimize('/[0-9]+/', OptimizerOptions::fromArray(['possessive' => false]));

echo $result->original;    // '/[0-9]+/'
echo $result->optimized;   // '/\d+/'
echo $result->changes[0];  // 'Wrote [0-9] as \d.'
```

When `verifyWithAutomata` is enabled, as it is by default, PHPRegex validates that the optimization writes the same `$matches` as the
pattern on every subject (`LanguageSolver::matchEquivalent()`). Where the match solver does not read the pattern, only
a rewrite that reads as the pattern does (`[0-9]` for `\d`), or whose changed parts are each proven alone (`(?:_|%)` for
`[_%]` beside a lookbehind), is kept: matching the same strings is not enough, as `/a(?!b)/` and `/a/` show on `"ab"`. The check works within a budget of its own: one it runs out of proves nothing,
and the original pattern is returned. The rewrite must hold in `preg_match_all()`, `preg_replace()`, `preg_split()` and from an offset
too: a pattern with `^` or `\A`, or that may match empty, is checked in those runs as well, and one that reads the
text before where the search starts (a lookbehind, `\b`, `\B`, `^` under `m`, `\K`) keeps only a rewrite that reads
as it does or whose changed parts are each proven alone (see [the logic solver](logic-solver.md)).

---

### format(string $regex, int $width = 80): FormatResult

Lays the pattern out over lines under `x` when it does not fit the width, keeping its comments and its spelling.
A pattern that fits comes back byte for byte. A width below 1 throws `InvalidRegexOptionException`.

```php
use PHPRegex\Toolkit\Regex;

$result = Regex::create()->format('/^(?<user>[\w.+-]+)@(?<domain>(?:[a-z0-9-]+\.)+[a-z]{2,})$/i', 40);

echo $result->pattern;
// /
//     ^
//     (?<user>[\w.+-]+)
//     @
//     (?<domain>
//         (?:[a-z0-9-]+\.)+
//         [a-z]{2,}
//     )
//     $
// /ix
```

The formatter parses its own output before returning it; if the tree differs from the input's, it throws
`FormattingException` (code `regex.formatting.unsafe`) instead of returning a different pattern. See
[Formatting patterns](../guides/formatting.md) for the layout rules and the refusals.

---

### compact(string $regex): FormatResult

Writes the pattern on one line: no `x`, no layout, no comments. Where removing them would fuse two tokens, an
empty `(?:)` separates them.

```php
echo Regex::create()->compact("/\n    (?<year>\d{4})   # year\n    -\n    (?<month>\d{2})  # month\n/x")->pattern;
// /(?<year>\d{4})-(?<month>\d{2})/
```

---

### transpile(string $regex, string $target, ?TranspileOptions $options = null): TranspileResult

Transpiles a PCRE literal to another regex dialect: JavaScript, the HTML `pattern` attribute, or Python.

```php
use PHPRegex\Toolkit\Regex;

$result = Regex::create()->transpile('/(?P<word>\\w+)/i', 'javascript');

echo $result->literal;     // /(?<word>\w+)/i
echo $result->constructor; // new RegExp("(?<word>\\w+)", "i")

$result = Regex::create()->transpile('/(?P<word>\\w+)/i', 'python');

echo $result->literal;     // r'(?ai)(?P<word>\w+)'
echo $result->constructor; // re.compile(r'(?P<word>\w+)', re.ASCII | re.IGNORECASE)
```

Notes:
- Unsupported PCRE constructs throw `TranspileException`, and so does a construct the target engine would match
  otherwise and has no way to write: in Python a backreference inside a lookbehind or under `/iu`, `\w` or `\b`
  under `u`, and an atomic group or a possessive quantifier unless `pythonVersion` is 3.11 or later; in JavaScript a
  backreference to a group that may not have matched (it matches the empty string there), a capturing group a
  repetition may leave unset, a repetition whose empty match sets a group or may be tried before a longer match, and
  `\p{Lu}`, `\p{Ll}` or `\p{Lt}` under `/i`. PCRE takes one empty repetition and stops where JavaScript takes none:
  the two agree, and the pattern is written, where the empty repetition sets no group and comes after every longer
  one (`/(?:(a)|b?)?c/`, `/(a)(\1)*b/`, `/(?:x*?y|z?)*w/`).
- What a target reads otherwise is written as PCRE reads it: `$` is `(?=\n?$)` in JavaScript, as it also matches before
  a newline that ends the subject, `$` under `/D` and `\z` are `$`, `\Z` is `(?=\n?$)`, and `^` and `$` under `/m` see
  `\n` alone end a line (`(?:^|(?<=\n)(?!$))` and `(?=\n|$)`); in Python `\z` is `\Z` and `\Z` is `(?=\n?\Z)`. The dot,
  which leaves out `\n` alone, is `[^\n]` in JavaScript. Under `u`, where PHP reads `\d`, `\w`, `\s` and `\b` in
  Unicode, JavaScript gets `\p{Nd}`, a class of the word characters and one of the spaces, and Python, without `u`,
  `re.ASCII`.
- JavaScript targets may add `/u` when Unicode properties or code point escapes are used.
- Without `u`, PHP reads the pattern byte by byte where JavaScript and Python read it character by character: a
  multibyte character written whole stays that character (`/café/` passes through), and a lone byte above `0x7F`,
  which matches that byte alone in PCRE and has no character equivalent in the targets, is refused — add `u`, or
  write the character itself (`/\xC3/` is refused with that advice).
- A script property takes the name JavaScript reads: PCRE2 reads `\p{Han}` as the script's extensions, so it is
  `\p{Script_Extensions=Han}`, and `\p{sc:Han}` is `\p{Script=Han}`; a Bidi_Class (`\p{bc:L}`) is refused. For a
  target before PCRE2 10.40 (PHP 8.1), which reads `\p{Han}` as the script alone, it is `\p{Script=Han}`.
- `/x` is dropped after comments/whitespace are normalized; `/m` and `/D` are dropped once `^` and `$` are written
  for them, each with a note.
- `/S` is dropped with a note in the JavaScript, `html-pattern` and Python targets: PHP has ignored it since 7.3.
- `/U` and `(?U)` are carried by swapping greedy and lazy in the quantifiers they govern (`/<.+>/U` is `/<[^\n]+?>/`);
  a possessive quantifier is left alone. JavaScript also takes `(?s)`, `(?m)` and `(?x)`, written out, and
  `(?i)` where it changes nothing; Python takes `(?i)` too, each atom it governs written `(?i:…)` or `(?-i:…)`.
- `TranspileOptions` lets you disable JS lookbehind support (`allowLookbehind: false`): `^` under `/m` and `\b` or
  `\B` under `u`, which are written with a lookbehind, are then refused.
- `TranspileOptions(pythonVersion: '3.11')` names the oldest Python the pattern runs on (`'3.11'` or `'3.11.4'`; a
  string of another shape throws `InvalidRegexOptionException`). Python's `re` takes atomic groups and possessive
  quantifiers from 3.11: from that version `(?>…)`, `*+`, `++`, `?+` and `{n,m}+` are written, before it, or with the
  default `null`, they are refused. A possessive quantifier is the atomic group around the greedy repetition to PCRE,
  and is written so where Python's own goes wrong: before 3.11.5, where a possessive repetition inside another runs
  past its end (`/a++/` is `(?>a+)` for `'3.11'`, `a++` for `'3.11.5'`), and over a group, which Python 3.11 to 3.12.7
  and 3.13.0 lose the capture of (`/(a|b)++/` is `(?>(a|b)+)`).
- Available targets: `javascript` (alias: `js`), `html-pattern` (alias: `html`) and `python` (alias: `py`).
- `html-pattern` gives the value of an HTML `pattern` attribute, which the browser matches whole under the `v` flag
  (`new RegExp("^(?:" + value + ")$", "v")`): an unanchored side is padded with `[\s\S]*`, so the attribute accepts
  what `preg_match()` finds, `\A`, `\z` and `\Z` become `^` and `$`, and classes escape what the `v` flag reserves.
  No flag can be passed: `/i`, `(?i)` and `(?i:…)` are spelled out, each atom written with the characters the running
  PCRE takes for it caselessly (`/^ab$/i` is `^[aA][bB]$`, `[a-z]` under `/iu` is `[a-zA-Z\u017F\u212A]`), and a
  backreference under `/i` is refused; `/s`, `/m` and `/D` change nothing in a field value, which holds no line
  break. `$result->flags` is `v`, `$result->literal` the attribute value, `$result->constructor` the browser's RegExp.

---

### literals(string $regex): LiteralExtractionResult

Extracts fixed literals and prefix/suffix data for fast prefilters or indexing. The literal set describes the text a
match consumes: every match starts with one of `literalSet->prefixes` and ends with one of `literalSet->suffixes`, and
an empty list says nothing about that end. Lookarounds add nothing, `(*ACCEPT)` drops the suffixes, and a set too large
to keep is dropped rather than cut.

```php
use PHPRegex\Toolkit\Regex;

$result = Regex::create()->literals('/user-\d{4}/');

print_r($result->literals);                     // ['user-']
echo $result->patterns[0];                      // '^user\-'
echo $result->literalSet->getLongestPrefix();   // 'user-'
print_r($result->literalSet->suffixes);         // [] (the digits vary)
echo $result->confidence->value;                // 'medium' (LiteralConfidence::Medium)
```

---

### generate(string $regex): string

Generates a sample string that matches the pattern. Useful for testing or documentation.

Each sample is checked against the running PHP, and generation is retried
until one matches: lookaheads, lookbehinds and assertions such as `\b` are
held where they stand. When none matches, `SampleGenerationException` is
thrown (error code `regex.generate.no_match`): the pattern matches nothing, as
`a(*FAIL)` or `a^b`, or its constraints are too tangled to guess. A sample
the engine gives up on, past `pcre.backtrack_limit` or `pcre.recursion_limit`
or on an error it meets matching, is no answer either, and another is tried,
up to eight such samples; when none is found, the exception's message says how many samples the engine
gave up on and the error it gave, as `Backtrack limit exhausted`. A pattern
the running PHP cannot compile gets a sample nothing checked. The sample is
drawn and checked as the running PCRE2 spells the target's properties: for
PHP 8.1, `\p{Hiragana}` is the script alone, and running on PHP 8.1 for PHP
8.4, `\p{sc=Grek}` is read as `\p{Greek}`. A property the running PCRE2 has
no name for, as the target reads it (a bare script name, which means its
script extensions from PHP 8.2, on PHP 8.1; `\p{Sunuwar}` before PCRE2 10.45),
gives no sample: `SampleGenerationException` is thrown. A pattern that
does not parse throws the parser's exception (`SyntaxErrorException` for
`/(/`); one that parses but is invalid, as `/(?1)a/`, throws
`SampleGenerationException`.

```php
use PHPRegex\Toolkit\Regex;

$sample = Regex::create()->generate('/[A-Z][a-z]{3,5}\d{2}/');
echo $sample;  // e.g., "Word12"
```

---

### explain(string $regex, string|ExplainFormat $format = ExplainFormat::Text): string

Generates a human-readable explanation of the pattern. The format is one of the `PHPRegex\Toolkit\ExplainFormat` cases — `Text` or `Html` — or the matching string (`'text'`, `'html'`); any other string throws
`InvalidRegexOptionException`.

The pattern is explained for the target of the instance (`php_version`, `pcre_version`, `locale`): `\w` under
`u` with the non-spacing marks and connector punctuation only for PCRE2 10.43 and later, `\w` and `\s` without
`u` with the letters and spaces of a locale other than C, and a `(?(VERSION>=10.5)` condition with the number as
that release reads it and whether it holds.

```php
use PHPRegex\Toolkit\ExplainFormat;
use PHPRegex\Toolkit\Regex;

// Plain text explanation (the default)
$text = Regex::create()->explain('/\d{3}-\d{4}/');
echo $text;
/*
Regex matches
  Character Type: A digit: [0-9] (exactly 3 times)
  '-'
  Character Type: A digit: [0-9] (exactly 4 times)
*/

// HTML explanation for docs/UIs
$html = Regex::create()->explain('/\d{3}-\d{4}/', ExplainFormat::Html);
echo $html;
/*
<div class="regex-explain">
<strong>Regex matches:</strong>
<ul><li><span title="Character Type: A digit: [0-9] (exactly 3 times)">Character Type: <strong>\d</strong> (A digit: [0-9])</span> (exactly 3 times)</li>
<li><span title="&#039;-&#039;"><strong>&#039;-&#039;</strong></span></li>
<li><span title="Character Type: A digit: [0-9] (exactly 4 times)">Character Type: <strong>\d</strong> (A digit: [0-9])</span> (exactly 4 times)</li></ul>
</div>
*/
```

---

### highlight(string $regex, string|HighlightFormat $format = HighlightFormat::Console): string

Generates syntax-highlighted output, one span per token in the HTML format.
The format is one of the `PHPRegex\Toolkit\HighlightFormat` cases — `Console` or `Html` — or the matching
string (`'console'`, `'html'`); any other string throws `InvalidRegexOptionException`. Each method has its
own enum, so a format the method does not write cannot be named.

```php
use PHPRegex\Toolkit\HighlightFormat;
use PHPRegex\Toolkit\Regex;

// ANSI colors for console (the default)
$highlighted = Regex::create()->highlight('/\d+/', HighlightFormat::Console);
echo $highlighted;  // "\033[38;2;78;201;176m\\d\033[0m\033[38;2;215;186;125m+\033[0m"

// HTML for web
$html = Regex::create()->highlight('/[a-z]+/', 'html');
echo $html;
// <span class="regex-token regex-meta">[</span><span class="regex-token regex-literal">a</span>
// <span class="regex-token regex-meta">-</span><span class="regex-token regex-literal">z</span>
// <span class="regex-token regex-meta">]</span><span class="regex-token regex-quantifier">+</span>
```

---

## Result Objects

### ValidationResult

Returned by `validate()`. Provides structured validation feedback.

```php
$result = Regex::create()->validate('/[unclosed/');

if (!$result->isValid) {
    echo $result->error;              // "Unclosed character class..."
    echo $result->errorCode->value;   // "regex.charclass.unclosed"
    echo $result->offset;             // 9
    echo $result->caretSnippet;       // "Line 1: [unclosed\n                 ^"
    echo $result->hint;               // "Close the class with \"]\", or write \"\\[\" ..."
    echo $result->category->value;    // "syntax"

    // The same parts as one block of plain text, as a console prints it:
    // the message, the caret line, then "Hint: ...". Empty when valid.
    echo $result->render();
}
```

---

### TolerantParseResult

Returned by `parseTolerant($regex)`. Contains partial AST plus errors.

```php
$result = Regex::create()->parseTolerant('/[broken/i');

echo $result->ast instanceof \PHPRegex\Parser\Node\RegexNode;  // true (partial)
echo count($result->errors);  // 1
echo $result->errors[0]->getMessage();  // Unclosed character class "]" at end of input.
```

---

### AnalysisReport

Returned by `analyze()`. Comprehensive pattern analysis.

```php
$report = Regex::create()->analyze('/(a+)+b/');

if (!$report->isValid) {
    // Handle validation errors: each entry is the message string
    foreach ($report->errors as $error) {
        echo $error, "\n";
    }
}

// Check ReDoS safety
if ($report->redos->severity->value !== 'safe') {
    echo "Pattern may be vulnerable!";
    echo $report->redos->recommendations[0];
}

// Get explanation
echo $report->explanation;
```

---

### OptimizationResult

Returned by `optimize()`. Shows what changed: `$changes` names each kind of
rewrite once, in the order the optimizer first made it, and is empty when the
pattern comes back as written.

```php
$result = Regex::create()->optimize('/[0-9]+/');

echo $result->original;    // '/[0-9]+/'
echo $result->optimized;   // '/\d+/'

foreach ($result->changes as $change) {
    echo "- $change\n";
}
// Output:
// - Wrote [0-9] as \d.
```

---

### FormatResult

Returned by `format()` and `compact()`.

| property | type | meaning |
|---|---|---|
| `pattern` | `string` | the result; the input itself when nothing changed or the layout was refused |
| `changed` | `bool` | whether `pattern` differs from the input |
| `refusal` | `?FormatRefusal` | why `format()` or `compact()` left the pattern as it was: `NewlineConvention`, `SpacedQuantifier`, `ExtendedModeSwitchedOff`, `LineBreakInName`, `ParenthesisInComment` or `DelimiterMisread`; `null` otherwise |

---

### TranspileResult

Returned by `transpile()`. Includes the output for the target, JavaScript, the HTML `pattern` attribute or Python, and diagnostics.

```php
$result = Regex::create()->transpile('/(?P<word>\\w+)/i', 'javascript');

echo $result->target;      // javascript
echo $result->pattern;     // (?<word>\w+)
echo $result->flags;       // i
echo $result->literal;     // /(?<word>\w+)/i
echo $result->constructor; // new RegExp("(?<word>\\w+)", "i")

foreach ($result->warnings as $warning) {
    echo "- $warning\n";
}
// (no warnings for this pattern)

foreach ($result->notes as $note) {
    echo "- $note\n";
}
// (no notes for this pattern; /m, /x or /U would each add one)
```

---

### LiteralExtractionResult

Returned by `literals()`. Extracts fixed content.

```php
$result = Regex::create()->literals('/user-\d{4}/');

echo $result->literalSet->getLongestPrefix();   // 'user-'
var_dump($result->literalSet->complete);        // bool(false)
echo $result->confidence->value;                // 'medium' (LiteralConfidence::Medium)

foreach ($result->literals as $literal) {
    echo "Found literal: $literal\n";
}
// Output: Found literal: user-
```

---

## Exception Map

PHPRegex uses a focused exception hierarchy for precise error handling:

Exception hierarchy (simplified):
- `ExceptionInterface`
  - `InvalidRegexOptionException` (invalid configuration option)
  - `CacheException` (a cache directory or file that cannot be written)
  - `InternalFailureException` (the library could not finish its own work: no verdict on the pattern)
  - `LintException` (a lint formatter or worker that failed)
  - `UnknownLintRuleException` (a rules map that names a rule the linter does not run)
  - `UnsupportedTargetException` (a target PHP whose engine the library does not judge: PHP 7.2 and earlier ship PCRE1)
  - `JsonEncodingException` (a value the JSON output cannot write)
  - `RegexException` (base exception with position and error code)
    - `LexerException` (tokenization failure)
    - `ParserException`
      - `SyntaxErrorException` (invalid syntax)
    - `LimitException` (the library stopped at one of its own limits)
      - `RecursionLimitException` (max recursion depth)
      - `ResourceLimitException` (max pattern length)
    - `SemanticErrorException` (semantic validation failure)
    - `TranspileException` (unsupported target or feature during transpile)
    - `SampleGenerationException` (no sample the pattern matches was found)
    - `ComplexityException` (a pattern outside what the automata can decide)

Each class lives in the `Exception` namespace of its package:
`PHPRegex\Parser\Exception\ExceptionInterface` and the parser's own,
`PHPRegex\Linter\Exception\LintException`,
`PHPRegex\Linter\Exception\UnknownLintRuleException`,
`PHPRegex\Linter\Exception\UnsupportedTargetException`,
`PHPRegex\Transpiler\Exception\TranspileException`,
`PHPRegex\Generator\Exception\SampleGenerationException` and
`PHPRegex\Automata\Exception\ComplexityException`.

`SemanticErrorException` sits beside `ParserException`, not under it: a
`catch (ParserException)` does not see it — catch `RegexException` to handle
every parse-time failure. `LimitException` sits beside it too: a limit of the
library (`max_pattern_length`, `max_recursion_depth`) is no grammar error, the
pattern was not read to its end, and `validate()` reports it with the `limit`
category.

`InternalFailureException` is no `RegexException`: the library could not
finish its own work, which says nothing of the pattern, so `parse()`,
`parseTolerant()` and `validate()` throw it rather than report an invalid
pattern. Its usual cause is a host where PHP refuses to raise
`pcre.backtrack_limit` or `pcre.recursion_limit` (a disabled `ini_set()`, a
`php_admin_value`) while they stand below PHP's defaults: one of the regexes
the library reads patterns with then gives up. `$pcreError` holds the
`preg_last_error()` code. Raise the limits, or allow `ini_set()`.

`VisualContextInterface` marks the exceptions that carry the failing pattern
with a caret under the offending character, in `RegexException::$snippet`, and
the pattern it is drawn from, `getPattern()`: `ParserException` and its syntax
subclass, `LimitException` (and its recursion and resource subclasses),
`LexerException`, `SemanticErrorException`, `TranspileException` and the
automata `ComplexityException`. Test for it with `instanceof` to render the
snippet when the caught exception has one. A line longer than 80 bytes is cut
around the caret, never inside a UTF-8 character.

Which one to catch: `LexerException`, `ParserException` and its subclass
`SyntaxErrorException` all mean a pattern PCRE refuses; which of them is
thrown follows the stage of the library that met the fault, which may move
between releases. Catch `RegexException` for every judgement on a pattern
(a semantic error and a limit of the library included), or read
`validate()`, which reports each with its category and error code.

**Usage Examples:**

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;

try {
    $regex = Regex::create(['invalid_key' => 'value']);
} catch (InvalidRegexOptionException $e) {
    echo "Bad option: {$e->getMessage()}";
}

try {
    $ast = Regex::create()->parse('/[unclosed/');
} catch (LexerException $e) {
    echo "Tokenization failed: {$e->getMessage()}";
} catch (ParserException $e) {
    echo "Parse failed: {$e->getMessage()}";
}

// Catch-all for any library error
try {
    $result = Regex::create()->validate('/test/');
} catch (\PHPRegex\Parser\Exception\ExceptionInterface $e) {
    echo "PHPRegex error: {$e->getMessage()}";
}
```

---

## Quick Reference

| Method                  | Returns                 | Purpose           |
|-------------------------|-------------------------|-------------------|
| `create($options)`      | Regex                   | Factory method    |
| `fromOptions($options)` | Regex                   | Factory from ParserOptions |
| `withTarget($target)`   | Regex                   | Copy with a different PcreTarget |
| `tokenize($regex)`      | list<Token>             | Lex into tokens   |
| `parse($pattern)`       | RegexNode               | Parse to AST      |
| `parsePattern($pattern, $flags, $delimiter)` | RegexNode | Parse bare pattern without delimiters |
| `parseTolerant($pattern)` | TolerantParseResult   | Parse with errors |
| `validate($regex)`      | ValidationResult        | Check validity    |
| `analyze($regex, ...)`  | AnalysisReport          | Analysis report   |
| `redos($regex)`         | RedosAnalysis           | ReDoS check       |
| `captureShape($regex)`  | CaptureShape            | Shape of `$matches` |
| `info($regex)`          | PatternInfo             | PCRE2 facts, lengths, anchors |
| `compatibility($regex)` | PatternCompatibility    | Valid on which PHP and PCRE2 |
| `optimize($regex)`      | OptimizationResult      | Optimize pattern  |
| `transpile($regex, $target)` | TranspileResult    | Convert dialects  |
| `explain($regex)`       | string                  | Human explanation |
| `highlight($regex)`     | string                  | Syntax highlight  |
| `format($regex, $width)` | FormatResult           | Pretty-print with line wrapping |
| `compact($regex)`       | FormatResult            | Minify a pattern  |
| `generate($regex)`      | string                  | Generate sample   |
| `literals($regex)`      | LiteralExtractionResult | Extract literals  |
| `parser()`              | RegexParser             | The parser the facade uses |
| `target()`              | PcreTarget              | The PHP and PCRE2 judged for |
| `getCache()`            | CacheInterface          | The shared AST cache |
| `getCacheStats()`       | array                   | Hit/miss/size counters |
| `clearCaches()`         | void                    | Empty every process-wide cache |

## Importable Type Aliases

The classes above export named PHPStan type aliases for the array shapes they
hand you. Import one instead of restating the shape:

```php
/**
 * @phpstan-import-type CacheStats from PHPRegex\Parser\Cache\RemovableCacheInterface
 */
final class MyCacheMonitor
{
    /**
     * @param CacheStats $stats {hits: int, misses: int}
     */
    public function report(array $stats): string
    {
        return $stats['hits'].' hits, '.$stats['misses'].' misses';
    }
}
```

The table lists the aliases a consumer is most likely to import; internal
classes export their own beside these. The aliases are part of the surface [the compatibility promise](backward-compatibility.md)
freezes for all of 2.x: a name will not be renamed or removed within 2.x.

| Alias | Exported by | The shape |
|---|---|---|
| `CacheStats` | `Parser\Cache\RemovableCacheInterface` | `{hits: int, misses: int}` |
| `OptionsArray` | `Parser\ParserOptions` | the eight configuration keys, each optional |
| `OptimizerOptionsArray` | `Optimizer\OptimizerOptions` | the eight optimizer options, in snake_case, each optional, as `OptimizerOptions::fromArray()` reads them |
| `ConfigArray` | `PHPStan\RegexPatternRule` | the `phpRegex` parameter of the PHPStan extension, every key optional |
| `NodeFilter` | `Parser\NodeFinder` | `Closure(NodeInterface): bool` |
| `LengthRange` | `Parser\Analysis\LengthRangeCalculator` | `{0: int, 1: int\|null}` — min and max match length |
| `Metrics` | `Parser\Analysis\MetricsCollector` | `{counts: array<string, int>, total: int, maxDepth: int}` |
| `WitnessArray` | `Redos\RedosWitness` | `{prefix, pump, suffix}` strings of the attack |
| `SearchCostArray` | `Redos\RedosSearchCost` | degree, witness and replay of a search |
| `AlphabetRange` | `Automata\Model\Dfa` | `{0: int, 1: int}` — code point range, both ends in |
| `RangeTransition` | `Automata\Model\DfaState` | `{0: int, 1: int, 2: int}` — range start, end, target state |
| `TestCases` | `Generator\TestCaseGenerator` | `{matching: array<string>, non_matching: array<string>}` |

---
description: "The PHPRegex facade — Regex::create() and every method, validate to transpile — with executed examples, result objects and the exception map."
---
# API Reference

This reference documents the public API surface of PHPRegex: entry points, configuration options, return objects, and the exception hierarchy.

The `Regex` facade lives in `php-regex/regex-toolkit` and needs PHP 8.2 or later with the `mbstring` extension. See [Quick Start](../quick-start.md) for a first tour.

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

---

### Regex::tokenize(string $regex, ?PcreTarget $target = null): TokenStream

Lexes a regex into a `TokenStream` with positional offsets. Useful for custom analysis or debugging. The stream is a cursor over the tokens, not an iterator: walk it with `current()`, `next()`, `peek()` and `hasMore()`, or read the whole array with `getTokens()`. Each `Token` exposes its `type`, its `value`, its `position` and `end()`, the offset just past it.

```php
use PHPRegex\Toolkit\Regex;

$stream = Regex::tokenize('/foo|bar/i');

foreach ($stream->getTokens() as $token) {
    echo "Type: {$token->type->value}, Value: '{$token->value}'\n";
    echo "Position: {$token->position} - {$token->end()}\n";
}
// Type: literal, Value: 'f'
// Position: 0 - 1
// Type: literal, Value: 'o'
// Position: 1 - 2
// ... the same for 'o', '|', 'b', 'a', 'r', then the eof token
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

### Regex::clearCaches(): void

Empties every process-wide cache the library keeps: the validator's, the lexer's, the compiler's, the complexity scorer's, the sample generator's and the automata's. Each is bounded, so memory does not grow without end: a cache keyed by what patterns hold keeps 1000 entries and drops the older half when full, the others hold a fixed handful. A long-running process may still empty them between batches. `RegexParser::clearCaches()` does the same.

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

Without `php_version` nor `pcre_version`, patterns are judged for the running PHP
and the PCRE2 it links. `runtime_pcre_validation` compiles with the running PHP,
so it is refused with a target that is not that engine. See
[Which PHP and which PCRE2 judge a pattern](../concepts/pcre.md#which-php-and-which-pcre2-judge-a-pattern).

### Regex::target(): PcreTarget

The PHP version (`$phpVersionId`) and the PCRE2 release (`$pcreVersion`) the
instance judges for. `PcreTarget::runtime()` is the running engine,
`PcreTarget::bundledWith(80400)` a PHP version with its bundled PCRE2, and
`new PcreTarget(80400, '10.42')` any pair; `Lexer`, `Parser`,
and `Validator` take one.

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

### PcreTarget::phpVersionBoundaries(): list<int>

The PHP versions, as `PHP_VERSION_ID`s in ascending order, at which a verdict
of the library may change: the lowest PHP it supports, then each version that
bundles a newer PCRE2 or where a rule changes. PHP 8.5 refuses `\K` in a
lookaround, and stops refusing `\C` under `u` until 8.5.10:

```php
use PHPRegex\Parser\PcreTarget;

PcreTarget::phpVersionBoundaries(); // [80200, 80300, 80400, 80425, 80500, 80510]
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

```php
use PHPRegex\Toolkit\Regex;

$result = Regex::create()->validate('/foo|bar/');

echo $result->isValid;             // true
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

---

### analyze(string $regex): AnalysisReport

Aggregates validation, lint, ReDoS analysis, optimization, and explanation into a single report.

```php
use PHPRegex\Toolkit\Regex;

$report = Regex::create()->analyze('/(a+)+b/');

echo $report->isValid;           // true/false
echo count($report->errors);      // Validation errors
echo count($report->lintIssues);  // Lint warnings
echo $report->redos->severity->value;  // 'critical', 'safe', etc.
echo $report->explain;            // Human explanation
echo $report->highlighted;        // Syntax-highlighted pattern
```

**AnalysisReport Fields:**

| Field           | Type                 | Description                    |
|-----------------|----------------------|--------------------------------|
| `isValid`       | bool                 | Pattern is syntactically valid |
| `errors`        | array\<string\>      | Validation error messages      |
| `lintIssues`    | array                | Lint findings                  |
| `redos`         | RedosAnalysis        | ReDoS analysis result          |
| `optimizations` | OptimizationResult   | Suggested optimizations        |
| `explain`       | string               | Human explanation              |
| `highlighted`   | string               | Highlighted pattern            |

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
echo $analysis->confidenceLevel()->value; // 'medium' until replayed
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
| `confidence`       | Confidence         | Analysis confidence (use `confidenceLevel()`) |
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

Judges the pattern on every PHP version a rule of the library changes at, each with every PCRE2 release from 10.40 to
the newest the library knows, whatever target the facade judges for. It never throws for the pattern: one no target
accepts, a missing delimiter included, gets a verdict refusing it at every point.

```php
use PHPRegex\Toolkit\Regex;

$compatibility = Regex::create()->compatibility('/(?<=a\Kb)c/');

$compatibility->isValidEverywhere();                        // false: PHP 8.5 refuses \K in a lookaround
$compatibility->invalidVerdicts()[0]->target->phpVersionId; // 80500
```

`verdicts()` lists one `TargetVerdict` per point, ordered by PHP version, then by PCRE2 release: its `target` (a
`PcreTarget`) and its `validation` (a `ValidationResult`). `invalidVerdicts()` keeps the ones that refuse the pattern.
Validity is not monotone, and the matrix widens when the library learns a PHP or PCRE2 release, so a minor release may
add verdicts. `CompatibilityChecker` gives the same answer from `php-regex/regex-parser`. See
[Pattern Info](pattern-info.md#compatibility-where-a-pattern-is-valid).

---

## Transform and Extract Methods

### optimize(string $regex, OptimizerOptions|array $options = []): OptimizationResult

Applies safe optimizations to the pattern. The options are an `OptimizerOptions` value, or an array keyed in
snake_case as `Regex::create()`'s are; an unknown key or a value of the wrong type throws
`InvalidRegexOptionException`.

```php
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Toolkit\Regex;

$result = Regex::create()->optimize('/[0-9]+/', [
    'digits' => true,                    // [0-9] -> \d
    'word' => true,                      // [A-Za-z0-9_] -> \w
    'ranges' => true,                    // Normalize ranges
    'canonicalize_char_classes' => true, // Normalize character class order/dedup
    'possessive' => false,               // Add possessive quantifiers
    'factorize' => false,                // Factor common prefixes of alternatives
    'min_quantifier_count' => 4,         // Use {n} only when repetition >= 4
    'verify_with_automata' => false,     // Verify equivalence with the automata solver when possible
]);

// The same, as a value:
$result = Regex::create()->optimize('/[0-9]+/', new OptimizerOptions(possessive: false));

echo $result->original;    // '/[0-9]+/'
echo $result->optimized;   // '/\d+/'
echo $result->changes[0];  // 'Optimized pattern.'
```

When `verifyWithAutomata` is enabled, PHPRegex validates that the optimization is language-equivalent for the
supported regular subset. Unsupported patterns fall back to the original behavior.

---

### transpile(string $regex, string $target, ?TranspileOptions $options = null): TranspileResult

Transpiles a PCRE literal to another regex dialect: JavaScript, the HTML `pattern` attribute, or Python.

```php
use PHPRegex\Toolkit\Regex;

$result = Regex::create()->transpile('/(?P<word>\\w+)/i', 'javascript');

echo $result->literal;     // /(?<word>\w+)/i
echo $result->constructor; // new RegExp("(?<word>\\w+)", "i")

$result = Regex::create()->transpile('/(?P<word>\\w+)/i', 'python');

echo $result->literal;     // r'(?i)(?P<word>\w+)'
echo $result->constructor; // re.compile(r'(?P<word>\w+)', re.IGNORECASE)
```

Notes:
- Unsupported PCRE constructs throw `TranspileException`.
- JavaScript targets may add `/u` when Unicode properties or code point escapes are used.
- A script property takes the name JavaScript reads: PCRE2 reads `\p{Han}` as the script's extensions, so it is
  `\p{Script_Extensions=Han}`, and `\p{sc:Han}` is `\p{Script=Han}`; a Bidi_Class (`\p{bc:L}`) is refused.
- `/x` is dropped after comments/whitespace are normalized.
- `/S` is dropped with a note in the JavaScript and `html-pattern` targets: PHP has ignored it since 7.3.
- `/U` and `(?U)` are carried by swapping greedy and lazy in the quantifiers they govern (`/<.+>/U` is `/<.+?>/`);
  a possessive quantifier is refused as before. JavaScript takes no other inline flag.
- `TranspileOptions` lets you disable JS lookbehind support (`allowLookbehind: false`).
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
echo $result->confidence;                       // 'medium'
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
the running PHP cannot compile gets a sample nothing checked.

```php
use PHPRegex\Toolkit\Regex;

$sample = Regex::create()->generate('/[A-Z][a-z]{3,5}\d{2}/');
echo $sample;  // e.g., "Word12"
```

---

### explain(string $regex, string|OutputFormat $format = OutputFormat::Text): string

Generates a human-readable explanation of the pattern. The format is one of the `PHPRegex\Toolkit\OutputFormat` cases — `Text`, `Html`, `Console` — or the matching string (`'text'`, `'html'`, `'console'`).

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Toolkit\OutputFormat;

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
$html = Regex::create()->explain('/\d{3}-\d{4}/', OutputFormat::Html);
echo $html;
/*
<div class="regex-explain">
<strong>Regex matches:</strong>
<ul><li>(exactly 3 times) <span title="Character Type: A digit: [0-9]">Character Type: <strong>\d</strong> (A digit: [0-9])</span></li>
<li><span title="Literal: &#039;-&#039;">Literal: <strong>&#039;-&#039;</strong></span></li>
<li>(exactly 4 times) <span title="Character Type: A digit: [0-9]">Character Type: <strong>\d</strong> (A digit: [0-9])</span></li></ul>
</div>
*/
```

---

### highlight(string $regex, string|OutputFormat $format = OutputFormat::Console): string

Generates syntax-highlighted output, one span per token in the HTML format.

```php
use PHPRegex\Toolkit\Regex;

// ANSI colors for console (the default)
$highlighted = Regex::create()->highlight('/\d+/', 'console');
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
    echo $result->hint;               // null: no hint for this one
    echo $result->category->value;    // "syntax"
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
echo $report->explain;
```

---

### OptimizationResult

Returned by `optimize()`. Shows what changed.

```php
$result = Regex::create()->optimize('/[0-9]+/');

echo $result->original;    // '/[0-9]+/'
echo $result->optimized;   // '/\d+/'

foreach ($result->changes as $change) {
    echo "- $change\n";
}
// Output:
// - Optimized pattern.
```

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
// - JavaScript \w and \b are ASCII-based; Unicode word boundaries may differ.
```

---

### LiteralExtractionResult

Returned by `literals()`. Extracts fixed content.

```php
$result = Regex::create()->literals('/user-\d{4}/');

echo $result->literalSet->getLongestPrefix();   // 'user-'
var_dump($result->literalSet->complete);        // bool(false)
echo $result->confidence;                       // 'medium'

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
  - `RegexException` (base exception with position and error code)
    - `LexerException` (tokenization failure)
    - `ParserException`
      - `SyntaxErrorException` (invalid syntax)
      - `RecursionLimitException` (max recursion depth)
      - `ResourceLimitException` ( resource limits)
    - `SemanticErrorException` (semantic validation failure)
    - `TranspileException` (unsupported target or feature during transpile)
    - `SampleGenerationException` (no sample the pattern matches was found)

`SemanticErrorException` sits beside `ParserException`, not under it: a
`catch (ParserException)` does not see it — catch `RegexException` to handle
every parse-time failure.

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
| `tokenize($regex, $target)` (static) | TokenStream | Lex into tokens |
| `parse($pattern)`       | RegexNode               | Parse to AST      |
| `parseTolerant($pattern)` | TolerantParseResult   | Parse with errors |
| `validate($regex)`      | ValidationResult        | Check validity    |
| `analyze($regex)`       | AnalysisReport          | Analysis report   |
| `redos($regex)`         | RedosAnalysis           | ReDoS check       |
| `captureShape($regex)`  | CaptureShape            | Shape of `$matches` |
| `info($regex)`          | PatternInfo             | PCRE2 facts, lengths, anchors |
| `compatibility($regex)` | PatternCompatibility    | Valid on which PHP and PCRE2 |
| `optimize($regex)`      | OptimizationResult      | Optimize pattern  |
| `transpile($regex, $target)` | TranspileResult    | Convert dialects  |
| `explain($regex)`       | string                  | Human explanation |
| `highlight($regex)`     | string                  | Syntax highlight  |
| `generate($regex)`      | string                  | Generate sample   |
| `literals($regex)`      | LiteralExtractionResult | Extract literals  |
| `parser()`              | RegexParser             | The parser the facade uses |
| `target()`              | PcreTarget              | The PHP and PCRE2 judged for |
| `clearCaches()`         | void                    | Empty every process-wide cache |

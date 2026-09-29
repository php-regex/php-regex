# Upgrading RegexParser

This guide helps you upgrade RegexParser between versions. For detailed changes, see [CHANGELOG.md](CHANGELOG.md).

## 1.3.0 → [Unreleased]

### Breaking Changes

#### `UnicodeNode` and `visitUnicode()` are gone

No parser path ever produced a `UnicodeNode`: a `\u{...}` or `\x{...}` escape
becomes a `CharLiteralNode`. The node is removed, and with it the
`visitUnicode()` method of `NodeVisitorInterface`.

A custom visitor keeps working as it is — an extra `visitUnicode()` method on
your class is simply never called, and you can delete it. Code that names
`RegexParser\Node\UnicodeNode` has to be updated to `CharLiteralNode`, whose
`codePoint` holds the value the `code` string used to spell.

#### `ReDoSAnalyzerInterface` is gone

Nothing implemented it, `ReDoSAnalyzer` included. Type against `ReDoSAnalyzer`.

#### One target: `PcreTarget` replaces the PHP version id

A pattern is judged for one PHP version and one PCRE2 release, now a value,
`RegexParser\PcreTarget`, resolved once by `Regex::create()` and passed to
everything that reads it.

| before | after |
|---|---|
| `new Lexer($versionId)` or `new Lexer(bool)` | `new Lexer(?PcreTarget $target = null)` |
| `new Parser($depth, ?int $phpVersionId)` | `new Parser($depth, ?PcreTarget $target)` |
| `new ValidatorNodeVisitor($max, $pattern, int $phpVersionId)` | `new ValidatorNodeVisitor($max, $pattern, ?PcreTarget $target)` |
| `Regex::tokenize($regex, ?int $phpVersionId)` | `Regex::tokenize($regex, ?PcreTarget $target)` |
| `Regex::cacheSeed($regex, int $phpVersionId, $depth)` | `Regex::cacheSeed($regex, PcreTarget $target, $depth)` |
| `RegexPattern::fromDelimited($regex, ?int)`, `PatternParser::extractPatternAndFlags($regex, ?int)` | take `?PcreTarget` |
| `RegexOptions::$phpVersionId`, `$phpVersionExplicit` | `RegexOptions::$target` |
| `new RegexOptions(..., $maxRecursionDepth, int $phpVersionId, bool $phpVersionExplicit)` | `new RegexOptions(..., $maxRecursionDepth, ?PcreTarget $target)` |
| `Lexer::readsWideRepeatCounts()` | gone: `PcreTarget::pcreAtLeast('10.43')` |

`null` means `PcreTarget::runtime()`, the running PHP and the PCRE2 it links.
`PcreTarget::bundledWith(80200)` is a PHP version with the PCRE2 it bundles.

#### `php_version` alone always means the PCRE2 that PHP bundles

`php_version` naming the running PHP used to mix two engines: the parser read
the bundled PCRE2, the validator the linked one. It now judges with the bundled
one throughout, as for any other version. Judging for the running engine is the
default, with no option; add `pcre_version` to name the linked release.

#### `runtime_pcre_validation` needs the running engine as target

It compiles with the running PHP, so combining it with a target that is another
engine now throws `InvalidRegexOptionException` instead of judging with the
wrong one. Drop one of the two options.

#### The PHPStan extension judges for PHPStan's `phpVersion`

The rule used to judge for the PHP running PHPStan and the PCRE2 it links; it
now judges for the `phpVersion` PHPStan analyses the project for, with the
PCRE2 that PHP bundles. Without a `phpVersion` in your PHPStan configuration,
PHPStan takes the PHP running it, so only the PCRE2 moves, from the linked
release to the bundled one. Set PHPStan's `phpVersion` to the PHP your project
runs on, `regexParser.pcreVersion` for a PHP that links another PCRE2, or
`regexParser.phpVersion: runtime` to keep the old behaviour.

#### The pattern extractors moved, and one is renamed

The two ways of finding regex patterns in PHP source now sit together under
`RegexParser\Lint\Extraction`, with the interface they implement:

  - `RegexParser\Lint\ExtractorInterface` → `RegexParser\Lint\Extraction\ExtractorInterface`
  - `RegexParser\Lint\TokenBasedExtractionStrategy` → `RegexParser\Lint\Extraction\TokenBasedExtractionStrategy`
  - `RegexParser\Lint\PhpStanExtractionStrategy` → `RegexParser\Lint\Extraction\PhpParserExtractionStrategy`

The last one never had anything to do with PHPStan: it reads the source with
nikic/php-parser, which PHPStan happens to bring along. The old names are
aliased and will be dropped in the next major version.

#### Two Symfony bridge classes are renamed

`RegexParser\Bridge\Symfony\Analyzer\Severity` said `pass`, `warn`, `fail`
and `critical` — the outcome of a check, not the severity of a lint issue,
which is what `RegexParser\Severity` means. It is now `CheckOutcome`.

`RegexParser\Bridge\Symfony\Analyzer\AnalysisReport` is the sectioned
report of the `regex:security` command and has nothing in common with
`RegexParser\AnalysisReport`, the result of `Regex::analyze()`. It is now
`SecurityReport`.

Both are marked `@internal`; the commands that build them are the only
callers.

#### The lint CLI command moved to the CLI namespace

`RegexParser\Lint\Command\LintCommand` and `LintOutputRenderer` are the only
two classes of that namespace that needed the console, so the lint domain no
longer depends on the CLI it is called from. They are now
`RegexParser\Cli\Command\LintCommand` and
`RegexParser\Cli\Command\LintOutputRenderer`.

The old names still resolve — they are aliased on first use — but they will
be dropped in the next major version.

#### Cached ASTs are rebuilt

`Regex::CACHE_VERSION` moved to `1.4.0`, so entries written by 1.3.0 are
ignored and the patterns are parsed once more. Nothing to do; a warm cache
directory rebuilds itself.

### Deprecated

#### `ClassOperationNode` and the class operation tokens

PHP compiles patterns without PCRE2's extended class syntax, so inside a
character class `&&` is two `&` members and `--` a range through `-`:
`[a&&b]` matches `&`, and `[a--b]` is refused as a range out of order. The
parser now reads them that way and never builds a `ClassOperationNode`.

These stay for compatibility and go in the next major version:

  - `RegexParser\Node\ClassOperationNode` and `RegexParser\Node\ClassOperationType`
  - `TokenType::T_CLASS_INTERSECTION` and `TokenType::T_CLASS_SUBTRACTION`, which the lexer no longer produces
  - `NodeVisitorInterface::visitClassOperation()` and its implementations

A custom visitor keeps its `visitClassOperation()` method for now; it is no
longer called for a parsed pattern. Code that looked for a `ClassOperationNode`
in a parsed tree finds the members and ranges instead.

### Planned

#### `ValidationResult::$offset` will become body-relative everywhere

Today a syntax error inside the pattern body reports an offset into the body,
while a flag error reports an offset into the whole pattern string, delimiters
included; a delimiter error reports none. A future release will report every
offset relative to the body, the coordinate PCRE2 uses. Code that places a
caret under flag errors will need to shift it by the length of the opening
delimiter. Nothing changes in this release.

## [Unreleased] → 1.3.0

### Breaking Changes

#### 1. Lint Configuration Migration

**Before:**
```php
// config/regex.php
return [
    'rules' => [
        'suspiciousAsciiRange' => true,
        'duplicateCharacterClass' => true,
    ],
    'redosMode' => 'theoretical',
    'redosThreshold' => 'high',
    'redosNoJit' => false,
    'optimizations' => [
        'digits' => true,
        'word' => true,
        'ranges' => true,
        'canonicalizeCharClasses' => true,
    ],
    'minSavings' => 10,
];
```

**After:**
```php
// config/regex.php
return [
    'checks' => [
        'lint' => [
            'suspiciousAsciiRange' => true,
            'duplicateCharacterClass' => true,
        ],
        'redos' => [
            'mode' => 'theoretical',  // 'theoretical' or 'confirmed'
            'threshold' => 'high',  // 'safe', 'low', 'medium', 'high', 'critical'
            'noJit' => false,
        ],
        'optimization' => [
            'digits' => true,
            'word' => true,
            'ranges' => true,
            'canonicalizeCharClasses' => true,
            'minSavings' => 10,
        ],
    ],
];
```

**Migration Steps:**
1. Move `rules.*` → `checks.lint.*`
2. Move `redosMode` → `checks.redos.mode`
3. Move `redosThreshold` → `checks.redos.threshold`
4. Move `redosNoJit` → `checks.redos.noJit`
5. Move `optimizations.*` → `checks.optimization.*`
6. Move `minSavings` → `checks.optimization.minSavings`

#### 2. Composer Script Replacement

**Before:**
```json
{
    "scripts": {
        "regex:lint": "bin/regex lint --rules=suspiciousAsciiRange,duplicateCharacterClass"
    }
}
```

**After:**
```json
{
    "scripts": {
        "regex:lint": "bin/regex lint --checks.lint.suspiciousAsciiRange --checks.lint.duplicateCharacterClass"
    }
}
```

#### 3. PHPStan Configuration Migration

**Before:**
```neon
parameters:
    regexParser:
        rules:
            suspiciousAsciiRange: true
        redosMode: theoretical
        redosThreshold: high
```

**After:**
```neon
parameters:
    regexParser:
        checks:
            lint:
                suspiciousAsciiRange: true
            redos:
                mode: theoretical
                threshold: high
```

### Deprecated Features

The following features are deprecated and will be removed in 2.0.0:

- ✗ `rules` config key (use `checks.lint`)
- ✗ `redosMode` config key (use `checks.redos.mode`)
- ✗ `redosThreshold` config key (use `checks.redos.threshold`)
- ✗ `redosNoJit` config key (use `checks.redos.noJit`)
- ✗ `optimizations` config key (use `checks.optimization`)
- ✗ `minSavings` config key (use `checks.optimization.minSavings`)

### New Features

#### 1. Structured Lint Configuration

All lint checks are now organized under `checks` namespace for better organization:

```php
$regex = Regex::create();
$result = $regex->lint($pattern, [
    'checks' => [
        'lint' => [
            'suspiciousAsciiRange' => true,
            'duplicateCharacterClass' => true,
            'uselessRange' => true,
            'zeroQuantifier' => true,
            'redundantQuantifier' => true,
            'emptyAlternative' => true,
            'duplicateDisjunction' => true,
            'uselessBackreference' => true,
            'optimalQuantifierConcatenation' => true,
        ],
        'redos' => [
            'mode' => 'confirmed',  // NEW: Run actual tests
            'threshold' => ReDoSSeverity::MEDIUM,
            'maxTestStrings' => 1000,
            'noJit' => false,
        ],
        'optimization' => [
            'digits' => true,
            'word' => true,
            'ranges' => true,
            'canonicalizeCharClasses' => true,
            'autoPossessify' => true,
            'allowAlternationFactorization' => false,
            'minQuantifierCount' => 4,
            'minSavings' => 10,
            'verifyWithAutomata' => true,  // NEW: Verify equivalence
        ],
    ],
]);
```

#### 2. Automata-Based Optimization Verification

New `verifyWithAutomata` option ensures optimized patterns are mathematically equivalent:

```php
$regex = Regex::create();
$result = $regex->optimize($pattern, [
    'verifyWithAutomata' => true,  // NEW: Guarantee equivalence
]);

if ($result->optimizedPattern !== $pattern) {
    echo "Optimized safely: {$result->optimizedPattern}\n";
} else {
    echo "Pattern already optimal or cannot guarantee equivalence\n";
}
```

### Migration Checklist

Use this checklist to ensure your upgrade is complete:

- [ ] Replace all `rules.*` config with `checks.lint.*`
- [ ] Replace `redosMode` with `checks.redos.mode`
- [ ] Replace `redosThreshold` with `checks.redos.threshold`
- [ ] Replace `redosNoJit` with `checks.redos.noJit`
- [ ] Move `optimizations.*` to `checks.optimization.*`
- [ ] Move `minSavings` to `checks.optimization.minSavings`
- [ ] Update PHPStan configuration (if used)
- [ ] Update composer scripts (if using custom flags)
- [ ] Test your regex patterns after upgrade
- [ ] Run `bin/regex lint` on your codebase
- [ ] Review ReDoS warnings with new `confirmed` mode if needed

### Testing Your Upgrade

After upgrading, run these commands to verify everything works:

```bash
# 1. Verify linting works with new config
bin/regex lint src/ --config=config/regex.php

# 2. Test ReDoS detection with new mode
bin/regex analyze '/(a+)+$/' --checks.redos.mode=confirmed

# 3. Verify optimization with automata verification
bin/regex optimize '/[0-9]{3}-[0-9]{3}-[0-9]{4}/' --checks.optimization.verifyWithAutomata

# 4. Run full test suite
composer phpunit
```

## [1.2.0] → 1.3.0

### Breaking Changes

#### 1. Transpiler API Signature Change

**Before:**
```php
use RegexParser\Transpiler\RegexTranspiler;

$transpiler = new RegexTranspiler($regex);
$result = $transpiler->transpile('/\d+/', 'javascript');
```

**After:**
```php
use RegexParser\Regex;

$regex = Regex::create();
$result = $regex->transpile('/\d+/', 'javascript');

// Or with options
use RegexParser\Transpiler\TranspileOptions;

$options = new TranspileOptions(
    targetLanguage: 'javascript',
    ensureBackwardCompatibility: true,
);
$result = $regex->transpile('/\d+/', $options);
```

**Migration Steps:**
1. Replace `new RegexTranspiler($regex)` with `Regex::create()`
2. Call `$regex->transpile()` instead of `$transpiler->transpile()`
3. Use `TranspileOptions` for advanced configurations

#### 2. Automata Solver API Changes

**Before:**
```php
use RegexParser\Automata\Solver\RegexSolver;

$solver = new RegexSolver($regex);
$result = $solver->equivalent($pattern1, $pattern2);
```

**After:**
```php
use RegexParser\Automata\RegexLanguageSolver;

$language = RegexLanguageSolver::create();
$result = $language->equivalent($pattern1, $pattern2, new SolverOptions());
```

**Migration Steps:**
1. Replace `RegexSolver` with `RegexLanguageSolver`
2. Use static factory method `RegexLanguageSolver::create()`
3. Pass `SolverOptions` for fine-tuning

### New Features

#### 1. Unicode-Aware Automata

Automata solver now supports `/u` flag patterns with proper Unicode handling:

```php
$language = RegexLanguageSolver::create();
$result = $language->subset('/[α-ω]+/u', '/[a-zA-Z]+/');
// Correctly handles Unicode code points
```

#### 2. CLI Graph Command

New command to export AST as DOT/Mermaid diagrams:

```bash
# Export as DOT format (Graphviz)
bin/regex graph '/\d{4}-\d{2}/' --format=dot

# Export as Mermaid format
bin/regex graph '/\d{4}-\d{2}/' --format=mermaid

# Save to file
bin/regex graph '/\d{4}-\d{2}/' --format=mermaid > diagram.mmd
```

## [1.1.0] → 1.2.0

### Breaking Changes

None. This is a feature release.

### New Features

#### 1. Symfony Bridge Commands

New Symfony bundle commands:

```bash
# Analyze Symfony routes for conflicts
php bin/console regex:routes

# Analyze Symfony security config
php bin/console regex:security

# Run all bridge analyzers
php bin/console regex:analyze
```

## [1.0.0] → 1.1.0

### Breaking Changes

#### 1. ReDoS Severity Enum Change

**Before:**
```php
use RegexParser\ReDoS\ReDoSSeverity;

$result = $regex->redos($pattern, 'high');  // string
```

**After:**
```php
use RegexParser\ReDoS\ReDoSSeverity;

$result = $regex->redos($pattern, ReDoSSeverity::HIGH);  // enum
```

**Migration Steps:**
1. Replace string thresholds with enum values:
   - `'safe'` → `ReDoSSeverity::SAFE`
   - `'low'` → `ReDoSSeverity::LOW`
   - `'medium'` → `ReDoSSeverity::MEDIUM`
   - `'high'` → `ReDoSSeverity::HIGH`
   - `'critical'` → `ReDoSSeverity::CRITICAL`
2. Update type hints to use `ReDoSSeverity` type

#### 2. Lint Result Structure Changes

**Before:**
```php
$issues = $linter->lint($pattern);

foreach ($issues as $issue) {
    echo "{$issue['type']}: {$issue['message']}\n";
}
```

**After:**
```php
use RegexParser\Lint\LintIssue;

$report = $linter->lint($pattern);

/** @var LintIssue $issue */
foreach ($report->issues as $issue) {
    echo "{$issue->type->value}: {$issue->message}\n";
}
```

**Migration Steps:**
1. Access properties via object properties instead of array keys
2. Use typed properties: `$issue->type`, `$issue->message`, `$issue->position`
3. Import `LintIssue` for type hints

### New Features

#### 1. Compare Command

New command for pattern comparison:

```bash
# Check intersection
bin/regex compare '/edit/' '/[a-z]+/'

# Check subset
bin/regex compare '/edit/' '/[a-z]+/' --method=subset

# Check equivalence
bin/regex compare '/[0-9]+/' '/\d+/' --method=equivalence
```

## Need Help?

If you encounter issues during upgrade:

1. Check [Troubleshooting Guide](docs/TROUBLESHOOTING.md)
2. Review [CHANGELOG.md](CHANGELOG.md) for detailed changes
3. Search [GitHub Issues](https://github.com/php-regex/regex-parser/issues)
4. Create new issue with your specific upgrade problem

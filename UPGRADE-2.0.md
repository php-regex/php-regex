# Upgrading from 1.x to 2.0

2.0 is a new major line. The 1.x line stays on the
[`1.x` branch](https://github.com/php-regex/regex-parser/tree/1.x), with its own
upgrade notes; this guide covers moving from 1.3 to 2.0. For every change, see
[CHANGELOG.md](CHANGELOG.md).

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

#### `GroupType` has a new case, and two nodes a new field

`GroupType::T_GROUP_SCAN_SUBSTRING` stands for `(*scs:(1)...)`, PCRE2 10.45: a
`match` over `GroupType` without a `default` arm needs one for it.
`GroupNode::$scannedGroups` lists the groups it scans, and
`SubroutineNode::$returnedGroups` the groups a call returns,
`(?1(2,<name>))` (PCRE2 10.47); both are empty lists otherwise, and both
constructors take them as a last, optional argument.

#### Two new nodes and two visitor methods

A Perl extended class, `(?[ \p{L} - [aeiou] ])` (PCRE2 10.45), is an
`ExtendedCharClassNode` whose expression is an operand or a tree of
`ClassSetOperationNode`s. `NodeVisitorInterface` gains
`visitExtendedCharClass()` and `visitClassSetOperation()`: a visitor extending
`AbstractNodeVisitor` inherits both, one implementing the interface directly
needs them.

#### `generate()` throws when it finds no matching sample

`Regex::generate()` used to return its last attempt when no sample matched,
as for `a(*FAIL)`: a string the pattern does not match. It now throws
`RegexParser\Exception\SampleGenerationException`. Catch it where a pattern
may match nothing.

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

#### The PHPStan extension reports what PHPStan does not

Enabled by extension-installer, the extension used to report every lint rule
and ReDoS finding, and every invalid pattern a second time next to PHPStan's
own `regexp.pattern`. It now reports, by default, only a pattern the target PHP
refuses while the PHP running PHPStan compiles it, under
`regex.invalidForTarget`. Lint rules and ReDoS analysis are opt-in: include
`vendor/yoeunes/regex-parser/rules.neon`, or switch each on under `checks`.

The configuration is `phpVersion`, `pcreVersion` and `checks` only:

| removed | use instead |
|---|---|
| `reportRedos` | `checks.redos.enabled` |
| `redosThreshold` | `checks.redos.threshold` |
| `redosMode`, `checks.redos.mode` | nothing: ReDoS analysis in PHPStan is theoretical |
| `checks.redos.noJit` | nothing: PHPStan never runs a pattern |
| `suggestOptimizations` | `checks.optimizations.enabled` |
| `optimizationConfig` | `checks.optimizations.options` |
| `ignoreParseErrors` | nothing: a pattern PHP cannot compile is PHPStan's to report |

The identifiers `regex.syntax.invalid`, `regex.syntax.delimiter` and
`regex.syntax.empty` are gone (PHPStan reports those patterns as
`regexp.pattern`), and ReDoS findings are reported as `regex.redos` whatever
their severity, instead of `regex.redos.critical` and the like. Update
`ignoreErrors` and baselines accordingly. The rule takes
`new RegexParserRule(array $config = [], ?PhpVersion $phpVersion = null)`, and
its `IDENTIFIER_SYNTAX_*` and `IDENTIFIER_REDOS_<SEVERITY>` constants are gone:
`IDENTIFIER_INVALID_FOR_TARGET` and `IDENTIFIER_REDOS` name the new ones.

#### The pattern extractors moved, and one is renamed

The two ways of finding regex patterns in PHP source now sit together under
`RegexParser\Lint\Extraction`, with the interface they implement:

  - `RegexParser\Lint\ExtractorInterface` → `RegexParser\Lint\Extraction\ExtractorInterface`
  - `RegexParser\Lint\TokenBasedExtractionStrategy` → `RegexParser\Lint\Extraction\TokenBasedExtractionStrategy`
  - `RegexParser\Lint\PhpStanExtractionStrategy` → `RegexParser\Lint\Extraction\PhpParserExtractionStrategy`

The last one never had anything to do with PHPStan: it reads the source with
nikic/php-parser, which PHPStan happens to bring along. The old names are
gone: import the new ones.

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

The old names are gone: import the new ones.

#### What reads patterns takes a `RegexParser`

Reading and judging a pattern moved from the `Regex` facade into
`RegexParser`, which the rest of the library now takes instead of the facade:

  - `new ReDoSAnalyzer(?RegexParser $parser, ...)`
  - `new RegexSolver(?RegexParser $parser, ...)` and `RegexLanguageSolver::forRegex(RegexParser $parser, ...)`
  - `new RegexTranspiler(RegexParser $parser, ...)`
  - `new RegexAnalysisService(RegexParser $parser, ...)`, whose `getRegex()` is now `getParser()`

Pass `$regex->parser()` where you passed a `Regex`, or build one with
`RegexParser::create()`, which takes the options `Regex::create()` takes.
`CharSet` and `CharSetAnalyzer` moved from `RegexParser\ReDoS` to
`RegexParser\Analysis`.

#### A custom node implements `getChildren()`

`NodeInterface` gains `getChildren()`. The library's nodes extend
`AbstractNode`, which returns no children; a node of your own that holds
others returns them, in pattern order. Custom visitors should extend
`AbstractNodeVisitor`, never implement `NodeVisitorInterface` directly: a
new kind of node adds a method to the interface.

#### The cache stores trees, in memory by default

`CacheInterface` now takes and gives back the tree itself:

```php
public function write(string $key, RegexNode $ast): void;
public function load(string $key): ?RegexNode;
```

`getTimestamp()` is gone, and so are `CachePayloadDecoder`,
`FilesystemCache::defaultDirectory()` and the file extension argument of
`FilesystemCache`. A custom cache stores the tree as it likes and returns
`null` for anything that is not one.

Without a `cache` option, `Regex::create()` keeps the latest 1024 trees in
memory (`ArrayCache`) instead of writing files under the system temp
directory. Name a directory to keep trees on disk: `['cache' => '/path']`. A
filesystem cache creates it for its owner only and ignores it when another
user owns it or others can write to it; its files hold data, not PHP.

#### Cached ASTs are rebuilt

`Regex::CACHE_VERSION` is now a fingerprint of the code that builds a tree, so
entries written by 1.x are ignored and the patterns are parsed once more.
Nothing to do; a warm cache directory rebuilds itself.

#### Error codes are an `ErrorCode` enum

`RegexException::getErrorCode()` returns a `RegexParser\ErrorCode` and is never
`null`; `ValidationResult::$errorCode` and `getErrorCode()` are `?ErrorCode`.
The values of the codes 1.x already had are unchanged, so code comparing strings
moves to `->value` or, better, to the case:

```php
// 1.x
if ('regex.lookbehind.unbounded' === $result->errorCode) { /* ... */ }

// 2.0
if (ErrorCode::LookbehindUnbounded === $result->errorCode) { /* ... */ }
```

`parser.error`, `lexer.error` and `regex.semantic` are gone: each error they
covered has its own code now, such as `regex.charclass.unclosed`,
`regex.group.unclosed` or `regex.delimiter.unclosed`. The full list is in
[Diagnostics: Error Codes](docs/reference/diagnostics.md#error-codes).

The exceptions that judge a pattern take the code as their second, required
argument:

| before | after |
|---|---|
| `new RegexException($message, $position, $snippet, ?string $errorCode, $previous)` | `new RegexException($message, ErrorCode $errorCode, $position, $snippet, $previous)` |
| `new ParserException($message, $position, $pattern)` | `new ParserException($message, ErrorCode $errorCode, $position, $pattern)` |
| `new LexerException($message, $position, $pattern)` | `new LexerException($message, ErrorCode $errorCode, $position, $pattern)` |
| `ParserException::withContext($message, $position, $pattern)` | `ParserException::withContext($message, ErrorCode $errorCode, $position, $pattern)` |
| `LexerException::withContext($message, $position, $pattern)` | `LexerException::withContext($message, ErrorCode $errorCode, $position, $pattern)` |
| `new SemanticErrorException($message, $position, $pattern, $previous, $errorCode, $hint)` | `new SemanticErrorException($message, ErrorCode $errorCode, $position, $pattern, $previous, $hint)` |
| `TokenStream::consume($type, $message)`, `consumeLiteral($value, $message)` | take an `ErrorCode $code` as third argument |

`SyntaxErrorException`, `RecursionLimitException` and `ResourceLimitException`
follow `ParserException`. `ComplexityException`, `TranspileException` and
`SampleGenerationException` keep their parameter order and default their code,
so a call without one keeps working; a call that passed the code as a string
passes the `ErrorCode` case instead.

`regex.callout.invalid_type` is gone: no pattern could raise it.

The CLI's JSON output and `RegexProblem::$code` still carry the string value.

### Deprecated

#### `ClassOperationNode` and the class operation tokens

PHP compiles patterns without PCRE2's extended class syntax, so inside a
character class `&&` is two `&` members and `--` a range through `-`:
`[a&&b]` matches `&`, and `[a--b]` is refused as a range out of order. The
parser now reads them that way and never builds a `ClassOperationNode`.

These stay for now and are removed before 2.0.0:

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
included; a delimiter error reports none. 2.0.0 reports every offset relative
to the body, the coordinate PCRE2 uses. Code that places a caret under flag
errors will need to shift it by the length of the opening delimiter.

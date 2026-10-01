# RegexParser Architecture

This document explains how RegexParser works under the hood. It is written for future maintainers and contributors who want to understand the AST, the parsing pipeline, and the analysis algorithms.

## Pipeline Overview

RegexParser treats a regex literal as structured input:

- `PatternParser` splits the literal into pattern and flags.
- The lexer builds a `TokenStream` with byte offsets.
- The parser builds a `RegexNode` AST.
- Visitors walk the AST to validate, explain, analyze, or transform.

## Step 1: Parse the Regex Literal

`Regex::parse()` accepts a full PCRE literal (`/pattern/flags`). Internally, the literal is split into:

- Pattern body (the text between delimiters)
- Delimiter (the chosen boundary character)
- Flags (`i`, `m`, `s`, `u`, `x`, and more)

This happens in `PhpRegex\Parser\Internal\PatternParser`. The output is then passed to the lexer.

## Step 2: Lexer (Tokenization)

`src/Parser/Lexer.php` scans the pattern body as bytes and emits tokens with start/end offsets. The lexer is stateful because PCRE syntax changes meaning depending on context. The main states include:

- Default text
- Character class (`[...]`)
- Quoted literal blocks (`\Q...\E`)
- Comment blocks (`(?#...)`)

The lexer output is a `TokenStream`, which is a linear sequence of `Token` objects. Tokens are positional, and offsets are byte-based so diagnostics line up with the original string.

### Lexer Contexts and Tunnel Modes

The lexer maintains explicit state flags:

- `inCharClass` switches to the character-class token set until a closing `]`.
- `inQuoteMode` treats everything as literal until `\E`.
- `inCommentMode` consumes `(?# ... )` as literal content until `)`.

Quote mode is allowed to run to end-of-pattern (PCRE treats `\Q` without `\E` as valid). Comment mode and character classes must be closed, or the lexer raises a `LexerException` in `validateFinalState()`.

### Token Priority and Matching

Tokens are matched by compiling two prioritized token maps into a single regex:

- `TOKENS_OUTSIDE` for normal parsing
- `TOKENS_INSIDE` for character-class parsing

At each position, the lexer runs the compiled pattern with an anchored match (`/A`) to find the next token. Context-sensitive literals are adjusted after matching; for example:

- `^` at the start of a class becomes `T_NEGATION`
- `-` within a class becomes `T_RANGE`

This keeps lexing fast and deterministic while preserving byte offsets.

## Step 3: Parser (Recursive Descent)

`src/Parser/Syntax/TokenParser.php` is a handwritten recursive descent parser. It walks the `TokenStream` and builds an AST that reflects PCRE precedence:

- Atoms (literals, classes, groups)
- Quantifiers
- Concatenation (sequence)
- Alternation (`|`)

The parser entry point is `parse()`, which delegates to smaller methods such as:

- `parseAlternation()`
- `parseSequence()`
- `parseQuantifiedAtom()`
- `parseAtom()`

Errors raised here become `SyntaxErrorException` or `SemanticErrorException` and include byte offsets for IDE integration.

### Parser Precedence and Flow

The parser implements precedence by control flow:

- `parseAlternation()` splits on `|` and builds `AlternationNode` only when multiple branches exist.
- `parseSequence()` consumes consecutive items until it hits `|` or `)`, building a `SequenceNode`.
- `parseQuantifiedAtom()` parses one atom, then checks for a trailing quantifier and wraps it in a `QuantifierNode`.
- `parseAtom()` delegates to specialized handlers for groups, character classes, verbs, assertions, and literals.

Extended mode (`/x`) is handled inside `parseSequence()` via `consumeExtendedModeContent()`. Whitespace and inline comments become nodes where necessary so the compiler can round-trip the original pattern.

## Step 4: AST Structure

Every node:

- Is immutable (`readonly`)
- Holds `startPosition` and `endPosition` byte offsets
- Implements `NodeInterface::accept()`

The root node is `RegexNode`, which wraps the parsed pattern and flags.

Example AST shape:

```
Pattern: /^(?<email>\w+@\w+\.\w+)$/

RegexNode
└── SequenceNode
    ├── AnchorNode("^")
    ├── GroupNode(name: email)
    │   └── SequenceNode
    │       ├── QuantifierNode("+") -> CharTypeNode("\\w")
    │       ├── LiteralNode("@")
    │       ├── QuantifierNode("+") -> CharTypeNode("\\w")
    │       ├── LiteralNode(".")
    │       └── QuantifierNode("+") -> CharTypeNode("\\w")
    └── AnchorNode("$")
```

Node definitions live in `src/Parser/Node/`. The full node reference is in [docs/nodes/README.md](nodes/README.md).

## Step 5: Visitors and Traversal

Visitors encapsulate behavior. Each node calls the correct method on the visitor, enabling double-dispatch:

```
$node->accept($visitor)
  -> $visitor->visitXxx($node)
```

Built-in visitors live in the package that owns their concern, and include:

- `Parser\Validation\Validator` (`src/Parser/Validation/`)
- `Explain\TextExplainer` (`src/Explain/`)
- `Parser\Printer\PatternPrinter` (`src/Parser/Printer/`)
- `Redos\RedosProfiler` (`src/Redos/`)
- `Optimizer\Rewriter` (`src/Optimizer/`)

Traversal details are in [docs/design/AST_TRAVERSAL.md](design/AST_TRAVERSAL.md).

## Diagnostics and Validation

Validation runs against the AST and produces structured errors. `Regex::validate()` returns a `ValidationResult` containing:

- `isValid()`
- Error message and hint
- Byte offset and caret snippet

Diagnostics codes and explanations are documented in [docs/reference/diagnostics.md](reference/diagnostics.md).

## ReDoS Analysis (Static)

ReDoS analysis uses the AST and never executes the regex. `RedosAnalyzer` builds a `RedosProfiler` with a `CharSetAnalyzer`, then walks the tree.

Core heuristics include:

- Nested unbounded quantifiers (star height > 1)
- Overlapping alternation branches inside repetition
- Backreference loops within unbounded quantifiers
- Quantifiers that repeat empty-match subpatterns
- Adjacent quantified tokens with overlapping character sets
- Large bounded quantifiers (low risk, but flagged)
- Atomic groups and possessive quantifiers lowering severity

Analysis results are wrapped in `RedosAnalysis` and include severity, findings, and suggested rewrites. See [docs/REDOS_GUIDE.md](REDOS_GUIDE.md) for user-facing guidance.

### ReDoS Heuristics in Practice

`RedosProfiler` tracks quantifier depth and atomic context while walking the AST:

- `unboundedQuantifierDepth` and `totalQuantifierDepth` model star height and nesting.
- Atomic groups (`(?>...)`) and possessive quantifiers (`*+`, `++`, `{m,n}+`) toggle `inAtomicGroup`, which reduces or avoids severity.
- `CharSetAnalyzer` compares alternation branches to detect overlap inside repetition.
- Backreference loops inside unbounded quantifiers are flagged as high risk.

Findings are collected as `Finding` objects, and the visitor records a `culpritNode` and `hotspots` so the CLI can highlight where the risk originates.

## CLI Lint Pipeline

The CLI linter runs in two stages:

1. Extract patterns from files into `PatternOccurrence` entries.
2. Analyze and format the results into a `LintReport` (console/json/github).

When `--jobs` is used and `pcntl_fork` is available, both extraction and analysis run in parallel workers. Each worker handles a chunk of files or patterns, and the parent process aggregates results.

## Layers

The code falls into layers, each allowed to use only those below it, and
[deptrac](https://github.com/deptrac/deptrac) checks the rule on every change
(`composer deptrac`):

| layer | what it holds | may use |
|---|---|---|
| core | lexer, parser, nodes, validation, `RegexParser`, `PcreTarget`, cache, the shared tree analyses | nothing |
| explain | explanations, highlighting, diagrams | core |
| optimizer | `Optimizer` and the optimizing visitors | core, automata |
| generator | sample and test-case generation | core |
| automata | the solver: equivalence, intersection, subset | core |
| ReDoS | ReDoS analysis | core |
| transpiler | JavaScript and Python output | core |
| linter | lint rules, pattern extraction, reports | core, explain, optimizer, automata, ReDoS |
| toolkit | the `Regex` facade | every library above |
| CLI, language server, bridges | applications and integrations | the toolkit and the libraries |

What reads a pattern below the facade takes a `RegexParser`; `Regex::parser()`
gives the one a facade uses.

## Caching and Limits

RegexParser can cache ASTs via `CacheInterface`. By default it keeps the latest 1024 trees in memory (`ArrayCache`); nothing is written to disk unless a directory is named with `cache => '/path'` or a `FilesystemCache`. A filesystem cache stores data, never code, in a directory it creates for its owner only (`0700`), and ignores a directory another user owns or others can write to. Shared caches go through the PSR-6 and PSR-16 adapters. You can disable caching with `cache => null` in `Regex::create()` options.

Limits are enforced in `ParserOptions`:

- `max_pattern_length`
- `max_lookbehind_length` (variable-length lookbehinds; a fixed-length one is only limited by PCRE's 65535)
- `max_recursion_depth`
- `php_version` and `pcre_version`, resolved once into a `PcreTarget` that the
  lexer, the parser, the validator and the cache key read

## Extension Points

When you add a new PCRE construct, you typically update:

- `src/Parser/Node/*` to define a node
- `src/Parser/Syntax/TokenParser.php` and `src/Parser/Lexer.php` to recognize syntax
- `src/Parser/NodeVisitorInterface.php` and every visitor that implements it, to support traversal
- Tests and fixtures for valid/invalid cases

See [docs/EXTENDING_GUIDE.md](EXTENDING_GUIDE.md) for the full workflow.

---

Previous: [Cookbook](COOKBOOK.md) | Next: [Maintainers Guide](MAINTAINERS_GUIDE.md)
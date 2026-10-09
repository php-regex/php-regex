---
description: "What an AST is and why PHPRegex parses a pattern into one node per character: structure, precise error reporting, and the tooling it enables."
---
# What is an AST?

**AST** stands for **Abstract Syntax Tree** - it's a structured representation of your regex pattern that makes it easier to analyze and understand.

## Simple explanation

Imagine you have a regex pattern like `/^hello\d+$/`. To a computer, this is just a string. But to understand what it means, we need to break it down:

```
String: "/^hello\d+$/"

AST structure:
RegexNode
├── SequenceNode
│   ├── AnchorNode ("^")
│   ├── LiteralNode ("h")
│   ├── LiteralNode ("e")
│   ├── LiteralNode ("l")
│   ├── LiteralNode ("l")
│   ├── LiteralNode ("o")
│   ├── QuantifierNode ("+")
│   │   └── CharTypeNode ("\\d")
│   └── AnchorNode ("$")
```

The parser emits **one `LiteralNode` per character**: the text `hello` is five nodes, and runs of literals are never merged. This is a structural invariant the whole library builds on - the literal extractor, the length calculator and any visitor you write all see `h`, `e`, `l`, `l`, `o` as separate, position-carrying nodes.

## Why use an AST?

### Before AST (string-based analysis)

```php
// Old way: String manipulation
$pattern = '/^hello\d+$/';
if (strpos($pattern, 'hello') !== false) {
    // This is unreliable - what if 'hello' is escaped?
}
```

### After AST (structured analysis)

```php
use PHPRegex\Toolkit\Regex;

// New way: Precise analysis
$ast = Regex::create()->parse('/^hello\d+$/');
$sequence = $ast->pattern; // Exact structure known
$literal = $sequence->children[1]; // the first literal character, 'h'
```

## AST components

### Root node
- **RegexNode**: Contains the entire pattern and flags

### Pattern nodes
- **SequenceNode**: Contains ordered elements (for example, `hello` then `\d+`).
- **AlternationNode**: Contains choices (for example, `a|b`).
- **GroupNode**: Contains sub-patterns (for example, `(hello)`).

### Element nodes
- **LiteralNode**: A single literal character - one node per character, so `hello` is five nodes.
- **CharTypeNode**: Shorthand character classes (for example, `\d`, `\w`).
- **CharClassNode**: Custom character sets (for example, `[a-z]`).
- **QuantifierNode**: Repetition (for example, `+`, `*`, `{2,4}`).
- **AnchorNode**: Position markers (for example, `^`, `$`).

## Visualizing ASTs

Use the CLI to see the AST structure:

```bash
# Show AST diagram
vendor/bin/regex diagram '/^hello\d+$/'

# Parse and recompile
vendor/bin/regex parse '/^hello\d+$/'
```

The `vendor/bin/regex` binary ships with the [pre-release monorepo](../quick-start.md#installation); from the 2.0.0 release it is its own package, `php-regex/regex-cli`. The `diagram` command prints the one-node-per-character structure verbatim:

```
Regex
\-- Sequence
    |-- Anchor (^)
    |-- Literal ('h')
    |-- Literal ('e')
    |-- Literal ('l')
    |-- Literal ('l')
    |-- Literal ('o')
    |-- Quantifier (+, greedy)
    |   \-- CharType (\d)
    \-- Anchor ($)
```

## Real-world benefits

### 1. Precise Error Reporting

```php
use PHPRegex\Parser\Exception\RegexException;
use PHPRegex\Toolkit\Regex;

// Exact error location
try {
    $ast = Regex::create()->parse('/[unclosed/');
} catch (RegexException $e) {
    echo "Error at position: " . $e->getPosition() . "\n";
    echo "Snippet: " . $e->getSnippet() . "\n";
}
```

Catch `RegexException`, the shared parent of every parse failure: an unclosed character class is reported by the lexer (`LexerException`), a missing closing delimiter by the parser (`ParserException`) - catching only one of the two lets the other escape. Both carry `getPosition()` and `getSnippet()`. The sample above prints:

```
Error at position: 9
Snippet: Line 1: [unclosed
                 ^
```

### 2. Pattern Transformation
```php
use PHPRegex\Toolkit\Regex;

// Safely modify patterns
$ast = Regex::create()->parse('/\d{3}-\d{4}/');
// You can now manipulate the AST nodes precisely
```

### 3. Complex Analysis
```php
use PHPRegex\Toolkit\Regex;

// Detect potential ReDoS risk
$analysis = Regex::create()->redos('/(a+)+$/');
echo $analysis->severity->value; // critical
// AST allows deep structural analysis
```

## Related concepts

- **[Understanding Visitors](visitors.md)** - How visitors process ASTs
- **[Nodes Reference](../nodes/README.md)** - Every node type and its fields
- **[Architecture](../architecture.md)** - How PHPRegex builds ASTs
- **[AST Traversal Design](../design/ast-traversal.md)** - How trees are processed

# What is an AST?

**AST** stands for **Abstract Syntax Tree** - it's a structured representation of your regex pattern that makes it easier to analyze and understand.

## Simple explanation

Imagine you have a regex pattern like `/^hello\d+$/`. To a computer, this is just a string. But to understand what it means, we need to break it down:

```
String: "/^hello\d+$"

AST structure:
RegexNode
├── SequenceNode
│   ├── AnchorNode ("^")
│   ├── LiteralNode ("hello")
│   ├── QuantifierNode ("+")
│   │   └── CharTypeNode ("\\d")
│   └── AnchorNode ("$")
```

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
// New way: Precise analysis
$ast = Regex::create()->parse('/^hello\d+$/');
$sequence = $ast->pattern; // Exact structure known
$literal = $sequence->children[1]; // The "hello" literal
```

## AST components

### Root node
- **RegexNode**: Contains the entire pattern and flags

### Pattern nodes
- **SequenceNode**: Contains ordered elements (for example, `hello` then `\d+`).
- **AlternationNode**: Contains choices (for example, `a|b`).
- **GroupNode**: Contains sub-patterns (for example, `(hello)`).

### Element nodes
- **LiteralNode**: Fixed text (for example, `hello`).
- **CharTypeNode**: Shorthand character classes (for example, `\d`, `\w`).
- **CharClassNode**: Custom character sets (for example, `[a-z]`).
- **QuantifierNode**: Repetition (for example, `+`, `*`, `{2,4}`).
- **AnchorNode**: Position markers (for example, `^`, `$`).

## Visualizing ASTs

Use the CLI to see the AST structure:

```bash
# Show AST diagram
bin/regex diagram '/^hello\d+$/'

# Parse and analyze
bin/regex parse '/^hello\d+$/' --ast
```

## Real-world benefits

### 1. Precise Error Reporting
```php
// Exact error location
try {
    $ast = Regex::create()->parse('/[unclosed/');
} catch (ParserException $e) {
    echo "Error at position: " . $e->getPosition();
    echo "Snippet: " . $e->getSnippet();
}
```

### 2. Pattern Transformation
```php
// Safely modify patterns
$ast = Regex::create()->parse('/\d{3}-\d{4}/');
// You can now manipulate the AST nodes precisely
```

### 3. Complex Analysis
```php
// Detect potential ReDoS risk
$analysis = Regex::create()->redos('/(a+)+$/');
// AST allows deep structural analysis
```

## Related concepts

- **[Understanding Visitors](visitors.md)** - How visitors process ASTs
- **[Architecture](../ARCHITECTURE.md)** - How PhpRegex builds ASTs
- **[Nodes Reference](../nodes/README.md)** - Node type reference

## Further reading

- [PhpRegex Architecture](../ARCHITECTURE.md) - Technical deep dive
- [AST Traversal Design](../design/AST_TRAVERSAL.md) - How trees are processed
- [Nodes Reference](../nodes/README.md) - All available node types

---

Previous: [Concepts Home](README.md) | Next: [Understanding Visitors](visitors.md)

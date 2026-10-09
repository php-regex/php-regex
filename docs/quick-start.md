---
redirect_from:
  - /QUICK_START/
  - /QUICK_START.html
---
# Quick Start Guide

This guide gets you from installation to a first analysis in a few steps. It is intentionally brief; the tutorial covers concepts in depth.

This is the fast hands-on tour: every step is a command or snippet you can run as-is. If you are completely new to regex, the [tutorial](tutorial/README.md) is the place to start; it assumes nothing. If you already know regex, you can jump to [Advanced Features](#advanced-features).

## What This Guide Covers

- Install PHPRegex.
- Use the CLI for quick analysis.
- Parse and validate patterns in PHP.
- Explain patterns in plain English.
- Check a pattern for ReDoS, and get the input that triggers it.
- Build custom analysis tools.

## Installation

```bash
composer require php-regex/regex-toolkit
composer require --dev php-regex/regex-cli
```

`regex-toolkit` is the parser and the PHP API. `regex-cli` is optional and installs the `vendor/bin/regex` binary used throughout this guide.

If you want to experiment without installing, use <https://regex101.com> in PCRE2 mode.

## How PHPRegex Works (Short Version)

- The literal is split into pattern and flags.
- The lexer emits a token stream.
- The parser builds an AST.
- Visitors walk the AST to validate, explain, analyze, or transform.

You do not need these details to use the API. For background, see [What is an AST?](concepts/ast.md).

## CLI Quick Start

The CLI gives you direct feedback. Try these commands:

```bash
# 1. Explain a pattern in plain English
vendor/bin/regex explain '/\d{4}-\d{2}-\d{2}/'

# 2. Visualize the pattern structure
vendor/bin/regex diagram '/\d{4}-\d{2}-\d{2}/'

# 3. Check for ReDoS: a proven verdict and, when vulnerable, the attack
vendor/bin/regex analyze '/(a+)+$/'

# 4. Colorize the pattern for better readability
vendor/bin/regex highlight '/\d{4}-\d{2}-\d{2}/'

# 5. Lint your entire codebase
vendor/bin/regex lint src/
```

Example output:
```
$ vendor/bin/regex explain '/\d{4}-\d{2}-\d{2}/' --no-visuals
Regex matches
    Character Type: A digit: [0-9] (exactly 4 times)
  '-'
    Character Type: A digit: [0-9] (exactly 2 times)
  '-'
    Character Type: A digit: [0-9] (exactly 2 times)
```

## Comparing Patterns

PHPRegex can compare two patterns as mathematical sets of strings.

```bash
# Intersection: do the patterns overlap?
vendor/bin/regex compare '/edit/' '/[a-z]+/'

# Subset: is pattern 1 fully contained in pattern 2?
vendor/bin/regex compare '/edit/' '/[a-z]+/' --method=subset

# Equivalence: do both patterns accept the same strings?
vendor/bin/regex compare '/[0-9]+/' '/\d+/' --method=equivalence
```

## PHP API: Five Essential Operations

### 1. Parse a pattern (turn regex into structured data)

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
$ast = $regex->parse('/\d{3}-\d{4}/');

// Now you have a structured AST (Abstract Syntax Tree)
// You can analyze, transform, or validate it
```

Use when you need to understand or analyze pattern structure.

Learn more: [What is an AST?](concepts/ast.md)

### 2. Validate a pattern (check for errors)

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
$result = $regex->validate('/(?<year>\d{4})-(?<month>\d{2})/');

if ($result->isValid) {
    echo "Pattern is valid.\n";
    echo "Complexity score: " . $result->getComplexityScore() . "\n";
} else {
    echo "Error: " . $result->error . "\n";
    echo "Hint: " . $result->getHint() . "\n";
}
```

Checks performed:
- Syntax errors (missing brackets, invalid escapes)
- Invalid backreferences
- Variable-length lookbehinds
- Invalid Unicode properties

### 3. Explain a pattern (get a plain English description)

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
$explanation = $regex->explain('/(?<email>\w+@\w+\.\w+)/');

echo $explanation;
```

**Example output:**
```
Regex matches
  Capturing group (named: 'email')
        Character Type: A word character: [a-zA-Z_0-9] (one or more times)
    '@'
        Character Type: A word character: [a-zA-Z_0-9] (one or more times)
    '.'
        Character Type: A word character: [a-zA-Z_0-9] (one or more times)
  End group
```

Use when documenting patterns, doing code reviews, or teaching regex.

### 4. Check for ReDoS (theoretical by default)

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Redos\RedosMode;

$regex = Regex::create();

// A vulnerable pattern: the verdict is proven, and comes with the attack
$analysis = $regex->redos('/(a+)+b/');
echo $analysis->severity->value, "\n";   // critical
echo $analysis->headline(), "\n";        // Exponential backtracking (proven)
echo $analysis->witness->render(), "\n"; // "a" x n . "!b"

// A safe pattern, proven safe
$analysis = $regex->redos('/a+b/');
echo $analysis->headline(), "\n";        // safe (proven)

// Optional: replay the attack on the running PCRE
$confirmed = $regex->redos('/(a+)+b/', mode: RedosMode::Confirmed);
echo $confirmed->isConfirmed() ? "confirmed\n" : "theoretical\n"; // confirmed
```

ReDoS (Regular Expression Denial of Service) is a performance risk where certain inputs can make a backtracking engine take a very long time; in PHP, `preg_match()` then gives up and returns `false`. `validate()` does not check for it: call `redos()`. The [ReDoS guide](guides/redos.md) explains the verdicts.

Learn more: [ReDoS Deep Dive](concepts/redos.md)

### 5. Highlight patterns (make regex readable)

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;

$regex = Regex::create();
$ast = $regex->parse('/^[0-9]+(\w+)$/');

$consoleOutput = $ast->accept(new ConsoleHighlighter());
echo $consoleOutput;
```

This is useful for documentation and reviews.

## Practical Use Cases

### 1. Parse and Understand Complex Patterns

```php
$regex = Regex::create();
$ast = $regex->parse('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/');

// Now you can analyze the email validation pattern
```

### 2. Validate User Input Patterns

```php
$regex = Regex::create();
$userPattern = $_POST['regex_pattern'];

$result = $regex->validate($userPattern);
if (!$result->isValid) {
    die("Invalid pattern: " . $result->error);
}
```

### 3. Document Your Regex Patterns

```php
function documentPattern(string $pattern, string $description): void
{
    $regex = Regex::create();
    $explanation = $regex->explain($pattern);
    
    echo "### $description\n";
    echo "Pattern: $pattern\n";
    echo "Explanation: $explanation\n";
}

documentPattern('/\d{4}-\d{2}-\d{2}/', 'Date format');
```

### 4. Find ReDoS in Your Codebase

```bash
# Scan your entire project
vendor/bin/regex lint src/ --redos --no-lint --no-optimize
```

### 5. Generate Test Data

```php
$regex = Regex::create();
$sample = $regex->generate('/\d{3}-[A-Z]{2}/');
echo $sample;  // Example: "123-AB"
```

### 6. Optimize Patterns

```php
$regex = Regex::create();
$literals = $regex->literals('/prefix-\d+-suffix/');

// Extract fixed parts for optimization
$prefix = $literals->literalSet->getLongestPrefix();
$suffix = $literals->literalSet->getLongestSuffix();
```

### 7. Build Custom Analysis Tools

```php
// Create a visitor to count quantifiers
// AbstractTraversingVisitor visits the children of every node you do not override
class QuantifierCounter extends \PHPRegex\Parser\AbstractTraversingVisitor
{
    private int $count = 0;

    public function visitQuantifier(\PHPRegex\Parser\Node\QuantifierNode $node)
    {
        $this->count++;

        return parent::visitQuantifier($node);
    }

    public function getCount(): int { return $this->count; }
}

$regex = Regex::create();
$ast = $regex->parse('/a+b*c?/');
$counter = new QuantifierCounter();
$ast->accept($counter);
echo "Quantifiers: " . $counter->getCount(); // "3"
```

Learn more: [Understanding Visitors](concepts/visitors.md)

---

## Common Pattern Examples

### Email Validation

```php
$pattern = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/';
$result = $regex->validate($pattern);
```

### URL Matching

```php
$pattern = '/^https?:\/\/(www\.)?[-a-zA-Z0-9@:%._\+~#=]{1,256}\.[a-zA-Z0-9()]{1,6}\b([-a-zA-Z0-9()@:%_\+.~#?&\/\/=]*)$/';
$result = $regex->validate($pattern);
```

### Phone Number (US)

```php
$pattern = '/^\+?1?\s*\(?([0-9]{3})\)?\s*-?\s*([0-9]{3})\s*-?\s*([0-9]{4})$/';
$result = $regex->validate($pattern);
```

### Date (YYYY-MM-DD)

```php
$pattern = '/^(?<year>\d{4})-(?<month>0[1-9]|1[0-2])-(?<day>0[1-9]|[12][0-9]|3[01])$/';
$result = $regex->validate($pattern);
```

---

## ⚠️ Error Handling

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\Exception\ParserException;

$regex = Regex::create();

try {
    $ast = $regex->parse('/invalid[/');  // Unclosed character class
} catch (ParserException $e) {
    echo "Parse error: " . $e->getMessage() . "\n";
    echo "Position: " . $e->getPosition() . "\n";
    echo "Snippet:\n" . $e->getSnippet() . "\n";
}
```

---

## ⚡ Performance Tips

1. **Parse Once, Reuse AST**: Don't re-parse the same pattern repeatedly
2. **Validate Early**: Check patterns during development, not in production
3. **Cache Results**: Store validated patterns and analysis results
4. **Reuse Regex Instance**: Create one `Regex` instance and reuse it
5. **Avoid Complex Patterns**: Simple patterns parse faster

---

## Advanced Features

### Working with Named Groups

```php
$regex = Regex::create();
$ast = $regex->parse('/(?<first>\w+)\s+(?<last>\w+)/');

// AST contains named group information
// Use PatternPrinter to regenerate pattern
// Or custom visitor to extract group names
```

### Conditional Patterns

```php
$pattern = '/(a)(?(1)b|c)/';  // If group 1 matches, then 'b', else 'c'
$result = $regex->validate($pattern);
```

### Recursion

```php
$pattern = '/\((?:[^()]|(?R))*\)/';
$result = $regex->validate($pattern);
```

### Atomic Groups (Performance)

```php
$pattern = '/(?>a+)b/';
$result = $regex->validate($pattern);
```

### Possessive Quantifiers (Performance)

```php
$pattern = '/a++b/';
$result = $regex->validate($pattern);
```

---

## Next Steps

Now that you've seen what PHPRegex can do, here's where to go next:

For beginners:
- [Learn Regex from Scratch](tutorial/README.md)
- [Regex in PHP Guide](guides/regex-in-php.md)

For users:
- [CLI Guide](guides/cli.md)
- [Cookbook](cookbook.md)
- [ReDoS Guide](guides/redos.md)

For developers:
- [Architecture](architecture.md)
- [AST Reference](nodes/README.md)
- [Visitors Guide](visitors/README.md)
- [Extending Guide](extending.md)

Reference:
- [API Reference](reference/api.md)
- [Diagnostics](reference/diagnostics.md)
- [FAQ & Glossary](reference/faq-glossary.md)

## Getting Help

- Issues and bug reports: <https://github.com/php-regex/php-regex/issues>
- Real-world examples: see `tests/Integration/`
- Interactive playground: <https://regex101.com> (PCRE2 mode)

---
description: "Install PHPRegex and run a first analysis in a few runnable steps: parse and validate patterns, explain them, and get a proven ReDoS verdict."
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

Requires PHP 8.2 or later and the `mbstring` extension (PCRE ships with PHP).

{% include install-prerelease.html package="php-regex/regex-toolkit" %}

`regex-toolkit` is the parser and the PHP API. The monorepo install above also provides the `vendor/bin/regex` binary used throughout this guide; from the 2.0.0 release, the CLI is its own package (`composer require --dev php-regex/regex-cli`).

No Composer? The CLI ships as a self-contained PHAR — but the `latest` release
channel still serves the 1.x CLI (`RegexParser 1.3.0`), which knows none of the
commands this guide shows. Until the 2.0.0 tag publishes the 2.x PHAR, use the
monorepo install above; the curl below will carry the 2.x CLI from that tag:

```bash
curl -Ls https://github.com/php-regex/php-regex/releases/latest/download/regex.phar \
  -o ~/.local/bin/regex
chmod +x ~/.local/bin/regex
```
{: data-copy="" }

To explore what a pattern matches without installing anything, use <https://regex101.com> in PCRE2 mode — for the semantics. Proven verdicts, witnesses and equivalence stay PHPRegex's job: `vendor/bin/regex analyze '/(a+)+$/'`.

## How PHPRegex Works (Short Version)

You do not need the pipeline details to use the API: the literal is split from its flags, lexed, parsed into an immutable AST, and visitors walk that AST to validate, explain, analyze, or transform. For background, see [What is an AST?](concepts/ast.md).

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
    echo "Complexity score: " . $result->complexityScore . "\n";
} else {
    echo "Error: " . $result->error . "\n";
    echo "Hint: " . $result->hint . "\n";
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

## Common Pattern Examples

Each block assumes the primer below — copy it once, then any block works on its own:

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
```

### Email Validation

```php
$pattern = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/';
$result = $regex->validate($pattern);
```

ReDoS verdict: `safe (proven)`.

### URL Matching

```php
$pattern = '/^https?:\/\/(www\.)?[-a-zA-Z0-9@:%._\+~#=]{1,256}\.[a-zA-Z0-9()]{1,6}\b([-a-zA-Z0-9()@:%_\+.~#?&\/\/=]*)$/';
$result = $regex->validate($pattern);
```

ReDoS verdict: `Polynomial backtracking, degree 2 (proven)` — severity `medium`. Valid,
but the two bounded character classes backtrack against each other; run it through
`vendor/bin/regex analyze` yourself before trusting it on hot paths.

### Phone Number (US)

```php
$pattern = '/^\+?1?\s*\(?([0-9]{3})\)?\s*-?\s*([0-9]{3})\s*-?\s*([0-9]{4})$/';
$result = $regex->validate($pattern);
```

ReDoS verdict: `Polynomial backtracking, degree 2 (proven)` — severity `medium`, for the
same reason: the optional separators backtrack. The [cookbook](cookbook.md) shows the
same shapes rewritten to `safe (proven)`.

### Date (YYYY-MM-DD)

```php
$pattern = '/^(?<year>\d{4})-(?<month>0[1-9]|1[0-2])-(?<day>0[1-9]|[12][0-9]|3[01])$/';
$result = $regex->validate($pattern);
```

ReDoS verdict: `safe (proven)`.

## Error Handling

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\Exception\ParserException;

$regex = Regex::create();

try {
    $ast = $regex->parse('/(?P<1x>a)/');  // Invalid group name
} catch (ParserException $e) {
    echo "Parse error: " . $e->getMessage() . "\n";
    echo "Position: " . $e->getPosition() . "\n";
    echo "Snippet:\n" . $e->getSnippet() . "\n";
}
```

## Performance Tips

1. **Parse Once, Reuse AST**: Don't re-parse the same pattern repeatedly
2. **Validate Early**: Check patterns during development, not in production
3. **Cache Results**: Store validated patterns and analysis results
4. **Reuse Regex Instance**: Create one `Regex` instance and reuse it
5. **Avoid Complex Patterns**: Simple patterns parse faster

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

## Next Steps

Now that you've seen what PHPRegex can do, here's where to go next:

For tool authors:
- [PHPStan Guide](guides/phpstan.md) - Regex findings inside static analysis
- [Psalm Guide](guides/psalm.md) - `$matches` typed from the pattern
- [Rector Guide](guides/rector.md) - `preg_*` calls rewritten when provably equal
- [Architecture](architecture.md)
- [Extending Guide](extending.md)

For app developers:
- [Laravel Guide](guides/laravel.md) and [Symfony Guide](guides/symfony.md)
- [CLI Guide](guides/cli.md) - Lint your code base in CI
- [Cookbook](cookbook.md)
- [ReDoS Guide](guides/redos.md)

New to regex:
- [Learn Regex from Scratch](tutorial/README.md)
- [Regex in PHP Guide](guides/regex-in-php.md)

Reference:
- [API Reference](reference/api.md)
- [Diagnostics](reference/diagnostics.md)
- [FAQ & Glossary](reference/faq-glossary.md)

## Getting Help

Issues, real-world examples and the playground are listed on the [documentation home page](README.md#getting-help).

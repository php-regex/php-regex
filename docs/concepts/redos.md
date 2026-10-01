# ReDoS Deep Dive

**ReDoS** (Regular Expression Denial of Service) is a security vulnerability where specially crafted input can cause a regex engine to take exponential time to process, potentially crashing your application.

## Simple explanation

Imagine you have a regex pattern like `/(a+)+b/` and someone gives you this input: `"aaaaa!"`. The regex engine tries many different ways to match this pattern, and with certain inputs it can take a very long time to conclude there is no match.

## How ReDoS happens

### The Problem: Backtracking

PCRE engines use **backtracking** to try different matching paths. When a pattern has multiple ways to match the same input, the engine explores all possibilities.

```
Pattern: /(a+)+b/
Input:   "aaaaa!"

The engine tries:
- (a+)+ matches "a" 5 times, then fails on "b"
- Backtrack: (a+)+ matches "a" 4 times, then "a" 1 time, then fails on "b"
- Backtrack: (a+)+ matches "a" 3 times, then "a" 2 times, then fails on "b"
- ... and so on, trying many combinations
```

### Risky Pattern Shapes

PHPRegex detects these common problematic patterns:

1. **Nested unbounded quantifiers**: `(a+)+`, `(.*)*`
2. **Overlapping alternation**: `(a|aa)+`, `(a|ab)+`
3. **Backreference loops**: `(\w+)\1+`
4. **Empty-match repetition**: `(a?)+`, `(a*)*`
5. **Ambiguous adjacent quantifiers**: `a+a+`, `(\w+)(\w+)`

## How PHPRegex detects ReDoS

PHPRegex analyzes the AST without executing the pattern:

- The lexer and parser build a `RegexNode` AST.
- `RedosProfiler` walks the tree.
- The result is a `RedosAnalysis` with severity, findings, and hints.

### Detection Methods

1. **Star-height detection**: Counts nested unbounded quantifiers
2. **Alternation overlap**: Uses `CharSetAnalyzer` to find overlapping choices
3. **Backreference loops**: Detects self-referencing groups in repetition
4. **Empty-match detection**: Finds quantifiers over optional patterns
5. **Atomic group mitigation**: Reduces severity for patterns using `(?>...)`

## Using PHPRegex for ReDoS protection

### CLI Usage

```bash
# Check a single pattern
bin/regex analyze '/(a+)+$/'

# Scan your entire codebase for ReDoS risk
bin/regex lint src/ --redos --no-lint --no-optimize
```

### PHP API

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Redos\RedosSeverity;

$regex = Regex::create();

// Basic analysis
$analysis = $regex->redos('/(a+)+b/');
echo $analysis->severity->value; // 'critical', 'high', 'medium', 'low', 'safe'

// Check against threshold
if ($analysis->exceedsThreshold(RedosSeverity::High)) {
    echo "Pattern is potentially dangerous!";
}

// Get recommendations
foreach ($analysis->recommendations as $recommendation) {
    echo "Suggestion: " . $recommendation . "\n";
}
```

## Fixing vulnerable patterns

### 1. Use Possessive Quantifiers

```
Vulnerable: /(a+)+b/
Safer:      /a++b/
```

Possessive quantifiers (`*+`, `++`, `?+`, `{m,n}+`) don't backtrack.

### 2. Use Atomic Groups

```
Vulnerable: /(a+)+b/
Safer:      /(?>a+)b/
```

Atomic groups `(?>...)` commit to the first successful match.

### 3. Simplify Nested Repeats

```
Vulnerable: /(a+)+b/
Equivalent: /a+b/
```

Often, nested quantifiers can be simplified.

### 4. Avoid Empty-Match Repetition

```
Vulnerable: /(a?)+/
Safer:      /a*/
Safer:      /a+/   (if empty should not match)
```

### 5. Avoid Ambiguous Adjacent Quantifiers

```
Vulnerable: /a+a+/
Safer:      /a+/
Safer:      /a++a+/   (if the split must be preserved)
```

### 6. Prefer Character Classes Over Alternation

```
Vulnerable: /(a|b)+c/
Safer:      /[ab]+c/
```

### 7. Bound Your Repeats

```
Vulnerable: /(\d+)+/
Safer:      /\d{1,10}/   (limit to reasonable bounds)
```

## Quick reference: risky vs safer patterns

```
(a+)+        -> a++        or (?>a+)
(a|aa)+      -> a+
(\d+)+       -> \d++       or \d{1,10}
(.+)+        -> .++        or .{1,100}
(a?)+        -> a*         or a+
(a*)*        -> a*
a+a+         -> a+         or a++a+
(a|b)+       -> [ab]+
(\w+\d+)+    -> (?>\w+\d+)+
```

## Defense in depth

1. **Validate patterns early**: Check patterns before deployment
2. **Use input limits**: Set reasonable length limits for regex inputs
3. **Prefer deterministic patterns**: Use atomic groups and possessive quantifiers
4. **Monitor performance**: Watch for slow regex operations in production
5. **Use PHPRegex in CI**: Add `bin/regex lint` to your build pipeline

## Related concepts

- **[ReDoS Guide](../REDOS_GUIDE.md)** - Practical guide to fixing ReDoS
- **[Architecture](../ARCHITECTURE.md)** - How ReDoS detection works
- **[FAQ & Glossary](../reference/faq-glossary.md)** - Common ReDoS questions

## Further reading

- [OWASP ReDoS Guide](https://owasp.org/www-community/attacks/Regular_expression_Denial_of_Service_-_ReDoS) - Security best practices
- [Regex Performance](https://sw.kovidgoyal.net/kitty/conf/#regex-performance) - Optimization techniques
- [Catastrophic Backtracking](https://www.regular-expressions.info/catastrophic.html) - Detailed explanation

---

Previous: [Understanding Visitors](visitors.md) | Next: [PCRE vs Other Engines](pcre.md)
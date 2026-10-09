---
description: "Fixes for the errors PHPRegex actually prints: lexer messages, ReDoS verdicts, cache and memory issues, CLI and Symfony bridge problems."
redirect_from:
  - /TROUBLESHOOTING/
  - /TROUBLESHOOTING.html
---
# Troubleshooting Guide

This guide helps you resolve common issues when using PHPRegex.

## Common Error Messages

### "Pattern exceeds maximum length"

**Problem:**

```
PHPRegex\Parser\Exception\ResourceLimitException: Regex pattern exceeds maximum length of 100000 characters.
```

**Causes:**
- You're parsing a generated pattern or loading patterns from database
- Pattern is too large for your application's needs

**Solutions:**

1. Increase limit:

```php
$regex = Regex::create([
    'max_pattern_length' => 1_000_000,  // 1 million characters
]);
```

2. Validate pattern before storing:

```php
$regex = Regex::create();
$validation = $regex->validate($pattern);

if (!$validation->isValid) {
    throw new \InvalidArgumentException("Invalid pattern: {$validation->error}");
}
```

3. Use cache to skip parsing:

```php
$regex = Regex::create([
    'cache' => new \PHPRegex\Parser\Cache\FilesystemCache(__DIR__.'/var/cache/regex'),
    // Pattern parsed once, cached for all workers
]);
```

4. Check pattern length before parsing:

```php
if (strlen($pattern) > 100_000) {
    throw new \RuntimeException("Pattern too long: " . strlen($pattern) . " characters");
}
```

---

## ReDoS False Positives

**Problem:** The ReDoS analyzer reports a pattern that works fine on your inputs.

**Cause:** Read the headline of the verdict first:

- `Exponential backtracking (proven)` or `Polynomial backtracking, degree N (proven)`: the analysis built an input that makes one match attempt blow up, printed as `Attack:`. Your inputs may never look like it; an attacker's can. The verdict is about a model of PCRE's backtracking: a lookaround it cannot evaluate, or an abstraction listed on the `Model:` line, can make it stricter than PCRE.
- `Potential backtracking (heuristic)`: the pattern holds a construct outside the model (a backreference, a conditional, recursion, …), and structural rules decided. They are conservative by design.

**Solutions:**

1. Run confirmed mode: the attack is replayed on your PCRE, without the JIT.

```php
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\RedosMode;
use PHPRegex\Redos\RedosSeverity;
use PHPRegex\Toolkit\Regex;

$result = Regex::create()->redos(
    $pattern,
    RedosSeverity::High,
    RedosMode::Confirmed,
    new ConfirmationOptions(backtrackLimit: 100_000),
);

echo 'Confirmed: ', $result->isConfirmed() ? 'yes' : 'no', "\n";
echo 'Confidence: ', $result->confidenceLevel()->value, "\n";
```

A proven verdict PCRE does not reproduce keeps `medium` confidence, and the CLI says `Not reproduced on PCRE2 …`. It is never an error in confirmed mode.

2. Add to ignore list:

```php
$regex = Regex::create([
    'redos_ignored_patterns' => [
        'your-safe-pattern-1',
        'your-safe-pattern-2',
    ],
]);
```

3. Check what the verdict is about:

```bash
vendor/bin/regex analyze '/(a{1,20})+$/'
#   Status     : Exponential backtracking (proven)
#   Model: {1,20} at offset 1 analysed as {1,}
#   Attack: "a" x n . "!"
```

---

## Invalid Escape Sequences

**Problem:**

```
PHPRegex\Parser\Exception\LexerException: \c must be followed by a printable ASCII character.
```

**Causes:**
- An escape sequence cut short, as `\c` dangling at the end of the pattern (`'/ab\c/'`)
- Using PCRE escape syntax your target PHP version does not support
- Typo in escape sequence

**Solutions:**

1. Check the PCRE2 version your PHP runs:

```bash
php --ri pcre
# PCRE Library Version => 10.49 2026-09-28
```

2. Use correct escape syntax:

```php
// Wrong: '\c' dangles at the end of the pattern
$pattern = '/ab\c/';

// Correct: '\c' followed by a printable ASCII letter
$pattern = '/ab\cA/';       // matches "ab" . "\x01"

// Or use the hexadecimal escape instead
$pattern = '/\x01/';        // Hexadecimal (recommended)
$pattern = '/\x{41}';       // Hexadecimal with braces
```

3. Fix common typos:

```php
// Wrong                        Correct
'\N{'   →  '\N{U+XXXX}'    // Full Unicode name
'\p{'   →  '\p{...}'       // Property needs a letter or a braced name
'\u{'   →  '\x{...}'       // Portable hex escape; \u{...} only exists on the newest PCRE2 releases
```

Run the validator to see these messages for your target PHP version:

```bash
vendor/bin/regex validate '/\u{41}/' --php-version 8.2
#   Status : INVALID
#   PCRE does not support the escape "\u" (\F, \L, \l, \N{name}, \U and \u are not supported).
```

---

## Parser/Lexer Errors

### "Unable to tokenize pattern at position X"

**Problem:**

```
PHPRegex\Parser\Exception\LexerException: Unable to tokenize pattern at position 15. Context: "abc..."
```

**Causes:**
- Malformed UTF-8 in a pattern read as UTF-8 (`/u` or a leading `(*UTF)`)
- Unsupported PCRE syntax
- Invalid character class nesting

**Solutions:**

1. Check the encoding of a UTF-8 pattern. Without `/u` a pattern is read as
   bytes, as PCRE reads it, and any byte is accepted (`/\xE9/` written with
   a raw byte is valid); with `/u` the pattern must be valid UTF-8, as PCRE
   requires:

```php
if (str_contains($flags, 'u') && !mb_check_encoding($pattern, 'UTF-8')) {
    throw new \RuntimeException('A /u pattern must be valid UTF-8');
}
```

2. Check for obvious syntax errors:

```php
// Check for unmatched brackets
$openBrackets = substr_count($pattern, '[');
$closeBrackets = substr_count($pattern, ']');

if ($openBrackets !== $closeBrackets) {
    throw new \RuntimeException('Unmatched character class brackets');
}

// Check for unmatched parentheses
$openParens = substr_count($pattern, '(');
$closeParens = substr_count($pattern, ')');

if ($openParens !== $closeParens) {
    throw new \RuntimeException('Unmatched parentheses');
}
```

---

## Performance Issues

### Slow Pattern Matching

**Problem:** Regex matching is taking too long on your input.

**Diagnosis:**

1. Check for catastrophic backtracking:

```bash
vendor/bin/regex analyze '/(a+)+$/' --redos-mode=confirmed

# A "Replayed on PCRE2 …: preg_match fails from length N" line means the
# attack printed on the "Attack:" line makes PCRE give up: a ReDoS issue
```

2. Check for unnecessary backtracking:

```bash
vendor/bin/regex debug '/.*a.*b.*a.*/'

# Heatmap will show where backtracking occurs
```

3. Test with realistic data:

```php
// Test with your actual data, not edge cases

$realisticData = $yourActualProductionData();
$start = microtime(true);
preg_match($yourPattern, $realisticData);
$elapsed = microtime(true) - $start;

echo "Time: " . ($elapsed * 1000) . " ms\n";
```

4. Use caching:

```php
$regex = Regex::create([
    'cache' => new \PHPRegex\Parser\Cache\FilesystemCache(__DIR__.'/var/cache/regex'),
]);

// Parse once, reuse across requests
$ast = $regex->parse($pattern);
```

---

## Cache Issues

### Cache Not Working

**Problem:** Cache is not being used, patterns are parsed on every request.

**Solutions:**

1. Verify cache is configured:

```php
$regex = Regex::create([
    'cache' => new \PHPRegex\Parser\Cache\FilesystemCache(__DIR__.'/var/cache/regex'),
]);

// Test
$ast1 = $regex->parse('/\d+/');
$ast2 = $regex->parse('/\d+/');  // Should use cache

echo "Cache enabled: " . ($regex->getCacheStats()['hits']) . " hits\n";
```

2. Clear cache for long-running processes:

```bash
# CLI
vendor/bin/regex clear-cache

# Programmatically
$cache = $regex->getCache();
if ($cache instanceof RemovableCacheInterface) {
    $cache->clear();
}
```

3. Check cache key generation:

```php
// Cache keys include pattern, flags, and PHP version
// Make sure these are correct for your use case

$cacheKey = $cache->generateKey('/\d+/i');
echo "Cache key: {$cacheKey}\n";
```

---

## CLI Issues

### Command Not Found

**Problem:**

```
Command "regex:test" is not defined.
```

**Solutions:**

1. Use `help` command to list available commands:

```bash
vendor/bin/regex help
```

2. Update to latest version:

```bash
composer update
# or
composer require --dev php-regex/regex-cli:^2.0
```

3. Check if command is deprecated:

```bash
vendor/bin/regex help | grep -i deprecated

# Use alternative command
```

### A Very Large File Reports No Patterns

**Problem:** `regex lint` finds nothing in a multi-megabyte generated file —
a call map, a fixture dump, a compiled container.

**Cause:** reading such a file into tokens costs around 55 times its size in
memory, and building an AST about twice that. A 2 MB file therefore needs more
than the default `memory_limit` of 128M. Rather than let the process die and
lose the results of every other file, the extractor skips the file.

**Solution:** give the process more memory:

```bash
php -d memory_limit=1G vendor/bin/regex lint src/
```

Or exclude the file, since generated code rarely carries patterns worth
linting:

```json
{
  "exclude": ["src/Generated"]
}
```

---

## Integration Issues

### Symfony Bridge Not Working

**Problem:** Symfony bundle commands not found or not working.

**Solutions:**

1. Verify bundle is installed:

```bash
composer show php-regex/regex-symfony
# Check if the Symfony bundle is listed
```

2. Register bundle in Symfony:

```php
// config/bundles.php
return [
    PHPRegex\Symfony\PHPRegexBundle::class => ['dev' => true, 'test' => true],
];
```

3. Clear Symfony cache:

```bash
php bin/console cache:clear
# Or
rm -rf var/cache/*
```

---

## Testing Issues

### Tests Failing After Upgrade

**Problem:** Tests pass with one version but fail with newer version.

**Solutions:**

1. Run tests after upgrade:

```bash
composer phpunit

# Run specific test file
composer phpunit tests/Unit/Parser/ParserTest.php
```

2. Update test expectations:

```php
// Check if test needs updating after API changes

// Before
public function test_parse_handles_new_feature(): void
{
    $result = $this->regex->parse('/new-syntax/');
    $this->assertInstanceOf(NewNode::class, $result->child);
}

// After API change
public function test_parse_handles_new_feature(): void
{
    $result = $this->regex->parse('/new-syntax/');
    $this->assertInstanceOf(DifferentNode::class, $result->child);  // Updated expectation
}
```

3. Check deprecation warnings:

```bash
# Run with error reporting
composer phpunit --display-deprecations

# Fix deprecations before running full test suite
```

---

## Getting Help

### Documentation Not Clear

**Problem:** Documentation doesn't explain what you need to do.

**Solutions:**

1. Check the [Quick Start guide](quick-start.md).

2. Check the [reference index](reference/README.md): rules, API, diagnostics and JSON output each have their own page.

3. Look at examples:

```bash
ls -la examples/
php examples/basic/validate.php
```

4. Search issues:

```bash
# Search GitHub issues
https://github.com/php-regex/php-regex/issues

# Create new issue with question
```

5. Join the community on [GitHub Discussions](https://github.com/php-regex/php-regex/discussions).

---

## Additional Resources

- [Quick Start Guide](quick-start.md)
- [API Reference](reference/rules.md)
- [ReDoS Guide](guides/redos.md)
- [Architecture Documentation](architecture.md)
- [Contributing Guide](https://github.com/php-regex/php-regex/blob/2.x/CONTRIBUTING.md)
- [Upgrading to 2.0](https://github.com/php-regex/php-regex/blob/2.x/UPGRADE-2.0.md)

## Still Need Help?

1. Check the [GitHub Issues](https://github.com/php-regex/php-regex/issues) for similar problems
2. Search for your specific error message in the codebase
3. Enable verbose mode for more details:

```bash
vendor/bin/regex analyze '/pattern/' -v
```

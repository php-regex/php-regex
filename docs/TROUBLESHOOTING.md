# Troubleshooting Guide

This guide helps you resolve common issues when using RegexParser.

## Common Error Messages

### "Pattern exceeds maximum length"

**Problem:**
```
RegexParser\Exception\ResourceLimitException: Regex pattern exceeds maximum length of 100000 characters.
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
    'cache' => new \FileCache('/tmp/regex_cache'),
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

**Problem:** Your ReDoS analyzer reports `HIGH` but pattern works fine.

**Cause:** ReDoS analysis is structural and conservative by design.

**Solutions:**

1. Run confirmed mode:
```php
$regex = Regex::create();
$result = $regex->redos(
    $pattern,
    ReDoSSeverity::HIGH,
    ReDoSMode::CONFIRMED,  // ← Test with real inputs
    new ReDoSConfirmOptions(
        maxTestStrings: 1000,
        maxStringLength: 1000,
    )
);

echo "Confirmed: " . ($result->isConfirmed ? 'Yes' : 'No') . "\n";
echo "Confidence: {$result->confidence->value}\n";
```

2. Add to ignore list:
```php
$regex = Regex::create([
    'redos_ignored_patterns' => [
        'your-safe-pattern-1',
        'your-safe-pattern-2',
    ],
]);
```

3. Understand structural analysis:
```php
// Theoretical mode finds POTENTIAL risks
// It's designed to be conservative and catch more

// These patterns are flagged because they COULD be dangerous
$riskyPatterns = [
    '/(a+)+$/',           // Classic ReDoS example
    '/^(\d+)+$/',         // Nested quantifiers
    '/(a+)+b+/',         // Alternation in quantifiers
];

// In practice, with realistic input, these might be safe
$realisticInputs = [
    'hello',  // No backtracking
    'test123',  // No backtracking
];
```

---

## Invalid Escape Sequences

**Problem:**
```
RegexParser\Exception\LexerException: Invalid escape sequence '\c' at position 5
```

**Causes:**
- Using PCRE escape syntax not supported by your PHP version
- Typo in escape sequence
- Confusing escape with literal character

**Solutions:**

1. Check PHP version:
```bash
php -v
php --re
# Output PCRE library version
# PHP 8.2 uses PCRE2 10.30+
# Newer versions support more escape sequences
```

2. Use correct escape syntax:
```php
// Wrong
$pattern = '\cA';           // Control character A

// Correct
$pattern = '\x01';          // Hexadecimal (recommended)
$pattern = '\x{41}';        // Hexadecimal with braces
```

3. Fix common typos:
```php
// Wrong                        Correct
'\d'   →  '\\d'          // Double backslash
'\N{'   →  '\N{U+XXXX}'    // Full Unicode name
'\p{'   →  '\p{...}'       // Property needs braces
'\u{'   →  '\u{...}'       // Hex needs braces for > 2 digits
```

---

## Parser/Lexer Errors

### "Unable to tokenize pattern at position X"

**Problem:**
```
RegexParser\Exception\LexerException: Unable to tokenize pattern at position 15. Context: "abc..."
```

**Causes:**
- Malformed UTF-8 input
- Unsupported PCRE syntax
- Invalid character class nesting

**Solutions:**

1. Validate UTF-8 encoding:
```php
if (!preg_match('//u', $pattern)) {
    throw new \RuntimeException('Pattern must be valid UTF-8');
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
bin/regex analyze '/(a+)+$/' --mode=confirmed

# If confirmed mode takes > 10ms per test,
# you likely have a ReDoS issue
```

2. Check for unnecessary backtracking:
```bash
bin/regex debug '/.*a.*b.*a.*/' --heatmap

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
    'cache' => new \FileCache('/tmp/regex_cache'),
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
    'cache' => new \FileCache('/tmp/regex_parser_cache'),
]);

// Test
$ast1 = $regex->parse('/\d+/');
$ast2 = $regex->parse('/\d+/');  // Should use cache

echo "Cache enabled: " . ($regex->getCacheStats()['hits']) . " hits\n";
```

2. Clear cache for long-running processes:
```bash
# CLI
bin/regex clear-cache

# Programatically
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
bin/regex help
```

2. Update to latest version:
```bash
composer update
# or
composer require yoeunes/regex-parser@latest
```

3. Check if command is deprecated:
```bash
bin/regex help | grep -i deprecated

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
composer show yoeunes/regex-parser
# Check if Symfony bridge is listed
```

2. Register bundle in Symfony:
```yaml
# config/bundles.php
return [
    RegexParser\Bridge\Symfony\RegexParserBundle::class => ['all' => true],
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

1. Check Quick Start Guide:
```bash
# docs/QUICK_START.md
```

2. Check API Reference:
```bash
# docs/reference.md
```

3. Look at examples:
```bash
ls -la examples/
php examples/basic/validate.php
```

4. Search issues:
```bash
# Search GitHub issues
https://github.com/php-regex/regex-parser/issues

# Create new issue with question
```

5. Join community:
```bash
# GitHub Discussions
https://github.com/php-regex/regex-parser/discussions

# Stack Overflow
https://stackoverflow.com/questions/tagged/regexparser
```

---

## Additional Resources

- [Quick Start Guide](QUICK_START.md)
- [API Reference](reference.md)
- [ReDoS Guide](REDOS_GUIDE.md)
- [Architecture Documentation](ARCHITECTURE.md)
- [Contributing Guide](CONTRIBUTING.md)
- [Upgrading Guide](UPGRADING.md)

## Still Need Help?

1. Check the [GitHub Issues](https://github.com/php-regex/regex-parser/issues) for similar problems
2. Search for your specific error message in the codebase
3. Enable verbose mode for more details:
```bash
bin/regex analyze '/pattern/' -v
```

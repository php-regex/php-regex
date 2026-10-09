---
redirect_from:
  - /references/
  - /references.html
---
# External References

This curated list of external resources provides authoritative information about regex syntax, engine behavior, and performance. These are the sources PHPRegex uses when verifying diagnostics and documenting edge cases.

## Table of Contents

| Category                                                | Resources                    |
|---------------------------------------------------------|------------------------------|
| [PCRE2 Specification](#pcre2-specification)             | Official PCRE2 documentation |
| [PHP References](#php-specific-references)              | PHP.net PCRE manual          |
| [Security and ReDoS](#security-and-redos)               | Security guides              |
| [Engine Internals](#engine-internals-and-theory)        | How regex engines work       |
| [Tutorials](#tutorials-and-practical-guidance)          | Learning resources           |
| [Testing and Visualization](#testing-and-visualization) | Tools                        |
| [Unicode](#unicode-references)                          | Unicode standards            |
| [Engine Compatibility](#engine-compatibility)           | Other engines                |

---

## PCRE2 Specification

The authoritative source for PCRE2 syntax and behavior.

| Resource                                                                        | Description                    |
|---------------------------------------------------------------------------------|--------------------------------|
| [PCRE2 Syntax Overview](https://www.pcre.org/current/doc/html/pcre2syntax.html) | Quick syntax reference         |
| [PCRE2 Pattern Syntax](https://www.pcre.org/current/doc/html/pcre2pattern.html) | Pattern documentation |
| [PCRE2 Performance](https://www.pcre.org/current/doc/html/pcre2perform.html)    | Performance considerations     |
| [PCRE2 API](https://www.pcre.org/current/doc/html/pcre2api.html)                | Engine API details             |

**Key topics covered:**
- Pattern modifiers (`imsxuADJSUX`)
- Escape sequences
- Assertions and zero-width matches
- Atomic grouping and possessive quantifiers
- Backtracking control verbs

---

## PHP-Specific References

PHP's implementation of PCRE and language-specific features.

| Resource                                                                                | Description                       |
|-----------------------------------------------------------------------------------------|-----------------------------------|
| [PHP PCRE Manual](https://www.php.net/manual/en/book.pcre.php)                          | PCRE extension reference |
| [Pattern Syntax Reference](https://www.php.net/manual/en/regexp.reference.php)          | PHP pattern syntax                |
| [Pattern Modifiers](https://www.php.net/manual/en/reference.pcre.pattern.modifiers.php) | Modifier documentation            |
| [preg_last_error()](https://www.php.net/manual/en/function.preg-last-error.php)         | Error detection function          |
| [preg_last_error_msg()](https://www.php.net/manual/en/function.preg-last-error-msg.php) | Error message function            |

**PHP-specific notes:**
- PHP uses PCRE2 (since PHP 7.3)
- `\g{0}` is invalid - use `\g<0>` or `(?R)`
- Backtrack limits via `pcre.backtrack_limit`
- Recursion limits via `pcre.recursion_limit`

---

## Security and ReDoS

Understanding and preventing Regular Expression Denial of Service attacks.

| Resource                                                                                                                                     | Description                        |
|----------------------------------------------------------------------------------------------------------------------------------------------|------------------------------------|
| [OWASP ReDoS](https://owasp.org/www-community/attacks/Regular_expression_Denial_of_Service_-_ReDoS)                                          | Comprehensive ReDoS overview       |
| [Catastrophic Backtracking](https://www.regular-expressions.info/catastrophic.html)                                                          | Detailed explanation with examples |
| [ReDoS Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Regular_Expression_Denial_of_Service_-_ReDoS_Prevention_Cheat_Sheet.html) | Prevention strategies              |

**Key concepts:**
- Exponential vs polynomial backtracking
- Common risky patterns
- Mitigation strategies
- Detection techniques

---

## Engine Internals and Theory

How regex engines actually work under the hood.

| Resource                                                                                      | Description                   |
|-----------------------------------------------------------------------------------------------|-------------------------------|
| [Russ Cox: Regex Matching Can Be Simple And Fast](https://swtch.com/~rsc/regexp/regexp1.html) | Classic article on NFA vs DFA |
| [Wikipedia: NFA](https://en.wikipedia.org/wiki/Nondeterministic_finite_automaton)             | NFA theory                    |
| [Wikipedia: Backtracking](https://en.wikipedia.org/wiki/Backtracking)                         | Backtracking algorithm        |

**Why this matters:**
- Backtracking engines (PCRE) can be exponential
- Non-backtracking engines (RE2) are linear
- Understanding tradeoffs helps write better patterns

---

## Tutorials and Practical Guidance

Learn regex from beginner to advanced.

| Resource                                                                       | Description                |
|--------------------------------------------------------------------------------|----------------------------|
| [Regular-Expressions.info](https://www.regular-expressions.info/)              | Comprehensive tutorial hub |
| [Character Classes](https://www.regular-expressions.info/charclass.html)       | Deep dive on `[...]`       |
| [Quantifiers and Greediness](https://www.regular-expressions.info/repeat.html) | Repetition explained       |
| [Lookarounds](https://www.regular-expressions.info/lookaround.html)            | Zero-width assertions      |
| [RexEgg](https://www.rexegg.com/)                                              | Advanced regex tutorial    |
| [RegexOne](https://regexone.com/)                                              | Interactive learning       |

**Recommended learning path:**
1. Start with Regular-Expressions.info basics
2. Practice on RegexOne
3. Explore advanced topics on RexEgg
4. Study PCRE2 manual for PHP-specifics

---

## Testing and Visualization

Tools to test, debug, and visualize regex patterns.

| Tool                           | Description                       | URL                       |
|--------------------------------|-----------------------------------|---------------------------|
| **regex101**                   | Popular tester with PCRE2 support | https://regex101.com/     |
| **regexper**                   | Railroad diagram visualization    | https://regexper.com/     |
| **Debuggex**                   | Visual regex matcher              | https://www.debuggex.com/ |
| **Regexr](https://regexr.com/) | Another popular tester            | https://regexr.com/       |

**regex101 tips:**
1. Set flavor to "PCRE (PHP)"
2. Use the structure panel to see groups
3. Check the match information for backtracking
4. Use the pattern generator for test cases

---

## Unicode References

Unicode support in regex patterns.

| Resource                                                                   | Description                |
|----------------------------------------------------------------------------|----------------------------|
| [Unicode Regular Expressions (UTS #18)](https://unicode.org/reports/tr18/) | Unicode technical standard |
| [Unicode Blocks](https://unicode.org/cldr/utility/blocks.jsp)              | Unicode character blocks   |
| [Unicode Categories](https://unicode.org/cldr/utility/category.jsp)        | Character categories       |

**Common Unicode patterns:**
```php
// Match any letter (any language)
'/\p{L}/u'

// Match Chinese characters
'/\p{Han}/u'

// Match emoji
'/\p{Emoji}/u'

// Match numbers in any script
'/\p{N}/u'
```

---

## Engine Compatibility

How other regex engines compare to PCRE2.

| Engine         | Description                 | Syntax Differences                          |
|----------------|-----------------------------|---------------------------------------------|
| **RE2**        | Google's linear-time engine | No backreferences, no lookbehinds           |
| **JavaScript** | V8 regex engine             | Different flags, some PCRE features missing |
| **Python re**  | Standard library            | Similar to PCRE2, some differences          |
| **.NET**       | .NET regex                  | More features, different syntax             |

**RE2 comparison (useful for understanding PCRE tradeoffs):**
```php
// PCRE2 - can be exponential
preg_match('/(a+)+b/', $input);

// RE2 - always linear
// $re2->match('(a+)+b', $input);  // No exponential behavior
```

---

## Quick Reference Cards

| Category              | URL                                                     |
|-----------------------|---------------------------------------------------------|
| PCRE2 Quick Reference | https://www.pcre.org/current/doc/html/pcre2quick.html   |
| PCRE2 Summary         | https://www.pcre.org/current/doc/html/pcre2summary.html |
| PHP PCRE Summary      | https://www.php.net/manual/en/reference.pcre.php        |

---

## Citation Style

When referencing these sources in documentation or code comments:

```php
// See: PCRE2 Pattern Syntax
// https://www.pcre.org/current/doc/html/pcre2pattern.html

// Based on: OWASP ReDoS Prevention
// https://owasp.org/www-community/attacks/Regular_expression_Denial_of_Service_-_ReDoS
```

---

## Related Documentation

| Topic         | File                                |
|---------------|-------------------------------------|
| ReDoS Guide   | [ReDoS guide](../guides/redos.md) |
| Cookbook      | [cookbook.md](../cookbook.md)       |
| API Reference | [api.md](api.md)                    |
| Diagnostics   | [diagnostics.md](diagnostics.md)    |

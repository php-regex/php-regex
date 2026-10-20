<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=1">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=1">
        <img src="art/banner.png?v=1" alt="PHPRegex PHPUnit" width="100%">
    </picture>
</p>

<p align="center">
    <a href="https://php-regex.com"><img src="https://img.shields.io/badge/documentation-php--regex.com-blue" alt="Documentation Badge"></a>
    <a href="https://www.linkedin.com/in/younes--ennaji"><img src="https://img.shields.io/badge/author-@yoeunes-blue.svg" alt="Author Badge"></a>
    <a href="https://github.com/php-regex/php-regex/releases"><img src="https://img.shields.io/github/tag/php-regex/php-regex.svg" alt="GitHub Release Badge"></a>
    <a href="https://github.com/php-regex/php-regex/blob/2.x/LICENSE"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg" alt="License Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-phpunit"><img src="https://img.shields.io/packagist/dt/php-regex/regex-phpunit.svg" alt="Packagist Downloads Badge"></a>
    <a href="https://github.com/php-regex/php-regex"><img src="https://img.shields.io/github/stars/php-regex/php-regex.svg" alt="GitHub Stars Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-phpunit"><img src="https://img.shields.io/packagist/php-v/php-regex/regex-phpunit.svg" alt="Supported PHP Version Badge"></a>
</p>

PHPRegex PHPUnit
================

PHPUnit assertions backed by the PHPRegex parser: assert what a pattern captures with exact capture semantics, assert the running engine compiles it, and assert a proven-linear ReDoS verdict — with group names validated against the pattern itself, so a typo'd name is a failure that names the real groups, never a silent pass.

Requires PHP 8.1+ and PHPUnit 9.6 or later — 10, 11, 12 and 13 (10 runs on PHP 8.1, 11 on 8.2, 12 on 8.3, 13 on 8.4).

Documentation: [php-regex.com](https://php-regex.com) — the [PHPUnit guide](https://php-regex.com/guides/phpunit/) walks the assertions end to end.

Features
--------

* `assertPatternMatches()` runs one guarded match attempt and judges a capture expectation: which groups participated, what each captured, and at which byte offset
* The expectation's keys are validated against the pattern's own groups before any value is compared: an unknown name or number fails listing the pattern's real groups, a name that owns several group numbers (a `(?J)` pattern) fails listing them, and a number with no group behind it under the `n` modifier — where only named groups still capture, renumbered — fails saying what the modifier did
* `assertPatternDoesNotMatch()` asserts no match — a pattern that does not parse or an engine refusal fails the assertion instead of passing it as "not a match"
* `assertPatternCompiles()` asks the running engine, carrying the PCRE message and the offset it names on a refusal
* `assertPatternIsLinear()` asserts a proven-linear ReDoS verdict, and fails with a stable first line — `proven unsafe:` for a held witness or a quadratic unanchored search, `could not analyze:` for a budget, a model or a parse refusal
* The four constraints behind the assertions — `MatchesPattern`, `DoesNotMatchPattern`, `CompilesPattern`, `IsLinearPattern` — compose with `assertThat()` and boolean composition; assert no match with `DoesNotMatchPattern`, since `LogicalNot` around `MatchesPattern` reads a pattern that does not parse, or an engine refusal, as "not a match"
* A parse failure carries the library's message, byte position and caret snippet; an engine refusal carries `preg_last_error_msg()`

Installation
------------

```bash
composer require --dev php-regex/regex-phpunit
```

The package brings `php-regex/regex-toolkit` with it: the parser, the capture information and the ReDoS analysis the assertions read.

Usage
-----

Add the trait to your test case and assert:

```php
use PHPRegex\PHPUnit\RegexAssertions;

final class SlugTest extends TestCase
{
    use RegexAssertions;

    public function test_a_slug_matches(): void
    {
        self::assertPatternMatches('/^[a-z0-9-]{3,}$/', 'php-regex');
        self::assertPatternDoesNotMatch('/^[a-z0-9-]{3,}$/', 'PHP Regex!');
    }
}
```

The methods are static, so the trait also works in a bare `Assert` subclass — arrange-only helpers included.

### The capture expectation

`assertPatternMatches()` takes an expectation keyed by group number or name. An empty expectation asks only whether the pattern matched.

| expectation | asks |
|-------------|------|
| `true` | the group participated, any value |
| `false` | the group did not participate |
| `'value'` | exactly that captured string |
| `['value', byteOffset]` | that string, at that byte offset |

```php
self::assertPatternMatches('/(?<alpha>a+)(?<beta>b+)?/', 'aa', [
    'alpha' => 'aa',          // exactly 'aa'
    'beta' => false,          // present in the pattern, did not participate
]);

self::assertPatternMatches('/(\d{4})-(\d{2})-(\d{2})/', '2026-10-17', [
    1 => '2026',
    2 => ['10', 5],           // '10', starting at byte 5
]);
```

When only the captures differ, the failure renders the expectation against the engine's answer, both in the expectation's own spelling:

```text
Failed asserting that '2026-10-17' matches the pattern '/(\\d{4})-(\\d{2})/'.
--- Expected
+++ Actual
@@ @@
-[1 => '2026', 2 => '01']
+[1 => '2026', 2 => '10']
```

The output above is pinned to PHPUnit 11; the wording of the surrounding frame moves with the PHPUnit release, the diff lines do not.

### Offsets are bytes

Offsets are byte offsets everywhere, `PREG_OFFSET_CAPTURE` semantics, under `/u` as everywhere else — `é` is two bytes:

```php
self::assertPatternMatches('/é(l+)/u', 'héllo', [1 => ['ll', 3]]);
```

### One match attempt

Each `assertPatternMatches()` call runs one `preg_match()` attempt, never `preg_match_all()`: repeated captures are not collected, and a group that could match twice is judged on the attempt the engine made — the leftmost one.

### Validation failures

Every expectation key is resolved against the pattern's own groups before any value is compared:

```text
Failed asserting that 'ab' matches the pattern '/(?<alpha>a)(?<beta>b)/'.
the pattern has no group named 'alpa'; its groups are 0 (the whole match), 1 'alpha', 2 'beta'.
```

A name that owns several group numbers — a `(?J)` pattern — fails rather than trusting one of them:

```text
the group name 'dup' owns several group numbers (1, 2); assert by number instead.
```

Group 0 is the whole match: it participates whenever the pattern matches, so `0 => false` is a contradiction and fails as one. A shape the vocabulary does not spell fails naming what it expected. Under the `n` modifier the engine keeps only the NAMED groups capturing, renumbered from 1 — in `/(a)(?<x>b)/n` the group `x` is group 1 — so a name, or the number the renumbering gave it, asserts as usual, and a number with no group behind it fails saying what the modifier did:

```text
the pattern has no group 2 under the n modifier; only named groups capture under it, renumbered from 1. Its groups are 0 (the whole match), 1 'x'.
```

A pattern that does not parse fails with the library's message, byte position and caret snippet; `preg_match()` answering `false` fails with `preg_last_error_msg()`, never as "no match".

### Compilation and linearity

```php
self::assertPatternCompiles('/^\d{4}-\d{2}-\d{2}$/');
self::assertPatternIsLinear('/^[a-z0-9-]{3,}$/');
```

`assertPatternCompiles()` leaves the truth to the engine alone: a pattern beyond the library's own parse limits still passes here when the engine takes it — while `assertPatternMatches()`, which parses to validate the expectation, fails with the limit message. The asymmetry is deliberate: each assertion asks its own question.

`assertPatternIsLinear()` fails unless one match attempt is proven linear and the unanchored search carries no quadratic-cost witness. The failure's first line is a stable prefix, so a quarantine can tell the two apart:

| first line | verdict |
|------------|---------|
| `proven unsafe:` | an unsafe attempt (with the witness input), or a quadratic unanchored search |
| `could not analyze:` | the analysis budget was exceeded, or the pattern sits outside the analyzer's model |

```text
proven unsafe: Exponential backtracking (proven). A witness input is "a" x n . "!".
```

Verdicts are memoized per pattern for the process — per worker under paratest — and the analysis is deterministic per release, so the memoization cannot be observed.

Documentation
-------------

* [The PHPUnit guide](https://php-regex.com/guides/phpunit/) — every assertion, the expectation vocabulary, the failure taxonomy
* [The ReDoS guide](https://php-regex.com/guides/redos/) — how the linearity verdict is reached and what a witness means
* [Choose a package](https://php-regex.com/packages/) — the other sixteen packages of the family
* [Backward compatibility promise](https://php-regex.com/reference/backward-compatibility/) — what stays stable across releases

Resources
---------

* [Upgrading from 1.x](https://github.com/php-regex/php-regex/blob/2.x/UPGRADE-2.0.md) — moving from `yoeunes/regex-parser` 1.3 to 2.0, every change that needs one on your side
* [Documentation](https://php-regex.com/docs/)
* The analysis layer behind the assertions: [regex-toolkit](https://github.com/php-regex/php-regex/tree/2.x/src/Toolkit)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and [send pull requests](https://github.com/php-regex/php-regex/pulls) in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

Sponsors
---------

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

License
-------

MIT. See [LICENSE](https://github.com/php-regex/php-regex/blob/2.x/LICENSE).

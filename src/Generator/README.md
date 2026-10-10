<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=2">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=2">
        <img src="art/banner.png?v=2" alt="PHPRegex Generator" width="100%">
    </picture>
</p>

<p align="center">
    <a href="https://php-regex.com"><img src="https://img.shields.io/badge/documentation-php--regex.com-blue" alt="Documentation Badge"></a>
    <a href="https://www.linkedin.com/in/younes--ennaji"><img src="https://img.shields.io/badge/author-@yoeunes-blue.svg" alt="Author Badge"></a>
    <a href="https://github.com/php-regex/php-regex/releases"><img src="https://img.shields.io/github/tag/php-regex/php-regex.svg" alt="GitHub Release Badge"></a>
    <a href="https://github.com/php-regex/php-regex/blob/2.x/LICENSE"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg" alt="License Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-generator"><img src="https://img.shields.io/packagist/dt/php-regex/regex-generator.svg" alt="Packagist Downloads Badge"></a>
    <a href="https://github.com/php-regex/php-regex"><img src="https://img.shields.io/github/stars/php-regex/php-regex.svg" alt="GitHub Stars Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-generator"><img src="https://img.shields.io/packagist/php-v/php-regex/regex-generator.svg" alt="Supported PHP Version Badge"></a>
</p>

PHPRegex Generator
==================

Generates sample strings and test cases that a regex matches or rejects.

Part of [PHPRegex](https://github.com/php-regex/php-regex), released with its siblings under one version number.

Documentation: [php-regex.com](https://php-regex.com) — the [API reference](https://php-regex.com/reference/api/) documents `generate()` end to end.

Features
--------

- Generates a random string the pattern matches, from the parsed AST
- Reproducible samples: seed the generator, reset the seed, draw again
- Open-ended quantifiers (`*`, `+`, `{n,}`) capped by a configurable repetition limit
- Unicode properties (`\p{Lu}`), POSIX classes (`[[:alpha:]]`) and negated classes resolved by asking the running PCRE engine
- Lookaheads and lookbehinds held where they stand; backreferences repeat what their group captured
- Matching and non-matching strings for a pattern, ready for a test suite
- `SampleGenerationException` when no sample can be built

Installation
------------

```bash
composer require php-regex/regex-generator
```

Requires PHP 8.2+ and `php-regex/regex-parser`. MIT licensed.

Configuration
-------------

- `maxRepetition` (int, default `3`): extra repetitions drawn for `*`, `+` and `{n,}`
- `setSeed(int $seed)` and `resetSeed()`: lock the random source for reproducible samples
- `PcreEngine $engine` (constructor of both generators): the engine asked what a class or a property holds

Usage
-----

A sample, made reproducible with a seed:

```php
use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Parser\RegexParser;

$generator = new SampleGenerator();
$generator->setSeed(42);

echo RegexParser::create()->parse('/[A-Z]{3}-\d{4}/')->accept($generator); // "VEL-0403"
```

Test cases, matching and not, each one checked against the running engine (`preg_match()`
gives 1 on every matching case, 0 on every non-matching one):

```php
use PHPRegex\Generator\TestCaseGenerator;
use PHPRegex\Parser\RegexParser;

$cases = RegexParser::create()->parse('/\d{3}-\d{4}/')->accept(new TestCaseGenerator());

echo $cases['matching'][0];     // "000-0000"
echo $cases['non_matching'][0]; // "00.000"
```

When no sample can be built, the visitor says why:

```php
use PHPRegex\Generator\SampleGenerationException;
use PHPRegex\Generator\SampleGenerator;
use PHPRegex\Parser\RegexParser;

try {
    RegexParser::create()->parse('/(?&missing)/')->accept(new SampleGenerator());
} catch (SampleGenerationException $e) {
    echo $e->getMessage(); // "Sample generation for subroutines is not supported."
}
```

The [Toolkit facade](https://php-regex.com/reference/api/) checks each sample against the running engine and retries before settling.

Documentation
-------------

- [Quick start](https://php-regex.com/quick-start/) — parse, generate and analyze in one tour
- [API reference](https://php-regex.com/reference/api/) — the `generate()` facade: retries, engine checks, error codes
- [Visitors](https://php-regex.com/concepts/visitors/) — the visitor pattern both generators implement
- [Testing and debugging](https://php-regex.com/tutorial/09-testing-debugging/) — generated samples in a test workflow
- [Backward compatibility](https://php-regex.com/reference/backward-compatibility/) — what stays stable across releases

Resources
---------

* [Changelog](CHANGELOG.md)
* [Bridges and tools](https://github.com/php-regex/php-regex/blob/2.x/README.md#getting-started) — Laravel, Symfony, CLI, PHPStan and LSP
* [Report issues](https://github.com/php-regex/php-regex/issues) and
  [send pull requests](https://github.com/php-regex/php-regex/pulls)
  in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

Sponsors
---------

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

License
-------

MIT. See [LICENSE](https://github.com/php-regex/php-regex/blob/2.x/LICENSE).

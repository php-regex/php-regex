<p align="center">
    <a href="https://php-regex.com"><img src="https://img.shields.io/badge/documentation-php--regex.com-blue" alt="Documentation Badge"></a>
    <a href="https://www.linkedin.com/in/younes--ennaji"><img src="https://img.shields.io/badge/author-@yoeunes-blue.svg" alt="Author Badge"></a>
    <a href="https://github.com/php-regex/php-regex/releases"><img src="https://img.shields.io/github/tag/php-regex/php-regex.svg" alt="GitHub Release Badge"></a>
    <a href="https://github.com/php-regex/php-regex/blob/2.x/LICENSE"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg" alt="License Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-psalm"><img src="https://img.shields.io/packagist/dt/php-regex/regex-psalm.svg" alt="Packagist Downloads Badge"></a>
    <a href="https://github.com/php-regex/php-regex"><img src="https://img.shields.io/github/stars/php-regex/php-regex.svg" alt="GitHub Stars Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-psalm"><img src="https://img.shields.io/packagist/php-v/php-regex/regex-psalm.svg" alt="Supported PHP Version Badge"></a>
</p>

PHPRegex Psalm
==============

A Psalm plugin that reads your regex patterns: it types `$matches` from the
pattern of `preg_match()` and `preg_match_all()`, and reports the patterns the
PHP version you target refuses.

Requires PHP 8.2+ to run and Psalm 6.19+. MIT licensed.

Documentation: [php-regex.com](https://php-regex.com) — the [Psalm guide](https://php-regex.com/guides/psalm/) is the place to start.

Features
--------

* Where `preg_match()` returned 1, `$matches` is the shape of the pattern: one
  key per group, named groups included, each holding what the group can
  capture (a literal, a non-falsy string, a non-empty string), optional where
  PHP may leave the key out. Where it did not, `$matches` is `[]`.
* After `preg_match_all()`, `$matches` is the shape of the pattern, in either
  order, for a pattern without `\K` and an offset absent or a constant ≤ 0.
* `PREG_OFFSET_CAPTURE` and `PREG_UNMATCHED_AS_NULL` are read, as are named
  arguments in any order.
* An invalid pattern is reported as `InvalidRegexPattern`, with the error and
  its offset in the pattern, in every `preg_*` call: `preg_match()`,
  `preg_match_all()`, `preg_replace()`, `preg_replace_callback()`,
  `preg_replace_callback_array()` (its keys), `preg_split()`, `preg_grep()` and
  `preg_filter()`.
* Patterns are judged for the PHP version Psalm analyses for, with the PCRE2
  release that PHP bundles: a pattern your PHP 8.4 accepts but PHP 8.2 refuses
  is reported on a project that targets 8.2.
* The plugin never replaces Psalm's own description of the `preg_*`
  functions: their purity, return types, parameter checks and taint flows
  stay as they are.

Installation
------------

```bash
composer require --dev php-regex/regex-psalm
vendor/bin/psalm-plugin enable php-regex/regex-psalm
```

`psalm-plugin enable` adds the plugin to your `psalm.xml`; you can add it by
hand instead:

```xml
<plugins>
    <pluginClass class="PHPRegex\Psalm\Plugin"/>
</plugins>
```

The plugin runs on PHP 8.2 or later, while Psalm 6 itself runs on PHP 8.1:
Psalm must run with PHP 8.2 or later to load it. The code you analyse may
target an older PHP (see "Configuration").

Usage
-----

Run Psalm as usual. Below each example is the message of Psalm's `Trace`
issue for the `@psalm-trace` annotation, as Psalm printed it.

```php
function release(string $tag): ?int
{
    if (!preg_match('/^v(?<major>\d+)\.(?<minor>\d+)(?:-(rc|beta)(\d+))?$/', $tag, $m)) {
        return null;
    }

    /** @psalm-trace $m */

    return (int) $m['major'];
}
```

```
$m: array{0: non-falsy-string, 1: non-empty-string, 2: non-empty-string, 3?: ''|'beta'|'rc', 4?: non-empty-string, major: non-empty-string, minor: non-empty-string}
```

With flags:

```php
if (preg_match('/(\w+)@(\w+)?/', $text, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
    /** @psalm-trace $m */
}
```

```
$m: array{0: list{non-falsy-string, int<0, max>}, 1: list{non-empty-string, int<0, max>}, 2: list{non-empty-string|null, int<-1, max>}}
```

After `preg_match_all()`:

```php
preg_match_all('/#([a-z]+)/', $text, $m);
/** @psalm-trace $m */

preg_match_all('/#([a-z]+)(?::(\d+))?/', $text, $sets, PREG_SET_ORDER);
/** @psalm-trace $sets */
```

```
$m: array{0: list<non-falsy-string>, 1: list<non-falsy-string>}
$sets: list<array{0: non-falsy-string, 1: non-falsy-string, 2?: non-empty-string}>
```

An invalid pattern, in `app/broken.php`:

```php
<?php

function broken(string $text): ?string
{
    return preg_replace('/(?<n>a)(?<n>b)/', '', $text);
}
```

```
ERROR: InvalidRegexPattern - app/broken.php:5:25 - Regex pattern is invalid for PHP 8.4 with PCRE2 10.44: Duplicate group name "n" at position 12 (offset 12).
```

### What is typed, and what is not

`$matches` of `preg_match()` takes the shape where Psalm reads the call as a
condition:

* `if (preg_match(…))`, and the code that follows `if (!preg_match(…)) { return; }`;
* the condition of a ternary, `preg_match(…) ? $m[1] : null`;
* `&&` and `||`, `(bool) preg_match(…)`, and `if ($found = preg_match(…))`.

No condition is read from the other forms: there, and outside the checked
branch, `$matches` keeps the type Psalm gives it, `array<array-key, string>`:

* a comparison, with anything: `1 === preg_match(…)`,
  `preg_match(…) !== false`, `false === preg_match(…)`, `preg_match(…) > 0`.
  Psalm reads a condition through a comparison with `true` or `false` as if
  the call returned a boolean; it returns `0` when nothing matches, so the
  plugin drops its condition from every comparison;
* a silenced call, `@preg_match(…)`;
* a result kept in a variable and tested later: `$ok = preg_match(…); if ($ok)`;
* the condition of a `while` loop;
* `$matches` that is not a variable, as `$this->matches`.

The plugin reads a call only when it can tell what the pattern is:

* the pattern and the flags must each have one constant value: a literal, a
  constant, or a variable holding one literal. A pattern that may be one of
  two literals, or flags from a parameter, are left to Psalm.
* Psalm keeps a literal string only below `maxStringLength` bytes (1000 by
  default, an attribute of `<psalm>`): a longer pattern is not constant for
  Psalm, and is not read.
* a call that unpacks its arguments, `preg_match(...$args)`, is left alone.
* a function of the same name in your namespace is yours: an unqualified
  `preg_match()` that reaches it is left alone.
* a pattern the PHP running Psalm does not compile, or the target PHP refuses,
  is not typed (the second is reported).
* a pattern past the library's length limit
  (`RegexParser::DEFAULT_MAX_PATTERN_LENGTH`) is neither typed nor reported:
  PCRE may accept it.
* a shape of more keys than Psalm's `maxShapedArraySize` is left to Psalm.
* `preg_match_all()` returns `false` and leaves `[]` for an offset past the
  subject, and for a match that ends before it starts (`\K` in a lookahead):
  a call whose pattern holds `\K`, or whose offset is not a constant ≤ 0, is
  left to Psalm.
* Psalm gets no `numeric-string`: Psalm 6.19's type combiner collapses
  `numeric-string|'a'` to `numeric-string`, which would reject `'none'` in
  `preg_match(…) ? $m[1] : 'none'`. A group of digits is a `non-falsy-string`
  where it cannot be `'0'`, else a `non-empty-string`.

The callbacks of `preg_replace_callback()` and `preg_replace_callback_array()`,
and the results of `preg_split()`, `preg_grep()`, `preg_replace()` and
`preg_filter()`, keep Psalm's types: the plugin only checks their patterns.

Configuration
-------------

Patterns are judged for the PHP version Psalm analyses for (`phpVersion` in
`psalm.xml`, `--php-version`, or your `composer.json`), 8.2 at least, with the
PCRE2 release that PHP bundles: 10.40 for 8.2, 10.42 for 8.3, 10.44 for 8.4 and
8.5. The plugin's options override it:

```xml
<plugins>
    <pluginClass class="PHPRegex\Psalm\Plugin">
        <phpVersion>8.2</phpVersion>
        <pcreVersion>10.40</pcreVersion>
    </pluginClass>
</plugins>
```

* `<phpVersion>`: a version (`8.2`), a `PHP_VERSION_ID` (`80200`), or
  `runtime`, the PHP running Psalm with the PCRE2 it links.
* `<pcreVersion>`: a PCRE2 release (`10.40`), for a PHP built against another
  PCRE2 than the one it bundles, as some Linux distributions do.

A value the plugin cannot read stops Psalm before any file is analysed.

With `<phpVersion>8.2</phpVersion>`, on Psalm running with PHP 8.4, the
`(?a…)` options of PCRE2 10.43 are reported in `app/numbers.php`:

```php
<?php

function is_ascii_number(string $input): bool
{
    return 1 === preg_match('/^(?aD)\d+$/u', $input);
}
```

```
ERROR: InvalidRegexPattern - app/numbers.php:5:29 - Regex pattern is invalid for PHP 8.2 with PCRE2 10.40: Invalid group modifier syntax at position 3 (offset 3).
```

On a project analysed below PHP 8.2, a pattern using the `n` modifier, which
PHP reads from 8.2 only, is not typed.

### The `InvalidRegexPattern` issue

It is a plugin issue: set its level, or suppress it, as any other.

```xml
<issueHandlers>
    <PluginIssue name="InvalidRegexPattern" errorLevel="info"/>
</issueHandlers>
```

```php
/** @psalm-suppress InvalidRegexPattern */
```

Adopting on an existing project
-------------------------------

The plugin may find invalid patterns, and the narrower `$matches` types may
show code that reads a key the pattern never writes. In `app/release.php`:

```php
<?php

function patch(string $tag): string
{
    if (preg_match('/^v(\d+)\.(\d+)$/', $tag, $m)) {
        return $m[3];
    }

    return '';
}
```

```
ERROR: InvalidArrayOffset - app/release.php:6:16 - Cannot access value on variable $m using offset value of '3', expecting 0, 1 or 2 (see https://psalm.dev/115)
ERROR: MixedReturnStatement - app/release.php:6:16 - Could not infer a return type (see https://psalm.dev/138)
```

Record what the plugin finds in a baseline, and fix the entries over time:

```bash
vendor/bin/psalm --set-baseline=psalm-baseline.xml
```

A later release may type a pattern more narrowly than the one before, which
may require regenerating the baseline with `--set-baseline`; the CHANGELOG
says when.

Backward compatibility
----------------------

The `Plugin` class, the `InvalidRegexPattern` issue name and the
`<phpVersion>` and `<pcreVersion>` options stay for all of 2.x. See
[the backward compatibility promise](https://php-regex.com/reference/backward-compatibility/).

Documentation
-------------

* [Psalm guide](https://php-regex.com/guides/psalm/) — the shape of `$matches`, the invalid patterns, the PHP version judged, adopting the plugin
* [Diagnostics](https://php-regex.com/reference/diagnostics/) — how findings are reported and how to read them

Resources
---------

* [Documentation](https://php-regex.com/docs/)
* [Report issues](https://github.com/php-regex/php-regex/issues) and
  [send pull requests](https://github.com/php-regex/php-regex/pulls) in the
  [main repository](https://github.com/php-regex/php-regex)

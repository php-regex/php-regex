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

Requires PHP 8.1+ to run and Psalm 6.19+. MIT licensed.

Documentation: [php-regex.com](https://php-regex.com) — the [Psalm guide](https://php-regex.com/guides/psalm/) is the place to start.

Features
--------

* Where `preg_match()` returned 1, `$matches` is the shape of the pattern: one
  key per group, named groups included, each holding what the group can
  capture (literals, as `'a'|'aa'|'aaa'` for `(a{1,3})`, a non-falsy string,
  a non-empty string), optional where PHP may leave the key out. Where it did
  not, `$matches` is `[]`.
* After `preg_match_all()`, `$matches` is the shape of the pattern, in either
  order, for a pattern without `\K` and an offset absent or a constant ≤ 0;
  where it returned a count of 1 or more, no list in it is empty.
* The callback of `preg_replace_callback()` takes the shape of the pattern, as
  `preg_match()` writes it on a match.
* `preg_split()` returns a `list<non-empty-string>` under
  `PREG_SPLIT_NO_EMPTY`, and `[piece, offset]` pairs typed
  `list{string, int<0, max>}` under `PREG_SPLIT_OFFSET_CAPTURE`, in a
  `non-empty-list` unless empty pieces are left out.
* Where `preg_match()` returned 1, a `string` subject is a `non-empty-string`
  when the pattern never matches `''`, a `non-falsy-string` when it matches
  neither `''` nor `'0'`, on a call that does not type `$matches`. When the
  pattern matches no more than 16 strings, as `/^(?:yes|no)$/D`, the subject
  is one of them.
* `PREG_OFFSET_CAPTURE` and `PREG_UNMATCHED_AS_NULL` are read, as are named
  arguments in any order.
* `preg_quote()` of literals is the literal it returns, so a pattern
  concatenated from literals and such calls is read as any literal. Around
  `preg_quote()` of a text not known, as `'/^' . preg_quote($id, '/') . '-(\d+)$/'`,
  `$matches` of `preg_match()` is typed from the rest of the pattern.
* An invalid pattern is reported as `InvalidRegexPattern`, with the error and
  its offset in the pattern, in every `preg_*` call: `preg_match()`,
  `preg_match_all()`, `preg_replace()`, `preg_replace_callback()`,
  `preg_replace_callback_array()` (its keys), `preg_split()`, `preg_grep()` and
  `preg_filter()`.
* Patterns are judged for the PHP version Psalm analyses for, with the PCRE2
  release that PHP bundles: a pattern your PHP 8.4 accepts but PHP 8.2 refuses
  is reported on a project that targets 8.2.
* The plugin keeps Psalm's own description of the `preg_*` functions: their
  purity, parameter checks and taint flows stay as they are, and a type it
  narrows keeps the data flow of Psalm's.

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

The plugin runs on every PHP Psalm 6 runs on, 8.1 or later. The code you
analyse may target an older PHP (see "Configuration").

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

Where `preg_match_all()` found a match (Psalm reports the bare call as a
`RiskyTruthyFalsyComparison`; a cast to `bool` reads the same):

```php
if ((bool) preg_match_all('/#([a-z]+)/', $text, $m)) {
    /** @psalm-trace $m */
}
```

```
$m: array{0: non-empty-list<non-falsy-string>, 1: non-empty-list<non-falsy-string>}
```

The callback of `preg_replace_callback()`, and `preg_split()`:

```php
preg_replace_callback('/#([a-z]+)(?::(\d+))?/', static function (array $m): string {
    /** @psalm-trace $m */

    return $m[1];
}, $text);

$words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
$pieces = preg_split('/,/', $text, -1, PREG_SPLIT_OFFSET_CAPTURE);
/** @psalm-trace $words */
/** @psalm-trace $pieces */
```

```
$m: array{0: non-falsy-string, 1: non-falsy-string, 2?: non-empty-string}
$words: false|list<non-empty-string>
$pieces: false|non-empty-list<list{string, int<0, max>}>
```

The subject, where `preg_match()` returned 1:

```php
function order_id(string $input): string
{
    if (!preg_match('/^\d+$/', $input)) {
        throw new InvalidArgumentException('Not an order id.');
    }

    /** @psalm-trace $input */

    return $input;
}
```

```
$input: non-empty-string
```

```php
function answer(string $input): bool
{
    if (!preg_match('/^(?:yes|no)$/', $input)) {
        throw new InvalidArgumentException('Answer yes or no.');
    }

    /** @psalm-trace $input */

    return 'yes' === $input;
}
```

```
$input: 'no'|'no\n'|'yes'|'yes\n'
```

`$` matches before a final `"\n"` too: `/^(?:yes|no)$/D` or `\z` leaves it out.

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
* a pattern concatenated around `preg_quote()` of a text not known types
  `$matches` of `preg_match()` where it returned 1, and nothing else: it is
  never reported, the subject is not narrowed, and where the call did not
  return 1 `$matches` keeps Psalm's type (a text holding the delimiter makes
  the pattern fail to compile, which leaves `$matches` as it was). Where the
  text would not be read as literal characters (in a class, in braces, after
  `\`, `(?` or `\Q`, before a quantifier), the call is left to Psalm.
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
  where it cannot be `'0'`, else a `non-empty-string`; so is the subject of
  `/^\d+$/`.
* a subject is narrowed to the strings its pattern matches only from offset
  0 (no offset argument), up to 16 strings each below `maxStringLength`
  bytes, and not when it holds literals already: where the call is false,
  Psalm would take those strings out of it, and a match error returns `false`
  on them too. Without `/u`, a caseless part, a shorthand class (`\d`, `\w`,
  `\s`…), a POSIX class or `\b` reads the character tables of the locale
  `setlocale()` sets: such a subject keeps the facts of its pattern.
* the subject of a call that types `$matches` keeps its type: Psalm reads a
  false condition as the negation of what holds where it is true, and on two
  variables that negation would take the `[]` from `$matches`. A subject that
  may be another type than `string`, or that is read from the `$matches`
  variable, keeps its type too.

The callback of `preg_replace_callback()` takes the shape of a constant
pattern, or of a pattern built around `preg_quote()`, and the flags must be
constant too; a callback the plugin cannot type keeps Psalm's
`callable(string[]): string`. `preg_match_all()` reads its success from the
forms `preg_match()` reads its own from: compared with anything, as
`preg_match_all(…) > 0`, it says nothing of `$matches`. `preg_split()` reads
constant flags only. The callbacks of `preg_replace_callback_array()`, and the
results of `preg_grep()`, `preg_replace()` and `preg_filter()`, keep Psalm's
types, which already follow the subject's: the plugin only checks their
patterns.

### Compared with the PHPStan extension

The plugin reads the same facts of a pattern as the PHPStan extension, and
says less in two places:

* A group of digits is never a `numeric-string`. For
  `/^(\d+)-(?<y>\d+)?$/`, PHPStan types `$matches` as
  `array{0: non-falsy-string, 1: numeric-string, y?: numeric-string, 2?: numeric-string}`,
  the plugin as
  `array{0: non-falsy-string, 1: non-empty-string, 2?: non-empty-string, y?: non-empty-string}`.
  Psalm's type combiner reads `numeric-string|'none'` as `numeric-string`, so
  with a `numeric-string` group Psalm would type
  `preg_match(…) ? $m[1] : 'none'` as a `numeric-string` and reject
  `'none' === $v`.
* A replacement of `preg_replace()` or `preg_filter()` that refers to a group
  the pattern does not have is not reported. `preg_replace('/(a)/', '[$2]', 'xay')`
  returns `'x[]y'`: PHP replaces `$2` with nothing. The PHPStan extension
  reports it as `regex.replacement.undefinedGroup`.

Configuration
-------------

Patterns are judged for the PHP version Psalm analyses for (`phpVersion` in
`psalm.xml`, `--php-version`, or your `composer.json`), 8.1 at least. That
version, and each version a guard or a marker keeps, gets its PCRE2 in this
order:

1. the release `<pcreVersion>` names, else the one the `composer.json` of
   Psalm's base directory pins in `config.platform.lib-pcre` (`"10.42"`, or
   `"10.42.0"` as Composer spells it), used as is, older or newer than the one
   that PHP bundles;
2. the PCRE2 of the PHP running Psalm, when the version judged is its minor
   version: on a PHP 8.4.27 that links 10.49, PHP 8.4 is judged with 10.49;
3. the PCRE2 that PHP bundles: 10.39 for 8.1, 10.40 for 8.2, 10.42 for 8.3,
   10.44 for 8.4 and 8.5.

The plugin's options override the version and the release:

```xml
<plugins>
    <pluginClass class="PHPRegex\Psalm\Plugin">
        <phpVersion>8.2</phpVersion>
        <pcreVersion>10.40</pcreVersion>
        <locale>tr_TR.UTF-8</locale>
    </pluginClass>
</plugins>
```

* `<phpVersion>`: a version (`8.2`), a `PHP_VERSION_ID` (`80200`), or
  `runtime`, the PHP running Psalm with the PCRE2 it links.
* `<pcreVersion>`: a PCRE2 release (`10.40`), for a PHP built against another
  PCRE2 than the one it bundles, as some Linux distributions do.
* `<locale>`: the `LC_CTYPE` locale the patterns run under (`tr_TR.UTF-8`),
  the one of the PHP running Psalm by default.

A value the plugin cannot read, or a locale this machine cannot set, stops
Psalm before any file is analysed.

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
PHP reads from 8.2 only, is not typed, and is reported as `InvalidRegexPattern`.

A call under `if (PHP_VERSION_ID >= 80400)`, or after an early `return` below
it, is judged for the versions the guard keeps of the series Psalm analyses
for, the guard read from the file as `regex lint` reads it; one that keeps
none leaves the call unjudged. Psalm's own narrowing of the constant is not
read: Psalm narrows it under `function_exists()` of a newer function too,
polyfilled or not. A call under `// @regex-target php>=8.4` (or
`pcre>=10.43`) is judged for the versions the marker keeps, and for those the
guard keeps too; a marker that keeps none, or cannot be read, is reported as
`InvalidRegexTarget`.

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

* [Upgrading from 1.x](https://github.com/php-regex/php-regex/blob/2.x/UPGRADE-2.0.md) — moving from `yoeunes/regex-parser` 1.3 to 2.0, every change that needs one on your side
* [Documentation](https://php-regex.com/docs/)
* [Report issues](https://github.com/php-regex/php-regex/issues) and
  [send pull requests](https://github.com/php-regex/php-regex/pulls) in the
  [main repository](https://github.com/php-regex/php-regex)

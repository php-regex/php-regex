<p align="center">
    <a href="https://php-regex.com"><img src="https://img.shields.io/badge/documentation-php--regex.com-blue" alt="Documentation Badge"></a>
    <a href="https://www.linkedin.com/in/younes--ennaji"><img src="https://img.shields.io/badge/author-@yoeunes-blue.svg" alt="Author Badge"></a>
    <a href="https://github.com/php-regex/php-regex/releases"><img src="https://img.shields.io/github/tag/php-regex/php-regex.svg" alt="GitHub Release Badge"></a>
    <a href="https://github.com/php-regex/php-regex/blob/2.x/LICENSE"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg" alt="License Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-rector"><img src="https://img.shields.io/packagist/dt/php-regex/regex-rector.svg" alt="Packagist Downloads Badge"></a>
    <a href="https://github.com/php-regex/php-regex"><img src="https://img.shields.io/github/stars/php-regex/php-regex.svg" alt="GitHub Stars Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-rector"><img src="https://img.shields.io/packagist/php-v/php-regex/regex-rector.svg" alt="Supported PHP Version Badge"></a>
</p>

PHPRegex Rector
===============

Rector rules that rewrite a `preg_*` call into the string function that does
the same thing, only when the automata prove it: no proof, no change. One more
rule readies patterns for the PCRE2 of PHP 8.4, which reads some of them
otherwise.

Requires PHP 8.1+ to run and Rector 2.4 or later. MIT licensed.

Documentation: [php-regex.com](https://php-regex.com) — the [Rector guide](https://php-regex.com/guides/rector/) walks each rewrite and the proof that unlocks it.

Features
--------

* `preg_match()` read as a boolean becomes `str_contains()`, `str_starts_with()`,
  `str_ends_with()`, `===` or `in_array(…, true)`.
* `preg_replace()` becomes `str_replace()`, `preg_split()` becomes `explode()`,
  when the pattern matches exactly one string.
* Every rewrite is proven: the automata compare the pattern with the string
  function on every subject before the code changes. A pattern they cannot
  prove, and any call whose shape they do not cover, is left as it is.
* The pattern must compile on the PHP running Rector and on the PHP your code
  targets, and the automata must give the same answer with the PCRE2 of
  both; the subject must be a string by its type.
* The functions are called fully qualified, `\str_contains()`: in a
  namespace that declares or imports a function of the same name, an
  unqualified call would reach that one.
* A pattern PHP 8.4 reads otherwise, `/a{,3}/` (text before PCRE2 10.43, a
  quantifier from it), is rewritten to keep its meaning: `/a\{,3}/`.
* A pattern an older PHP your code runs on refuses, and a later one reads, is
  spelled the way every PHP reads it: `/(*pla:a)b/` (PHP 7.4 and later) as
  `/(?=a)b/`, a NUL byte (PHP 8.2 and later) as `\x00`, `/a{65536/` (PHP 8.1
  and later) as `/a\{65536/`.
* Code written for `yoeunes/regex-parser` 1.3 moves to the 2.0 names: every
  class, enum case and renamed method, with `RegexSetList::UPGRADE_2_0`.
* Seven rules and three sets, `RegexSetList::STRING_FUNCTIONS`,
  `RegexSetList::PCRE_UPGRADE` and `RegexSetList::UPGRADE_2_0`. The rule names
  and the sets stay the same for all of 2.x; the rules take no configuration.

Installation
------------

```bash
composer require --dev php-regex/regex-rector
```

Rector loads the rules from your `rector.php`; nothing else to register.

### Projects pinned below PHP 8.1

The package needs PHP 8.1 to run, not to be your target: install Rector and the
rules in a directory of their own, and run them with PHP 8.1 or later.

```bash
mkdir -p tools/rector
composer require --working-dir=tools/rector --dev rector/rector php-regex/regex-rector
tools/rector/vendor/bin/rector process
```

Rector reads the PHP version your code targets from your `composer.json`
(`require.php`), or from `withPhpVersion()` in `rector.php`.

Usage
-----

Enable the set in `rector.php`:

```php
use PHPRegex\Rector\Set\RegexSetList;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src'])
    ->withSets([RegexSetList::STRING_FUNCTIONS]);
```

Or pick single rules:

```php
use PHPRegex\Rector\PregMatchToStringComparisonRector;
use PHPRegex\Rector\PregReplaceToStrReplaceRector;
use PHPRegex\Rector\PregSplitToExplodeRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src'])
    ->withRules([
        PregMatchToStringComparisonRector::class,
        PregReplaceToStrReplaceRector::class,
        PregSplitToExplodeRector::class,
    ]);
```

Then run `vendor/bin/rector process --dry-run` to see the changes, and
`vendor/bin/rector process` to apply them.

After upgrading this package, run Rector once with `--clear-cache`: Rector
caches the files it found nothing to change in, and a new release may prove
patterns the previous one could not.

### Upgrading from 1.x

`RegexSetList::UPGRADE_2_0` moves code written for `yoeunes/regex-parser` 1.3
to the 2.0 names: `RegexParser\Regex` to `PHPRegex\Toolkit\Regex`,
`ReDoSSeverity::HIGH` to `RedosSeverity::High`, `clearValidatorCaches()` to
`clearCaches()`. Run Rector twice: the second run renames the methods called
on an object whose class the first one renamed.

```php
use PHPRegex\Rector\Set\RegexSetList;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src'])
    ->withSets([RegexSetList::UPGRADE_2_0])
    ->withImportNames(removeUnusedImports: true);
```

The [upgrade guide](https://github.com/php-regex/php-regex/blob/2.x/UPGRADE-2.0.md)
lists what the set cannot change.

The rules
---------

### `PregMatchToStringComparisonRector`

```php
function check(string $url, string $path, string $method, string $status, string $text): void
{
    if (preg_match('/^https:/', $url)) {
        echo 'secure';
    }

    $isJson = preg_match('/\.json\z/', $path) === 1;
    $isSafe = !preg_match('/^(?:GET|HEAD)\z/', $method);
    $isFinal = preg_match('/^draft\z/', $status) === 0;
    $isEmpty = (bool) preg_match('/^\z/', $text);
    $line = preg_match('/^end$/', $text) ? 'last' : 'more';
}
```

becomes

```php
function check(string $url, string $path, string $method, string $status, string $text): void
{
    if (\str_starts_with($url, 'https:')) {
        echo 'secure';
    }

    $isJson = \str_ends_with($path, '.json');
    $isSafe = !\in_array($method, ['GET', 'HEAD'], true);
    $isFinal = 'draft' !== $status;
    $isEmpty = '' === $text;
    $line = \in_array($text, ['end', "end\n"], true) ? 'last' : 'more';
}
```

`/^end$/` is no `===`: `$` also matches before a final newline, so the
subject `"end\n"` matches too.

The call is rewritten only where its value is read as true or false: the
condition of an `if`, `elseif`, `while`, `do … while`, a full ternary or the
last expression of a `for`; an operand of `!`, `&&`, `||`, `and`, `or`,
`xor`; a `(bool)` cast; a strict comparison with `1` or `0` on either side
(`=== 1`, `!== 0`, `=== 0`, `!== 1`), and `> 0`.

Left alone, and why:

* Any other read: an assignment, a `return`, an argument, `?:`, `??`,
  `match`, `== 1`, `=== true`, `=== false`, `=== null`. `preg_match()` returns
  `1`, `0` or `false`, and these see the difference.
* `$matches`, flags or an offset; named or unpacked arguments; a call
  directly under `@`; a first-class callable `preg_match(...)`. A call nested
  deeper in an `@` expression, `@($ok && preg_match(…))`, is rewritten: the
  string function raises nothing the `@` would have hidden.
* A pattern that is not one constant string, or that the running PHP does
  not compile.
* `/u`: on a subject that is not valid UTF-8, `preg_match()` fails where the
  string function answers.
* `/i` in any form, the shorthand classes `\d`, `\s`, `\w`, `\h`, `\v` (and
  their negations) and POSIX classes: once a program calls `setlocale()`, PHP
  builds PCRE's character tables from the locale, so their answer moves with
  it.
* `/A` and any verb such as `(*LIMIT_MATCH=1)` or `(*CRLF)`.
* A pattern that reaches one string along two paths, as `(?:a|a)` or
  `/^a?a?\z/`: the engine tries both, and repeated they multiply.
  `preg_match('/(?:a|a){20}b/', $s)` returns `false` ("Backtrack limit
  exhausted") on forty `a` and a `c`, where `str_contains()` answers.
* With `x` or `xx` on, as a modifier or as `(?x)`, a raw byte above 0x7F
  anywhere in the pattern: extended mode skips the bytes the locale's tables
  call white space (a raw 0xA0 under `nl_NL.UTF-8` on macOS). The escape
  `\xa0` is fine, and so is the raw byte without `x`.
* A literal that would hold DEL (0x7F), which the printer writes as an
  invisible raw byte (this holds for the three rules).
* A subject that may be something else than a string: the string functions
  throw a `TypeError` under `strict_types` where `preg_match()` converts.
  `===` and `in_array()` compare without converting, so for them the
  subject's declared type must be `string`; a `@param string` is not enough.
* For `===` and `!==`, a subject that is not a variable, a property, an array
  element, a call or a constant: out of the call's parentheses, `$a ?: $b`,
  `$a ?? $b`, an assignment or a concatenation would bind to the comparison.
* Code that targets PHP below 8.0, which has no `str_contains()`.
* A pattern the PHP your code targets refuses: `n` before 8.2, `r` before
  8.4, a raw NUL byte before 8.2 (`\x00`, the escape, is fine). Or one it
  reads otherwise: the proof is made again with the PCRE2 that PHP bundles,
  and with the PCRE2 of PHP 8.3 and older `/a{,0}b/` is the text `a{,0}b`,
  not `b`. This holds for the three rules.

### `PregReplaceToStrReplaceRector`

```php
$unix = preg_replace('/\r\n/', "\n", $text);
$dots = preg_replace('/\.{3}/', '…', $text);
```

becomes

```php
$unix = \str_replace("\r\n", "\n", $text);
$dots = \str_replace('...', '…', $text);
```

Both scan the subject from left to right and replace the occurrences that do
not overlap: `/aa/` on `aaa` gives `Xa` either way.

Left alone, and why:

* A pattern that may match more than one string: `/ab?/` finds what `/a/`
  finds, yet replaces `ab` whole (`preg_replace('/ab?/', 'X', 'ab')` is `X`,
  `str_replace('a', 'X', 'ab')` is `Xb`). The pattern must match exactly one
  non-empty string, proven by the automata.
* An anchor, `\b`, a lookaround, `\K` or a backreference: where the string is
  found, or whether it is, then depends on what surrounds it.
* A replacement that is not one constant string, or that holds a `$` or a
  `\`: `preg_replace()` reads group references there (`$1`, `${1}`, `\1`).
* Arrays, a limit or a count argument; named or unpacked arguments; a call
  directly under `@` (one nested deeper is rewritten).
* `/i`, `/u`, `/A`, the shorthand and POSIX classes, the verbs, a string
  reached along two paths (`preg_replace('/(?:a|a){20}b/', …)` returns
  `null` where `str_replace()` answers) and a raw byte above 0x7F under `x`,
  for the reasons given for `preg_match()`.
* A subject that may be something else than a string.
* A pattern the target PHP refuses or reads otherwise, as for
  `preg_match()`. The rule has no other version floor.

### `PregSplitToExplodeRector`

```php
$cells = preg_split('/;/', $csv);
$parts = preg_split('/\|/', $csv, -1);
```

becomes

```php
$cells = \explode(';', $csv);
$parts = \explode('|', $csv);
```

Both keep the empty pieces, and both return `['']` for the empty subject.

Left alone, and why:

* A pattern that may match more than one string, or the empty one:
  `/[,;]/`, `/,?/`, `//`.
* Flags, or a limit other than a literal `-1` or `0`; a `null` limit, which
  `preg_split()` refuses under `strict_types`.
* Named or unpacked arguments; a call directly under `@` (one nested deeper
  is rewritten); a subject that may be something else than a string; the pattern modifiers and constructs refused above.
* A pattern the target PHP refuses or reads otherwise, as for
  `preg_match()`. The rule has no other version floor.

### The patterns the upgrade rules read

The four rules of the `RegexSetList::PCRE_UPGRADE` set read the pattern of
each `preg_*()` call, each key of the array `preg_replace_callback_array()`
takes, and each argument a function or a static method marks with
`#[PHPRegex\Parser\Attribute\RegexPattern]` or PhpStorm's
`#[Language('RegExp')]` receives, as `regex lint` reads them:

```php
function matches(#[RegexPattern] string $pattern, string $subject): bool { /* ... */ }

matches('/a{,3}/', $subject);   // becomes matches('/a\{,3}/', $subject) for PHP 8.3 and earlier
```

Each is a string literal rewritten in place, or the declaration of a private
class constant its class reads only there, written `self::NAME`:

```php
final class Version
{
    private const PATTERN = '/a{,3}/';   // becomes '/a\{,3}/' for PHP 8.3 and earlier

    public function matches(string $subject): bool
    {
        return 1 === preg_match(self::PATTERN, $subject);
    }
}
```

Any other constant is left as it is: rewriting the call would inline the
constant, and rewriting a public, protected or global constant would change
what code elsewhere reads. A private constant its class also reads another way
(a string function, `static::NAME`, its class name, another constant, an
attribute, `constant()` or a call whose function is a variable) is left too,
and so is every private constant of a class that uses a trait.

### `EscapeLiteralBraceRector`

In the `RegexSetList::PCRE_UPGRADE` set. Before PCRE2 10.43 (PHP 8.3 and
earlier) `{,3}` and `{ 2 }` are text; from 10.43 (PHP 8.4) they are
quantifiers. For code that targets a PHP below 8.4,

```php
preg_match('/a{,3}/', $subject);   // finds "a{,3}" on PHP 8.3, "a" on PHP 8.4
```

becomes

```php
preg_match('/a\{,3}/', $subject);  // finds "a{,3}" on both
```

The rule reads the range itself, with nothing to configure: from the PHP your
code targets (`withPhpVersion()`, else `composer.json`) to the newest PHP the
library knows, through every PHP version where a verdict may change, each with
the PCRE2 its sources bundle. A backslash goes before each brace read as text
outside a class, and the call changes only when a PHP of that range reads the
pattern otherwise, and the rewritten pattern then reads alike on every PHP of
it. Left alone: code that targets PHP 8.4 or later, where the pattern already
means what it says; a pattern built from more than one string; a pattern
holding `\Q`, or delimited by `{}`; one a later PHP of the range refuses, as
PHP 8.5 refuses `/(?<=a\Kb)c{,3}/` whatever its brace reads.

The rule also readies a pattern the targeted PHP refuses for its brace: before
PCRE2 10.37 (PHP 8.0 and earlier), the digits after a `{` that opens no
quantifier are read as a count, and `/a{65536/` fails with "number too big in
{} quantifier"; from 10.37 the brace is text. The call becomes `/a\{65536/`,
text on every PHP, as the newest PHP read it.
Run it before raising the PHP version in `composer.json`.

### `AlphabeticAssertionToClassicRector`

In the `RegexSetList::PCRE_UPGRADE` set. PCRE2 10.33 (PHP 7.4) named the
assertions and the atomic group: `(*pla:`, `(*nla:`, `(*plb:`, `(*nlb:`,
`(*atomic:` and their long forms, as `(*positive_lookahead:`. PHP 7.3, on
10.32, refuses them. For code that targets PHP 7.3,

```php
preg_match('/(*pla:foo)\w+(*nlb:bar)/', $subject);  // fails to compile on PHP 7.3
```

becomes

```php
preg_match('/(?=foo)\w+(?<!bar)/', $subject);        // the same tree on every PHP
```

The call changes only when the PHP your code targets refuses the pattern, and
the rewritten pattern then reads alike on every PHP from it to the newest the
library knows, and as the newest read the original. The non-atomic
assertions, `(*napla:` and `(?*`, and the script runs, `(*sr:`, have no
spelling an older release reads: they are left as they are.

### `EscapeNulByteRector`

In the `RegexSetList::PCRE_UPGRADE` set. PHP 8.1 and earlier refuse a pattern
holding a NUL byte ("Null byte in regex"); PHP 8.2 reads it. For code that
targets PHP 8.1 or earlier,

```php
preg_match("/a\0b/", $subject);   // fails on PHP 8.1
```

becomes

```php
preg_match('/a\x00b/', $subject); // the same character on every PHP
```

A byte the pattern reads as a character of its own is escaped, inside a class
too. A byte after a backslash or in a comment, a pattern holding `\Q`, and a
pattern built from more than one string are left as they are.

### `PossessiveRepeatOnceRector`

In the `RegexSetList::PCRE_UPGRADE` set. PCRE2 10.33 and earlier (PHP 7.3,
and 7.4.0 to 7.4.5) ignore `{1}`, possessive or not; from 10.34 (PHP 7.4.6)
a possessive `{1}+` makes the group it stands on atomic. The tree is the same
on both sides, so only the release table tells the change. For code that
targets PHP 7.3 or 7.4.0 to 7.4.5,

```php
preg_match('/(a|ab){1}+c/', $subject);  // finds "abc" in "abc" on PHP 7.4.0, nothing from 7.4.6
```

becomes

```php
preg_match('/(a|ab){1}c/', $subject);   // finds "abc" on every PHP
```

The `+` goes from a `{1}`, `{1,1}` or `{01}` on a group, a script run or a
subroutine call, and the call changes only when the rewritten pattern reads
alike on every PHP from the one your code targets to the newest the library
knows. A repeat on a single character, an atomic group or a lookaround means
the same on every release, and is left as written; so is code that targets
PHP 7.4.6 or later.

Documentation
-------------

* [Rector guide](https://php-regex.com/guides/rector/) — what the rules prove and how
* [Prefilters](https://php-regex.com/reference/prefilters/) — `TrivialMatchClassifier`, the proof behind the rules

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released
with its siblings under one version number. Read
[the backward compatibility promise](https://php-regex.com/reference/backward-compatibility/).

Resources
---------

* [Upgrading from 1.x](https://github.com/php-regex/php-regex/blob/2.x/UPGRADE-2.0.md) — moving from `yoeunes/regex-parser` 1.3 to 2.0, every change that needs one on your side
* [Documentation](https://php-regex.com/docs/)
* The proof behind the rules: [regex-automata](https://github.com/php-regex/php-regex/tree/2.x/src/Automata)
* [Changelog](CHANGELOG.md)
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

<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=2">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=2">
        <img src="art/banner.png?v=2" alt="PHPRegex Transpiler" width="100%">
    </picture>
</p>

<p align="center">
    <a href="https://php-regex.com"><img src="https://img.shields.io/badge/documentation-php--regex.com-blue" alt="Documentation Badge"></a>
    <a href="https://www.linkedin.com/in/younes--ennaji"><img src="https://img.shields.io/badge/author-@yoeunes-blue.svg" alt="Author Badge"></a>
    <a href="https://github.com/php-regex/php-regex/releases"><img src="https://img.shields.io/github/tag/php-regex/php-regex.svg" alt="GitHub Release Badge"></a>
    <a href="https://github.com/php-regex/php-regex/blob/2.x/LICENSE"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg" alt="License Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-transpiler"><img src="https://img.shields.io/packagist/dt/php-regex/regex-transpiler.svg" alt="Packagist Downloads Badge"></a>
    <a href="https://github.com/php-regex/php-regex"><img src="https://img.shields.io/github/stars/php-regex/php-regex.svg" alt="GitHub Stars Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-transpiler"><img src="https://img.shields.io/packagist/php-v/php-regex/regex-transpiler.svg" alt="Supported PHP Version Badge"></a>
</p>

PHPRegex Transpiler
===================

Transpiles PCRE patterns to JavaScript regular expressions, HTML `pattern` attributes and Python regular expressions, with the losses reported.

Documentation: [php-regex.com](https://php-regex.com) — the [API reference](https://php-regex.com/reference/api/) documents `transpile()` in full.

Features
--------

- Three targets, `javascript` (alias `js`), `html-pattern` (alias `html`) and `python` (alias `py`), each producing a paste-ready literal and a constructor call
- Dialect rewrites: `(?P<name>)` becomes `(?<name>)` in JavaScript, `\k<name>` becomes `(?P=name)` in Python, `\h` and `\v` become character classes
- What a target reads otherwise is written out as PCRE reads it: `$`, which also matches before a final newline, `^` and `$` under `m`, the dot, which leaves out `\n` alone, and, under `u`, `\d`, `\w`, `\s` and `\b`, which PHP reads in Unicode
- Flags are mapped per target: JavaScript keeps `i`, `s`, `u`, gains `u` when Unicode escapes need it, and has `/m`, `/D`, `/x` and `/U` applied while printing; Python keeps `i` and `s`, gains `re.ASCII` without `u`, has `/m`, `/D`, `/x` and `/U` applied while printing, and spells flags inline or as `re` constants; every target drops `/S`, which PHP has ignored since 7.3, with a note
- Warnings list every rewrite to review; notes list the flags applied or dropped
- A construct the target cannot express without matching otherwise — `\w` under `u` in Python, a possessive quantifier or an atomic group in JavaScript, and in Python unless `pythonVersion` is 3.11 or later, a backreference to a group that may not have matched in JavaScript — throws a `TranspileException` carrying the position in the pattern
- Every target is checked against its own engine: the test suite runs the transpiled patterns in Node and Python on the subjects `preg_match()` is run on, and compares the matches and the groups

Installation
------------

```bash
composer require php-regex/regex-transpiler
```

PHP 8.1 or newer. `php-regex/regex-parser` is pulled in automatically.

Configuration
-------------

`transpile()` takes an optional `TranspileOptions` object:

- `allowLookbehind` (bool, default `true`) — `false` makes lookbehind throw for JavaScript targets.
- `pythonVersion` (string or null, default `null`) — the oldest Python the pattern runs on, as `'3.11'` or
  `'3.11.4'`. Python's `re` takes atomic groups and possessive quantifiers from 3.11: from that version they are
  written, before it, or with `null`, they are refused.

```php
use PHPRegex\Transpiler\Transpiler;
use PHPRegex\Transpiler\TranspileOptions;

$result = (new Transpiler())->transpile('/\d++(?>px|em)/', 'python', new TranspileOptions(pythonVersion: '3.12'));

echo $result->literal; // r'(?a)\d++(?>px|em)'
```

A possessive quantifier is the atomic group around the greedy repetition to PCRE, and is written so where Python's
own goes wrong: before 3.11.5, where a possessive repetition inside another runs past its end (`a++` is `(?>a+)` for
`'3.11'`), and over a group, which Python 3.11 to 3.12.7 and 3.13.0 lose the capture of (`(a|b)++` is `(?>(a|b)+)`).

Usage
-----

A PCRE pattern with a named group and a backreference, for a JavaScript codebase:

```php
use PHPRegex\Transpiler\Transpiler;

$transpiler = new Transpiler();
$result = $transpiler->transpile('/(?P<word>\w+)\k{word}/i', 'javascript');

echo $result->literal;  // /(?<word>\w+)\k<word>/i

// "$" also matches before a newline that ends the subject; JavaScript's does not.
echo $transpiler->transpile('/^\d+$/', 'javascript')->literal; // /^\d+(?=\n?$)/
```

Without a parser the transpiler reads with `RegexParser::create()`; pass the
one your code reads with, as `new Transpiler($parser)`, to read the pattern for
its target and limits.

For Python, the literal is a raw string carrying the flags inline, and the constructor is a `re.compile()` call.
Without `u` PCRE reads `\w`, `\d`, `\s`, `\b` and case in ASCII, so the pattern gains `re.ASCII`:

```php
$result = $transpiler->transpile('/(?P<word>\w+)\k<word>/i', 'python');

echo $result->literal;     // r'(?ai)(?P<word>\w+)(?P=word)'
echo $result->constructor; // re.compile(r'(?P<word>\w+)(?P=word)', re.ASCII | re.IGNORECASE)
```

Every rewrite is reported, and what a target cannot express is refused rather than approximated:

```php
$result = $transpiler->transpile('/\p{L}+/', 'javascript');

echo $result->literal;     // /\p{L}+/u
echo $result->warnings[0]; // Added /u for Unicode property escapes.

// PCRE2 reads a bare script name as the script's extensions (from 10.40; the script alone before); JavaScript names them.
echo $transpiler->transpile('/\p{Han}+/u', 'javascript')->literal; // /\p{Script_Extensions=Han}+/u

try {
    $transpiler->transpile('/a++/', 'javascript');
} catch (\PHPRegex\Transpiler\Exception\TranspileException $e) {
    echo $e->getMessage(); // Possessive quantifiers are not supported in JavaScript.
}
```

For an HTML form, the `pattern` attribute matches the whole value under the
`v` flag; the `html-pattern` target pads an unanchored side so the attribute
accepts what `preg_match()` finds, and escapes in classes what `v` reserves:

```php
$result = $transpiler->transpile('/^[\w.-]+@[\w-]+\.[a-z]{2,}$/', 'html-pattern');

echo $result->literal;     // ^[\w\.\-]+@[\w\-]+\.[a-z]{2,}$
echo $transpiler->transpile('/\d{4}/', 'html')->literal; // [\s\S]*(?:\d{4})[\s\S]*
```

No flag reaches the attribute. `/i`, `(?i)` and `(?i:…)` are spelled out:
each letter, class or property is written with every character the running
PCRE takes for it caselessly, so the attribute works in every browser that
has the `v` flag, and under `u` the Kelvin sign and the long s come along:

```php
echo $transpiler->transpile('/^[a-z]+-\d+$/i', 'html')->literal;  // ^[a-zA-Z]+-\d+$
echo $transpiler->transpile('/^ok$/iu', 'html')->literal;         // ^[oO][kK\u212A]$
```

A backreference under `/i` is refused: it would match its group's text in
one case only. `/s`, `/m` and `/D` change nothing in a field value, which
holds no line break. `/S`, which PHP has ignored since 7.3, is dropped with a
note, there as for JavaScript, and `/U` or `(?U)` swaps greedy and lazy in the
quantifiers it governs: `/<.+>/U` is `/<[^\n]+?>/` for JavaScript, the dot
written as PCRE reads it.

JavaScript and Python read characters, where PCRE without `u` reads bytes. A
multibyte character written whole (`/café/`) stays that character; a byte above
0x7F on its own, as an escape (`\xE9`, `[\x80-\xFF]`) or as invalid UTF-8, has
no equivalent there and is refused: add `u`, or write the character itself.

Documentation
-------------

- [API reference](https://php-regex.com/reference/api/) — the `transpile()` entry point and the `TranspileResult` fields
- [CLI guide](https://php-regex.com/guides/cli/) — the `regex transpile` command, its `--target` option and its exit codes
- [Backward compatibility promise](https://php-regex.com/reference/backward-compatibility/) — what stays stable across releases

Resources
---------

* [Upgrading from 1.x](https://github.com/php-regex/php-regex/blob/2.x/UPGRADE-2.0.md) — moving from `yoeunes/regex-parser` 1.3 to 2.0, every change that needs one on your side
* [Documentation](https://php-regex.com/docs/)
* The parsing core it builds on: [regex-parser](https://github.com/php-regex/php-regex/tree/2.x/src/Parser)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and [send pull requests](https://github.com/php-regex/php-regex/pulls) in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

Sponsors
---------

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

License
-------

MIT. See [LICENSE](https://github.com/php-regex/php-regex/blob/2.x/LICENSE).

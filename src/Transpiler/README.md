<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=2">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=2">
        <img src="art/banner.png?v=2" alt="PHPRegex Transpiler" width="100%">
    </picture>
</p>

PHPRegex Transpiler
===================

Transpiles PCRE patterns to JavaScript regular expressions, HTML `pattern` attributes and Python regular expressions, with the losses reported.

Features
--------

- Three targets, `javascript` (alias `js`), `html-pattern` (alias `html`) and `python` (alias `py`), each producing a paste-ready literal and a constructor call
- Dialect rewrites: `(?P<name>)` becomes `(?<name>)` in JavaScript, `\k<name>` becomes `(?P=name)` in Python, `\h` and `\v` become character classes, atomic groups are emulated in Python
- Flags are mapped per target: JavaScript keeps `i`, `m`, `s`, `u`, gains `u` when Unicode escapes need it, and has `/x` applied then dropped; Python keeps `i`, `m`, `s`, `x` and spells flags inline or as `re` constants
- Warnings list every rewrite to review; notes list run-time caveats, such as ASCII-based `\w` in JavaScript
- A construct the target cannot express — a possessive quantifier, `\p{...}` in Python — throws a `TranspileException` carrying the position in the pattern

Installation
------------

```bash
composer require php-regex/regex-transpiler
```

PHP 8.2 or newer. `php-regex/regex-parser` is pulled in automatically.

Configuration
-------------

`transpile()` takes an optional `TranspileOptions` object: `allowLookbehind`
(bool, default `true`) — `false` makes lookbehind throw for JavaScript targets.

Usage
-----

A PCRE pattern with a named group and a backreference, for a JavaScript codebase:

```php
use PHPRegex\Parser\RegexParser;
use PHPRegex\Transpiler\Transpiler;

$transpiler = new Transpiler(RegexParser::create());
$result = $transpiler->transpile('/(?P<word>\w+)\k{word}/i', 'javascript');

echo $result->literal;  // /(?<word>\w+)\k<word>/i
echo $result->notes[0]; // JavaScript \w and \b are ASCII-based; Unicode word boundaries may differ.
```

For Python, the literal is a raw string carrying the flags inline, and the constructor is a `re.compile()` call:

```php
$result = $transpiler->transpile('/(?P<word>\w+)\k<word>/i', 'python');

echo $result->literal;     // r'(?i)(?P<word>\w+)(?P=word)'
echo $result->constructor; // re.compile(r'(?P<word>\w+)(?P=word)', re.IGNORECASE)
```

Every rewrite is reported, and what a target cannot express is refused rather than approximated:

```php
$result = $transpiler->transpile('/\p{L}+/', 'javascript');

echo $result->literal;     // /\p{L}+/u
echo $result->warnings[0]; // Added /u for Unicode property escapes.

// PCRE2 reads a bare script name as the script's extensions; JavaScript names them.
echo $transpiler->transpile('/\p{Han}+/u', 'javascript')->literal; // /\p{Script_Extensions=Han}+/u

try {
    $transpiler->transpile('/a++/', 'javascript');
} catch (\PHPRegex\Transpiler\TranspileException $e) {
    echo $e->getMessage(); // Possessive quantifiers are not supported in JavaScript.
}
```

For an HTML form, the `pattern` attribute matches the whole value under the
`v` flag; the `html-pattern` target pads an unanchored side so the attribute
accepts what `preg_match()` finds, and escapes in classes what `v` reserves:

```php
$result = $transpiler->transpile('/^[\w.-]+@[\w-]+\.[a-z]{2,}$/u', 'html-pattern');

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
quantifiers it governs: `/<.+>/U` is `/<.+?>/` for JavaScript.

JavaScript and Python read characters, where PCRE without `u` reads bytes. A
multibyte character written whole (`/café/`) stays that character; a byte above
0x7F on its own, as an escape (`\xE9`, `[\x80-\xFF]`) or as invalid UTF-8, has
no equivalent there and is refused: add `u`, or write the character itself.

Documentation
-------------

- [API reference](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/api.md) — the `transpile()` entry point and the `TranspileResult` fields
- [CLI guide](https://github.com/php-regex/php-regex/blob/2.x/docs/guides/cli.md) — the `regex transpile` command, its `--target` option and its exit codes
- [Backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md) — what stays stable across releases

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
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

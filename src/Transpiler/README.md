<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=2">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=2">
        <img src="art/banner.png?v=2" alt="PHPRegex Transpiler" width="100%">
    </picture>
</p>

PHPRegex Transpiler
===================

Transpiles PCRE patterns to JavaScript and Python regular expressions, with the losses reported.

Features
--------

- Two targets, `javascript` (alias `js`) and `python` (alias `py`), each producing a paste-ready literal and a constructor call
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

try {
    $transpiler->transpile('/a++/', 'javascript');
} catch (\PHPRegex\Transpiler\TranspileException $e) {
    echo $e->getMessage(); // Possessive quantifiers are not supported in JavaScript.
}
```

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

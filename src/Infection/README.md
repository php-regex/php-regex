<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=1">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=1">
        <img src="art/banner.png?v=1" alt="PHPRegex Infection" width="100%">
    </picture>
</p>

<p align="center">
    <a href="https://php-regex.com"><img src="https://img.shields.io/badge/documentation-php--regex.com-blue" alt="Documentation Badge"></a>
    <a href="https://www.linkedin.com/in/younes--ennaji"><img src="https://img.shields.io/badge/author-@yoeunes-blue.svg" alt="Author Badge"></a>
    <a href="https://github.com/php-regex/php-regex/releases"><img src="https://img.shields.io/github/tag/php-regex/php-regex.svg" alt="GitHub Release Badge"></a>
    <a href="https://github.com/php-regex/php-regex/blob/2.x/LICENSE"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg" alt="License Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-infection"><img src="https://img.shields.io/packagist/dt/php-regex/regex-infection.svg" alt="Packagist Downloads Badge"></a>
    <a href="https://github.com/php-regex/php-regex"><img src="https://img.shields.io/github/stars/php-regex/php-regex.svg" alt="GitHub Stars Badge"></a>
    <a href="https://packagist.org/packages/php-regex/regex-infection"><img src="https://img.shields.io/packagist/php-v/php-regex/regex-infection.svg" alt="Supported PHP Version Badge"></a>
</p>

PHPRegex Infection
==================

Mutators for [Infection](https://infection.github.io) that mutate the regex literals your code passes to `preg_*()`: a `+` turns into `*`, an anchor goes, a class loses a member. The PHPRegex automata drop every mutant they prove behaves as the original for the call it sits in, so no run of your suite is spent on a mutant no test could kill — and each mutant that survives carries the subject that kills it, written into the pattern as `(?#kill: "…")`, ready to paste into a test.

Requires Infection 0.29 or later and nikic/php-parser 5. Works with Infection installed through Composer or as its PHAR.

Documentation: [php-regex.com](https://php-regex.com) — the [Infection guide](https://php-regex.com/guides/infection/) walks the mutators end to end.

Features
--------

* Six mutators, one per family of regex fault: `RegexQuantifier`, `RegexAnchor`, `RegexCharClass`, `RegexAlternation`, `RegexFlag`, `RegexEscape`
* A mutant the automata prove equivalent for the call is never produced: `/a+/` → `/a/` is equivalent for `preg_match($re, $s)`, which only says whether a match exists, and kept for `preg_match($re, $s, $m)`, which writes the match
* Each mutant that survives says what kills it: `(?#kill: "0\x0a")` before the closing delimiter, a PHP double-quoted literal, checked against the running engine for the call as written — its offset, flags, limit and replacement included
* Mutants the engine refuses, mutants whose backtracking the ReDoS analysis proves exponential where the original's is not, and mutants alike another one already produced are left out
* Deterministic: the work per pattern is counted in steps, never in time, so the same code gets the same mutants on every machine
* No configuration: the mutators take Infection's own `ignore` and `ignoreSourceCodeByRegex` settings

Installation
------------

```bash
composer require --dev php-regex/regex-infection
```

The package brings `php-regex/regex-parser`, `regex-automata`, `regex-redos` and `regex-generator` with it: the parser, the automata that prove two patterns alike, the ReDoS analysis, and the sample generator that looks for a kill string where the automata cannot.

Usage
-----

Enable the mutators in `infection.json5`, and turn off Infection's own regex mutators, which this package replaces:

```json5
{
    "mutators": {
        "@default": true,
        "PregMatchRemoveCaret": false,
        "PregMatchRemoveDollar": false,
        "PregMatchRemoveFlags": false,
        "PHPRegex\\Infection\\Mutator\\RegexQuantifier": true,
        "PHPRegex\\Infection\\Mutator\\RegexAnchor": true,
        "PHPRegex\\Infection\\Mutator\\RegexCharClass": true,
        "PHPRegex\\Infection\\Mutator\\RegexAlternation": true,
        "PHPRegex\\Infection\\Mutator\\RegexFlag": true,
        "PHPRegex\\Infection\\Mutator\\RegexEscape": true
    }
}
```

`PregMatchRemoveFlags` removes a modifier the pattern may not use — `/^\d+$/i` without `i` matches the same strings — which leaves a mutant no test can kill.

An escaped mutant then reads:

```diff
- if (1 === preg_match('/^\d+$/', $id)) {
+ if (1 === preg_match('/^\d+\z(?#kill: "0\x0a")/', $id)) {
```

`"0\x0a"` is `"0\n"`: `$` matches before a final newline, `\z` does not. A test that passes it, and asserts the answer, kills the mutant.

### The kill string

* It is a subject for the `preg_*()` call as written — what reaches the call, not what reaches your function: a function that trims its input first needs a test that passes something the trim leaves.
* It is a PHP double-quoted literal: `$`, `\`, `"`, the parentheses, the braces, `#` and the delimiters are written `\xNN`, as is every byte outside printable ASCII. It pastes into a test as is.
* `preg_match_all()` without `$matches` gets the subject on which the count differs; `preg_replace_callback()` the one on which the callback receives other matches, as the call's flags shape them, or is called at other places; `preg_grep()` the element one pattern keeps and the other does not.
* It is left out when the literal would be longer than 120 bytes, when the call's offset, flags, limit or replacement is only known at run time, or a number is written as a string, when the pattern's delimiter is `?`, `:` or `"` or the pattern ends inside an open `\Q`, when neither the automata nor the sampled subjects tell the two patterns apart, and once the checks a pattern gets are spent.
* With the `#` delimiter, which `(?#` would end, it is written `(?(DEFINE)kill: "…")`, every byte but letters, digits and spaces as `\xNN`.

### What is read

A string literal written as the pattern of a native `preg_match()`, `preg_match_all()`, `preg_replace()`, `preg_replace_callback()`, `preg_split()`, `preg_grep()` or `preg_filter()` call, by position or as `pattern:`, inside a function — Infection mutates nothing else. A pattern in a constant, a variable, a concatenation or an array, `Safe\preg_match()`, `Composer\Pcre\Preg`, Laravel's `regex:` rule and the `#[Assert\Regex]` constraint are not read; a pattern PHPRegex cannot read is skipped without a word — `regex lint` shows which.

Two mutators of another package with the same short name would replace one another: Infection keys mutators by `getName()`.

Documentation
-------------

* [The Infection guide](https://php-regex.com/guides/infection/) — each mutator, the kill string, what is dropped and why
* [Choose a package](https://php-regex.com/packages/) — the other packages of the family
* [Backward compatibility promise](https://php-regex.com/reference/backward-compatibility/) — what stays stable across releases

Resources
---------

* [Upgrading from 1.x](https://github.com/php-regex/php-regex/blob/2.x/UPGRADE-2.0.md) — moving from `yoeunes/regex-parser` 1.3 to 2.0, every change that needs one on your side
* [Documentation](https://php-regex.com/docs/)
* The analysis layer behind the mutators: [regex-parser](https://github.com/php-regex/php-regex/tree/2.x/src/Parser), [regex-automata](https://github.com/php-regex/php-regex/tree/2.x/src/Automata) and [regex-redos](https://github.com/php-regex/php-regex/tree/2.x/src/Redos)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and [send pull requests](https://github.com/php-regex/php-regex/pulls) in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

Sponsors
---------

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

License
-------

MIT. See [LICENSE](https://github.com/php-regex/php-regex/blob/2.x/LICENSE).

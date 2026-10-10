<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=2">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=2">
        <img src="art/banner.png?v=2" alt="PHPRegex Parser" width="100%">
    </picture>
</p>

PHPRegex Parser
===============

The PCRE2 regex parser: lexer, immutable AST, validator that answers as PHP's PCRE2 would for a chosen release, AST cache and the small AST analyses.

Features
--------

* The full PCRE2 pattern syntax, parsed into an AST of 29 node types: groups, quantifiers, character classes, conditionals, subroutines, callouts, backreferences, verb controls — every node a readonly value object carrying its offsets into the pattern.
* Validation answers as PHP's PCRE2 would for a chosen release — a PHP version with the PCRE2 it bundles, or a PCRE2 release on its own — with an error code, an offset and a caret snippet.
* `parseTolerant()` returns a best-effort AST plus the parse errors instead of throwing on broken input.
* Visitors walk the tree: implement `NodeVisitorInterface`, query with `NodeFinder`, or walk with `NodeWalker`.
* The AST cache sits behind one interface: in memory by default, a directory on disk, or a PSR-6 / PSR-16 pool.
* Small AST analyses: group numbering, complexity score, literal extraction, length ranges, and the literals every match holds, for a `str_contains()` prefilter.
* Capture shapes: which groups a match always sets, may leave unset or never sets, the strings each can hold, and the `$matches` array `preg_match()` fills, written as a PHPStan type for static analysers.

Installation
------------

```bash
composer require php-regex/regex-parser
```

Requires PHP 8.2+ and ext-mbstring. The AST cache can sit on a PSR-6 pool (`psr/cache`) or a PSR-16 cache (`psr/simple-cache`) when either is installed; `ext-intl` resolves `\N{name}` escapes by Unicode name when loaded.

Configuration
-------------

`RegexParser::create()` takes an options array, validated on the way in — an unknown key is refused with the list of the allowed ones.

| Key | Type | Default | What it does |
| --- | --- | --- | --- |
| `php_version` | `"8.3"`, `80300` or `"runtime"` | running PHP | PHP release the patterns are judged for; alone, it means the PCRE2 it bundles |
| `pcre_version` | `"10.44"` | bundled with the target PHP | PCRE2 release the patterns are judged for |
| `max_pattern_length` | positive int | `100000` | pattern length beyond which parsing is refused |
| `max_lookbehind_length` | non-negative int | `255` | maximum length of a variable-length lookbehind, PCRE2's own `max_varlookbehind` |
| `max_recursion_depth` | positive int | `1024` | how deep groups may nest before the parser stops |
| `runtime_pcre_validation` | bool | `false` | also compile with the running PHP; only when the judged engine is the running one |
| `cache` | `null`, directory or `CacheInterface` | in memory | `null` disables caching, a string names a directory, an instance is used as is |
| `redos_ignored_patterns` | list of strings | `[]` | patterns handed to the ReDoS layer to skip |

Usage
-----

Parse a pattern and look at the tree — `NodeDumper` prints it:

```php
use PHPRegex\Parser\Printer\NodeDumper;
use PHPRegex\Parser\RegexParser;

$ast = RegexParser::create()->parse('/a+/');
echo $ast->accept(new NodeDumper());
```

```
Regex(delimiter: /, flags: )
Quantifier(quant: +, type: greedy)
  Literal('a')
```

Validate a pattern — the result says where it breaks and why:

```php
use PHPRegex\Parser\RegexParser;
$result = RegexParser::create()->validate('/[a-\d]/');
var_dump($result->isValid);
echo $result->error, "\n";
echo $result->caretSnippet, "\n";
```

```
bool(false)
Invalid range in character class: a character type, POSIX class, or Unicode property cannot be a range endpoint at position 5.
Line 1: [a-\d]
             ^
```

Judge for another release — a bounded variable-length lookbehind needs PCRE2 10.43, so the same pattern splits by target:

```php
use PHPRegex\Parser\RegexParser;
foreach (['10.42', '10.44'] as $pcreVersion) {
    $result = RegexParser::create(['pcre_version' => $pcreVersion])
        ->validate('/(?<=ab{0,5})c/');
    printf("PCRE2 %s: %s\n", $pcreVersion, $result->isValid ? 'valid' : $result->error);
}
```

```
PCRE2 10.42: Variable-length lookbehind needs PCRE2 10.43, which PHP bundles from 8.4.
PCRE2 10.44: valid
```

Query the tree — `NodeFinder` collects every node of a type, each with its offsets:

```php
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\NodeFinder;
use PHPRegex\Parser\RegexParser;

$ast = RegexParser::create()->parse('/(?<year>\d{4})-(?<month>\d{2})/');
foreach (NodeFinder::findInstanceOf($ast, GroupNode::class) as $group) {
    echo $group->name, ' starts at offset ', $group->getStartPosition(), "\n";
}
```

```
year starts at offset 0
month starts at offset 15
```

Documentation
-------------

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released with its siblings under one version number; deep material lives in [the docs](https://php-regex.com/docs/), under [the backward compatibility promise](https://php-regex.com/reference/backward-compatibility/).

* [Quick start](https://php-regex.com/quick-start/) — parsing and validating patterns in PHP, among the first steps
* [API reference](https://php-regex.com/reference/api/) — entry points, configuration options, return objects, the exception hierarchy
* [AST node reference](https://php-regex.com/nodes/) — every node type and its properties
* [AST visitor reference](https://php-regex.com/visitors/) — the built-in visitors and how to write custom ones
* [Capture shapes](https://php-regex.com/reference/capture-shapes/) — what `preg_match()` writes into `$matches`, read from the pattern
* [PCRE2 conformance](https://php-regex.com/reference/pcre2-conformance/) — how often validation matches PHP's own engine on PCRE2's test suite

Resources
---------

* The batteries-included facade: [regex-toolkit](https://github.com/php-regex/php-regex/tree/2.x/src/Toolkit)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and [send pull requests](https://github.com/php-regex/php-regex/pulls) in the [main repository](https://github.com/php-regex/php-regex)

Sponsors
---------

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

License
-------

MIT. See [LICENSE](https://github.com/php-regex/php-regex/blob/2.x/LICENSE).

<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=2">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=2">
        <img src="art/banner.png?v=2" alt="PHPRegex Explain" width="100%">
    </picture>
</p>

PHPRegex Explain
================

Explains, highlights and draws regex ASTs: plain text and HTML explanations, console and HTML highlighting, ASCII trees, Mermaid and railroad diagrams.

Every class here is a node visitor: parse once with regex-parser, then walk the AST with `accept()`. There is nothing to configure.

Features
--------

* Plain-English explanation, one line per construct (`TextExplainer`)
* The same explanation as HTML, each node carrying a `title` tooltip (`HtmlExplainer`)
* Terminal syntax highlighting in 24-bit ANSI colors (`Highlighter\ConsoleHighlighter`)
* Web syntax highlighting as `regex-token` spans you style yourself (`Highlighter\HtmlHighlighter`)
* ASCII tree of the AST, for logs and code review (`AsciiTreeRenderer`)
* Mermaid flowchart source, for Markdown and docs sites (`MermaidRenderer`)
* Standalone railroad-diagram SVG with its stylesheet embedded (`RailroadSvgRenderer`)
* Covers the parser's whole PCRE node set: groups, quantifiers, class-set operations, conditionals, subroutines, callouts

Installation
------------

```bash
composer require php-regex/regex-explain
```

Requires PHP 8.2 and `php-regex/regex-parser` at the same version, pulled in
automatically.

Usage
-----

Explain a pattern in plain English:

```php
use PHPRegex\Explain\TextExplainer;
use PHPRegex\Parser\RegexParser;

$ast = RegexParser::create()->parse('/[A-Z][a-z]+\d*/');

echo $ast->accept(new TextExplainer());
// Regex matches
//   Character Class: any character in [   Range: from 'A' to 'Z' ]
//     Character Class: any character in [   Range: from 'a' to 'z' ] (one or more times)
//     Character Type: A digit: [0-9] (zero or more times)
```

Print the AST itself as a tree:

```php
use PHPRegex\Explain\AsciiTreeRenderer;

$ast = RegexParser::create()->parse('/(?<year>\d{4})-\d{2}/');

echo $ast->accept(new AsciiTreeRenderer());
// Regex
// \-- Sequence
//     |-- Group (named) name="year"
//     |   \-- Quantifier ({4}, greedy)
//     |       \-- CharType (\d)
//     |-- Literal ('-')
//     \-- Quantifier ({2}, greedy)
//         \-- CharType (\d)
```

Highlight the pattern instead of explaining it — ANSI for a terminal, span classes for a web page:

```php
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Explain\Highlighter\HtmlHighlighter;

$ast = RegexParser::create()->parse('/\d{2,}/');

// wraps each token as \e[38;2;R;G;Bm...\e[0m (true color)
echo $ast->accept(new ConsoleHighlighter());

echo $ast->accept(new HtmlHighlighter());
// <span class="regex-token regex-type regex-escape">\d</span><span class="regex-token regex-quantifier">{2,}</span>
```

Render an explanation ready for a web page — the `title` attribute holds the detail:

```php
use PHPRegex\Explain\HtmlExplainer;

echo RegexParser::create()->parse('/\d{3}-\d{4}/')->accept(new HtmlExplainer());
// <div class="regex-explain">
// <strong>Regex matches:</strong>
// <ul><li>(exactly 3 times) <span title="Character Type: A digit: [0-9]">…</span></li>…
// </ul>
// </div>
```

Draw the pattern — Mermaid source for a docs page, a complete SVG for anywhere else:

```php
use PHPRegex\Explain\MermaidRenderer;
use PHPRegex\Explain\RailroadSvgRenderer;

$ast = RegexParser::create()->parse('/a(b|c)*/');

echo $ast->accept(new MermaidRenderer());
// graph TD;
//     node0["Regex: none"]
//     node1["Sequence"]
//     node2["Literal: a"]
//     node1 --> node2
//     node3["Quantifier: *"]
//     … remaining nodes and edges …

file_put_contents('railroad.svg', $ast->accept(new RailroadSvgRenderer()));
// a complete XML document: an <svg> root with its stylesheet embedded
```

Documentation
-------------

* [Quick start](https://php-regex.com/quick-start/) — explain and highlight in the opening tour
* [Visitor reference](https://php-regex.com/visitors/) — every Explain class, with examples
* [CLI guide](https://php-regex.com/guides/cli/) — the `regex explain`, `highlight` and `diagram` commands
* [Backward compatibility](https://php-regex.com/reference/backward-compatibility/) — what stays stable across releases

Resources
---------

* [All PHPRegex packages](https://github.com/php-regex/php-regex/blob/2.x/README.md) — one repo, one version number
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and [send pull requests](https://github.com/php-regex/php-regex/pulls) in the [main repository](https://github.com/php-regex/php-regex)

Sponsors
---------

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

License
-------

MIT. See [LICENSE](https://github.com/php-regex/php-regex/blob/2.x/LICENSE).

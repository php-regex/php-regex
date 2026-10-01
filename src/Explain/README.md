<p align="center"><img src="https://raw.githubusercontent.com/php-regex/php-regex/2.x/art/org-icon-dark.svg?v=1" width="96" alt="PHPRegex"></p>

PHPRegex regex-explain
======================

Explains, highlights and draws regex ASTs: plain text and HTML explanations, console and HTML highlighting, ASCII trees, Mermaid and railroad diagrams.

```bash
composer require php-regex/regex-explain
```

Requires PHP 8.2+, regex-parser ^2.0. MIT licensed.

```php
use PHPRegex\Explain\TextExplainer;
use PHPRegex\Parser\RegexParser;

$ast = RegexParser::create()->parse('/[A-Z][a-z]+\d*/');

echo $ast->accept(new TextExplainer());
// Regex matches
//   Character Class: any character in [ Range: from 'A' to 'Z' ]
```

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released
with its siblings under one version number. Read
[the guide](https://github.com/php-regex/php-regex/blob/2.x/docs/visitors/README.md) and
[the backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md).

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and
  [send pull requests](https://github.com/php-regex/php-regex/pulls)
  in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

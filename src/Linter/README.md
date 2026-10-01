<p align="center"><img src="https://raw.githubusercontent.com/php-regex/php-regex/2.x/art/org-icon-dark.svg?v=1" width="96" alt="PHPRegex"></p>

PHPRegex regex-linter
=====================

Lints the regex patterns of a PHP code base: extraction from PHP sources, lint rules, ReDoS and validity checks, reports in console, JSON, GitHub, Checkstyle and JUnit formats.

```bash
composer require php-regex/regex-linter
```

Requires PHP 8.2+. MIT licensed.

```php
use PHPRegex\Linter\PatternLinter;
use PHPRegex\Parser\RegexParser;

$linter = new PatternLinter();
RegexParser::create()->parse('/(a+)+b/')->accept($linter);

foreach ($linter->getIssues() as $violation) {
    echo $violation->id, ': ', $violation->message, "\n";
}
```

To lint a whole code base, `vendor/bin/regex lint src/` (php-regex/regex-cli)
extracts every pattern from PHP sources and runs the same 28 rules.

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released
with its siblings under one version number. Read
[the guide](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/diagnostics.md) and
[the backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md).

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and
  [send pull requests](https://github.com/php-regex/php-regex/pulls)
  in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

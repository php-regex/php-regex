<p align="center"><img src="https://raw.githubusercontent.com/php-regex/php-regex/2.x/art/org-icon-dark.svg?v=1" width="96" alt="PHPRegex"></p>

PHPRegex regex-toolkit
======================

One entry point to every PHPRegex analysis library: parse, validate, explain, check ReDoS, optimize, generate, transpile and lint.

```bash
composer require php-regex/regex-toolkit
```

Requires PHP 8.2+. MIT licensed.

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

$regex->parse('/^\d{4}-\d{2}-\d{2}$/'); // RegexNode AST
$regex->generate('/[a-z]+@[a-z]+\.[a-z]{2,}/'); // a matching sample string
$regex->redos('/(a+)+$/')->severity->value; // 'critical'
```

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released
with its siblings under one version number. Read
[the guide](https://github.com/php-regex/php-regex/blob/2.x/docs/QUICK_START.md) and
[the backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md).

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* [Changelog](CHANGELOG.md)
* [Bridges and tools](https://github.com/php-regex/php-regex/blob/2.x/README.md#getting-started) — Laravel, Symfony, CLI, PHPStan and LSP
* [Report issues](https://github.com/php-regex/php-regex/issues) and
  [send pull requests](https://github.com/php-regex/php-regex/pulls)
  in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

<p align="center"><img src="https://raw.githubusercontent.com/php-regex/php-regex/2.x/art/org-icon-dark.svg?v=1" width="96" alt="PHPRegex"></p>

PHPRegex regex-optimizer
========================

Rewrites regex patterns into shorter equivalents and modernizes old syntax; equivalence can be proven, opt-in, by regex-automata.

```bash
composer require php-regex/regex-optimizer
```

Requires PHP 8.2+. MIT licensed.

```php
use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Parser\RegexParser;

$optimizer = new Optimizer(RegexParser::create());
$result = $optimizer->optimize('/[0-9][0-9][0-9][0-9]/', ['verify_with_automata' => true]);

$result->optimized; // '/\d{4}/'
```

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released
with its siblings under one version number. Read
[the guide](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/api.md) and
[the backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md).

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and
  [send pull requests](https://github.com/php-regex/php-regex/pulls)
  in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

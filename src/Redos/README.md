<p align="center"><img src="https://raw.githubusercontent.com/php-regex/php-regex/2.x/art/org-icon-dark.svg?v=1" width="96" alt="PHPRegex"></p>

PHPRegex regex-redos
====================

Finds the patterns that backtrack catastrophically (ReDoS) from the AST, and can confirm a finding against the engine.

```bash
composer require php-regex/regex-redos
```

Requires PHP 8.2+. MIT licensed.

```php
use PHPRegex\Redos\ConfirmationRunner;
use PHPRegex\Redos\RedosAnalyzer;

$analyzer = new RedosAnalyzer();
$analysis = $analyzer->analyze('/(\w+\s?)+$/');

$confirmation = (new ConfirmationRunner())->confirm('/(\w+\s?)+$/', $analysis);

var_dump($analysis->severity->value); // 'critical'
var_dump($confirmation->confirmed);   // true
```

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released
with its siblings under one version number. Read
[the guide](https://github.com/php-regex/php-regex/blob/2.x/docs/REDOS_GUIDE.md) and
[the backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md).

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and
  [send pull requests](https://github.com/php-regex/php-regex/pulls)
  in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

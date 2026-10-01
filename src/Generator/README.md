<p align="center"><img src="https://raw.githubusercontent.com/php-regex/php-regex/2.x/art/org-icon-dark.svg?v=1" width="96" alt="PHPRegex"></p>

PHPRegex regex-generator
========================

Generates sample strings and test cases that a regex matches or rejects.

```bash
composer require php-regex/regex-generator
```

Requires PHP 8.2+. MIT licensed.

```php
use PHPRegex\Parser\RegexParser;
use PHPRegex\Generator\TestCaseGenerator;

$ast = RegexParser::create()->parse('/\d{3}-\d{4}/');
$cases = $ast->accept(new TestCaseGenerator());

echo $cases['matching'][0];      // "000-0000"
echo $cases['non_matching'][0];  // "00.000"
```

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released
with its siblings under one version number. Read
[the guide](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/api.md) and
[the backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md).

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* [Report issues](https://github.com/php-regex/php-regex/issues) and
  [send pull requests](https://github.com/php-regex/php-regex/pulls)
  in the [main PHPRegex repository](https://github.com/php-regex/php-regex)
* [Changelog](CHANGELOG.md)

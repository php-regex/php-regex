<p align="center"><img src="https://raw.githubusercontent.com/php-regex/php-regex/2.x/art/org-icon-dark.svg?v=1" width="96" alt="PHPRegex"></p>

PHPRegex regex-automata
=======================

Compiles the regular subset of PCRE to automata to compare languages: equivalence, intersection, subset and example strings.

```bash
composer require php-regex/regex-automata
```

Requires PHP 8.2+. MIT licensed.

```php
use PHPRegex\Automata\LanguageSolver;

$solver = new LanguageSolver();

$result = $solver->equivalent('/a+/', '/a*a+/');

var_dump($result->isEquivalent); // true
```

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released
with its siblings under one version number. Read
[the guide](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/logic-solver.md) and
[the backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md).

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and
  [send pull requests](https://github.com/php-regex/php-regex/pulls)
  in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

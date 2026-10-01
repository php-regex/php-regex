<p align="center"><img src="https://raw.githubusercontent.com/php-regex/php-regex/2.x/art/org-icon-dark.svg?v=1" width="96" alt="PHPRegex"></p>

PHPRegex regex-symfony
======================

Symfony bundle for PHPRegex: the Regex service and the regex:lint, regex:routes, regex:security, regex:analyze, regex:compare and regex:transpile commands.

```bash
composer require php-regex/regex-symfony
```

Requires PHP 8.2+, Symfony 7.4+ or 8.0+. MIT licensed.

```php
use PHPRegex\Toolkit\Regex;

// the bundle registers the Regex service for autowiring
$result = $regex->validate('/^[a-z0-9-]{3,}$/');

if (!$result->isValid()) {
    echo $result->getErrorMessage();
}
```

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released
with its siblings under one version number. Read
[the guide](https://github.com/php-regex/php-regex/blob/2.x/docs/guides/symfony.md) and
[the backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md).

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* The runtime library behind the service: [regex-toolkit](https://github.com/php-regex/php-regex/tree/2.x/src/Toolkit)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and
  [send pull requests](https://github.com/php-regex/php-regex/pulls)
  in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

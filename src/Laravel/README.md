<p align="center"><img src="https://raw.githubusercontent.com/php-regex/php-regex/2.x/art/org-icon-dark.svg?v=1" width="96" alt="PHPRegex"></p>

PHPRegex regex-laravel
======================

Laravel integration for PHPRegex: the Regex service and facade, and the regex:lint, regex:routes, regex:explain, regex:compare and regex:transpile commands.

```bash
composer require php-regex/regex-laravel
```

Requires PHP 8.2+, Laravel 12. MIT licensed.

```php
use PHPRegex\Laravel\Facades\Regex;

$result = Regex::validate('/^[a-z0-9-]{3,}$/');

if (!$result->isValid()) {
    echo $result->getErrorMessage();
}
```

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released
with its siblings under one version number. Read
[the guide](https://github.com/php-regex/php-regex/blob/2.x/docs/guides/laravel.md) and
[the backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md).

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* The runtime library behind the facade: [regex-toolkit](https://github.com/php-regex/php-regex/tree/2.x/src/Toolkit)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and
  [send pull requests](https://github.com/php-regex/php-regex/pulls)
  in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

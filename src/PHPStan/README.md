<p align="center"><img src="https://raw.githubusercontent.com/php-regex/php-regex/2.x/art/org-icon-dark.svg?v=1" width="96" alt="PHPRegex"></p>

PHPRegex regex-phpstan
======================

PHPStan extension that reports the regex patterns your target PHP refuses, and, opt-in, lint, ReDoS and optimization findings.

```bash
composer require --dev php-regex/regex-phpstan
```

Requires PHP 8.2+, PHPStan 2.x. MIT licensed.

```php
use PHPRegex\PHPStan\RegexPatternRule;

// custom wiring: the "phpRegex" parameter, as an array
$rule = new RegexPatternRule([
    'phpVersion' => '8.2',
    'checks' => ['redos' => ['enabled' => true, 'threshold' => 'high']],
]);
```

Without phpstan/extension-installer, include the extension in your `phpstan.neon`;
`rules.neon` turns lint and ReDoS on:

```neon
includes:
    - vendor/php-regex/regex-phpstan/extension.neon
```

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released
with its siblings under one version number. Read
[the guide](https://github.com/php-regex/php-regex/blob/2.x/docs/guides/phpstan.md) and
[the backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md).

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* [Report issues](https://github.com/php-regex/php-regex/issues) and
  [send pull requests](https://github.com/php-regex/php-regex/pulls)
  in the [main PHPRegex repository](https://github.com/php-regex/php-regex)
* [Changelog](CHANGELOG.md)

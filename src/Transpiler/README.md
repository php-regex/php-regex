<p align="center"><img src="https://raw.githubusercontent.com/php-regex/php-regex/2.x/art/org-icon-dark.svg?v=1" width="96" alt="PHPRegex"></p>

PHPRegex regex-transpiler
=========================

Transpiles PCRE patterns to JavaScript and Python regular expressions, with the losses reported.

```bash
composer require php-regex/regex-transpiler
```

Requires PHP 8.2+. MIT licensed.

```php
use PHPRegex\Parser\RegexParser;
use PHPRegex\Transpiler\Transpiler;

$transpiler = new Transpiler(RegexParser::create());
$result = $transpiler->transpile('/(?P<word>\w+)\k{word}/i', 'javascript');

echo $result->literal;     // '/(?<word>\w+)\k<word>/i'
print_r($result->notes);   // portability caveats, e.g. ASCII-only \w
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

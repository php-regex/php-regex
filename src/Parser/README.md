<p align="center"><img src="https://raw.githubusercontent.com/php-regex/php-regex/2.x/art/org-icon-dark.svg?v=1" width="96" alt="PHPRegex"></p>

PHPRegex regex-parser
=====================

The PCRE2 regex parser: lexer, immutable AST, validator that answers as PHP's PCRE2 would for a chosen release, AST cache and the small AST analyses.

```bash
composer require php-regex/regex-parser
```

Requires PHP 8.2+, ext-mbstring. MIT licensed.

```php
use PHPRegex\Parser\RegexParser;

$parser = RegexParser::create();
$ast = $parser->parse('/\d{3}-\d{4}/');  // a readonly RegexNode tree
$result = $parser->validate('/a{2,1}/');

var_dump($result->isValid);  // false
echo $result->error;         // Invalid quantifier range "{2,1}": min > max.
```

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released
with its siblings under one version number. Read
[the guide](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/api.md) and
[the backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md).

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* The batteries-included facade: [regex-toolkit](https://github.com/php-regex/php-regex/tree/2.x/src/Toolkit)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and
  [send pull requests](https://github.com/php-regex/php-regex/pulls)
  in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

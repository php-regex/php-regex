<p align="center"><img src="https://raw.githubusercontent.com/php-regex/php-regex/2.x/art/org-icon-dark.svg?v=1" width="96" alt="PHPRegex"></p>

PHPRegex regex-cli
==================

The regex console: sixteen subcommands to parse, explain, validate, lint, hunt ReDoS, transpile, diagram and debug patterns — also shipped as a self-updating regex.phar.

```bash
composer require --dev php-regex/regex-cli
```

Or, standalone:

```bash
curl -Ls https://github.com/php-regex/php-regex/releases/latest/download/regex.phar -o ~/.local/bin/regex && chmod +x ~/.local/bin/regex
```

Requires PHP 8.2+. MIT licensed.

```bash
vendor/bin/regex analyze '/^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$/i'
vendor/bin/regex debug '/(a+)+$/'
vendor/bin/regex validate '/(?<=a+)b/'
vendor/bin/regex lint src/ --format=github
```

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released
with its siblings under one version number. Read
[the guide](https://github.com/php-regex/php-regex/blob/2.x/docs/guides/cli.md) and
[the backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md).

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and
  [send pull requests](https://github.com/php-regex/php-regex/pulls)
  in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

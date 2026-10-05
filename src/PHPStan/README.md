<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=2">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=2">
        <img src="art/banner.png?v=2" alt="PHPRegex PHPStan" width="100%">
    </picture>
</p>

PHPRegex PHPStan
================

PHPStan extension that reports the regex patterns your target PHP refuses, and, opt-in, lint, ReDoS and optimization findings.

Requires PHP 8.2+ and PHPStan 2.x. MIT licensed.

Features
--------

* Reports the patterns your target PHP refuses while the engine running PHPStan compiles them; what that engine refuses stays with PHPStan core, never reported twice.
* Reads the eight `preg_*` functions, patterns held in constants and constant expressions, and the array keys of `preg_replace_callback_array`.
* Opt-in lint: the 32 lint rules of [php-regex/regex-linter](https://github.com/php-regex/php-regex/tree/2.x/src/Linter), each finding carrying its rule identifier and a tip.
* Opt-in ReDoS analysis, theoretical only — the pattern is read, never run inside PHPStan — with four severity thresholds: a proven exponential or polynomial verdict, or a heuristic one, with the attack input in the tip. A call whose subject PHPStan knows to be constant is not reported: no input can reach it.
* Opt-in optimization suggestions behind a minimum-savings setting; every rewrite is proven equivalent by the automata solver before it is reported.
* With optimizations on, a `preg_match($pattern, $subject)` a string function answers alike is reported with the function: `/^https:/` is `str_starts_with($subject, 'https:')`, `/^(?:GET|POST)\z/` an `in_array()`. Each is proven by the automata; `/^foo$/` is no `===`, as `$` also takes `"foo\n"`.
* Stable identifiers for `ignoreErrors` and baselines: `regex.invalidForTarget`, `regex.redos`, `regex.optimization`, `regex.trivialMatch`, `regex.lint.<rule>`.
* The `phpRegex` parameter is validated by a Neon schema before analysis starts; a version or threshold that names no real value stops the run there.

Installation
------------

```bash
composer require --dev php-regex/regex-phpstan
```

With [phpstan/extension-installer](https://github.com/phpstan/extension-installer) this is
everything: the extension registers itself.

Without it, include the extension in your `phpstan.neon`:

```neon
includes:
    - vendor/php-regex/regex-phpstan/extension.neon
```

`rules.neon` turns the lint rules and the ReDoS analysis on — optimizations
stay off, enable them with your own parameters:

```neon
includes:
    - vendor/php-regex/regex-phpstan/extension.neon
    - vendor/php-regex/regex-phpstan/rules.neon
```

Configuration
-------------

Everything lives under the `phpRegex` parameter; suppress findings by identifier
in `ignoreErrors`, as with any PHPStan rule. The defaults as shipped:

| Option | Default | Meaning |
| --- | --- | --- |
| `phpVersion` | `null` | PHP the patterns are judged for: `null` for PHPStan's `phpVersion`, `'runtime'` for the PHP running the analysis, `'8.2'` or `80200` for a release |
| `pcreVersion` | `null` | PCRE2 release the patterns are judged for, `'10.42'`; `null` for the one the PHP version bundles |
| `checks.lint.enabled` | `false` | lint rules |
| `checks.redos.enabled` | `false` | ReDoS analysis |
| `checks.redos.threshold` | `critical` | lowest severity reported: `low`, `medium`, `high` or `critical` |
| `checks.optimizations.enabled` | `false` | optimization suggestions |
| `checks.optimizations.minSavings` | `1` | characters saved before a suggestion is reported |
| `checks.optimizations.options` | as shipped | which rewrites the optimizer may apply: `digits`, `word`, `ranges`, `canonicalizeCharClasses` and `verifyWithAutomata` on, `possessive` and `factorize` off, `minQuantifierCount` at `4` |

Usage
-----

The default check needs no configuration beyond the include — run
`vendor/bin/phpstan analyse`; the target is PHPStan's `phpVersion`, here PHP 8.2:

```php
// (*scs:...) arrived in PCRE2 10.45; PHP 8.2 bundles 10.40.
preg_match('/(a)(*scs:(1)a)/', $value);
```

```
Regex pattern is invalid for PHP 8.2 with PCRE2 10.40: Invalid or unsupported PCRE verb: "scs".
🪪 regex.invalidForTarget
```

With `rules.neon`, lint and ReDoS findings appear:

```php
preg_match('/no_dot/s', $value);   // flag 's' with no dot to match
preg_match('/(a+)+$/', $value);    // nested unbounded quantifiers
```

```
Flag 's' is useless: the pattern contains no unescaped dot outside a character class.
🪪 regex.lint.flag.useless.s
Nested quantifiers can cause catastrophic backtracking.
🪪 regex.lint.quantifier.nested
💡 Consider atomic groups (?>...) or possessive quantifiers — verify the rewrite still matches everything you need.
Exponential backtracking (ReDoS): /(a+)+$/
🪪 regex.redos
💡 Severity: critical, exponential (proven).
💡 Attack: "a" x n . "!"
```

A ReDoS message is `Exponential backtracking (ReDoS)`, `Polynomial backtracking (ReDoS)` or `Potential backtracking (ReDoS)`, then the pattern; the text of each stays the same for all of 2.x, and the severity, how the verdict was reached and the attack (`str_repeat("a", $n) . "!"`) are in the tip. When the analysis improves, an error may appear, disappear or change class: regenerate the baseline after such an upgrade, and once after moving from 1.x.

Every lint issue is a PHPStan error, whatever the rule's severity: an issue the `regex lint` console prints as `INFO`, such as `regex.lint.group.quantifiedCapture` on an unnamed group, is reported too, under its own identifier, so you can ignore it by identifier. Lint messages and the set of reported issues moved in 2.0.0: after upgrading, regenerate the baseline once with `vendor/bin/phpstan analyse --generate-baseline`.

Optimizations, once enabled, suggest the shorter equivalent as a tip:

```php
preg_match('/[0-9]+/', $value);
```

```
Regex pattern can be optimized: "/[0-9]+/"
🪪 regex.optimization
💡 Consider using: /\d+/
```

Documentation
-------------

* [PHPStan guide](https://github.com/php-regex/php-regex/blob/2.x/docs/guides/phpstan.md) — the target model, each check, every identifier
* [Diagnostics](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/diagnostics.md) — how findings are reported and how to read them
* [ReDoS guide](https://github.com/php-regex/php-regex/blob/2.x/docs/REDOS_GUIDE.md) — risky shapes, severities, mitigations
* [Quick start](https://github.com/php-regex/php-regex/blob/2.x/docs/QUICK_START.md) — the PHPRegex packages in five commands

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released
with its siblings under one version number. Read
[the backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md).

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* The linter behind the opt-in checks: [regex-linter](https://github.com/php-regex/php-regex/tree/2.x/src/Linter)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and
  [send pull requests](https://github.com/php-regex/php-regex/pulls)
  in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

Sponsors
---------

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

License
-------

MIT. See [LICENSE](https://github.com/php-regex/php-regex/blob/2.x/LICENSE).

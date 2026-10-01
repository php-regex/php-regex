# PHPStan

The PHPStan extension reads the regex patterns passed to `preg_*` functions
and reports what PHPStan itself cannot see: a pattern the PHP your project
targets refuses, while the PHP running PHPStan accepts it. Lint rules and ReDoS
analysis are there too, off until you ask for them.

## Installation

With [phpstan/extension-installer](https://github.com/phpstan/extension-installer),
there is nothing to do: installing the package enables the extension.

Without it, include the extension in your `phpstan.neon`:

```neon
includes:
    - vendor/php-regex/regex-phpstan/extension.neon
```

## What it reports by default

PHPStan already reports every pattern the PHP running it cannot compile
(identifier `regexp.pattern`). The extension does not report those again. It
reports a pattern the running PHP compiles but the target refuses, as a
pattern using syntax a newer PCRE2 added:

```
Regex pattern is invalid for PHP 8.2 with PCRE2 10.40: Invalid group modifier syntax at position 2.
🪪 regex.invalidForTarget
```

The target is PHPStan's `phpVersion` (the PHP running PHPStan unless your
configuration sets it; the lowest version of a range) with the PCRE2 that PHP
bundles. When the target is the PHP running PHPStan and the PCRE2 it links,
there is nothing PHPStan misses, and the extension reports no invalid pattern.

## Lint rules and ReDoS analysis

`rules.neon` turns them on:

```neon
includes:
    - vendor/php-regex/regex-phpstan/rules.neon
```

Without extension-installer, include both `extension.neon` and `rules.neon`.
Each check can also be switched on its own (see below).

ReDoS analysis in PHPStan is theoretical: it reads the pattern and never runs
it inside PHPStan.

## Configuration

```neon
parameters:
    phpRegex:
        # The PHP patterns are judged for: null for PHPStan's phpVersion,
        # 'runtime' for the PHP running PHPStan, a version like '8.2', or a
        # PHP_VERSION_ID like 80200.
        phpVersion: null
        # The PCRE2 release, like '10.42', for a PHP that links another one
        # than it bundles; null for the bundled one.
        pcreVersion: null
        checks:
            lint:
                enabled: false
            redos:
                enabled: false
                # The lowest severity reported: low, medium, high or critical.
                threshold: critical
            optimizations:
                enabled: false
                minSavings: 1
```

A `phpVersion` or `pcreVersion` that names no release stops the analysis when
it starts, not on the first file. So does a `threshold` that is not `low`,
`medium`, `high` or `critical`, even while `redos` is off: `safe` and
`unknown` are the verdicts a pattern gets, not thresholds. The configuration
schema takes the four values in lower case; a rule built from an array, in
custom wiring, reads them in any case.

## Identifiers

| identifier | reported for |
|---|---|
| `regex.invalidForTarget` | a pattern the target refuses and the running PHP compiles |
| `regex.redos` | a pattern at or above the ReDoS threshold; the severity is in the message |
| `regex.optimization` | a pattern with a shorter equivalent |
| `regex.lint.<rule>` | a lint rule, as `regex.lint.flag.useless.i` |

Use them in `ignoreErrors` or a baseline as with any PHPStan identifier.

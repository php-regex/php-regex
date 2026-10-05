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

## ReDoS findings

A pattern at or above `checks.redos.threshold` (`critical` by default) is
reported under `regex.redos`, with one of three messages:

| message | verdict |
|---|---|
| `Exponential backtracking (ReDoS): <pattern>` | proven exponential |
| `Polynomial backtracking (ReDoS): <pattern>` | proven polynomial |
| `Potential backtracking (ReDoS): <pattern>` | judged by the structural heuristics |

When a rewrite of the pattern is proven to match the same subjects and proven
linear, the tip prints it: `Proven repair: /(a+)$/ (same subjects, same matches, linear).`

The message holds the verdict class and the pattern only, and stays the same
for all of 2.x. The severity, how the verdict was reached and the attack are in
the tip: a better verdict changes the tip, never the message, and your baseline
keeps working.

A call whose subject PHPStan knows to be constant — a literal, a constant, a
concatenation of them, or an array of them — is not reported: it backtracks
the same way on every run, or never, so no input can turn it into an attack.
The same pattern is reported wherever its subject may come from outside.

```php
preg_match('/^(a+)+$/', 'fixed');  // not reported: the subject is constant
preg_match('/^(a+)+$/', $input);   // reported
```

```php
preg_match('/^(\w+\s?)+$/', $value);
preg_match('/\d*\d*\d*$/', $value);
preg_match('/^(a+)+\1$/', $value);
```

With `threshold: high`:

```
 ------ -----------------------------------------------------------------------
  Line   src/Validator.php
 ------ -----------------------------------------------------------------------
  7      Exponential backtracking (ReDoS): /^(\w+\s?)+$/
         🪪  regex.redos
         💡  Severity: critical, exponential (proven).
         💡  Attack: "0" x n . "!"
         💡  Unbounded quantifier detected. May cause backtracking on
         non-matching input. Consider making it possessive (*+) or using
         atomic groups (?>...). Suggested (verify behavior): Consider using
         possessive quantifiers or atomic groups to limit backtracking.
         💡
         💡  Read more about possessive quantifiers: …
         💡  Read more about atomic groups: …
         💡  Read more about catastrophic backtracking: …
  12     Polynomial backtracking (ReDoS): /\d*\d*\d*$/
         🪪  regex.redos
         💡  Severity: high, polynomial degree 3 (proven).
         💡  Attack: "0" x n . "!"
         …
  17     Potential backtracking (ReDoS): /^(a+)+\1$/
         🪪  regex.redos
         💡  Severity: critical, heuristic.
         …
```

The tip starts with the severity and how it was reached: `exponential
(proven)`, `polynomial degree N (proven)`, `heuristic`, or `heuristic (budget
exceeded)`. A proven verdict adds the attack, `"0" x n . "!"`: the input
`str_repeat("0", $n) . "!"` that drives the worst case. The recommendations and
the documentation links follow. The [ReDoS guide](../REDOS_GUIDE.md) explains
each part of the verdict.

The attack is a PHP expression that pastes back into code. A `<` in it is
written `\x3C`, the same byte, so that no output format reads it as markup:

```
  5      Exponential backtracking (ReDoS): /^(<b>|<b>\s?)+$/
         🪪  regex.redos
         💡  Severity: critical, exponential (proven).
         💡  Attack: "\x3Cb>\x3Cb>" x n . "!"
```

A proven quadratic pattern is `medium`, below the default threshold; set
`threshold: medium` to see it.

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
| `regex.redos` | a pattern at or above the ReDoS threshold; the severity is in the tip |
| `regex.optimization` | a pattern with a shorter equivalent |
| `regex.trivialMatch` | a `preg_match($pattern, $subject)` a string function answers alike, `str_starts_with()` for `/^https:/`, proven by the automata; with `optimizations` on |
| `regex.lint.<rule>` | a lint rule, as `regex.lint.flag.useless.i` |

Use them in `ignoreErrors` or a baseline as with any PHPStan identifier.

PHPStan has one level of report, so every lint issue is a PHPStan error, whatever
the severity of its rule: an issue the `regex lint` console prints as `INFO`, as
`regex.lint.group.quantifiedCapture` on an unnamed group, is reported as well.
Its identifier lets you ignore it:

```neon
parameters:
    ignoreErrors:
        - identifier: regex.lint.group.quantifiedCapture
```

A baseline written before 2.0 holds a 1.x ReDoS message, `Potential ReDoS
risk (theoretical) (severity: …, confidence: …): …` or `Confirmed ReDoS risk
(…): …`, which no longer matches:
regenerate it with `vendor/bin/phpstan analyse --generate-baseline` (see
[UPGRADE-2.0.md](../../UPGRADE-2.0.md)).

Regenerate it once after upgrading to 2.0.0 as well, whatever version wrote it:
lint messages were reworded (the useless `m` and `s` flags, a lazy quantifier
under `U`, redundant class ranges), false positives were removed, and with
`redos` on, the nested-quantifier, dot-star and overlapping-set issues are no
longer reported for a pattern the analysis proves linear.

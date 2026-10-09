---
description: "Install the PHPRegex PHPStan extension: the invalid-for-target check that runs by default, plus lint, ReDoS and optimization checks on demand."
---
# PHPStan

The PHPStan extension reads the regex patterns passed to `preg_*` functions,
and to your own functions and methods that mark a parameter as a pattern,
and reports what PHPStan itself cannot see: a pattern the PHP your project
targets refuses, while the PHP running PHPStan accepts it. Lint rules and ReDoS
analysis are there too, off until you ask for them.

{% include install-prerelease.html package="php-regex/regex-phpstan" %}

The extension runs on PHP 8.2 or later and PHPStan 2.x
(`phpstan/phpstan ^2.0`); reading a parameter attribute needs PHPStan
2.1.31 at least ([Pattern parameters](#pattern-parameters)).

With [phpstan/extension-installer](https://github.com/phpstan/extension-installer),
there is nothing to do: installing the package enables the extension. Without
it, include the extension in your `phpstan.neon`:

```neon
includes:
    - vendor/php-regex/php-regex/src/PHPStan/extension.neon
```

Under the pre-release monorepo install, the extension's neon files live
under `vendor/php-regex/php-regex/src/PHPStan/`; from the 2.0.0 tag and the
split package, under `vendor/php-regex/regex-phpstan/`. The rest of this
guide writes the pre-release path.

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

A range, `phpVersion: {min: 80400, max: 80599}`, is read whole: a pattern the
lowest version accepts is also validated at each later PHP up to `max` where
the PCRE2 that PHP bundles or the library's compat rules change (8.4.25,
8.5, 8.5.10…). [`regex lint`](cli.md) reads a `composer.json` range the same
way; the first version that refuses the pattern is reported under the same
identifier:

```text
Regex pattern is invalid for PHP 8.5 with PCRE2 10.44: \K is not allowed in a lookaround from PHP 8.5, which compiles without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK.
🪪 regex.invalidForTarget
```

A `phpRegex.phpVersion` naming one version judges that version alone.

## Pattern parameters

A function, a method or a constructor of your own that takes a pattern says
so with the attribute `PHPRegex\Parser\Attribute\RegexPattern`, or with
PhpStorm's `#[Language('RegExp')]`:

```php
use PHPRegex\Parser\Attribute\RegexPattern;

final class Str
{
    public function matches(string $subject, #[RegexPattern] string $regex): bool
    {
        return 1 === preg_match($regex, $subject);
    }
}

$str->matches($input, '/(foo/');
```

The constant pattern passed to that parameter, by position or by name, is
checked as a `preg_*()` pattern is, in calls to functions, static and
instance methods (PHPStan knows the type of `$str`) and constructors. PHPStan
core reads only `preg_*()` calls, so a pattern the running PHP refuses is
reported here, in PHPStan's words and under its identifier:

```text
Regex pattern is invalid: missing closing parenthesis at offset 4.
🪪 regexp.pattern
```

A pattern the target refuses is `regex.invalidForTarget`, and `rules.neon`
lints it and checks it for ReDoS as it does in a `preg_*()` call. A variadic
parameter has each of its arguments read. PHPStan reads the arguments of an
attribute only when it knows its class: `#[Language('RegExp')]` is read when
`jetbrains/phpstorm-attributes` is installed, and `#[RegexPattern]`, which
takes none, always. Parameter attributes come from PHPStan's reflection,
which offers them from PHPStan 2.1.31 at least: on an older 2.x release that
lacks them, no parameter is read as a pattern, and nothing is reported.

## Lint rules and ReDoS analysis

`rules.neon` turns them on:

```neon
includes:
    - vendor/php-regex/php-regex/src/PHPStan/rules.neon
```

Without extension-installer, include both `extension.neon` and `rules.neon`.
Each check can also be switched on its own (see below).

ReDoS analysis in PHPStan is theoretical: it reads the pattern and never runs
it inside PHPStan.

## Cost

With the opt-in checks off, the extension parses and validates each distinct
constant pattern once — the parse lands in an in-memory cache, so a pattern
that appears in a hundred files is not parsed a hundred times. On the
project's benchmark corpus, 1,645 patterns collected from real PHP projects,
the whole corpus validates in about a third of a second: an everyday pattern
validates in a few tens of microseconds once warm, a pathological one in
about 1.5 milliseconds. ReDoS analysis is the expensive check: the same
corpus judged for ReDoS takes about 4 seconds, 2.4 milliseconds per pattern
on average, and its worst real-world pattern about 230 milliseconds.
PHPStan's result cache does the rest: a file it does not analyse again costs
the extension nothing.

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

The verdicts and the character sets behind them come from the PCRE2 that runs
PHPStan. PHPStan's result cache does not know when a distribution upgrades
libpcre2 under an unchanged PHP: clear it (`vendor/bin/phpstan clear-result-cache`)
after such an upgrade.

`<pattern>` is the pattern as the console shows it, cut after 50 characters —
never mid-character or mid-escape — with invisible characters (C1 controls,
bidirectional overrides, zero-width spaces…) written as escapes (`\x{202E}`
under `/u`, `\xE2\x80\xAE` otherwise) and a pattern under `x` on one line
without its `#` comments, so no output format reads it as markup.

The message holds the verdict class and the pattern only, and the text of each
of the three stays the same for all of 2.x. The severity, how the verdict was
reached and the attack are in the tip. Which patterns are reported is not
frozen: when the analysis improves, an error may appear, disappear or change
class (Exponential, Polynomial, Potential). After an upgrade that changes the
analysis, regenerate the baseline:

```bash
vendor/bin/phpstan analyse --generate-baseline
```

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
the documentation links follow. The [ReDoS guide](redos.md) explains
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

## Quadratic search

One attempt of `/\s+$/` is linear, but an unanchored `preg_match()` starts an
attempt at each position of the subject, and on a run of spaces each one reads
to the end of the run before it fails: PCRE2's interpreter takes a number of
steps quadratic in the length of the run. `pcre.backtrack_limit` does not stop
it, as the limit counts each attempt apart, and the JIT may avoid it for some
patterns, not for all (see the [ReDoS guide](redos.md#the-cost-of-an-unanchored-search)).
Such a pattern is reported under its own identifier, `regex.redos.search`,
with the message `Quadratic search (ReDoS): <pattern>`, frozen for 2.x like
the three others. It has no setting of its own: it follows `checks.redos.enabled`
and is `medium`, like a proven quadratic attempt, so `threshold: medium` or
`low` shows it. A constant subject is not reported.

```php
function trailing(string $value): bool
{
    return 1 === preg_match('/\s+$/', $value);
}
```

With `threshold: medium`:

```
 ------ -----------------------------------------------------------------------
  Line   Validator.php
 ------ -----------------------------------------------------------------------
  5      Quadratic search (ReDoS): /\s+$/
         🪪  regex.redos.search
         💡  Quadratic search: one attempt is linear (proven); an unanchored
         search is quadratic in PCRE2's interpreter (pcre.jit=0, a build
         without JIT, or (*NO_JIT)); the JIT may avoid it for some patterns.
         Severity: MEDIUM.
         💡  Attack: " " x n . "!". pcre.backtrack_limit does not stop it: the
         limit counts each attempt apart. preg_match_all(), preg_replace() and
         preg_split() retry the same way. Anchor the pattern when every match
         starts at a known place (^, \A, \G or the A modifier), or bound the
         length of the run.
         💡
         💡  Read more about catastrophic backtracking: …
 ------ -----------------------------------------------------------------------
```

Ignore it by identifier where the length of the subject is bounded:

```neon
parameters:
    ignoreErrors:
        - identifier: regex.redos.search
```

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
| `regexp.pattern` | a pattern passed to a parameter marked `#[RegexPattern]` or `#[Language('RegExp')]` that the running PHP refuses; PHPStan core's identifier, which it gives such a pattern in a `preg_*()` call |
| `regex.replacement.undefinedGroup` | a constant replacement of `preg_replace()` or `preg_filter()` that refers to a group the pattern does not have, or names one (`${name}`, which PHP never substitutes); when the pattern and the replacement both vary, only a reference no possible pattern defines; always on |
| `regex.redos` | a pattern at or above the ReDoS threshold; the severity is in the tip |
| `regex.redos.search` | a pattern whose one attempt is proven linear and whose unanchored search is quadratic in PCRE2's interpreter, at the ReDoS threshold `medium` or below |
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
[UPGRADE-2.0.md](https://github.com/php-regex/php-regex/blob/2.x/UPGRADE-2.0.md)).

Regenerate it once after upgrading to 2.0.0 as well, whatever version wrote it:
lint messages were reworded (the useless `m` and `s` flags, a lazy quantifier
under `U`, redundant class ranges), false positives were removed, and with
`redos` on, the nested-quantifier, dot-star and overlapping-set issues are no
longer reported for a pattern the analysis proves linear.

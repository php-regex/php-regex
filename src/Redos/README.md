<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=2">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=3">
        <img src="art/banner.png?v=3" alt="PHPRegex ReDoS" width="100%">
    </picture>
</p>

PHPRegex Redos
==============

Proves a pattern safe from catastrophic backtracking (ReDoS), or hands you the input that triggers it — and can replay that input on the engine.

Requires PHP 8.2+. MIT licensed.

Features
--------

* One verdict per pattern, read straight from the AST: `safe`, `low`, `medium`, `high` or `critical` — or `unknown`, with the error, when the analysis itself fails.
* A backtracking model proves the cost of one match attempt — linear, polynomial (with its degree) or exponential — and the verdict says who decided: `proven`, `heuristic`, `budget_exceeded` or `not_analyzed`.
* Every proven vulnerable verdict carries a witness: the prefix, pump and suffix of the input family that drives the worst case.
* When one attempt is proven linear, the cost of the search that retries it is looked for too: `RedosSearchCost` holds the prefix, the run and the breaker on which an unanchored search is quadratic in PCRE2's interpreter, where `pcre.backtrack_limit`, counted per attempt, trips only when one attempt exceeds it.
* Confirmation adds runtime evidence: the witness replayed pump by pump, or growing inputs — always without the JIT, under the limits you set, with the failing evidence named.
* Outside the model, structural heuristics decide and say so: confidence level, false-positive risk, one `Finding` per risk with its message and suggested rewrite.
* The verdict serializes to JSON with the PCRE2 release and the analysis version; `Hotspot` objects pin each risk to byte offsets, for `Heatmap` to paint.

Installation
------------

```bash
composer require php-regex/regex-redos
```

`php-regex/regex-parser` is pulled in automatically, at the same version. To
scan a whole code base instead of one pattern at a time, use the
[linter](https://github.com/php-regex/php-regex/tree/2.x/src/Linter).

Configuration
-------------

`new RedosAnalyzer()` takes named arguments: `ignoredPatterns` (`array<string>`,
patterns skipped by value, with or without their delimiters), `threshold`
(`RedosSeverity::High`, the lowest severity that triggers a confirmation run)
and `options` (a `RedosOptions`).

`RedosOptions` is the budget of the backtracking model, counted in states and
steps, never in time — the same pattern gets the same verdict on every machine:

| Option | Default | Role |
| --- | --- | --- |
| `maxStates` | `2000` | states the pattern's automata may hold |
| `maxSteps` | `250_000` | states created, product pairs visited, class scans and the memory they take |
| `boundedRepeatCutoff` | `16` | largest bounded-repeat maximum unrolled; past it, `{m,n}` is analyzed as `{m,}` |

`ConfirmationOptions` drives the runtime probe:

| Option | Default | Role |
| --- | --- | --- |
| `minInputLength`, `maxInputLength`, `steps` | `16`, `128`, `3` | subject lengths probed, doubling up to the maximum |
| `iterations`, `timeoutMs` | `3`, `50.0` | runs per length, and the average duration that stops one |
| `backtrackLimit`, `recursionLimit` | `100_000`, `10_000` | engine limits during the probe |
| `previewLength` | `64` | subject bytes kept per sample; `0` keeps none |

`analyze()` also takes `threshold` (the constructor one by default), `mode` and
`confirmOptions`. `RedosMode::Off` skips the analysis, `RedosMode::Theoretical`
reads the AST only, `RedosMode::Confirmed` runs the confirmation for you.

Usage
-----

The first verdict:

```php
use PHPRegex\Redos\RedosAnalyzer;

$analysis = (new RedosAnalyzer())->analyze('/(\w+\s?)+$/');

echo $analysis->severity->value, "\n";   // critical
echo $analysis->complexity->value, "\n"; // exponential
echo $analysis->proof->value, "\n";      // proven
```

Proven safe, and polynomial with its degree:

```php
$analyzer = new RedosAnalyzer();

var_dump($analyzer->analyze('/^\d{4}-\d{2}-\d{2}$/')->isProvenSafe()); // bool(true)

$adjacent = $analyzer->analyze('/\w+\w+!/');
echo $adjacent->severity->value, ' ', $adjacent->complexity->value, ' ', $adjacent->degree, "\n";
// medium polynomial 2
```

The witness — the input family that drives the worst case — and the headline every consumer prints:

```php
$vuln = (new RedosAnalyzer())->analyze('/(a+)+b/');

echo $vuln->headline(), "\n";        // Exponential backtracking (proven)
echo $vuln->witness->render(), "\n"; // "a" x n . "!b"
echo $vuln->witness->build(3), "\n"; // aaa!b
```

Confirmed mode replays an exponential witness on the running engine; it tries several candidate suffixes and publishes the one that made `preg_match()` exhaust the backtrack limit:

```php
use PHPRegex\Redos\RedosMode;

$replayed = (new RedosAnalyzer())->analyze('/(a+)+b/', mode: RedosMode::Confirmed);

echo $replayed->witness->render(), "\n";         // "a" x n . "!b"
var_dump($replayed->replayed);                   // bool(true)
echo $replayed->confidenceLevel()->value, "\n"; // high
```

What the model analysed differently from the pattern, and the budget it ran under:

```php
use PHPRegex\Redos\RedosOptions;

$bounded = (new RedosAnalyzer())->analyze('/(a{1,20})+$/');
echo $bounded->abstractions[0], "\n"; // {1,20} at offset 1 analysed as {1,}

$small = new RedosAnalyzer(options: new RedosOptions(maxStates: 100, maxSteps: 5_000));
echo $small->analyze('/^(?:\d{1,16}|[a-f]{1,16})+$/')->proof->value, "\n"; // budget_exceeded
```

The confirm step, with your own limits on the probe:

```php
use PHPRegex\Redos\ConfirmationOptions;
use PHPRegex\Redos\ConfirmationRunner;

$analysis = (new RedosAnalyzer())->analyze('/(\w+\s?)+$/');

$confirmation = (new ConfirmationRunner())->confirm(
    '/(\w+\s?)+$/',
    $analysis,
    new ConfirmationOptions(backtrackLimit: 50_000),
);

var_dump($confirmation->confirmed); // bool(true)
echo $confirmation->evidence, "\n"; // backtrack_limit
```

Where `ini_set()` is disabled the engine cannot set those limits: nothing is
run, `$confirmation->wasSkipped()` is true and its evidence is
`Confirmation::LIMITS_UNAVAILABLE`.

Documentation
-------------

* [Quick start](https://github.com/php-regex/php-regex/blob/2.x/docs/QUICK_START.md) — where the ReDoS check sits in the opening tour
* [ReDoS guide](https://github.com/php-regex/php-regex/blob/2.x/docs/REDOS_GUIDE.md) — the verdict and its guarantee, the witness, confirmed mode and the mitigations
* [ReDoS deep dive](https://github.com/php-regex/php-regex/blob/2.x/docs/concepts/redos.md) — how backtracking explodes, shape by shape, and how the model finds it
* [API reference](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/api.md) — the analyzer's options and the aggregate analysis report
* [Backward compatibility](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md) — what stays stable across releases

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* [All PHPRegex packages](https://github.com/php-regex/php-regex/blob/2.x/README.md) — one repo, one version number
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and [send pull requests](https://github.com/php-regex/php-regex/pulls)
  in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

Sponsors
---------

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

License
-------

MIT. See [LICENSE](https://github.com/php-regex/php-regex/blob/2.x/LICENSE).

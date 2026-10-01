<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.svg?v=1">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.svg?v=1">
        <img src="art/banner.svg?v=1" alt="PHPRegex Automata" width="100%">
    </picture>
</p>

PHPRegex Automata
=================

Compiles the regular subset of PCRE to automata to compare languages: equivalence, intersection, subset and example strings.

Features
--------

- Equivalence, intersection and subset checks, each answered with the shortest witness or counter-example string
- Patterns compile to DFAs: NFA construction, determinization, then Hopcroft or Moore minimization
- Two match semantics: the whole input (`MatchMode::Full`) or any substring (`MatchMode::Partial`)
- The `i`, `s` and `u` flags, character classes and ranges, dot, `^`/`$`, alternation, groups and quantifiers
- Backreferences, lookarounds, recursion and other non-regular constructs throw a `ComplexityException` instead of a wrong answer
- NFA, DFA and transition budgets bound the work on pathological patterns
- A pluggable DFA cache (`DfaCacheInterface`) reuses compiled automata across questions
- `compile()` exposes the DFA of a single pattern

Installation
------------

```bash
composer require php-regex/regex-automata
```

PHP 8.2 or newer. `php-regex/regex-parser` is pulled in automatically.

Configuration
-------------

Every question takes an optional `SolverOptions` object as its last argument.

| Option | Values | Default |
|--------|--------|---------|
| `matchMode` | `MatchMode::Full`, `MatchMode::Partial` | `Full` |
| `maxNfaStates` | int | `5000` |
| `maxDfaStates` | int | `10000` |
| `minimizeDfa` | bool | `true` |
| `minimizationAlgorithm` | `MinimizationAlgorithm::Hopcroft`, `::Moore` | `Hopcroft` |
| `determinizationAlgorithm` | `DeterminizationAlgorithm::SubsetIndexed`, `::Subset` | `SubsetIndexed` |
| `maxTransitionsProcessed` | int, `null` to disable the guard | `1000000` |

Reaching a limit throws the same `ComplexityException` as an unsupported construct. The solver constructor also accepts a `DfaCacheInterface` (`InMemoryDfaCache` ships with the package) to reuse compiled DFAs across questions.

Usage
-----

Are two patterns interchangeable? When they are not, the shortest string only one side matches tells you where they differ:

```php
use PHPRegex\Automata\LanguageSolver;

$solver = new LanguageSolver();

$result = $solver->equivalent('/a+/', '/a*a+/');
var_dump($result->isEquivalent); // bool(true)

$result = $solver->equivalent('/\w+/', '/[a-z]+/');
var_dump($result->isEquivalent);     // bool(false)
var_dump($result->leftOnlyExample);  // string(1) "0"
var_dump($result->rightOnlyExample); // NULL
```

Do two route patterns ever match the same URL?

```php
$result = $solver->intersection('/user\/[a-z0-9_]+/', '/user\/[0-9]+/');

var_dump($result->isEmpty); // bool(false)
echo $result->example;      // user/0
```

Is every string of one pattern also matched by the other?

```php
var_dump($solver->subsetOf('/\d{3}/', '/\d+/')->isSubset); // bool(true)

$result = $solver->subsetOf('/\d+/', '/\d{3}/');

var_dump($result->isSubset);       // bool(false)
var_dump($result->counterExample); // string(1) "0"
```

Match semantics change what a pattern means. `/a/` alone accepts only the string `a`; searched anywhere in the input it accepts every string that contains an `a`:

```php
use PHPRegex\Automata\Options\MatchMode;
use PHPRegex\Automata\Options\SolverOptions;

$options = new SolverOptions(matchMode: MatchMode::Partial);

var_dump($solver->equivalent('/a/', '/^a$/', $options)->isEquivalent); // bool(false)
var_dump($solver->equivalent('/a/', '/^a$/')->isEquivalent);           // bool(true)
```

Outside the regular subset there is no answer to fake — the exception names the limit:

```php
use PHPRegex\Automata\Exception\ComplexityException;

try {
    $solver->equivalent('/(a)\1/', '/aa/');
} catch (ComplexityException $e) {
    echo $e->getMessage(); // Unsupported regex feature in automata conversion.
}
```

Documentation
-------------

- [Logic solver reference](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/logic-solver.md) — the concept, the route-conflict, security-audit and refactoring use cases, strategy tuning and safety limits
- [API reference](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/api.md) — the solver entry points among the library's public API
- [Feature support matrix](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/feature-support-matrix.md) — which constructs every component supports, the solver column included
- [Correctness contracts](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/correctness-contracts.md) — the semantics each solver answer rests on
- [Backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md) — what stays stable across releases

This package is part of [PHPRegex](https://github.com/php-regex/php-regex), released with its siblings under one version number.

Resources
---------

* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* The parsing core it builds on: [regex-parser](https://github.com/php-regex/php-regex/tree/2.x/src/Parser)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and [send pull requests](https://github.com/php-regex/php-regex/pulls) in the [main PHPRegex repository](https://github.com/php-regex/php-regex)

Sponsors
---------

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

License
-------

MIT. See [LICENSE](https://github.com/php-regex/php-regex/blob/2.x/LICENSE).

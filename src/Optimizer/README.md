<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.png?v=2">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.png?v=2">
        <img src="art/banner.png?v=2" alt="PHPRegex Optimizer" width="100%">
    </picture>
</p>

PHPRegex Optimizer
==================

Rewrites regex patterns into shorter equivalents and modernizes old syntax; equivalence can be proven, opt-in, by regex-automata.

Features
--------

- `[0-9]` becomes `\d`, `[a-zA-Z0-9_]` becomes `\w`; character class members are sorted, merged and deduplicated
- Repeated items collapse into a quantifier, `/aaaa/` becoming `/a{4}/`, from `min_quantifier_count` repetitions on
- Alternatives sharing a prefix share it, `/abc|abd/` becoming `/ab(?:c|d)/` — opt-in
- A quantifier becomes possessive where nothing after it can take back what it matched, `/[0-9]+;/` becoming `/\d++;/` — opt-in
- Each rewrite can be proven to match the same strings by the automata engine before it is offered — opt-in
- A `Modernizer` visitor cleans legacy syntax: unneeded escapes dropped, redundant non-capturing groups unwrapped
- A pattern that cannot be improved comes back unchanged, with an empty change log

Installation
------------

```bash
composer require php-regex/regex-optimizer
```

Requires PHP 8.2+. MIT licensed. `php-regex/regex-parser`, and the `php-regex/regex-automata`
engine the equivalence proof runs on, are installed with it. This package is part of
[PHPRegex](https://github.com/php-regex/php-regex), released with its siblings under one version number.

Configuration
-------------

`optimize()` takes an `OptimizerOptions` value, or an array it reads. Array keys
are the snake_case names below; `regex.json` and the PHPStan parameters spell
the same options in camelCase (`OptimizerOptions::fromCamelCaseArray()`).

| Option                      | Type | Default | What it does                                                                |
|-----------------------------|------|---------|-----------------------------------------------------------------------------|
| `digits`                    | bool | `true`  | `[0-9]` becomes `\d`                                                        |
| `word`                      | bool | `true`  | `[a-zA-Z0-9_]` becomes `\w`                                                 |
| `ranges`                    | bool | `true`  | runs of consecutive characters become ranges                                |
| `canonicalize_char_classes` | bool | `true`  | members of a class are sorted and merged                                    |
| `possessive`                | bool | `false` | a quantifier becomes possessive where nothing after it can take the match back |
| `factorize`                 | bool | `false` | alternatives sharing a prefix share it                                      |
| `min_quantifier_count`      | int  | `4`     | how many repetitions of an item become a quantifier, at least 2             |
| `verify_with_automata`      | bool | `false` | a rewrite is only offered when the automata prove it matches the same strings |

`possessive` and `factorize` are off by default: a backreference can make a
possessive quantifier change what the pattern matches, and factorization makes
an `/x` pattern harder to read. `verify_with_automata` is off by default too:
the proof builds automata for both patterns, which costs time on large ones,
and a rewrite it disproves is withdrawn, the original pattern returned.

An unknown key or a value of the wrong type throws `InvalidRegexOptionException`. The 1.x names
`autoPossessify` and `allowAlternationFactorization` are `possessive` and `factorize` since 2.0.

Usage
-----

```php
use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Parser\RegexParser;

$optimizer = new Optimizer(RegexParser::create());

$result = $optimizer->optimize('/[0-9][0-9][0-9][0-9]/');

echo $result->optimized;   // '/\d{4}/'
echo $result->changes[0];  // 'Optimized pattern.'
```

Each rewrite is opt-out: turn off the ones you do not want, keep the rest.

```php
$result = $optimizer->optimize('/[abc][0-9]/', ['digits' => false]);

echo $result->optimized;  // '/[a-c][0-9]/'
```

The same options as a value object, turning on the two off-by-default rewrites:

```php
use PHPRegex\Optimizer\OptimizerOptions;

$options = new OptimizerOptions(possessive: true, factorize: true);

echo $optimizer->optimize('/[0-9]+;/', $options)->optimized;  // '/\d++;/'
echo $optimizer->optimize('/abc|abd/', $options)->optimized;  // '/ab(?:c|d)/'
```

The instance keeps the solver its automata checks ask, with a DFA cache: a
later `optimize()` on the same instance reuses the automata an earlier one
built. Keep one instance for a batch of patterns rather than building one per
call; the cache it stores them in is the constructor's optional second
argument, any `DfaCacheInterface`.

To have every rewrite proven before it is offered:

```php
$result = $optimizer->optimize('/[0-9][0-9][0-9][0-9]/', ['verify_with_automata' => true]);

echo $result->optimized;  // '/\d{4}/'
```

The `Modernizer` visitor rewrites old syntax on the AST; render its result with
the parser's printer:

```php
use PHPRegex\Optimizer\Modernizer;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;

$parser = RegexParser::create();
$modernized = $parser->parse('/a\-(?:[0-9])/')->accept(new Modernizer());

echo $modernized->accept(new PatternPrinter());  // '/a-\d/'
```

Documentation
-------------

- [API reference](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/api.md) — `optimize()` in full: both option spellings and `OptimizationResult`
- [Correctness contracts](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/correctness-contracts.md) — what the optimizer guarantees, and when it declines a rewrite
- [Backward compatibility promise](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/backward-compatibility.md) — the public surface of this package and what stays stable within 2.x
- [Feature support matrix](https://github.com/php-regex/php-regex/blob/2.x/docs/reference/feature-support-matrix.md) — which constructs each analyzer, the optimizer included, handles

Resources
---------

* The batteries-included facade: [regex-toolkit](https://github.com/php-regex/php-regex/tree/2.x/src/Toolkit)
* The equivalence engine: [regex-automata](https://github.com/php-regex/php-regex/tree/2.x/src/Automata)
* [Documentation](https://github.com/php-regex/php-regex/tree/2.x/docs)
* [Changelog](CHANGELOG.md)
* [Report issues](https://github.com/php-regex/php-regex/issues) and [send pull requests](https://github.com/php-regex/php-regex/pulls) in the
  [main PHPRegex repository](https://github.com/php-regex/php-regex)

Sponsors
---------

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

License
-------

MIT. See [LICENSE](https://github.com/php-regex/php-regex/blob/2.x/LICENSE).

# build/ — the PHP 7.4 twin of src/Parser

The parser package, rewritten mechanically to run on PHP 7.4 to 8.6, and
published as `php-regex/regex-parser-php74` for hosts that cannot require
PHP 8.1, as a tool's PHAR downgraded to PHP 7.4 does. The generated tree
lives in `php74/` (git-ignored); everything here produces it and proves it
reads every pattern as the original does.

## Generate

```sh
bash build/generate.sh
```

1. `src/Parser` is copied and downgraded by Rector (`rector-downgrade.php`:
   the PHP 8.1 and 8.0 sets, which bring the code to 7.4 and keep its typed
   properties; the enum rule is left out, step 4 writes the enums).
2. A second Rector pass (`rector-post.php`, rules in `rector/`) puts back
   what the downgrade loses:
   - `StrictSwitchRector`: each switch written from a match compares
     strictly (PHP 7.4 holds `'a' == 0` and `'1' == '01'` true) and throws
     `UnhandledMatchError` where no case holds, as the match did;
   - `SingleArraySetRector`: `array_intersect_key(...$sets)` and its
     siblings give back the one array they get, as PHP 8.0 does (7.4 asks
     for two);
   - `PropertyOrderRector`: properties are declared in the original's order
     (`property-order.php` reads it), which reflection, `get_object_vars()`,
     `serialize()` and the structural fingerprint see;
   - `DowngradeLeftoversRector`: the `null;` statements and the
     `iterator_to_array()` fallbacks of array spreads the twin does not need.
3. `restore-docblock-types.php` writes back as PHPDoc each native type the
   downgrade drops untagged (`mixed`), from the original's signatures.
4. `emit-enum-factories.php` writes each enum as a class of shared
   instances: `GroupType::Capturing()` returns one instance, so `===`,
   `->name`, `->value`, `instanceof` and the native type hints hold;
   `from()` throws PHP's `ValueError` with PHP's message; the classes
   implement the twin's `UnitEnum` / `BackedEnum`. An enum with a constant,
   an interface or a method with parameters stops the build.
5. `rewrite-enum-calls.php` turns `Enum::Case` into `Enum::Case()` and
   `\UnitEnum` / `\BackedEnum` into the twin's interfaces, reading tokens and
   resolving names, so no string or comment is touched.
6. `hoist-enum-consts.php` turns each private constant that holds enum cases
   into a typed static property filled once and a method of the same name.
7. `patch-twin-cache.php` lets a cache restore the twin's enum cases and
   gives each its shared instance back (`EnumCases::shared()`).
8. php-cs-fixer styles the tree with `php-cs-fixer-twin.php`, layout rules
   only: the repository's rules migrate to PHP 8.2.

## Verify

```sh
composer install --working-dir build/smoke          # the twin's environment
tools/phpstan/vendor/bin/phpstan analyse -c build/phpstan-php74.neon
tools/phpstan/vendor/bin/phpstan analyse -c build/phpstan-verify.neon
bash build/verify/run.sh                            # 7.4 8.0 8.1 … 8.6-rc
```

- **Static analysis**: the twin at level max, judged as PHP 7.4 code, the
  bar `src/Parser` passes. The three entries of `phpstan-php74.neon` are
  where the analysis's map of PHP 7.4 is wrong, each checked on
  `php:7.4-cli`.
- **The twin's suite** (`tests/`, PHPUnit 9.6, run on the official image of
  each PHP):
  - `TwinParityTest`: every pattern of `verify/patterns.php` (the corpus
    fixtures and every delimited literal of the test suite, some 17,000),
    for six targets, read by the twin and by the original
    (`verify/Witness.php`: tokens, tree, tolerant tree, validation, capture
    shapes under each flag set, pattern settings, literals, lengths,
    complexity, metrics, character set, printers, dumpers, fingerprint, the
    tree a cache gives back). On PHP 8.1 and later the original reads them
    on the same PHP and every reading must be the same; on 7.4 and 8.0 the
    reference is the original on 8.1, and only the patterns
    `tests/engine-dependent.php` names, each with its reason, may differ;
  - `TwinSurfaceTest`: the same classes, methods, parameters, properties,
    constants and enum cases as the original;
  - `EnumCasesTest`, `CacheRoundTripTest`: the classes of cases behave as
    enums, and a tree read back from a cache directory reads as the tree
    parsed;
  - `TwinLintTest`: every file compiles on the PHP that runs, with no
    notice.

`verify/show.php` prints one reading of one pattern, to see what a
difference is made of.

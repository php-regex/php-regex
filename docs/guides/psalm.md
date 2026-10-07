# Psalm

`php-regex/regex-psalm` is a Psalm plugin that reads the pattern of every
`preg_*` call. Psalm types `$matches` the same way whatever the pattern,
`array<array-key, string>`; with the plugin, where `preg_match()` returned 1,
`$matches` is the shape of the pattern, and after `preg_match_all()` too. The
plugin also reports the patterns the PHP you target refuses.

## Installation

```bash
composer require --dev php-regex/regex-psalm
vendor/bin/psalm-plugin enable php-regex/regex-psalm
```

or, in `psalm.xml`:

```xml
<plugins>
    <pluginClass class="PHPRegex\Psalm\Plugin"/>
</plugins>
```

The plugin needs Psalm 6.19 or later, run with PHP 8.2 or later; Psalm 6
itself runs on PHP 8.1, which cannot load the plugin.

## The shape of `$matches`

Each example is followed by the message of the `Trace` issue Psalm printed
for its `@psalm-trace`.

```php
function release(string $tag): ?int
{
    if (!preg_match('/^v(?<major>\d+)\.(?<minor>\d+)(?:-(rc|beta)(\d+))?$/', $tag, $m)) {
        return null;
    }

    /** @psalm-trace $m */

    return (int) $m['major'];
}
```

```
$m: array{0: non-falsy-string, 1: non-empty-string, 2: non-empty-string, 3?: ''|'beta'|'rc', 4?: non-empty-string, major: non-empty-string, minor: non-empty-string}
```

A key PHP may leave out is optional, a group with a few possible values
holds them as literals. The shape follows `CaptureShape` (see
[Capture Shapes](../reference/capture-shapes.md)): the same facts the PHPStan
extension's types are built from, but `numeric-string`. Psalm 6.19's type
combiner collapses `numeric-string|'a'` to `numeric-string`: after
`$v = preg_match(…) ? $m[1] : 'none'`, Psalm would reject `'none' === $v`. So
a group of digits is a `non-falsy-string` where it cannot be `'0'`, else a
`non-empty-string`. The cases of a pattern the analysis splits, as
`/(a)|(b)/`, are merged key by key into one array shape.

`PREG_OFFSET_CAPTURE` and `PREG_UNMATCHED_AS_NULL` are read:

```php
if (preg_match('/(\w+)@(\w+)?/', $text, $m, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL)) {
    /** @psalm-trace $m */
}
```

```
$m: array{0: list{non-falsy-string, int<0, max>}, 1: list{non-empty-string, int<0, max>}, 2: list{non-empty-string|null, int<-1, max>}}
```

`preg_match_all()` writes its shape whenever it returns an int, `0`
included. It returns `false` and leaves `[]` for an offset past the subject,
and for a match that ends before it starts (`\K` in a lookahead): the plugin
types the call only for a pattern without `\K` and an offset absent or a
constant ≤ 0 (PHP reads a negative offset as 0).

```php
preg_match_all('/#([a-z]+)/', $text, $m);
/** @psalm-trace $m */

preg_match_all('/#([a-z]+)(?::(\d+))?/', $text, $sets, PREG_SET_ORDER);
/** @psalm-trace $sets */
```

```
$m: array{0: list<non-falsy-string>, 1: list<non-falsy-string>}
$sets: list<array{0: non-falsy-string, 1: non-falsy-string, 2?: non-empty-string}>
```

### Where `preg_match()` is narrowed

In the branch where `preg_match()` returned 1, `$matches` is the shape; in the
branch where it did not, `[]`. Psalm reads that branch from:

- `if (preg_match(…))`, and the code after `if (!preg_match(…)) { return …; }`;
- a ternary, `preg_match(…) ? $m[1] : null`;
- `&&` and `||`, `(bool) preg_match(…)`, and `if ($found = preg_match(…))`.

Outside the checked branch, `$matches` keeps Psalm's own type: the plugin
does not change what an unchecked read of `$matches` costs.

No branch is read from these, and `$matches` keeps Psalm's own type in them:
a call compared with anything (`1 === preg_match(…)`,
`preg_match(…) !== false`, `false === preg_match(…)`, `preg_match(…) > 0`),
`@preg_match(…)`, `$ok = preg_match(…); if ($ok)`, a `while` condition, and a
`$matches` that is not a variable, as `$this->matches`. Psalm reads a
plugin's condition only from a call that is the condition itself or its
negation: this is a limit of Psalm. It also reads it through a comparison
with `true` or `false`, as if the call returned a boolean; `preg_match()`
returns `0` when nothing matches, so `preg_match(…) !== false` holds on a
call that left `[]`, and the plugin drops its condition from every
comparison.

### What the plugin leaves to Psalm

- A pattern or flags without one constant value: a parameter, a variable that
  may hold one of two literals. A constant, a class constant and a variable
  holding one literal are read.
- A pattern of `maxStringLength` bytes or more (1000 by default, an attribute
  of `<psalm>`): Psalm does not keep it as a literal.
- A call that unpacks its arguments, `preg_match(...$args)`.
- An unqualified `preg_match()` in a namespace that declares its own
  `preg_match()`: the call reaches that function, as in PHP.
- A pattern the PHP running Psalm does not compile, or the target PHP refuses
  (the second is reported): it leaves `$matches` as it was, so no branch can
  say what it holds.
- A pattern past the library's length limit
  (`RegexParser::DEFAULT_MAX_PATTERN_LENGTH`): neither typed nor reported.
- A shape of more keys than `maxShapedArraySize`: Psalm keeps no such shape.
- A `preg_match_all()` call whose pattern holds `\K`, or whose offset is not
  a constant ≤ 0: it may return `false` and leave `[]`.
- The callbacks of `preg_replace_callback()` and
  `preg_replace_callback_array()`, and the results of the other `preg_*`
  functions.

The plugin never replaces the stubs Psalm describes the `preg_*` functions
with: their purity, return types, parameter checks and taint flows stay.

## Invalid patterns

The constant pattern of every `preg_*` call is validated: `preg_match()`,
`preg_match_all()`, `preg_replace()` and `preg_replace_callback()` (each
pattern of an array too), the keys of `preg_replace_callback_array()`,
`preg_split()`, `preg_grep()` and `preg_filter()`. In `app/broken.php`:

```php
<?php

function broken(string $text): ?string
{
    return preg_replace('/(?<n>a)(?<n>b)/', '', $text);
}
```

```
ERROR: InvalidRegexPattern - app/broken.php:5:25 - Regex pattern is invalid for PHP 8.4 with PCRE2 10.44: Duplicate group name "n" at position 12 (offset 12).
```

`InvalidRegexPattern` is a plugin issue, configured and suppressed as any
other:

```xml
<issueHandlers>
    <PluginIssue name="InvalidRegexPattern" errorLevel="info"/>
</issueHandlers>
```

```php
/** @psalm-suppress InvalidRegexPattern */
```

## The PHP version judged

Patterns are judged for the PHP version Psalm analyses for, 8.2 at least,
with the PCRE2 release that version bundles: 10.40 for 8.2, 10.42 for 8.3,
10.44 for 8.4 and 8.5. Two options of the plugin override it:

```xml
<plugins>
    <pluginClass class="PHPRegex\Psalm\Plugin">
        <phpVersion>8.2</phpVersion>
        <pcreVersion>10.40</pcreVersion>
    </pluginClass>
</plugins>
```

`<phpVersion>` takes a version (`8.2`), a `PHP_VERSION_ID` (`80200`) or
`runtime`, the PHP running Psalm and the PCRE2 it links. `<pcreVersion>`
takes a PCRE2 release, for a PHP linked against another PCRE2 than the one it
bundles. A value the plugin cannot read stops Psalm before it reads any file.

With `<phpVersion>8.2</phpVersion>`, Psalm running on PHP 8.4 reports the
`(?a…)` options PCRE2 10.43 added, in `app/numbers.php`:

```php
<?php

function is_ascii_number(string $input): bool
{
    return 1 === preg_match('/^(?aD)\d+$/u', $input);
}
```

```
ERROR: InvalidRegexPattern - app/numbers.php:5:29 - Regex pattern is invalid for PHP 8.2 with PCRE2 10.40: Invalid group modifier syntax at position 3 (offset 3).
```

When Psalm analyses for a PHP older than 8.2, a pattern with the `n` modifier,
which PHP reads from 8.2 only, is not typed.

## Adopting the plugin

The narrower types may show code that reads a key the pattern never writes,
as in `app/release.php`:

```php
<?php

function patch(string $tag): string
{
    if (preg_match('/^v(\d+)\.(\d+)$/', $tag, $m)) {
        return $m[3];
    }

    return '';
}
```

```
ERROR: InvalidArrayOffset - app/release.php:6:16 - Cannot access value on variable $m using offset value of '3', expecting 0, 1 or 2 (see https://psalm.dev/115)
ERROR: MixedReturnStatement - app/release.php:6:16 - Could not infer a return type (see https://psalm.dev/138)
```

On an existing project, record what the plugin finds in a baseline and fix
it over time:

```bash
vendor/bin/psalm --set-baseline=psalm-baseline.xml
```

A minor release may type a pattern more narrowly than the one before, which
may require regenerating the baseline; its CHANGELOG says so. The `Plugin`
class, the `InvalidRegexPattern` name and the two options stay for all of
2.x: see [the backward compatibility promise](../reference/backward-compatibility.md).

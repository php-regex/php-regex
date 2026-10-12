---
description: "The PHPRegex Psalm plugin: how $matches gets the shape of the pattern, what the plugin leaves to Psalm, and the PHP version patterns are judged for."
---
# Psalm

`php-regex/regex-psalm` is a Psalm plugin that reads the pattern of every
`preg_*` call. Psalm types `$matches` the same way whatever the pattern,
`array<array-key, string>`; with the plugin, where `preg_match()` returned 1,
`$matches` is the shape of the pattern, and after `preg_match_all()` too. The
plugin also reports the patterns the PHP you target refuses.

{% include install-prerelease.html package="php-regex/regex-psalm" dev=true %}

Then declare the plugin in `psalm.xml`:

```xml
<plugins>
    <pluginClass class="PHPRegex\Psalm\Plugin"/>
</plugins>
```

From the 2.0.0 tag and the split package, `vendor/bin/psalm-plugin enable
php-regex/regex-psalm` writes that line for you.

The plugin needs Psalm 6.19 or later (`vimeo/psalm ^6.19`), run with PHP 8.2
or later; Psalm 6 itself runs on PHP 8.1, which cannot load the plugin.

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

Where `preg_match_all()` returned a count of 1 or more, every list holds
that many entries: none is empty. The marks under `MARK` sit only under the
matches that set one, so their array may still be empty. The call is read
from the forms `preg_match()` is read from (see "Where `preg_match()` is
narrowed"); Psalm reports the bare call as a `RiskyTruthyFalsyComparison`,
and a cast to `bool` reads the same:

```php
if ((bool) preg_match_all('/#([a-z]+)/', $text, $m)) {
    /** @psalm-trace $m */
}
```

```
$m: array{0: non-empty-list<non-falsy-string>, 1: non-empty-list<non-falsy-string>}
```

### The callback of `preg_replace_callback()`

The callback receives what `preg_match()` writes on a match, the flags of the
call included: its parameter takes the shape of the pattern. Psalm types an
untyped closure parameter from it, and narrows an `array` one.

```php
preg_replace_callback('/#([a-z]+)(?::(\d+))?/', static function (array $m): string {
    /** @psalm-trace $m */

    return $m[1];
}, $text);
```

```
$m: array{0: non-falsy-string, 1: non-falsy-string, 2?: non-empty-string}
```

The pattern and the flags must be constant: a literal, a constant, a variable
holding one literal, or a pattern built with `preg_quote()`. Psalm reads the
parameters before the arguments, so the plugin reads only arguments that
write nothing (a call to anything but `preg_quote()`, an assignment, are left
to Psalm's `callable(string[]): string`). An array of patterns, and
`preg_replace_callback_array()`, keep Psalm's type.

### `preg_split()`

Under `PREG_SPLIT_NO_EMPTY` the pieces are `non-empty-string`, and under
`PREG_SPLIT_OFFSET_CAPTURE` each is a `list{string, int<0, max>}` pair;
without `PREG_SPLIT_NO_EMPTY` there is always one piece at least, the whole
subject when the pattern never matches. Psalm's stub says only the first of
these, for the default flags:

```php
$words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
$pieces = preg_split('/,/', $text, -1, PREG_SPLIT_OFFSET_CAPTURE);
/** @psalm-trace $words */
/** @psalm-trace $pieces */
```

```
$words: false|list<non-empty-string>
$pieces: false|non-empty-list<list{string, int<0, max>}>
```

The `false` of a failed call stays, ignored as Psalm's stub ignores it. The
flags must be one constant. `preg_grep()`, `preg_replace()` and
`preg_filter()` keep Psalm's types, which already follow the subject's.

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

### The subject of `preg_match()`

Where `preg_match()` returned 1, a `string` subject is narrowed: to
`non-empty-string` when the pattern never matches `''`, to
`non-falsy-string` when it matches neither `''` nor `'0'`. The plugin asks
the PHP running Psalm, from every offset the call may start at: `/a+/`,
`/ab/` and `/(?<=a)/` make a `non-falsy-string`, `/\d/` a
`non-empty-string`, and `/x*/`, which matches `''`, leaves a `string`.

```php
function order_id(string $input): string
{
    if (!preg_match('/^\d+$/', $input)) {
        throw new InvalidArgumentException('Not an order id.');
    }

    /** @psalm-trace $input */

    return $input;
}

function slug(string $input): void
{
    $slug = preg_match('/^[a-z]+(?:-[a-z]+)*$/', $input) ? $input : null;

    /** @psalm-trace $slug */
}
```

```
$input: non-empty-string
$slug: non-falsy-string|null
```

Where the call did not return 1, the subject keeps its type. No
`numeric-string` either, for the reason the groups of digits have none:
`/^\d+$/` makes a `non-empty-string`.

A pattern anchored at both ends may match only a few strings. Up to 16 of
them, each below `maxStringLength` bytes, the subject is one of them; the
plugin reads them from the pattern's automaton (`php-regex/regex-automata`),
and each one is matched by the PHP running Psalm before it is used:

```php
function answer(string $input): bool
{
    if (!preg_match('/^(?:yes|no)$/', $input)) {
        throw new InvalidArgumentException('Answer yes or no.');
    }

    /** @psalm-trace $input */

    return 'yes' === $input;
}
```

```
$input: 'no'|'no\n'|'yes'|'yes\n'
```

`$` matches before a final `"\n"` too, so `"yes\n"` is among them;
`/^(?:yes|no)$/D` or `/^(?:yes|no)\z/` leaves it out. Under `/iu`, the
strings follow Unicode case folding: `/^(?:ok)$/Diu` matches six, `k` folding
with U+212A KELVIN SIGN. The strings are read only from offset 0, a call with
no offset argument. A subject that holds literals already keeps them: where
the call is false, Psalm would take the strings out of it, and a match error
(a backtrack limit the runtime lowered) returns `false` on them too. Without
`/u`, PHP builds PCRE's character tables from the locale `setlocale()` sets:
after `setlocale(LC_CTYPE, 'fr_FR.ISO8859-1')`, `preg_match('/^\w$/', "\xE9")`
returns 1. A caseless part, a shorthand class, a POSIX class or `\b` without
`/u` leaves the subject the facts of its pattern.

A group reads its literals the same way, through alternations and bounded
repeats: `(a{1,3})` holds `'a'|'aa'|'aaa'`.

The subject is narrowed on a call that does not type `$matches`. Psalm reads
a false condition as the negation of what holds where it is true; on two
variables that negation says nothing of either, and `$matches` would lose
its `[]`. A subject that may be another type than `string` (PHP reads an
`int` as a string) or that is read from the `$matches` variable is left as
it is, and so is every form the previous section lists.

### Patterns built with `preg_quote()`

`preg_quote()` of literals is the literal it returns:
`preg_quote('1+1=2')` is `'1\+1\=2'` to Psalm, and a pattern concatenated
from literals and such calls is a literal, typed, narrowed and validated as
any other.

`preg_quote()` of a text the analysis does not know escapes every character
that means something in a pattern: where the pattern reads the text as
literal characters, it adds no group. Where `preg_match()` returned 1,
`$matches` is then the shape of the rest of the pattern, the text read as any
string:

```php
function ticket(string $prefix, string $input): ?int
{
    if (!preg_match('/^' . preg_quote($prefix, '/') . '-(\d+)$/', $input, $m)) {
        return null;
    }

    /** @psalm-trace $m */

    return (int) $m[1];
}
```

```
$m: array{0: non-falsy-string, 1: non-empty-string}
```

The text is not read as literal characters everywhere, and there the call is
left to Psalm: in a class (`'/[' . preg_quote($x) . ']/'`), in braces, where
digits make a quantifier, after a `\` or a `(?`, which it would continue,
after `\Q`, where a quoted `\E` ends the quoting, and before a quantifier,
which an empty text leaves to what precedes it (`'/(a)' . preg_quote($x) . '{0}/'`).
Where the call did not return 1, `$matches` keeps Psalm's type: a text
holding the delimiter, which `preg_quote()` escapes only when it is given,
makes the pattern fail to compile, and the call then leaves `$matches` as it
was. The pattern is never reported, nor is the subject narrowed: the plugin
does not know it. Only `preg_match()` reads such a pattern;
`preg_match_all()` writes its shape only for a pattern that compiles.

### What the plugin leaves to Psalm

- A pattern or flags without one constant value: a parameter, a variable that
  may hold one of two literals, a concatenation with anything but literals
  and `preg_quote()`. A constant, a class constant and a variable holding one
  literal are read.
- A pattern of `maxStringLength` bytes or more (1000 by default, an attribute
  of `<psalm>`): Psalm does not keep it as a literal.
- A call that unpacks its arguments, `preg_match(...$args)`.
- An unqualified `preg_match()` in a namespace that declares its own
  `preg_match()`: the call reaches that function, as in PHP.
- A pattern the PHP running Psalm does not compile, or the target PHP refuses
  (the second is reported): it leaves `$matches` as it was, so no branch can
  say what it holds.
- A pattern past the library's length limit (100,000 bytes,
  `RegexParser::DEFAULT_MAX_PATTERN_LENGTH`): neither typed nor reported.
- A shape of more keys than `maxShapedArraySize`: Psalm keeps no such shape.
- A `preg_match_all()` call whose pattern holds `\K`, or whose offset is not
  a constant ≤ 0: it may return `false` and leave `[]`.
- `preg_match_all()` compared with anything, as `preg_match_all(…) > 0`: no
  success is read from it.
- The callbacks of `preg_replace_callback_array()`, and the results of
  `preg_grep()`, `preg_replace()` and `preg_filter()`.

The plugin keeps the stubs Psalm describes the `preg_*` functions with: their
purity, parameter checks and taint flows stay, and a type the plugin narrows
keeps the data flow of Psalm's.

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

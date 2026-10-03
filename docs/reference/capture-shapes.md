# Capture Shapes

`CaptureShapeAnalyzer` reads, from the pattern alone, what a successful `preg_match()`
writes into `$matches`: which groups every match sets, which some matches leave unset,
which no match sets, and the strings each group can hold. It is built for static
analysers that type `$matches`, and for anyone who wants to know what a pattern
captures before running it.

```php
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\RegexParser;

$regex = RegexParser::create()->parse('/(GET|POST) (\S+)/');
$shape = (new CaptureShapeAnalyzer())->analyze($regex);

$shape->groups[0]->values;        // ['GET', 'POST']
$shape->groups[1]->minLength;     // 1
$shape->matchShape();             // "array{0: non-empty-string, 1: 'GET'|'POST', 2: non-empty-string}"
```

## What a group record holds

`analyze()` returns a `CaptureShape`: `$whole` for `$matches[0]`, `$groups` with one
`CaptureGroupShape` per group number, in order, and `$marks`, the names a `(*MARK)` verb,
or a verb that sets a mark, may leave under the `MARK` key.

| property | meaning |
|---|---|
| `number` | the group number; groups of a branch reset `(?|...)` share one record |
| `name` | the group name, or `null` |
| `participation` | `Always`, `MayBeUnset` or `Never` (below) |
| `minLength`, `maxLength` | bounds of what the group holds, in the characters PCRE reads: code points in UTF mode, bytes otherwise; `maxLength` is `null` when unbounded |
| `values` | every string the group can hold, when they are a small finite set read from literals, or `null` |

`Participation`:

- `Always`: every match sets the group, as `(\d{4})` in `/^(\d{4})-/`.
- `MayBeUnset`: some matches leave it unset, as `(a)` in `/(a)|b/` or `/(a)?/`. It is also
  the answer when the pattern alone cannot tell.
- `Never`: no match sets it: the group sits in a negative lookaround, which PCRE
  discards, in a `(?(DEFINE)...)` block, or under `{0}`.

## The shape of `$matches`

`matchShape(int $flags = 0)` writes the array `preg_match()` fills on success as a PHPStan
type, honouring `PREG_UNMATCHED_AS_NULL` and `PREG_OFFSET_CAPTURE`. It follows what PHP
does with an unset group:

| | unset group before a set one | unset groups at the end |
|---|---|---|
| no flag | `''` | left out of the array, name and number |
| `PREG_UNMATCHED_AS_NULL` | `null` | `null` |
| `PREG_OFFSET_CAPTURE` | `['', -1]` | left out |

```php
$shape = (new CaptureShapeAnalyzer())->analyze(
    RegexParser::create()->parse('/^(?<year>\d{4})-(?<month>\d{2})(?:-(?<day>\d{2}))?$/'),
);

$shape->matchShape();
// array{0: non-empty-string, year: non-empty-string, 1: non-empty-string,
//       month: non-empty-string, 2: non-empty-string,
//       day?: non-empty-string, 3?: non-empty-string}

$shape->matchShape(PREG_UNMATCHED_AS_NULL);
// array{0: non-empty-string, year: non-empty-string, 1: non-empty-string,
//       month: non-empty-string, 2: non-empty-string,
//       day: non-empty-string|null, 3: non-empty-string|null}
```

A group's value is written as a union of constant strings when its values are known,
`non-empty-string` when it cannot be empty, `string` otherwise.

## Soundness

Every answer is sound: a group reported `Always` is set by every match, a value set holds
every string the group can take, a length bound holds every match. When the pattern alone
cannot tell, the answer widens rather than guesses:

- Values come from literals only. Under `/i`, or once `(?i)` appears anywhere, they are
  unknown.
- `\K` and `(*ACCEPT)` cut a match short: the whole match then keeps only its presence,
  and `(*ACCEPT)` makes every group `MayBeUnset` with unknown values.
- A group read through a subroutine call, or a backreference, has unknown values.
- A key that every match writes, but only through different groups in different
  branches, is written optional.

The facts are checked against the engine: for patterns covering each case above, every
`$matches` PHP writes under each flag combination must follow them, and PHPStan, reading
the written shape, must accept it.

---

Previous: [Reference Index](README.md) | Next: [API Reference](api.md)

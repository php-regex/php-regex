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

$shape->groups[1]->values;        // ['GET', 'POST']
$shape->groups[2]->minLength;     // 1
$shape->matchShape();             // "array{0: non-empty-string, 1: 'GET'|'POST', 2: non-empty-string}"
```

An application that already uses the facade gets the same answer in one call. It
parses through the facade's cache, and an invalid pattern throws what `parse()` throws:

```php
use PHPRegex\Toolkit\Regex;

$shape = Regex::create()->captureShape('/(GET|POST) (\S+)/');
```

A static analysis extension needs only `php-regex/regex-parser`: the analyzer and its
results live there. The facade, in `php-regex/regex-toolkit`, is for applications.

## What a group record holds

`analyze()` returns a `CaptureShape`: `$whole` for `$matches[0]`, `$groups` with one
`CaptureGroupShape` per group number, and `$marks`, the names a `(*MARK)` verb, or a
verb that sets a mark, may leave under the `MARK` key.

`$groups` is keyed by group number: `$shape->groups[1]` is group 1. Its keys are exactly
`1` to `N`, in order, with no gap, so `count($shape->groups)` is the number of capturing
groups. A group no match sets has its record too, as does a group inside
`(?(DEFINE)...)`. Under `/n` or `(?n)` only named groups capture, and they are numbered
from 1: in `/(a)(?<x>b)/n`, `$groups[1]` is `x`.

| property | meaning |
|---|---|
| `number` | the group number, the record's key; groups of a branch reset `(?|...)` share one record |
| `name` | the group name, or `null` |
| `participation` | `Always`, `MayBeUnset` or `Never` (below) |
| `minLength`, `maxLength` | bounds of what the group holds, in the characters PCRE reads: code points in UTF mode, bytes otherwise; `maxLength` is `null` when unbounded |
| `values` | every string the group can hold, when they are a small finite set read from literals, or `null` |

A branch-reset record takes the name any of its branches gives. In `/(?|(x)|(?<a>y))/`,
group 1 is named `a`, and PHP writes `a` on `x` too. PCRE refuses two different names
for one number.

`Participation`:

- `Always`: every match sets the group, as `(\d{4})` in `/^(\d{4})-/`.
- `MayBeUnset`: some matches leave it unset, as `(a)` in `/(a)|b/` or `/(a)?/`. It is also
  the answer when the pattern alone cannot tell.
- `Never`: no match sets it: the group sits in a negative lookaround, which PCRE
  discards, in a `(?(DEFINE)...)` block, or under `{0}`.

## The shape of `$matches`

`matchShape(int $flags = 0)` writes the array `preg_match()` fills on success as a type
in PHPStan's syntax: array shapes, constant strings, `int<a, b>`, `non-empty-string`.
The facts it is written from, the records above, are engine-neutral: any other type
system can be fed from them.

It takes the flags `preg_match()` takes, `PREG_OFFSET_CAPTURE` and
`PREG_UNMATCHED_AS_NULL`, and checks the rest as `preg_match()` does. A bit of the low
byte (`$flags & 0xff`), such as `PREG_SET_ORDER`, makes `preg_match()` throw a
`ValueError`; `matchShape()` throws `InvalidRegexOptionException` instead. Any other bit
is ignored, as PHP ignores it: `preg_match('/(a)/', 'a', $m, 1024)` writes `['a', 'a']`.

It follows what PHP does with an unset group:

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

The keys come in the order PHP writes them: `0`, then for each group its name, when it
has one, before its number, then `MARK`. PHPStan prints this order in its messages, so
it reaches baselines.

### Shared names and `MARK`

Under `(?J)` several groups may share a name. The name then holds the value of the
highest-numbered group of that name that is set, and a group set to `''` counts as set.
`/(?J)(?<n>a)(?<n>b)/` on `ab` writes `n => 'b'`. When none of them is set, the name
holds what an unset group holds under the flags.

A group named `MARK` and a mark verb share one `MARK` key, as they do in `$matches`.
Its type is the union of the group's type and the mark names. Under
`PREG_OFFSET_CAPTURE` the group's part is a pair, and the mark names stay plain strings.
The key is required or optional as the group's own name key is: required whenever PHP
writes that key, that is when the group always participates, when a later group always
does (PHP then writes `''` for the unset group before it), or under
`PREG_UNMATCHED_AS_NULL` (PHP then writes `null` when nothing set it); optional
otherwise. It sits where the group's name sits:

```php
// preg_match('/(?<MARK>a)(*MARK:x)b/', 'ab', $m): [0 => 'ab', 'MARK' => 'x', 1 => 'a']
"array{0: 'ab', MARK: 'a'|'x', 1: 'a'}"

// preg_match('/(?<MARK>a)|(*MARK:x)b/', 'a', $m): [0 => 'a', 'MARK' => 'a', 1 => 'a']
"array{0: 'a'|'b', MARK?: 'a'|'x', 1?: 'a'}"

// preg_match('/(?<MARK>a)?(b)(*MARK:x)/', 'b', $m): [0 => 'b', 'MARK' => 'x', 1 => '', 2 => 'b']
"array{0: 'b'|'ab', MARK: ''|'a'|'x', 1: ''|'a', 2: 'b'}"
```

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

## What a release may change

`CaptureShapeAnalyzer::ANALYSIS_VERSION` is a string of digits, as
`RedosAnalyzer::ANALYSIS_VERSION` is. It rises in any release that
changes an answer, a fact or the string. Key a cache of shapes on it.

- A minor release may add properties and methods to `CaptureShape` and
  `CaptureGroupShape`. `matchShape()` grows through new methods or trailing optional
  parameters, never through a new positional boolean.
- A minor release may add a `Participation` case. A new case only refines
  `MayBeUnset`, so a `match` that maps any case it does not know to `MayBeUnset` stays
  sound.
- A minor release may narrow the facts and the string: more precise, still holding every
  `$matches` PHP writes. It may also write the same type differently, such as another
  union order or another cap on the values it lists.
- A patch release may widen an answer to make it sound again.

Either way, a PHPStan baseline that prints the type may need regenerating. The CHANGELOG
says when.

---

Previous: [Reference Index](README.md) | Next: [API Reference](api.md)

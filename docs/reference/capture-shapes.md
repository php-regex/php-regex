---
description: "What CaptureShapeAnalyzer reads from a pattern alone: which groups every match sets, their values, lengths and facts, and the $matches type it writes."
---

# Capture Shapes

`CaptureShapeAnalyzer` reads, from the pattern alone, what a successful `preg_match()`
writes into `$matches`: which groups every match sets, which some matches leave unset,
which no match sets, the strings each group can hold, and facts such as "never falsy"
or "digits only". It is built for static
analysers that type `$matches`, and for anyone who wants to know what a pattern
captures before running it.

```php
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\RegexParser;

$regex = RegexParser::create()->parse('/(GET|POST) (\S+)/');
$shape = (new CaptureShapeAnalyzer())->analyze($regex);

$shape->groups[1]->values;        // ['GET', 'POST']
$shape->groups[2]->minLength;     // 1
$shape->groups[2]->nonFalsy;      // false: \S+ may read "0"
$shape->matchShape();             // "array{0: non-falsy-string, 1: 'GET'|'POST', 2: non-empty-string}"
```

`matchAllShape()` types what `preg_match_all()` writes, in either order
([`preg_match_all()`](#preg_match_all)), and `matchShape()` also types the array a replace
callback receives ([Replace callbacks](#replace-callbacks)).

An application that already uses the facade gets the same answer in one call. It
parses through the facade's cache, and an invalid pattern throws what `parse()` throws:

```php
use PHPRegex\Toolkit\Regex;

$shape = Regex::create()->captureShape('/(GET|POST) (\S+)/');
```

A static analysis extension needs only `php-regex/regex-parser` (PHP 8.2+ with
`ext-mbstring`): the analyzer and its results live there. The facade, in
`php-regex/regex-toolkit`, is for applications. Until the 2.0.0 tag publishes
the split packages, install through the monorepo — see the
[Quick Start](../quick-start.md).

## What a group record holds

`analyze()` returns a `CaptureShape`: `$whole` for `$matches[0]`, `$groups` with one
`CaptureGroupShape` per group number, `$marks`, the names a `(*MARK)` verb, or a
verb that sets a mark, may leave under the `MARK` key, and `$cases`, described in
[Cases](#cases).

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
| `nonFalsy` | `true` when every value satisfies `(bool) $v`: it is neither `''` nor `'0'` ([Facts](#facts)) |
| `digitsOnly` | `true` when every value satisfies `ctype_digit($v)`: a non-empty run of ASCII `0` to `9` ([Facts](#facts)) |

The lengths, the values and the facts describe the group when it is set. What PHP
writes for an unset group, `''` or `null`, is told by the participation: a `MayBeUnset`
group with `minLength` 1 still reads `''` when a match leaves it unset.

A branch-reset record takes the name any of its branches gives. In `/(?|(x)|(?<a>y))/`,
group 1 is named `a`, and PHP writes `a` on `x` too. PCRE refuses two different names
for one number.

`Participation`:

- `Always`: every match sets the group, as `(\d{4})` in `/^(\d{4})-/`.
- `MayBeUnset`: some matches leave it unset, as `(a)` in `/(a)|b/` or `/(a)?/`. It is also
  the answer when the pattern alone cannot tell.
- `Never`: no match sets it: the group sits in a negative lookaround, which PCRE
  discards, in a `(?(DEFINE)...)` block, or under `{0}`.

### Facts

`nonFalsy` and `digitsOnly` are PHP predicates on every value `$v` the group holds when it is
set:

| fact | `true` when every value satisfies |
|---|---|
| `nonFalsy` | `(bool) $v` |
| `digitsOnly` | `ctype_digit($v)` |

A fact is `true` only when the pattern proves it. `false` means "not proven", not "false
for some value". Both facts are `false` for a `Never` group, which holds no value.

`nonFalsy` holds when the group reads at least two characters, or at least one and never
`'0'` alone. PHP treats only `''` and `'0'` as falsy, so `'00'` is truthy. `digitsOnly`
holds when the group reads at least one character and each character it reads is an ASCII
digit.

```php
$shape = (new CaptureShapeAnalyzer())->analyze(
    RegexParser::create()->parse('/^(\d+)-(\d{2,})-([a-z]+)$/'),
);

$shape->groups[1]->nonFalsy;    // false: the group may hold '0'
$shape->groups[1]->digitsOnly;  // true
$shape->groups[2]->nonFalsy;    // true: two characters at least
$shape->groups[2]->digitsOnly;  // true
$shape->groups[3]->nonFalsy;    // true: no letter is '0'
$shape->groups[3]->digitsOnly;  // false
```

`preg_match('/^(\d+)-(\d{2,})-([a-z]+)$/', '0-00-a', $m)` writes `'0'` in group 1, which
is falsy, and `'00'` in group 2, which is not.

`\d` proves `digitsOnly` only without Unicode properties. Under `/u`, or with `(*UCP)` at
the start of the pattern, `\d` and `[[:digit:]]` follow Unicode properties and may match
the digits of other scripts: `preg_match('/^\d$/u', "\u{0663}")` returns 1, while
`ctype_digit("\u{0663}")` is `false`. `(*UCP)` without UTF mode reads bytes, and there
`\d` still matches `0` to `9` only, but the analysis does not rely on the engine's
tables: wherever Unicode properties are on, `\d` leaves `digitsOnly` false. `[0-9]` and
literal digits always prove it:

```php
$shape = (new CaptureShapeAnalyzer())->analyze(
    RegexParser::create()->parse('/^(\d+)-([0-9]+)$/u'),
);

$shape->groups[1]->digitsOnly;  // false
$shape->groups[2]->digitsOnly;  // true
```

## The shape of `$matches`

`matchShape(int $flags = 0)` writes the array `preg_match()` fills on success as a type
in PHPStan's syntax: array shapes, constant strings, `int<a, b>`, `non-empty-string`,
`non-falsy-string`, `numeric-string`, and a union of shapes when the pattern has
[cases](#cases). The records it is written from, above, are engine-neutral: any other
type system can be fed from them. The [Psalm plugin](../guides/psalm.md) builds its
types from them, key for key the same facts, but `numeric-string`: Psalm gets none,
because Psalm 6.19's type combiner collapses `numeric-string|'a'` to `numeric-string`.

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
// array{0: non-falsy-string,
//       year: non-falsy-string&numeric-string, 1: non-falsy-string&numeric-string,
//       month: non-falsy-string&numeric-string, 2: non-falsy-string&numeric-string,
//       day?: non-falsy-string&numeric-string, 3?: non-falsy-string&numeric-string}

$shape->matchShape(PREG_UNMATCHED_AS_NULL);
// array{0: non-falsy-string,
//       year: non-falsy-string&numeric-string, 1: non-falsy-string&numeric-string,
//       month: non-falsy-string&numeric-string, 2: non-falsy-string&numeric-string,
//       day: (non-falsy-string&numeric-string)|null, 3: (non-falsy-string&numeric-string)|null}
```

A group's value is written, from the most precise to the least:

- as a union of constant strings, when its values are known;
- `''`, when it is always empty;
- `non-falsy-string&numeric-string`, when both facts hold;
- `numeric-string`, when `digitsOnly` holds: a run of digits is a numeric string, never empty;
- `non-falsy-string`, when `nonFalsy` holds;
- `non-empty-string`, when it cannot be empty;
- `string` otherwise.

PHPStan reads an intersection inside a union only in parentheses, so a group that may be
unset is written `(non-falsy-string&numeric-string)|null`.

The keys come in the order PHP writes them: `0`, then for each group its name, when it
has one, before its number, then `MARK`.

The string is PHPRegex's own spelling of the type. PHPStan resolves it into a type, and
prints that type its own way: it writes a shape whose keys run from 0 with no gap as a
list without keys, and it sorts the members of a union. `/(\d+)/` is written
`array{0: numeric-string, 1: numeric-string}`, and PHPStan prints
`array{numeric-string, numeric-string}`. An adapter should resolve the string and rely on
the type it gets, never on the text.

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
// preg_match('/(?<MARK>a)|(*MARK:x)b/', 'b', $m): [0 => 'b', 'MARK' => 'x']
"array{0: 'a', MARK: 'a', 1: 'a'}|array{0: 'b', MARK?: 'x'}"

// preg_match('/(?<MARK>a)?(b)(*MARK:x)/', 'b', $m): [0 => 'b', 'MARK' => 'x', 1 => '', 2 => 'b']
"array{0: 'b'|'ab', MARK: ''|'a'|'x', 1: ''|'a', 2: 'b'}"
```

## Cases

A pattern can match in ways one shape blurs. `/^(?:(\d+)|([a-z]+))$/` sets group 1 or
group 2, never both, and the merged shape loses that link. `CaptureShape::$cases` holds
shapes whose union covers every match, each more precise than the merged view:

```php
$shape = (new CaptureShapeAnalyzer())->analyze(
    RegexParser::create()->parse('/^(?:(\d+)|([a-z]+))$/'),
);

count($shape->cases);  // 2
$shape->matchShape();
// array{0: numeric-string, 1: numeric-string}|array{0: non-falsy-string, 1: '', 2: non-falsy-string}
```

Merged, the same matches read
`array{0: non-empty-string, 1?: ''|numeric-string, 2?: non-falsy-string}`.

- `$cases` is non-empty only when the analyzer splits the pattern. Otherwise it is `[]`,
  and `$whole` and `$groups` are the whole answer.
- `$whole`, `$groups` and `$marks` keep merging every match, cases or not: there, group 1
  above is `MayBeUnset`. In each case it is `Always` or `Never`.
- A case shares no index with the alternatives of the pattern: `$cases[0]` need not
  stand for the first one. Read the cases as a set.

### When the pattern splits

The analyzer splits on one alternation: the one holding a capturing group that the root
reaches through sequences and through groups that neither capture nor repeat (`(?:...)`,
atomic `(?>...)`, option groups such as `(?i:...)`). Each alternative gives one case.
`/(a)|(b)/`, `/^(?:(\d+)|([a-z]+))$/` and `/x(?:(a)|(b))/` split.

- A `?` on that group, or `??`, `?+`, `{0,1}`, adds a case where no alternative is taken,
  so no group of the alternation is set: `/(?:(a)|(b))?c/` gives three cases, the last
  one `array{0: 'c'}`.
- An alternation inside an alternative is not on the path, and stays merged within its
  case: `/(a)|b(?:(c)|(d))/` gives two cases, the second keeping `c` and `d` merged.

Nothing is split when:

- the alternation sits under another quantifier (`*`, `+`, `{2}`), inside a capturing
  group, or inside a lookaround;
- the root reaches a second alternation holding capturing groups, as in
  `/(?:(a)|(b))(?:(c)|(d))/`;
- the root reaches a branch reset `(?|...)` holding capturing groups: its alternatives
  share their numbers;
- the split would give more than 16 cases. Sixteen alternatives split; seventeen do not,
  nor sixteen under `?`.

Each case reads the pattern as PCRE does, options included. An inline option set in one
alternative stays in force in the following ones. In `/a(?i)b|(c)/` the `(?i)` makes the
second alternative match `C` too: `preg_match('/a(?i)b|(c)/', 'C', $m)` writes
`['C', 'C']`. Its case does not claim group 1 holds only `'c'`:

```php
$shape = (new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse('/a(?i)b|(c)/'));

$shape->matchShape();
// array{0: non-falsy-string}|array{0: non-falsy-string, 1: non-falsy-string}
```

Under `(?J)`, a name that groups of different alternatives share holds, in each case, the
group of that case: `/(?<n>a)|(?<n>b)/J` is written
`array{0: 'a', n: 'a', 1: 'a'}|array{0: 'b', n: 'b', 1: '', 2: 'b'}`.

### How the cases are written

`matchShape()` writes the union of the shapes of the cases, each once. PHPStan generalises
a union of array shapes that holds more than 256 value types, nested arrays included, to
a list that loses its keys. When the union would hold more, `matchShape()` writes the
merged shape instead, which says more. PHPStan first merges the shapes that share the
same keys, and generalises only when what is left still holds more than 256; the count
here is taken before any merging, so it is never below PHPStan's. The merged shape may
then be written where PHPStan would have kept the union: still sound, only less
precise. Sixteen one-group alternatives, `/(a)|(b)|...|(p)/`,
are written as a union of sixteen shapes without flags, and as the merged shape under
`PREG_OFFSET_CAPTURE`, where each value is a pair. `$cases` holds the sixteen cases
either way.

## `preg_match_all()`

`matchAllShape(int $flags = PREG_PATTERN_ORDER)` writes the array `preg_match_all()`
fills, in the same syntax. It takes the order, `PREG_PATTERN_ORDER` or `PREG_SET_ORDER`,
with `PREG_OFFSET_CAPTURE` and `PREG_UNMATCHED_AS_NULL`. `0` is read as
`PREG_PATTERN_ORDER`, as PHP reads it.

The shape holds where `preg_match_all()` returns an int, a call that finds no match
included: then `preg_match_all('/(a)(b)?(c)?/', 'x', $m)` writes `[[], [], [], []]`, and
under `PREG_SET_ORDER` it writes `[]`. A list in the shape may therefore be empty. An
adapter that knows the count is positive, after `preg_match_all(...) > 0`, narrows each
list to `non-empty-list` itself.

Where it returns `false`, two cases leave `[]`, outside the shape: an offset past the
subject (`preg_match_all('/(a)/', 'abc', $m, 0, 10)`), and a match that ends before it
starts, `\K` in a lookahead (`preg_match_all('/a(?=b\K)/', 'xab', $m)`, with the warning
"Get subpatterns list failed"). A match error, a subject that is not UTF-8 under `/u` or
an exhausted backtrack limit, returns `false` too but stays within the shape: every key
is written, its list holding the matches found before the error, none for
`preg_match_all('/(a)/u', "\xff", $m)` (`[[], []]`). An adapter types the call only where
neither case can happen: a pattern without `\K` and an offset absent or a constant ≤ 0
(PHP reads a negative offset as 0).

### Pattern order

Under `PREG_PATTERN_ORDER` the shape is one array shape: each key holds a list, with one
value per match. Every group key is written on every call, set or not, so no group key
is optional and none is left out at the end; only `MARK` may be missing. The keys come in the order `matchShape()` writes them:
`0`, then for each group its name before its number. Where a match leaves a group unset,
its list holds `''`, `null` under `PREG_UNMATCHED_AS_NULL`, and the pair `['', -1]` or
`[null, -1]` under `PREG_OFFSET_CAPTURE`:

```php
$shape = (new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse('/(a)(b)?(c)?/'));

// preg_match_all('/(a)(b)?(c)?/', 'a ab', $m): [['a', 'ab'], ['a', 'a'], ['', 'b'], ['', '']]
$shape->matchAllShape();
// array{0: list<'a'|'ac'|'ab'|'abc'>, 1: list<'a'>, 2: list<''|'b'>, 3: list<''|'c'>}

$shape->matchAllShape(PREG_UNMATCHED_AS_NULL);
// array{0: list<'a'|'ac'|'ab'|'abc'>, 1: list<'a'>, 2: list<'b'|null>, 3: list<'c'|null>}

$shape->matchAllShape(PREG_OFFSET_CAPTURE);
// array{0: list<array{'a'|'ac'|'ab'|'abc', int<0, max>}>, 1: list<array{'a', int<0, max>}>,
//       2: list<array{''|'b', int<-1, max>}>, 3: list<array{''|'c', int<-1, max>}>}
```

Each list holds the merged value of its group, facts included; a split pattern is not
written per case here, since one call gathers the matches of every case.

Two keys differ from `preg_match()`:

- A name several groups share under `(?J)` holds the list of the highest-numbered group
  bearing it, set or not, where `preg_match()` gives the highest one set.
  `preg_match_all('/(?J)(?<n>a)(?<n>z)?(c)/', 'ac azc', $m)` writes `n => ['', 'z']`, the
  list of group 2, though group 1 is set on `ac`. The name is typed `list<''|'z'>`.
- The marks a verb leaves sit under `MARK`, keyed by the index of each match that set
  one, and the key is written only when some match did:
  `preg_match_all('/(*MARK:m)(a)|(b)/', 'ba', $m)` writes `'MARK' => [1 => 'm']`, and
  on `b` alone writes no `MARK` key. It is typed `MARK?: array<int, 'm'>`, the last key,
  and a mark stays a plain string under `PREG_OFFSET_CAPTURE`. A group named `MARK`
  keeps its list until a match sets a mark, which then writes the marks over it, at the
  group's place: `/(?<MARK>a)|(*MARK:x)b/` on `a` writes `'MARK' => ['a']`, on `ab`
  writes `'MARK' => [1 => 'x']`, and is typed `MARK: list<''|'a'>|array<int, 'x'>`.

### Set order

Under `PREG_SET_ORDER` each element of the list is what `preg_match()` writes at that
match, trailing unset groups left out unless `PREG_UNMATCHED_AS_NULL`. The shape is
`list<S>`, where `S` is exactly `matchShape()` under the same `PREG_OFFSET_CAPTURE` and
`PREG_UNMATCHED_AS_NULL` flags, the union of the cases and its budget of 256 value types
included:

```php
// preg_match_all('/(a)(b)?(c)?/', 'a ab', $m, PREG_SET_ORDER): [['a', 'a'], ['ab', 'a', 'b']]
$shape->matchAllShape(PREG_SET_ORDER);
// list<array{0: 'a'|'ac'|'ab'|'abc', 1: 'a', 2?: ''|'b', 3?: 'c'}>

$shape->matchAllShape(PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
// list<array{0: 'a'|'ac'|'ab'|'abc', 1: 'a', 2: 'b'|null, 3: 'c'|null}>
```

### Flags

`preg_match_all()` accepts `0`, `PREG_PATTERN_ORDER` (1) or `PREG_SET_ORDER` (2) in the
low byte (`$flags & 0xff`) and throws a `ValueError` for any other value: both orders
together (3), `PREG_SPLIT_OFFSET_CAPTURE` (4), any other bit of that byte.
`matchAllShape()` throws `InvalidRegexOptionException` for the same values. A bit above
the low byte is ignored, as PHP ignores it: `preg_match_all('/(a)/', 'a', $m, 1024)`
writes `[['a'], ['a']]`. `PREG_SPLIT_NO_EMPTY` and `PREG_SPLIT_DELIM_CAPTURE` share the
values 1 and 2, so PHP reads them as the two orders.

## Replace callbacks

The callback of `preg_replace_callback()`, and each callback of
`preg_replace_callback_array()`, receives at each match what `preg_match()` writes there.
Its array is typed `matchShape($flags & (PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL))`
for the `$flags` passed to the replace call, which ignores every other bit:

```php
// preg_replace_callback('/(a)(b)?(c)?/', $callback, 'a'): $callback(['a', 'a'])
$shape->matchShape();
// array{0: 'a'|'ac'|'ab'|'abc', 1: 'a', 2?: ''|'b', 3?: 'c'}

// preg_replace_callback('/(a)(b)?(c)?/', $callback, 'a', -1, $count, PREG_UNMATCHED_AS_NULL):
// $callback(['a', 'a', null, null])
$shape->matchShape(PREG_UNMATCHED_AS_NULL);
// array{0: 'a'|'ac'|'ab'|'abc', 1: 'a', 2: 'b'|null, 3: 'c'|null}
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
- A fact is `true` only when every character the group can read proves it. A part the
  analysis cannot read, as a backreference or a subroutine call, proves no fact.

The facts and the cases are computed from the pattern alone. No character table of the
running engine is read, so the same pattern gives the same answer on every machine, and
`ANALYSIS_VERSION` stays a valid cache key. Without `/u`, the analysis assumes the
character tables of the C locale, which PCRE uses by default. PHP builds other tables
when `setlocale()` sets `LC_CTYPE` to another locale: after
`setlocale(LC_CTYPE, 'fr_FR.ISO8859-1')`, `preg_match('/^\w$/', "\xE9")` returns 1, where
it returns 0 under the C locale.

The facts are checked against the engine: for patterns covering each case above, every
`$matches` PHP writes under each flag combination must follow them, and PHPStan, reading
the written shape, must accept it.
The shapes are also checked against the engine on a parity corpus of a few hundred
`preg_match()` cases, some taken from the php-src PCRE tests: under each flag
combination, every shape holds every `$matches` PHP writes, key by key. The same corpus
is replayed through `preg_match_all()`, in both orders, on subjects with several matches
and with none, and through the replace callbacks.

## What a release may change

`CaptureShapeAnalyzer::ANALYSIS_VERSION` is a string of digits, as
`RedosAnalyzer::ANALYSIS_VERSION` is. It rises in any release that
changes an answer, a fact or either string, the one `matchShape()` writes or the one
`matchAllShape()` writes. Key a cache of shapes on it.

- A minor release may add properties and methods to `CaptureShape` and
  `CaptureGroupShape`. `matchShape()` and `matchAllShape()` grow through new methods or
  trailing optional parameters, never through a new positional boolean.
- A minor release may add a `Participation` case. A new case only refines
  `MayBeUnset`, so a `match` that maps any case it does not know to `MayBeUnset` stays
  sound.
- A minor release may narrow the facts and the string: more precise, still holding every
  `$matches` PHP writes. It may also write the same type differently, such as another
  union order or another cap on the values it lists.
- A patch release may widen an answer to make it sound again.

Either way, a PHPStan or Psalm baseline that prints the type may need regenerating. The
CHANGELOG says when.

## Group numbers

`GroupNumberingCollector` answers a smaller question: which numbers the groups of a
pattern take, as PCRE numbers them. It reads branch resets `(?|...)` and duplicate
names under `J`. It does not read what the groups capture. A tool that checks a
backreference, a subroutine call or a `$matches` key against the pattern needs only
this.

`collect()` takes a parsed pattern and returns a `GroupNumbering`:

- `$maxGroupNumber`: the highest group number. It is the last numeric key of
  `$matches` only when every group is kept: without `PREG_UNMATCHED_AS_NULL`, PHP drops
  the trailing groups that took no part in the match (`preg_match('/(a)|(b)/', 'a', $m)`
  gives the keys `[0, 1]`, where `$maxGroupNumber` is 2).
- `$captureSequence`: the number of each capturing group, in the order they are written.
- `getCaptureCount()`: how many capturing groups are written. Inside a branch reset,
  this can be more than `$maxGroupNumber`.
- `$namedGroups`: each name and the numbers it stands for.
- `hasNamedGroup()` and `getNamedGroupNumbers()`: one name, looked up. An unknown name
  has no numbers.

```php
use PHPRegex\Parser\Analysis\GroupNumberingCollector;
use PHPRegex\Parser\RegexParser;

$regex = RegexParser::create()->parse('/(?<year>\d{4})-(?|(\d\d)|([a-z]{3}))-(?<day>\d\d)/');
$numbering = (new GroupNumberingCollector())->collect($regex);

$numbering->maxGroupNumber;                 // 3
$numbering->captureSequence;                // [1, 2, 2, 3]
$numbering->getCaptureCount();              // 4
$numbering->namedGroups;                    // ['year' => [1], 'day' => [3]]
$numbering->hasNamedGroup('day');           // true
$numbering->getNamedGroupNumbers('day');    // [3]
$numbering->getNamedGroupNumbers('month');  // []
```

The two branches of the branch reset share group 2, as in PCRE: `preg_match()` on
`2026-oct-06` writes `oct` under key `2` and `06` under key `3`. A name used twice under
`J` stands for both groups: in `/(?<n>a)|(?<n>b)/J`, `getNamedGroupNumbers('n')` is
`[1, 2]`.

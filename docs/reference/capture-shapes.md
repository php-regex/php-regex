---
description: "What CaptureShapeAnalyzer reads from a pattern alone: which groups every match sets, their values, lengths and facts, and the $matches type it writes."
---

# Capture Shapes

`CaptureShapeAnalyzer` reads, from the pattern alone, what a successful `preg_match()`
writes into `$matches`: which groups every match sets, which some matches leave unset,
which no match sets, the strings each group can hold, facts such as "never falsy",
"digits only" or "lowercase", and what a match proves of the whole subject. It is built for static
analysers that type `$matches`, and for anyone who wants to know what a pattern
captures before running it.

<div class="page-filter">
  <label class="visually-hidden" for="page-filter-input">Filter the capture shapes reference</label>
  <input type="search" id="page-filter-input" data-unit="sections" placeholder="Filter by pattern shape — e.g. group, named, optional" autocomplete="off" spellcheck="false">
  <span class="page-count"></span>
</div>
<p class="page-empty" role="status" hidden>Nothing matches that filter — try a shorter fragment, or press Escape to see the whole page again.</p>

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

A static analysis extension needs only `php-regex/regex-parser` (PHP 8.1+ with
`ext-mbstring`): the analyzer and its results live there. The facade, in
`php-regex/regex-toolkit`, is for applications. Until the 2.0.0 tag publishes
the split packages, install through the monorepo — see the
[Quick Start](../quick-start.md).

## What a group record holds

`analyze()` returns a `CaptureShape`: `$whole` for `$matches[0]`, `$groups` with one
`CaptureGroupShape` per group number, `$marks`, the names a `(*MARK)` verb, or a
verb that sets a mark, may leave under the `MARK` key, `$cases`, described in
[Cases](#cases), and `$subject`, described in [The subject](#the-subject).

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
| `decimalInt` | `true` when every value satisfies `(string) (int) $v === $v`: `'0'`, or digits without a leading zero after an optional `-` ([Facts](#facts)) |
| `lowercase` | `true` when every value satisfies `strtolower($v) === $v`: it holds no ASCII letter `A` to `Z` ([Facts](#facts)) |
| `uppercase` | `true` when every value satisfies `strtoupper($v) === $v`: it holds no ASCII letter `a` to `z` ([Facts](#facts)) |
| `arms` | the record of each way the group is set, each branch it reads or each group of a branch reset, when joining them loses what they say; empty otherwise (below) |

The lengths, the values and the facts describe the group when it is set. What PHP
writes for an unset group, `''` or `null`, is told by the participation: a `MayBeUnset`
group with `minLength` 1 still reads `''` when a match leaves it unset. A length past
`PHP_INT_MAX`, which the parser takes and PCRE refuses as too large, gives a `minLength`
of `PHP_INT_MAX` and a `maxLength` of `null`.

A group that reads either constants or digits keeps both in `arms`. In `/(a|\d)/`, the
join of the two branches holds no value set and no digit fact, so `values` is `null` and
`digitsOnly` is `false`; `arms` holds one record whose `values` is `['a']` and one whose
`digitsOnly` is `true`. Each arm bears the group's number, name and participation. An arm
also stays when it proves `decimalInt`, `lowercase` or `uppercase` and the join does
not: in `/([a-z]+|[A-Z]+)/` neither case holds for the group, and each holds for one arm.
The list stays empty when the join says it all, as in `/(a|b)/`, `/(\d|\d\d)/` or
`/(a+|b+)/`, and past eight distinct arms.

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

The facts are PHP predicates on every value `$v` the group holds when it is set. Each of
the last three is the one PHPStan reads its accessory type with:

| fact | `true` when every value satisfies |
|---|---|
| `nonFalsy` | `(bool) $v` |
| `digitsOnly` | `ctype_digit($v)` |
| `decimalInt` | `(string) (int) $v === $v`, PHPStan's `decimal-int-string` |
| `lowercase` | `strtolower($v) === $v`, PHPStan's `lowercase-string` |
| `uppercase` | `strtoupper($v) === $v`, PHPStan's `uppercase-string` |

A fact is `true` only when the pattern proves it. `false` means "not proven", not "false
for some value". Every fact is `false` for a `Never` group, which holds no value.

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

`decimalInt` holds where every value is `'0'`, or ASCII digits after an optional `-`, the
first of them not `0`, and no more than 18 of them, which stay below `PHP_INT_MAX` on a
64-bit build (9 on a 32-bit one): `(int)` caps a longer number, so
`'9999999999999999999'` is no decimal integer string. `\d+` does not prove it, since it
may read `'007'`; nor does `-?\d+`, which may read `'-0'`, nor `\+?[1-9]`, which may read
`'+1'`:

```php
$shape = (new CaptureShapeAnalyzer())->analyze(
    RegexParser::create()->parse('/^(\d+)-(0|[1-9]\d{0,17})-(-?[1-9][0-9]{0,8})$/'),
);

$shape->groups[1]->decimalInt;  // false: '007'
$shape->groups[2]->decimalInt;  // true
$shape->groups[3]->decimalInt;  // true
```

`lowercase` and `uppercase` read letters as `strtolower()` and `strtoupper()` do: since
PHP 8.2 they fold the ASCII letters alone, so `'É'` is lowercase and `'é'` uppercase, and
a value without a letter, as `'42'` or `'-'`, is both. A group of digits, white space or
punctuation proves both; `[a-z]`, `[[:lower:]]` and `\p{Ll}` prove `lowercase`. Where the
engine reads caselessly, under `/i`, `(?i)` or `(?i:...)`, a letter reads in either case,
and so does `\p{Ll}` or `[[:lower:]]`. Under `/iu` a code point past ASCII may fold into
an ASCII letter: `preg_match('/^\x{212A}$/iu', 'K')` returns 1 for the KELVIN SIGN, so
there no character past ASCII proves a case. A negated class, a backreference and `.`
prove no case:

```php
$shape = (new CaptureShapeAnalyzer())->analyze(
    RegexParser::create()->parse('/^([a-z]+)-([A-Z]+)-(\d+)-(?i:([a-z]+))$/'),
);

$shape->groups[1]->lowercase;  // true
$shape->groups[2]->uppercase;  // true
$shape->groups[3]->lowercase;  // true, and uppercase too: no letter
$shape->groups[4]->lowercase;  // false: read caselessly, it may hold 'AB'
```

## The subject

`$subject` says what the whole subject holds when `preg_match()` returns 1, for a pattern
that pins both ends: every match starts at the start of the subject, `\A` or `^` without
multiline, and ends at its end, `\z`, or `$` under `/D`, without multiline. It is a
`CaptureGroupShape` numbered 0, read as the whole match is, or `null` when the pattern
does not pin both ends. A start offset never lets `^` or `\A` match, so it holds whatever
the offset; `\G` and `/A` stand at the offset, and pin nothing.

`$` without `/D`, and `\Z`, also match before a final newline:
`preg_match('/^[a-z]+$/', "abc\n")` returns 1. The subject is then what the pattern reads,
or that followed by one newline: `/^(a|b)$/` gives the values `'a'`, `'b'`, `"a\n"` and
`"b\n"`. A newline holds no letter and keeps a value truthy, so `lowercase`, `uppercase`
and `nonFalsy` survive it; `digitsOnly` and `decimalInt` do not. The newline is the one of
the pattern's convention: `"\n"`, unless a leading `(*CR)`, `(*CRLF)`, `(*ANYCRLF)`,
`(*ANY)` or `(*NUL)` says otherwise, the last of them holding.

```php
$shape = (new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse('/^[a-z]+$/'));
$shape->subject->lowercase;   // true, "abc\n" included
$shape->subject->maxLength;   // null

$shape = (new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse('/^(0|[1-9]\d{0,17})$/D'));
$shape->subject->decimalInt;  // true

$shape = (new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse('/^(0|[1-9]\d{0,17})$/'));
$shape->subject->decimalInt;  // false: "7\n" matches
```

`\K` moves the start of the match, not of the subject: `/^a\Kb$/D` writes `'b'` and
proves the subject `'ab'`. `(*ACCEPT)` may end the match before the end anchor, and leaves
`$subject` `null`. Under `(?m)` or `/m`, `^` and `$` stand at any line, and pin nothing.
Each case holds the subject of its own matches: in `/^(?:(\d+)|([a-z]+))$/D` the case
of the digits proves `digitsOnly`, the other one `lowercase`, and the merged subject
proves `lowercase` alone.
## The shape of `$matches`

`matchShape(int $flags = 0)` writes the array `preg_match()` fills on success as a type
in PHPStan's syntax: array shapes, constant strings, `int<a, b>`, `non-empty-string`,
`non-falsy-string`, `numeric-string`, `decimal-int-string`, `lowercase-string`,
`uppercase-string`, and a union of shapes when the pattern has
[cases](#cases). The records it is written from, above, are engine-neutral: any other
type system can be fed from them. The [Psalm plugin](../guides/psalm.md) builds its
types from them, key for key the same facts, but `numeric-string`, `decimal-int-string`
and the letter cases: Psalm gets no `numeric-string`, because Psalm 6.19's type combiner
collapses `numeric-string|'a'` to `numeric-string`, and has no type for the others.

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
//       year: non-falsy-string&numeric-string,
//       1: non-falsy-string&numeric-string,
//       month: ..., 2: ..., day?: ..., 3?: ...}  (each the same as year)

$shape->matchShape(PREG_UNMATCHED_AS_NULL);
// array{0: ..., year: ..., 1: ..., month: ..., 2: ...,
//       day: (non-falsy-string&numeric-string)|null,
//       3: (non-falsy-string&numeric-string)|null}
```

A group's value is written, from the most precise to the least, once per arm when it has
`arms`: `/(a|\d)/` writes group 1 `'a'|numeric-string`.

- as a union of constant strings, when its values are known;
- `''`, when it is always empty;
- `decimal-int-string` when `decimalInt` holds, with `&non-falsy-string` when `nonFalsy`
  holds too: PHPStan reads a decimal integer string as numeric, non-empty and of both
  cases already;
- otherwise as the intersection of the accessory types the facts prove, in the order
  PHPStan prints them: `lowercase-string` for `lowercase`, `non-falsy-string` for
  `nonFalsy` (else `non-empty-string` when it cannot be empty), `numeric-string` for
  `digitsOnly` (a run of digits is a numeric string, never empty), `uppercase-string`
  for `uppercase`. A case is written when it alone holds: a value with no letter, a run
  of digits among them, is both lowercase and uppercase, which says nothing.
  `/^([a-z]+)$/` writes group 1 `lowercase-string&non-falsy-string`, `/(\d+)/` writes it
  `numeric-string`;
- `''|` before that intersection, in parentheses, when `digitsOnly` holds and the group
  may be empty, as `(\d*)`;
- `string` when no fact holds.

PHPStan reads an intersection inside a union only in parentheses, so a group that may be
unset is written `(lowercase-string&non-falsy-string)|null`.

The keys come in the order PHP writes them: `0`, then for each group its name, when it
has one, before its number, then `MARK`.

The string is PHPRegex's own spelling of the type. PHPStan resolves it into a type, and
prints that type its own way: it writes a shape whose keys run from 0 with no gap as a
list without keys, and it sorts the members of a union. `/([a-z]+)/` is written
`array{0: lowercase-string&non-falsy-string, 1: lowercase-string&non-falsy-string}`, and
PHPStan prints `array{lowercase-string&non-falsy-string, lowercase-string&non-falsy-string}`. An adapter should resolve the string and rely on
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
// array{0: numeric-string, 1: numeric-string}
// |array{0: lowercase-string&non-falsy-string, 1: '', 2: lowercase-string&non-falsy-string}
```

Merged, the same matches read
`array{0: lowercase-string&non-empty-string, 1?: ''|numeric-string, 2?: lowercase-string&non-falsy-string}`.

- `$cases` is non-empty only when the analyzer splits the pattern. Otherwise it is `[]`,
  and `$whole` and `$groups` are the whole answer.
- `$whole`, `$groups` and `$marks` keep merging every match, cases or not: there, group 1
  above is `MayBeUnset`. In each case it is `Always` or `Never`.
- A case shares no index with the alternatives of the pattern: `$cases[0]` need not
  stand for the first one. Read the cases as a set.

### When the pattern splits

The analyzer splits on an alternation holding a capturing group that the root
reaches through sequences and through groups that do not repeat (`(?:...)`, atomic
`(?>...)`, option groups such as `(?i:...)`, and capturing groups, set in every case).
Each alternative gives one case. `/(a)|(b)/`, `/^(?:(\d+)|([a-z]+))$/`,
`/x(?:(a)|(b))/` and `/Price: (£|(\d)|x)/i` split: in the last, group 2 is set in the
case of the digit only.

- A `?` on that group, or `??`, `?+`, `{0,1}`, adds a case where no alternative is taken,
  so no group of the alternation is set: `/(?:(a)|(b))?c/` gives three cases, the last
  one `array{0: 'c'}`.
- An alternation inside an alternative is not on the path, and stays merged within its
  case: `/(a)|b(?:(c)|(d))/` gives two cases, the second keeping `c` and `d` merged.

It splits the same way on a conditional group holding a capturing group, reached the
same way: one case for its yes branch, one for its no
branch. The condition runs in both. An assertion keeps what it captured where its body
matched: a positive one in the yes case, a negative one in the no case, as
`preg_match('/(?(?!(a))x|a)/', 'a', $m)` writes `['a', 'a']`. So group 1 below is
written whenever group 2 is set, though its group may be unset:

```php
// preg_match('/(?(?=(a))a|(c))/', 'c', $m): ['c', '', 'c']
$shape = (new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse('/(?(?=(a))a|(c))/'));

$shape->matchShape();
// array{0: 'a', 1: 'a'}|array{0: 'c', 1: '', 2: 'c'}
```

A `?` around the conditional group adds the case where it is skipped, as for an
alternation.

A condition that reads the same on every path to it keeps only the arm the engine runs,
and is not split: the other arm sets no group and adds nothing to the whole match.
`(?(1)...)` holds where every path sets group 1 before it, and fails where no path does:
before the group, inside it until it closes, or after an `(*ACCEPT)` that always ends
the match first. A back reference that matched proves its group set, since a reference
to an unset group never matches: `preg_match('/(a)?b\1/', 'b')` returns 0, so group 1 of
`/(a)?b\1/` is `Always`. `(?(R)...)`, `(?(R1)...)` and `(?(R&name)...)` fail where
nothing calls a group or the pattern. A repeat that runs again, a call anywhere in the
pattern, or a name several groups share keeps both arms:

```php
// preg_match('/(a)(?(1)b|(c))/', 'ab', $m): ['ab', 'a']; on 'ac' it returns 0
$shape = (new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse('/(a)(?(1)b|(c))/'));

$shape->matchShape();
// array{0: 'ab', 1: 'a'}
```

`(?(VERSION>=10.0)...)` keeps both arms: the analyzer is not told which PCRE2 release
the pattern runs on.

And it splits on an optional group (`?`, `??`, `?+`, `{0,1}`) holding two capturing
groups or more, itself included, reached the same way, that holds no alternation or
conditional group the analyzer splits on: one case
where it runs and sets its groups together, one where it is skipped and sets none.
Checking that the outer group is set then tells that the groups it holds are set too:

```php
$shape = (new CaptureShapeAnalyzer())->analyze(
    RegexParser::create()->parse('/^([A-Z]{1,3})([0-9]{1,7})(:([A-Z]{1,3})([0-9]{1,7}))?$/'),
);

$shape->matchShape();
// array{0: U, 1: U, 2: D, 3: U, 4: U, 5: D}|array{0: U, 1: U, 2: D}
// where U is non-falsy-string&uppercase-string and D is numeric-string
```

Merged, the same matches read
`array{0: U, 1: U, 2: D, 3?: ''|(U), 4?: ''|(U), 5?: D}`,
where `isset($m[3])` says nothing of `$m[5]`. An optional group holding one capturing
group, `/(a)?/` or `/(?:x(a))?/`, says as much merged, and is not split.

Each node the root reaches this way splits, and the cases multiply: one case per way of
taking a branch of each. `/(?:(a)(b))?(?:(c)(d))?/` gives four cases, one per pair of
groups run or skipped, `/(?:(a)|(b))(?:(c)(d))?/` four, and
`/(?:(a)|(b))(?:(c)|(d))(?:(e)(f))?/` eight:

```php
$shape = (new CaptureShapeAnalyzer())->analyze(RegexParser::create()->parse('/(?:(a)(b))?(?:(c)(d))?/'));

$shape->matchShape();
// array{0: 'abcd', 1: 'a', 2: 'b', 3: 'c', 4: 'd'}|array{0: 'ab', 1: 'a', 2: 'b'}
// |array{0: 'cd', 1: '', 2: '', 3: 'c', 4: 'd'}|array{0: ''}
```

The cases stay within 16. The alternations count first, then the conditional groups,
then the optional groups, each in pattern order, and a node whose cases would take the
product past 16 stays merged within each case: in
`/(?:(a)|(b)|(c)|(d))(?:(e)|(f)|(g)|(h))(?:(i)|(j))/` the first two alternations give 16
cases, and the third stays merged.

Nothing is split when:

- the alternation, the conditional group or the optional group sits under another
  quantifier (`*`, `+`, `{2}`), inside a capturing group, or inside a lookaround;
- two alternations or conditional groups share one `?`, as in
  `/(?:(?:(a)|(b))(?:(c)|(d)))?/`: neither splits;
- the root reaches a branch reset `(?|...)` holding capturing groups: its alternatives
  share their numbers;
- the split would give more than 16 cases. Sixteen alternatives split; seventeen do not,
  nor sixteen under `?`, and the analyzer moves on to the next node.

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

- Values come from literals only, through alternations and bounded repeats: `(a{1,3})`
  holds `'a'`, `'aa'` or `'aaa'`, up to 32 strings. Where caseless matching holds, a
  letter that may fold leaves them unknown: under `/i`, from `(?i)` to the end of its
  group, or inside `(?i:...)`. In `/(?i:a)(b)/` group 1 still holds `'b'`.
- After `\K` the whole match holds the text from the last `\K` run to the end, and the
  whole span where no `\K` ran: `/a\K(?:b|c\Kd)/` writes `'b'` or `'d'`, `/a(?:\K|b)c/`
  writes `'c'` or `'abc'`. Backtracking past a `\K` takes its reset back:
  `preg_match('/(?:a\Kb|a)c/', 'ac', $m)` writes `'ac'`. A `\K` in a lookaround, in a
  condition or in a group a call runs leaves the whole match its presence alone.
- `(*ACCEPT)` cuts a match short where the match runs it: the whole match then keeps only
  its presence, a group it leaves open is unbounded from zero with unknown values, and a
  group after it may be unset. A group before it, or in a branch it does not sit in, keeps
  what it reads: `/(a)|b(*ACCEPT)(c)/` writes group 1 `'a'` and group 2 `'c'` where set.
  One in a lookaround ends the assertion, and one in a group a call runs ends the call:
  `preg_match('/(?(DEFINE)(?<t>a(*ACCEPT)bb))x((?&t)c)/', 'xac', $m)` writes group 2
  `'ac'`, and the whole match stays known. A group open around one in a lookaround is
  closed, and the assertion keeps it: `preg_match('/(?=(a(*ACCEPT)))(?(1)(a)|b)/', 'a', $m)`
  writes `['a', 'a', 'a']`. A value such a cut leaves may be shorter than what follows the
  verb would make it: `preg_match('/(?=(0(*ACCEPT)1))/', '0', $m)` writes group 1 `'0'`,
  which is falsy. One in `(?(DEFINE)...)`, under `{0}` or in a negative lookaround ends
  nothing the match sees.
- A subroutine call reads what the group it calls reads, with the options of that
  group: in `/(?<t>a)&((?&t))/` group 2 holds `'a'`. Its length is the length of that
  group, wherever the call stands: in `/(?<t>\w\w)x((?&t)c)/` group 2 is three
  characters long. A call of the group that holds it, or of the whole pattern, and a
  backreference, have unknown values, and a length unbounded from zero.
- A call that returns groups, `(?1(2))` in PCRE2 10.47, sets them as the group it runs
  does: in `/(?(DEFINE)((Sat)urday))(?1(2)),\2/` group 2 holds `'Sat'`, and is `MayBeUnset`.
- A substring scan, `(*scan_substring:...)` or `(*scs:...)` in PCRE2 10.45, reads no
  character of the subject, as a lookahead does.
- A key that every match writes, but only through different groups in different
  branches, is written optional.
- A fact is `true` only when every character the group can read proves it. A part the
  analysis cannot read, as a backreference or a recursive call, proves no fact.
- `decimalInt` counts on a 64-bit build: up to 18 digits, 9 on a 32-bit one. It reads the
  digits as `digitsOnly` does, so `\d` under `/u` or `(*UCP)` proves no decimal integer.
- `$subject` assumes the newline PHP's bundled PCRE2 uses when no verb sets one, `"\n"`.

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

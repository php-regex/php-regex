---
description: "The PHPRegex PHPUnit assertions: assert what a pattern captures with exact capture semantics, that the running engine compiles it, and that it is proven linear — with group names validated against the pattern itself."
---
# PHPUnit

`php-regex/regex-phpunit` brings four assertions to your test suite, backed
by the parser: `assertPatternMatches()` runs one guarded match attempt and
judges a capture expectation, `assertPatternDoesNotMatch()` asserts no
match, `assertPatternCompiles()` asks the running engine, and
`assertPatternIsLinear()` asks the ReDoS analysis for a proof. The
expectation's group keys are validated against the pattern's own groups
before any value is compared, so a typo'd name is a failure that names the
pattern's real groups — never a silent pass.

{% include install.html package="php-regex/regex-phpunit" dev=true %}

The assertions run on PHP 8.1 or later and PHPUnit 9.6 to 13 (10 on PHP 8.1,
11 on 8.2, 12 on 8.3, 13 on 8.4). Add the trait to your test case:

```php
use PHPRegex\PHPUnit\RegexAssertions;

final class SlugTest extends TestCase
{
    use RegexAssertions;

    public function test_a_slug_matches(): void
    {
        self::assertPatternMatches('/^[a-z0-9-]{3,}$/', 'php-regex');
        self::assertPatternDoesNotMatch('/^[a-z0-9-]{3,}$/', 'PHP Regex!');
    }
}
```

The methods are static, so the trait also works in a bare `Assert` subclass
— arrange-only helpers included. The four constraints behind the
assertions (`MatchesPattern`, `DoesNotMatchPattern`, `CompilesPattern`,
`IsLinearPattern`) compose with `assertThat()` and boolean composition.
Assert no match with `DoesNotMatchPattern` rather than `LogicalNot` around
`MatchesPattern`: the negation reads a pattern that does not parse, or an
engine refusal, as "not a match", where `DoesNotMatchPattern` fails with the
library's or the engine's words.

## The capture expectation

`assertPatternMatches()` takes an expectation keyed by group number or
name. An empty expectation asks only whether the pattern matched.

| expectation | asks |
|-------------|------|
| `true` | the group participated, any value |
| `false` | the group did not participate |
| `'value'` | exactly that captured string |
| `['value', byteOffset]` | that string, at that byte offset |

```php
self::assertPatternMatches('/(?<alpha>a+)(?<beta>b+)?/', 'aa', [
    'alpha' => 'aa',          // exactly 'aa'
    'beta' => false,          // present in the pattern, did not participate
]);

self::assertPatternMatches('/(\d{4})-(\d{2})-(\d{2})/', '2026-10-17', [
    1 => '2026',
    2 => ['10', 5],           // '10', starting at byte 5
]);
```

When only the captures differ, the failure renders the expectation against
the engine's answer, both in the expectation's own spelling — here under
PHPUnit 11; the wording of the surrounding frame moves with the PHPUnit
release, the diff lines do not:

```text
Failed asserting that '2026-10-17' matches the pattern '/(\\d{4})-(\\d{2})/'.
--- Expected
+++ Actual
@@ @@
-[1 => '2026', 2 => '01']
+[1 => '2026', 2 => '10']
```

### Offsets are bytes

Offsets are byte offsets everywhere, `PREG_OFFSET_CAPTURE` semantics, under
`/u` as everywhere else — `é` is two bytes:

```php
self::assertPatternMatches('/é(l+)/u', 'héllo', [1 => ['ll', 3]]);
```

### One match attempt

Each `assertPatternMatches()` call runs one `preg_match()` attempt, never
`preg_match_all()`: repeated captures are not collected, and a group that
could match twice is judged on the attempt the engine made — the leftmost
one.

## Validation failures

Every expectation key is resolved against the pattern's own groups before
any value is compared. An unknown name or number fails listing what the
pattern really has:

```text
Failed asserting that 'ab' matches the pattern '/(?<alpha>a)(?<beta>b)/'.
the pattern has no group named 'alpa'; its groups are 0 (the whole match), 1 'alpha', 2 'beta'.
```

A name that owns several group numbers — a `(?J)` pattern, where the engine
would fill the last participating duplicate — fails rather than trusting
one input:

```text
the group name 'dup' owns several group numbers (1, 2); assert by number instead.
```

Group 0 is the whole match: it participates whenever the pattern matches,
so `0 => false` is a contradiction and fails as one. A shape the vocabulary
does not spell fails naming what it expected. Under the `n` modifier the
engine keeps only the NAMED groups capturing, renumbered from 1 — in
`/(a)(?<x>b)/n` the group `x` is group 1 — so a name, or the number the
renumbering gave it, asserts as usual, and a number with no group behind
it fails saying what the modifier did:

```text
the pattern has no group 2 under the n modifier; only named groups capture under it, renumbered from 1. Its groups are 0 (the whole match), 1 'x'.
```

A pattern that does not parse fails with the library's message, byte
position and caret snippet; `preg_match()` answering `false` — invalid
UTF-8 under `/u`, a backtrack limit — fails with `preg_last_error_msg()`,
never as "no match".

## Compilation and linearity

```php
self::assertPatternCompiles('/^\d{4}-\d{2}-\d{2}$/');
self::assertPatternIsLinear('/^[a-z0-9-]{3,}$/');
```

`assertPatternCompiles()` leaves the truth to the engine alone: a pattern
beyond the library's own parse limits still passes here when the engine
takes it — while `assertPatternMatches()`, which parses to validate the
expectation, fails with the limit message. The asymmetry is deliberate:
each assertion asks its own question.

`assertPatternIsLinear()` fails unless one match attempt is proven linear
and the unanchored search carries no quadratic-cost witness — a pattern
that is safe per attempt but quadratic to search for, as `/\s+$/` on a run
of spaces, fails too. The failure's first line is a stable prefix, so a
quarantine can tell the two apart:

| first line | verdict |
|------------|---------|
| `proven unsafe:` | an unsafe attempt (with the witness input), or a quadratic unanchored search |
| `could not analyze:` | the analysis budget was exceeded, or the pattern sits outside the analyzer's model |

```text
proven unsafe: Exponential backtracking (proven). A witness input is "a" x n . "!".
```

What each verdict is built on, and what a witness means, is the
[ReDoS guide](redos.md)'s subject.

## Cost

The assertions are dev-time tooling, not a runtime dependency: per
assertion, one parse (served from an in-memory cache of 1024 patterns, per
process — per worker under paratest) and one guarded match attempt.
`assertPatternIsLinear()` adds one bounded analysis — a state and step
budget, never wall-clock — memoized per pattern for the process; the
analysis is deterministic per release, so the memoization cannot be
observed, only a budget spent twice saved.

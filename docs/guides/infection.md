---
description: "Infection mutators for the regex literals of preg_*() calls: no mutant the automata prove equivalent to the original, and each mutant with the subject that kills it, ready to paste into a test."
---
# Infection

`php-regex/regex-infection` adds six mutators to
[Infection](https://infection.github.io), the mutation testing framework for
PHP. They mutate the regex literals your code passes to `preg_*()`: a `+`
turns into `*`, an anchor goes, a class loses a member, a branch of an
alternation goes. Infection then runs your tests against each mutant; a mutant
no test fails on is a regex behaviour your suite does not check.

Two things set these mutators apart from mutating the pattern as text:

- **A mutant the automata prove equivalent is never produced.** Removing `i`
  from `/^\d+$/i` changes nothing — there is no letter to match — so no test
  could ever kill that mutant. The automata of
  [`regex-automata`](../packages.md) prove it, and the mutant is dropped before
  Infection spends a run of your suite on it.
- **Each mutant that survives says what kills it.** The subject on which the
  call behaves differently with the mutant is written into the mutated
  pattern, as a comment the engine skips.

{% include install.html package="php-regex/regex-infection" dev=true %}

The mutators work with Infection 0.29 or later, installed through Composer
or as its PHAR, and need nikic/php-parser 5. Infection 0.28 and earlier carry
a mutator interface of their own, which would meet the one the package
installs: Composer refuses them beside it.

## Enable the mutators

Infection loads a custom mutator by its class name. Add the six to
`infection.json5`, and turn off Infection's own regex mutators, which they
replace:

```json5
{
    "mutators": {
        "@default": true,
        "PregMatchRemoveCaret": false,
        "PregMatchRemoveDollar": false,
        "PregMatchRemoveFlags": false,
        "PHPRegex\\Infection\\Mutator\\RegexQuantifier": true,
        "PHPRegex\\Infection\\Mutator\\RegexAnchor": true,
        "PHPRegex\\Infection\\Mutator\\RegexCharClass": true,
        "PHPRegex\\Infection\\Mutator\\RegexAlternation": true,
        "PHPRegex\\Infection\\Mutator\\RegexFlag": true,
        "PHPRegex\\Infection\\Mutator\\RegexEscape": true
    }
}
```

`PregMatchRemoveFlags` removes each modifier whether the pattern uses it or
not, which leaves mutants no test can kill; `PregMatchRemoveCaret` and
`PregMatchRemoveDollar` write mutants `RegexAnchor` writes too, without the
kill string. `PregMatchMatches` and `PregQuote` mutate the call rather than the
pattern: keep them.

The mutators take no settings. Infection's own do apply: a mutator can be
turned off, and `ignore` and `ignoreSourceCodeByRegex` narrow where it runs.

## What each mutator does

| mutator | mutations |
|---|---|
| `RegexQuantifier` | `+` and `*` swap; each bound of `{m,n}`, `{m,}` or `{n}` moves by one; the repeat goes; a greedy repeat turns lazy, a lazy or possessive one greedy |
| `RegexAnchor` | `^`, `$`, `\A`, `\z`, `\Z`, `\b` and `\B` go; `$` and `\z` swap |
| `RegexCharClass` | a class is negated; a class of two members or more loses one; a range narrows by one character at either end; `\d`, `\w` and `\s` turn into `\D`, `\W` and `\S`, and back |
| `RegexAlternation` | each branch goes; neighbouring branches swap, which matters where PCRE takes the first branch that leads to a match |
| `RegexFlag` | each trailing modifier goes, `u` aside |
| `RegexEscape` | an escaped `.`, `$`, `^`, `|`, `?`, `*`, `+` or `{` outside a class loses its backslash |

Within a mutator, mutants come in the order of the pattern, left to right,
then in the order of the table.

## The kill string

An escaped mutant reads:

```diff
- if (1 === preg_match('/^\d+$/', $id)) {
+ if (1 === preg_match('/^\d+\z(?#kill: "0\x0a")/', $id)) {
```

`"0\x0a"` is `"0\n"`: `$` also matches before a final newline, `\z` does not.
A test that passes it, and checks the answer, kills the mutant:

```php
public function test_an_id_with_a_trailing_newline_is_refused(): void
{
    $this->assertFalse(Id::isValid("0\x0a"));
}
```

The contract:

- **What it is.** A subject on which the `preg_*()` call, as written, answers
  differently with the mutant: the same function, offset, flags, limit and
  replacement. It was replayed on the running engine before it was written.
  It is what reaches the call, not what reaches your function: a function
  that trims its input first needs a test input the trim leaves as it is.
- **How it is written.** A PHP double-quoted literal, pasteable as is: `$`,
  `\`, `"`, the parentheses, the braces, `#` and the pattern's delimiters
  are written `\xNN`, as is every byte outside printable ASCII.
- **Where.** Just before the closing delimiter, as `(?#kill: "…")`, a comment
  the engine skips. Under the `#` delimiter, which `(?#` would end, it is
  `(?(DEFINE)kill: "…")`, a group that never runs, every byte but letters,
  digits and spaces written `\xNN`. Under `/x`, when the pattern ends in a
  comment, a newline comes first so the hint is not part of that comment.
- **`preg_match_all()` without `$matches`.** The subject on which the count
  differs: the call answers nothing else.
- **`preg_replace_callback()`.** The subject on which the callback receives
  other matches, as the call's own flags shape them, or is called at other
  places of the subject.
- **`preg_grep()`.** The element one pattern keeps and the other does not.

A mutant carries no kill string when the literal would be longer than 120
bytes, quotes aside, each `\xNN` counting four; when the call's offset, flags, limit or replacement is only known at
run time; when the delimiter is `?`, `:` or `"`, or the pattern ends inside
an open `\Q`, where no comment can be written safely; and when neither the
automata nor the subjects drawn from the two patterns tell them apart — a
pattern with a lookaround or a backreference, for instance, which the
automata do not read; and once the checks a pattern gets are spent (see
below).

The automata find the shortest subject; a byte outside printable ASCII in
it is written as `!`, `a`, `0` or a space instead wherever the replayed call
still tells the two patterns apart, so `"v0!0"` rather than `"v0\x000"`. A
byte that matters, the newline that tells `$` from `\z`, stays.

## What is dropped

A mutant is not produced when:

- the running engine refuses it — `\*a` unescaped is `*a`, which repeats
  nothing;
- the automata prove it behaves as the original for the call. For
  `preg_match()` given only a pattern and a subject, and for `preg_grep()`,
  that is whether a match exists: `/a+/` and `/a/` are alike there. For
  every other call, it is the matches in every run the call makes —
  from the start, from past it, and the retry after an empty match: `/a+/`
  and `/a/` differ on `"aa"` there;
- the automata prove it behaves as a mutant of the same part of the pattern
  already produced: `/^a+a*$/` gives `/^a+a+$/` and `/^a+a$/` from its `*`,
  which match the same strings;
- the ReDoS analysis proves its backtracking exponential where the
  original's is not. Infection would wait out its timeout on such a mutant
  and count it as detected, though no test caught anything. A polynomial
  rating drops nothing: on the short subjects a test passes, such a mutant
  answers at once.

Only a proof drops a mutant: a pattern outside what the automata read, or a
check that reaches its cap, leaves the mutant in. The equivalence is the
pattern's, not the program's: a mutant that no input your code can produce
would expose still runs, and the proof does not model the engine's resource
limits (`pcre.backtrack_limit`, the JIT stack).

## What is read

A string literal written as the pattern argument of a native `preg_match()`,
`preg_match_all()`, `preg_replace()`, `preg_replace_callback()`,
`preg_split()`, `preg_grep()` or `preg_filter()` call — by position or as
`pattern:` — inside a function or a method. Infection mutates nothing outside
them, so a pattern in a property default or a constant declaration is never
mutated.

Not read:

- a pattern in a constant, a variable, a concatenation or an array;
- `Safe\preg_match()` and the other wrappers, `Composer\Pcre\Preg`, Laravel's
  `regex:` rule, Symfony's `#[Assert\Regex]`, `#[RegexPattern]` parameters;
- a pattern PHPRegex cannot read or the running engine refuses. It is
  skipped without a word, since a mutator has no way to report: run
  [`regex lint`](cli.md) to see which.

The function must be the global one: a call that resolves to another
namespace's function through `use function` or a qualified name is not
mutated.

Infection keys mutators by their short name: another package's mutator named
`RegexFlag`, say, would replace this one.

## Performance and determinism

The mutators run in Infection's main process, once per literal and mutator,
while Infection writes its mutants; they never run during your tests.

Each question to the automata is capped at 1,000 states and 200,000
transitions, and a pattern gets 96 units per mutator: a check costs one unit,
and one more per 256 bytes of the patterns it reads, since a question about a
long pattern takes longer to answer or to reach its cap. Once the units are
spent, the remaining mutants are produced unproven and without a kill string.
There is no clock in any of it: the same code gets the same mutants, in the
same order, on every machine and under any load.

The mutants are handed to Infection one at a time, each built and judged when
Infection asks for it, so a literal of thousands of branches mutates within
PHP's default memory limit.

Measured on 400 regex literals taken from the `preg_match()` calls of open
source projects, each mutated by the six mutators inside five kinds of call,
on PHP 8.4:

| per literal and mutator | time |
|---|---|
| median | under a millisecond |
| 95th percentile | about 100 ms |
| 99th percentile | about 400 ms |
| slowest | about 7 s |

Most of the time goes to a few long literals with many classes. On the same
literals, the mutants that carry a kill string are about 95% for a
`preg_match()` given only a pattern and a subject, about 86% for one that
writes `$matches`, and about 85% for `preg_replace()`, `preg_split()` and
`preg_match_all()`; the subjects drawn when the automata found none add one
to four points of that.

## Stability

Infection identifies a mutant by its mutator and its rank among that
mutator's mutants, so the mutants a release produces are part of what it
promises. A patch release keeps every mutator's operators and their order;
new operators, new mutators and deliberate changes to what is dropped come
in a minor release, listed in the package's `CHANGELOG.md`.
A fix in the shared analysis that corrects a wrong verdict may ship in a
patch, listed there as a behavioural fix. The text of a kill string may
change in any release.

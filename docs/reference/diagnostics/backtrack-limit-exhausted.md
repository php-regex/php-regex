---
description: "preg_match() returned false and preg_last_error_msg() says backtrack limit exhausted: the pattern backtracked past its limit. How to find the loop, and fix it."
head_title: "Backtrack limit exhausted in preg_match"
parent: /reference/diagnostics/
engine_family: "The match"
engine_order: 1
engine:
  message: "Backtrack limit exhausted"
  from: "preg_last_error_msg"
  pattern: "/^(a+)+$/"
  subject: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa!"
---
# Backtrack limit exhausted

PHP stopped the match: the pattern tried too many ways, and `preg_match()` returned `false`.

```php
var_dump(preg_match('/^(a+)+$/', str_repeat('a', 30) . '!'));
// bool(false)
echo preg_last_error_msg();
// Backtrack limit exhausted
```

The subject holds thirty `a` and a `!`. The `$` cannot match after an `a`, so the engine gives characters back and tries every way of splitting the run between `a+` and `(a+)+`. The ways double with each `a`. No warning is printed: the call returns `false`, where `0` means no match.

## Why PHP prints it

Each match has a budget, `pcre.backtrack_limit`, one million by default. The pattern spends it, and the engine gives up on the whole call.

**Nested quantifiers.** A quantified group holds a quantifier, and both read the same characters. A list validator, on nineteen `a` and a `!`:

```php
var_dump(preg_match('/^(?:\w+,?)+$/', str_repeat('a', 19) . '!'));
// bool(false)
echo preg_last_error_msg();
// Backtrack limit exhausted
```

**Overlapping alternatives.** Two branches take the same text, as `a` and `aa`:

```php
var_dump(preg_match('/^(?:a|aa)+$/', str_repeat('a', 30) . '!'));
// bool(false)
echo preg_last_error_msg();
// Backtrack limit exhausted
```

**A limit set too low.** A pattern with nothing wrong fails when the budget is small:

```php
ini_set('pcre.backtrack_limit', '100');
var_dump(preg_match('/^\w+(?:\s\w+)*$/', trim(str_repeat('word ', 200))));
// bool(false)
echo preg_last_error_msg();
// Backtrack limit exhausted
```

## How to fix it

Find the part of the pattern that reads one text in two ways, and leave it one way.

```php
// One quantifier: nothing to split
var_dump(preg_match('/^a+$/', str_repeat('a', 30) . '!'));
// int(0)

// An atomic group never gives back what it matched
var_dump(preg_match('/^(?>a+)+$/', str_repeat('a', 30) . '!'));
// int(0)

// A possessive quantifier keeps what it took
var_dump(preg_match('/^(a++)+$/', str_repeat('a', 30) . '!'));
// int(0)
```

The two last rewrites can change what a pattern matches: `/(a|ab)c/` matches `abc`, and its atomic group keeps `a` without trying `ab`:

```php
var_dump(preg_match('/(a|ab)c/', 'abc'), preg_match('/(?>a|ab)c/', 'abc'));
// int(1)
// int(0)
```

Raising the limit moves the wall: at `10000000` the example takes 22 `a` and fails from 23.

## Where it hides

- **A check that reads the result as a boolean.** `if (!preg_match($pattern, $subject))` takes `false` for no match: the input is refused, and nothing tells the two apart.
- **A validator of words and spaces.** `/^(\w+\s?)+$/` passes five words and fails on `"word word word word word word !"`: the word characters can be split between the turns of the group in many ways. `/^\w+(?:\s\w+)*$/` matches the same words, and no longer a trailing space.
- **Input only production sends.** The pattern is the one the tests pass. The subject is long, made of one kind of character, and ends with one that breaks the match.
- **A limit lowered on purpose.** `pcre.backtrack_limit` is reduced in `php.ini` or by `ini_set()`: `ini_get('pcre.backtrack_limit')` says the value, and a long honest input fails.

## What PHPRegex reports

PHPRegex refuses nothing here: the pattern is valid. Its ReDoS analysis names the loop:

- `regex.lint.redos` for `/^(a+)+$/`, which `regex lint --redos` reports: "Exponential backtracking (proven). Severity: CRITICAL, confidence: MEDIUM." The hint carries the attack, `Attack: "a" x n . "!"`, to read as `str_repeat("a", $n) . "!"`. Confirmed mode replays it on the running PCRE2, without the JIT, and adds "Replayed on PCRE2 10.49: preg_match fails from length 17 (backtrack_limit 100000, JIT off)." The [ReDoS guide](../../guides/redos.md) has the verdicts and the replay.

The linter also warns `regex.lint.quantifier.nested`: "Nested quantifiers in capturing group 1 "(a+)" can cause catastrophic backtracking."

[Open `/^(a+)+$/` in the playground](/#pattern=%2F%5E%28a%2B%29%2B%24%2F&subject=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaa%21): the ReDoS tab holds the verdict and the string that triggers it.

## Related

- [ReDoS Guide](../../guides/redos.md)—the verdicts, the attack string and confirmed mode.
- [Chapter 8: Performance & ReDoS](../../tutorial/08-performance-redos.md)—nested quantifiers and their safe rewrites.
- [Nested Quantifiers (ReDoS Risk)](../rules/nested-quantifiers-redos-risk.md)—the warning the linter gives on `(a+)+`.
- [Malformed UTF-8 characters, possibly incorrectly encoded](malformed-utf-8-characters.md)—another `false` that a match returns.

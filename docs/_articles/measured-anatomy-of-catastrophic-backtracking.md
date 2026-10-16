---
date: 2026-10-16 14:30:00 +00:00
author: younes
description: "One nested-quantifier pattern timed at input lengths 18 to 26 on PHP 8.4 and PCRE2 10.49 — the exponential curve, what the default limit turns it into, the factor of seven the JIT buys, and the rewrites that are provably safe."
---
# The measured anatomy of catastrophic backtracking

Take `/^(\w+)+$/` — the pattern every ReDoS talk opens with — and feed it `str_repeat('a', $n) . '!'`. Every input fails to match, and each length fails slower, on purpose. This probe does one run per number:

```php
<?php

echo PHP_VERSION, ' / PCRE2 ', PCRE_VERSION, ' / jit ', ini_get('pcre.jit'), "\n";

// argv: the length, then the backtrack limit (default: PHP's 1000000).
$n = (int) ($argv[1] ?? 26);
ini_set('pcre.backtrack_limit', (string) ($argv[2] ?? 1000000));

$subject = str_repeat('a', $n) . '!';
$t = hrtime(true);
$r = preg_match('/^(\w+)+$/', $subject);
printf("n=%d  %10.2f ms  ret=%s  %s\n", $n, (hrtime(true) - $t) / 1e6, var_export($r, true), preg_last_error_msg());
```
{: data-file="splits.php" data-copy="" }

```console
$ php -d pcre.jit=0 splits.php 26 2147483647
8.4.26 / PCRE2 10.49 2026-09-28 / jit 0
n=26     2139.44 ms  ret=0  No error
```
{: data-copy="" }

Timed that way on PHP 8.4.26 with PCRE2 10.49 — externally linked; a stock PHP 8.4 bundles 10.44 — with `pcre.jit=0` and the backtrack limit raised to 2147483647:

| n | Time |
|---|---|
| 18 | 8.31 ms |
| 20 | 33.90 ms |
| 22 | 140.26 ms |
| 24 | 539.90 ms |
| 26 | 2 139.44 ms |

Each additional character doubles the time — the signature of an exponential, not of a slow regex. Every number below comes from a probe printed next to it, one process per run.

## What the engine is doing sixteen million times a second

The pattern's language is innocent — "one or more word characters, and nothing else." The cost is in how many ways there are to produce it. The inner `\w+` swallows some prefix of the `a` run, the outer `+` asks for another round, and every way of cutting a run into consecutive pieces is a different path through the pattern. A run of n letters has 2^(n−1) such cuts:

| n | 1 | 2 | 3 | 4 | 22 | 26 |
|---|---|---|---|---|---|---|
| cuts | 1 | 2 | 4 | 8 | 2 097 152 | 33 554 432 |

At n = 26 that is 33 554 432 splits, and the trailing `!` makes every single one of them fail. The timing table agrees with the arithmetic: 140.26 ms for 2 097 152 splits at n = 22, 2 139.44 ms for 33 554 432 at n = 26 — fifteen to sixteen million splits per second. The engine is not misbehaving; it is exhaustively searching a space that doubles with each character, at a constant rate. Which is also why a *matching* input is cheap — measured below, the same n = 26 run without the `!` returns in 0.002 ms.

## What the default settings turn it into

The curve above needed `pcre.backtrack_limit` raised; the production default is 1 000 000. With it, the same probe stops early — and this is where the failure stops being slow and starts being quiet:

```console
$ php -d pcre.jit=0 splits.php 30
8.4.26 / PCRE2 10.49 2026-09-28 / jit 0
n=30       12.88 ms  ret=false  Backtrack limit exhausted
```
{: data-copy="" }

Twelve milliseconds, not twenty-seven seconds — but look at the return. `preg_match()` returns `false`, not `0`. The engine is not saying "no match"; it is saying "I gave up":

```php
<?php
// The same limit, asked directly.
var_dump(preg_match('/^(\w+)+$/', str_repeat('a', 30) . '!'), preg_last_error_msg());
```
{: data-copy="" }

```text
bool(false)
string(25) "Backtrack limit exhausted"
```

The distinction is exactly one that `if (preg_match(...))` throws away. And the cap deserves precision: the limit counts backtracks, not time — thirteen milliseconds is what one attempt of this pattern costs without the JIT on this machine, two with it, and a pattern whose work is polynomial rather than exponential burns seconds without ever tripping it (the guide's `/a*a*$/` does exactly that).

> **Warning:** within its reach, the trade is this: the limit converts the attack into an answer production code reads as *no match*. A validation gate built on this pattern does not hang — it silently rejects whatever tripped the limit.

## The JIT buys a factor of seven, not a new curve

The PCRE2 JIT compiles a pattern to machine code instead of walking it in the interpreter: a constant-factor win, never a complexity change.

> **Note:** one measurement trap shaped every number in this section: an `ini_set('pcre.jit', '1')` that arrives after the pattern has already run interpreted does not recompile it — the process reuses the compiled pattern, and you time the interpreter twice believing you compared engines. That is why `splits.php` takes its JIT state from the command line, one process per run.

| n | Interpreter | JIT |
|---|---|---|
| 22 | 140.26 ms | 19.12 ms |
| 26 | 2 139.44 ms | 301.59 ms |
| 30 | 27 264.13 ms, then the raised budget is exhausted | 4 884.45 ms, and the match completes |

Seven times faster where both engines return the same verdict — and still doubling per character: 19.12 → 301.59 → 4 884.45 is the same ×2 curve. At n = 30 the two engines even part ways on outcome: the interpreter's counter exhausts the raised 2147483647 budget and returns `false`, while the JIT run completes in 4.88 seconds and returns `0`. The limit still counts under the JIT — at the default 1 000 000 it aborts after 2.36 ms:

```console
$ php -d pcre.jit=1 splits.php 30
8.4.26 / PCRE2 10.49 2026-09-28 / jit 1
n=30        2.36 ms  ret=false  Backtrack limit exhausted
```
{: data-copy="" }

Turning the JIT on is a real win for this pattern — a factor of seven — and no fix at all: the curve survives it.

## Two myths, measured

Both probes come from one file, run with n = 24, `pcre.jit=0`, limits raised. Absolute times drift a few percent run to run; the ratios do not:

```php
<?php

echo PHP_VERSION, ' / PCRE2 ', PCRE_VERSION, ' / jit ', ini_get('pcre.jit'), "\n";

// argv: the length, then the backtrack limit (default: PHP's 1000000).
$n = (int) ($argv[1] ?? 24);
ini_set('pcre.backtrack_limit', (string) ($argv[2] ?? 1000000));

$attack = str_repeat('a', $n) . '!';
$patterns = [
    'original /^(\w+)+$/' => '/^(\w+)+$/',
    'non-capturing' => '/^(?:\w+)+$/',
    'bounded {1,100}' => '/^(?:\w+){1,100}$/',
    'possessive /^\w++$/' => '/^\w++$/',
    'atomic /^(?>\w+)+$/' => '/^(?>\w+)+$/',
    'simplified /^\w+$/' => '/^\w+$/',
];

foreach ($patterns as $label => $pattern) {
    $t = hrtime(true);
    $r = preg_match($pattern, $attack);
    printf("%-22s %10.3f ms  ret=%s\n", $label, (hrtime(true) - $t) / 1e6, var_export($r, true));
}

$t = hrtime(true);
$r = preg_match('/^(\w+)+$/', str_repeat('a', $n));
printf("%-22s %10.3f ms  ret=%s   (matching input, no '!')\n", 'original, no bang', (hrtime(true) - $t) / 1e6, var_export($r, true));

echo 'accept/reject: ', var_export((bool) preg_match('/^\w++$/', 'ab_9'), true), ' / ', var_export((bool) preg_match('/^\w++$/', 'ab!'), true), "\n";
```
{: data-file="myth.php" data-copy="" }

```console
$ php -d pcre.jit=0 myth.php 24 2147483647
8.4.26 / PCRE2 10.49 2026-09-28 / jit 0
original /^(\w+)+$/        538.303 ms  ret=0
non-capturing              487.466 ms  ret=0
bounded {1,100}           1339.234 ms  ret=0
possessive /^\w++$/         0.011 ms  ret=0
atomic /^(?>\w+)+$/         0.003 ms  ret=0
simplified /^\w+$/          0.002 ms  ret=0
original, no bang           0.002 ms  ret=1   (matching input, no '!')
accept/reject: true / false
```
{: data-copy="" }

**The capture is not the problem.** The non-capturing variant takes 487.466 ms where the capturing original takes 538.303 — the same half-second. The explosion comes from one quantifier walking a range another quantifier can re-split, not from anything `(` does.

**A bound left in the nest makes it worse, not better.** `/^(?:\w+){1,100}$/` caps the *number of rounds*, not the length of anything — one round's `\w+` still swallows the whole run, which is why `preg_match('/^(?:\w+){1,2}$/', 'aaa')` matches. At n = 24 no split needs more than twenty-four rounds, so the cap excludes not one of the 2^23 paths — and the pattern takes 1 339.234 ms, two and a half times the unbounded original, every path now paying the counted repeat's bookkeeping. This is the same lesson as the tutorial's `/(a+)+$/` chapter, measured on the `\w` variant: a bound you added for safety buys none, while the *guide's* "bound your repeats" advice stands — for a repeat that is no longer inside a nest.

## The rewrites, measured and checked

The analyzer's confirmed mode runs the attack before it calls the verdict confirmed. Its verdict on the pattern, condensed from the repository's own ReDoS demo:

```text
Severity: critical
- Finding: Nested unbounded quantifiers detected. This allows exponential backtracking. Consider using atomic groups (?>...) or possessive quantifiers (*+, ++).
…
Confirmed: Yes
Confidence: high
```

Three rewrites come back — `/^\w++$/` (possessive), `/^(?>\w+)+$/` (atomic group), `/^\w+$/` (drop the redundant nesting) — each reported `Valid: Yes` and `ReDoS Risk: safe`. The same file times them, n = 26 attack input, JIT off, limits raised:

```console
$ php -d pcre.jit=0 myth.php 26 2147483647
8.4.26 / PCRE2 10.49 2026-09-28 / jit 0
original /^(\w+)+$/       2125.576 ms  ret=0
non-capturing             1953.658 ms  ret=0
bounded {1,100}           5687.146 ms  ret=0
possessive /^\w++$/         0.008 ms  ret=0
atomic /^(?>\w+)+$/         0.003 ms  ret=0
simplified /^\w+$/          0.001 ms  ret=0
original, no bang           0.002 ms  ret=1   (matching input, no '!')
accept/reject: true / false
```
{: data-copy="" }

The possessive and atomic forms refuse to re-enter the inner quantifier, so the engine never enumerates the splits. All three land under a hundredth of a millisecond — the spread between them is measurement overhead, not engine work. The language is untouched: `ab_9` still matches and `ab!` (not a word character) is still rejected.

> **Warning:** one caveat travels with **all three** rewrites: every one of them drops the capture group, so anything reading `$matches[1]` needs re-reading against the new shape.

The script that produced the verdict and the rewrites — theoretical analysis, confirmed mode with witness samples, the three suggestions — is [runnable in the repository](https://github.com/php-regex/php-regex/blob/2.x/demo/redos/analysis.php); its own timing loop uses its inputs, not this article's.

The intuition behind all of this is taught once, properly, in the [tutorial's performance chapter](../tutorial/08-performance-redos.md); the verdicts, the guarantees and the disclosure policy live in the [ReDoS guide](../guides/redos.md). When either page and this article differ, those pages win.

## Recap

You now understand:

- why `/^(\w+)+$/` doubles in time per input character — 2^(n−1) ways to split the run, walked at fifteen to sixteen million per second — and why a *matching* input is microseconds
- that the default `pcre.backtrack_limit` caps the attack at milliseconds but returns `false`, which `if (preg_match(...))` silently reads as *no match*
- that the JIT is a factor of seven, not a class change — the doubling survives it, and the default limit still aborts in 2.36 ms — and that a `{1,100}` bound measured *slower* than the unbounded original
- the three provably safe rewrites, their measured times, and the caveat that travels with all three: each drops the capture group

**Next:** the [ReDoS guide](../guides/redos.md) maps every verdict the analyzer returns — `safe (proven)` to `Exponential backtracking (proven)` — and the fix that belongs to each.

---
date: 2026-09-22 17:08:00 +00:00
last_modified_at: 2026-10-16 12:00:00 +00:00
author: younes
published: false
tags: [Engines, Linear time]
description: "Why one regex makes PHP give up and Node.js stall while RE2-style engines answer at once, what linear time costs, and how to write PHP patterns that cannot backtrack."
---
# RE2 vs PCRE2: why some regex engines never hang

Run `/^(\w+)+$/` on 26 letters and a `!`. In PHP, the PCRE2 engine gives up and `preg_match()` returns `false`; Node.js needs about 3 seconds. The search tool ripgrep, whose Rust engine is built like Google's RE2, says "no match" at once, even on a million letters. The pattern is the same; the engine is not.

PCRE2 is a *backtracking* engine: when a guess fails, it goes back and tries another. Engines built like RE2 never go back, so they run in *linear time*: twice the text, about twice the time. The price is that they refuse backreferences and lookarounds. That is why PHP keeps PCRE2 — and why, in PHP, your protection is the way you write the pattern.

## Before you start

- **You should already know:** what `preg_match()` does, and the basics of a pattern: delimiters, literal characters, `^`, `$` and `\w`. Every other term — backreference, lookaround, JIT — is defined where it first appears. New to regex? Start with the [basics chapter](../tutorial/01-basics.md).
- **You will learn:** why one pattern makes PHP give up while ripgrep answers at once, what linear-time engines give up for it, and the three layers that protect your PHP patterns.
- **Running the examples:** the PHP snippets run with plain PHP 8.1 or newer, nothing to install. The Node.js and ripgrep rows need those two tools, only if you want to rerun them. Running everything takes about five minutes.

## One pattern, three engines

This PHP file runs the pattern on `n` letters and a `!`, and prints its engine, limit, result and time:

```php
<?php

// Usage: php same-pattern.php [length] [pattern]
$n = (int) ($argv[1] ?? 26);
$pattern = $argv[2] ?? '/^(\w+)+$/';
$subject = str_repeat('a', $n) . '!';

printf("PHP %s / PCRE2 %s / jit %s / backtrack_limit %s\n",
    PHP_VERSION, PCRE_VERSION, ini_get('pcre.jit'), ini_get('pcre.backtrack_limit'));

$start = hrtime(true);
$result = preg_match($pattern, $subject);
$ms = (hrtime(true) - $start) / 1e6;

printf("%s  n=%d  result=%s  %s  %.2f ms\n",
    $pattern, $n, var_export($result, true), preg_last_error_msg(), $ms);
```
{: data-file="same-pattern.php" data-copy="" }

Run it twice. The second run raises the backtrack limit — the number of search steps PHP allows before it gives up — a thousand times:

```console
$ php same-pattern.php
PHP 8.4.26 / PCRE2 10.49 2026-09-28 / jit 1 / backtrack_limit 1000000
/^(\w+)+$/  n=26  result=false  Backtrack limit exhausted  2.37 ms
$ php -d pcre.backtrack_limit=1000000000 same-pattern.php
PHP 8.4.26 / PCRE2 10.49 2026-09-28 / jit 1 / backtrack_limit 1000000000
/^(\w+)+$/  n=26  result=0  No error  306.16 ms
```
{: data-copy="" }

The first line names the engine: PCRE2 10.49, with the JIT (the compiler that turns a pattern into machine code) on, as PHP sets it by default. With the default limit, PHP stops after about 2 milliseconds and returns `false`. With the higher limit, it reaches the real answer, `0` (no match), after about 0.3 seconds — for a string of 27 bytes.

> **Warning:** `false` is not "no match". It means the engine gave up before it knew the answer. Code like `if (!preg_match(...))` treats both the same way. When you get `false`, read `preg_last_error_msg()`.

Node.js uses V8, which also goes back and retries. Here it runs to the end:

```console
$ node -e 'const t = Date.now(); const r = /^(\w+)+$/.test("a".repeat(26) + "!"); console.log("match:", r, " ms:", Date.now() - t);'
match: false  ms: 2818
```
{: data-copy="" }

Last, ripgrep, a search tool with two engines in one program: the Rust `regex` crate (a Rust library) by default, and PCRE2 behind `--pcre2`. ripgrep exits with `1` when it finds no match, and `2` on an error:

```console
$ php -r 'file_put_contents("attack.txt", str_repeat("a", 26) . "!\n");'
$ php -r 'file_put_contents("long.txt", str_repeat("a", 1000000) . "!\n");'
$ /usr/bin/time -p rg '^(\w+)+$' long.txt; echo "exit $?"
real 0.01
user 0.00
sys 0.00
exit 1
$ /usr/bin/time -p rg 'zzz' long.txt; echo "exit $?"
real 0.00
user 0.00
sys 0.00
exit 1
$ /usr/bin/time -p rg --pcre2 '^(\w+)+$' attack.txt; echo "exit $?"
rg: attack.txt: PCRE2: error matching: match limit exceeded
real 0.03
user 0.03
sys 0.00
exit 2
```
{: data-copy="" }

The Rust engine reads a million letters and answers "no match" in about the time it takes to start the program. A search for `zzz`, a word that is not in the file, costs the same. PCRE2, inside the same program, gives up on 27 bytes.

| Engine | Kind | Answer on 26 letters and `!` | Time |
|---|---|---|---:|
| PHP 8.4.26, PCRE2 10.49, default limit | backtracking | gave up: `false` | 2.37 ms |
| PHP 8.4.26, PCRE2 10.49, limit × 1,000 | backtracking | no match | 306.16 ms |
| Node.js 24.18.0, V8 | backtracking | no match | 2,818 ms |
| ripgrep 15.2.0 `--pcre2`, PCRE2 10.45 | backtracking | gave up: match limit exceeded | 0.03 s, program start included |
| ripgrep 15.2.0, Rust `regex` | linear-time | no match, on a million letters too | 0.01 s, program start included |

<figure class="pipeline-figure" role="group" aria-labelledby="engines-chart-caption">
<svg viewBox="0 0 640 210" role="img" aria-label="Bar chart of one identical match call on three backtracking engines: 2.37 milliseconds for PHP at its default limit, which gave up; 306 milliseconds with the limit raised one thousand times; 2,818 milliseconds on Node.js.">
  <title>The same call, three backtracking engines</title>
  <line x1="48" y1="170" x2="616" y2="170" stroke="var(--line-soft)" stroke-width="1"/>
  <g>
    <rect x="104" y="169" width="96" height="1" fill="var(--ink-soft)"/>
    <rect x="272" y="151" width="96" height="19" fill="var(--ink-soft)"/>
    <rect x="440" y="10" width="96" height="160" fill="var(--amber)"/>
  </g>
  <g style="font-family: var(--font-mono); font-size: 13px;" text-anchor="middle" fill="var(--ink)">
    <text x="152" y="162">2.37 ms</text>
    <text x="320" y="144">306 ms</text>
    <text x="488" y="2" style="font-weight: 600; fill: var(--amber);">2,818 ms</text>
  </g>
  <g style="font-family: var(--font-mono); font-size: 12px;" text-anchor="middle" fill="var(--ink-soft)">
    <text x="152" y="190">PHP &middot; default limit</text>
    <text x="320" y="190">PHP &middot; limit &times;1000</text>
    <text x="488" y="190">Node.js 24</text>
    <text x="332" y="208">one call, 27 bytes of input</text>
  </g>
</svg>
<figcaption id="engines-chart-caption">
  The same <code>preg_match</code>-shaped call, in-process, on the three backtracking engines that finished or gave up. The first bar is fast because PHP's default limit stopped the search. The linear-time engines are off the chart on purpose: their timings include program start, a different race.
</figcaption>
</figure>

Times vary by a few percent between runs; the shape does not. Whether a regex is slow depends on the pattern *and* on the engine that runs it.

## Why PHP's engine goes back and retries

PCRE2 is a *backtracking engine*: it follows one way to match at a time. When that way fails, it goes back to its last choice and tries the next one.

In `^(\w+)+$`, the inner `\w+` and the outer `+` can share the letters in many ways. Three letters `aaa` can be split as `(aaa)`, `(aa)(a)`, `(a)(aa)` or `(a)(a)(a)`. The final `!` makes every split fail, so the engine tries them all. Each extra letter doubles the count: 26 letters give 2^(26−1) = 33,554,432 splits.

PHP protects itself with `pcre.backtrack_limit`, 1,000,000 by default. Past it, the engine stops and `preg_match()` returns `false` — the 2-millisecond answer above. [The catastrophic backtracking article](catastrophic-backtracking-in-php.md) measures how the time grows, what the JIT changes and which rewrites fix it.

## How a linear-time engine never goes back

A *linear-time engine* reads the text once, from left to right, and never goes back. At each character, it keeps a list of every place in the pattern it could be, and moves all of them forward together. Its time grows in step with the text, and no pattern can make it explode.

RE2's documentation says it in one line: "RE2 is pessimistic where a backtracking engine is optimistic." A backtracking engine bets that its first guess will work. RE2 "evaluates all alternatives in parallel."

The idea is older than PHP: Ken Thompson published it in 1968. In January 2007, Russ Cox measured it against the backtracking engines of the day. Thompson's method, he found, was "a million times faster than Perl when running on a miniscule 29-character string."

Cox also saw that "PCRE stops getting the right answer at n=23, because it aborts the recursive backtracking after a maximum number of steps." PHP's `false` above is the same kind of give-up, from PCRE2 10.49.

Three engines in wide use make this promise in writing:

- **RE2**, Google's C++ library, in production "since 2006": "the match time is linear in the length of the input string."
- **Go's `regexp`** package uses RE2's syntax, and "is guaranteed to run in time linear in the size of the input."
- **Rust's `regex`** crate, the default engine of ripgrep: "all regex searches in this crate have worst case `O(m * n)` time complexity" — m grows with the pattern, n with the text.

## What linear-time engines give up

The promise has a price, and RE2 names it: "As a matter of principle, RE2 does not support constructs for which only backtracking solutions are known to exist. Thus, backreferences and look-around assertions are not supported."

A *backreference*, like `\1`, matches the same text an earlier group captured. A *lookaround*, like `(?=.*\d)`, checks what comes before or after a position without consuming it. The tutorial has a chapter on each: [lookarounds](../tutorial/06-lookarounds.md), [backreferences](../tutorial/07-backreferences-recursion.md).

Ask ripgrep for two patterns PHP developers write often: a word typed twice, and a password rule.

```console
$ echo 'Please check the the logs.' > typo.txt
$ printf 'sunset2026\nsunsetsky\n' > passwords.txt
$ rg '\b(\w+) \1\b' typo.txt
rg: regex parse error:
    (?:\b(\w+) \1\b)
               ^^
error: backreferences are not supported

Consider enabling PCRE2 with the --pcre2 flag, which can handle backreferences
and look-around.
$ rg '^(?=.*\d)(?=.*[a-z]).{8,}$' passwords.txt
rg: regex parse error:
    (?:^(?=.*\d)(?=.*[a-z]).{8,}$)
        ^^^
error: look-around, including look-ahead and look-behind, is not supported

Consider enabling PCRE2 with the --pcre2 flag, which can handle backreferences
and look-around.
$ rg --pcre2 '\b(\w+) \1\b' typo.txt
Please check the the logs.
$ rg --pcre2 '^(?=.*\d)(?=.*[a-z]).{8,}$' passwords.txt
sunset2026
```
{: data-copy="" }

The Rust engine refuses both patterns and points to PCRE2. With `--pcre2`, the same program finds the doubled word and the one valid password.

## Why PHP keeps the backtracking engine

PHP's `preg_*` functions run on PCRE2, and PHP has no linear-time engine built in. The practical reading is simple: PHP code depends on the features RE2 gives up. Here are the same two patterns in PHP:

```php
<?php

// A backreference: find a word typed twice in a row.
preg_match('/\b(\w+) \1\b/', 'Please check the the logs.', $m);
var_dump($m[0]);

// Two lookaheads: at least one digit and one lowercase letter, 8+ characters.
var_dump(preg_match('/^(?=.*\d)(?=.*[a-z]).{8,}$/', 'sunset2026'));
var_dump(preg_match('/^(?=.*\d)(?=.*[a-z]).{8,}$/', 'sunsetsky'));
```
{: data-file="what-php-keeps.php" data-copy="" }

```text
string(7) "the the"
int(1)
int(0)
```
{: data-copy="" }

Both work in PHP; a linear-time engine refuses both.

The trade runs both ways. In RE2's words, a backtracking engine "tests each alternative sequentially, making it fast when the first alternative is common." Most patterns, on most input, never come near the bad case.

> **Note:** Since version 8.8 (announced 11 January 2021), V8 carries a second, non-backtracking engine. The flag `--enable-experimental-regexp-engine-on-excessive-backtracks` falls back to it "on excessive backtracking". Node.js 24.18.0 leaves the flag off; turned on, the Node.js call above gives the same `match: false` in 1 ms. PHP has no such switch: its PCRE settings are the two limits and the JIT.

## What this means for your PHP patterns

You cannot change PHP's engine, but you can write patterns that leave it nothing to retry. A *possessive quantifier*, such as `++`, keeps everything it matched and never gives any of it back. An *atomic group*, `(?>…)`, does the same for a whole group. `^\w++$` accepts the same strings as `^(\w+)+$` (without the capture group), and has only one way to match.

Run it through the same file, on 27 bytes and on a million:

```console
$ php same-pattern.php 26 '/^\w++$/'
PHP 8.4.26 / PCRE2 10.49 2026-09-28 / jit 1 / backtrack_limit 1000000
/^\w++$/  n=26  result=0  No error  0.05 ms
$ php same-pattern.php 1000000 '/^\w++$/'
PHP 8.4.26 / PCRE2 10.49 2026-09-28 / jit 1 / backtrack_limit 1000000
/^\w++$/  n=1000000  result=0  No error  0.45 ms
```
{: data-copy="" }

PHP gives the real answer, `0`, in under a millisecond on both, with its default limit. On this pattern, PCRE2 behaves like a linear-time engine.

In PHP, your protection has three layers:

1. **The pattern's shape.** Where you need no backreference or lookaround, use possessive quantifiers and atomic groups so the engine has nothing to retry.
2. **The limits.** Check for `false`, and read `preg_last_error_msg()`.
3. **The input length.** Cap untrusted input before you match it.

The [ReDoS guide](../guides/redos.md) lists the rewrites, pattern by pattern. ReDoS — regular expression denial of service — is an attack that sends a backtracking engine its worst-case input.

## Try it yourself

- Run `same-pattern.php 1000000 '/^\w++$/'`: PHP answers in under a millisecond on a million letters, with its default limit.
- Ask ripgrep for the doubled-word rule without `--pcre2`, and read the error: a linear-time engine names what it refuses.
- Put the password rule `/^(?=.*\d)(?=.*[a-z]).{8,}$/` into `same-pattern.php` at length 1,000,000: it fails in milliseconds too, because each lookahead scans the text once.

## How this was measured

Every output above was produced on 16 October 2026, on an Apple-silicon Mac:

- PHP 8.4.26 with PCRE2 10.49 (2026-09-28), JIT on, default limits unless the command says otherwise. This PCRE2 is linked from the system; PHP 8.4 itself bundles 10.44 ([which PCRE2 ships with which PHP](which-pcre2-ships-with-which-php.md)).
- Node.js 24.18.0; ripgrep 15.2.0, which carries its own PCRE2 10.45.
- Each timing ran three times; the median run is shown.
- Both PHP files also ran unchanged on the official `php:8.1-cli` image (PHP 8.1.34, PCRE2 10.39), with the same results.
- The PHPRegex output comes from the 2.x branch, run in the repository as `php src/Cli/bin/regex` — the program `vendor/bin/regex` starts.

## Check your patterns with PHPRegex

A rewrite by eye can be wrong; PHPRegex, the library behind this site, checks it. Install it as a development dependency:

{% include install.html package="php-regex/php-regex" dev=true inline=true %}

`vendor/bin/regex analyze` reads a pattern and proves that one match attempt stays linear in PCRE2, or gives you the input that makes it slow. Where it can do neither, it says so. With `--redos-mode=confirmed`, it also replays that input on the PCRE2 you run. Here is the ReDoS step of its output for the original pattern:

```console
$ vendor/bin/regex analyze '/^(\w+)+$/' --redos-mode=confirmed
  ...
  [3/5] ReDoS analysis
  Status     : Exponential backtracking (proven)
  Severity   : CRITICAL (score 10)
  Mode       : CONFIRMED
  Confidence : HIGH
  Attack: "0" x n . "!"
  Replayed on PCRE2 10.49: preg_match fails from length 17 (backtrack_limit 100000, JIT off).
  Hotspot:   2-5
```
{: data-copy="" }

*Exponential* means each extra character doubles the work. The attack reads as PHP: `str_repeat("0", $n) . "!"`.

Replayed on the real engine, with a smaller limit and the JIT off, `preg_match()` fails from 17 characters. The command exits with `1`, so a CI job fails on it.

The rewrite:

```console
$ vendor/bin/regex analyze '/^\w++$/'
  ...
  [3/4] ReDoS analysis
  Status     : safe (proven)
  Severity   : SAFE (score 0)
  Mode       : THEORETICAL
  Confidence : HIGH
```
{: data-copy="" }

`safe (proven)`: whatever the input, one match attempt grows only in step with its length. The [ReDoS guide](../guides/redos.md#the-guarantee) states exactly what the proof covers.

The password rule, `/^(?=.*\d)(?=.*[a-z]).{8,}$/`, is also `safe (proven)`: a pattern RE2 refuses is not a dangerous pattern in PHP. The doubled-word rule gets `Potential backtracking (heuristic)`. Backreferences are outside what PHPRegex can prove, so a heuristic — a rule of thumb — decides, and the verdict says so.

To check every pattern in a project, run `vendor/bin/regex lint src/ --redos`; the [CLI guide](../guides/cli.md) lists every option.

## Sources

Sources, for checking rather than trusting:

- [The RE2 README](https://github.com/google/re2): the linear-time guarantee, the "pessimistic" passage and the refused features.
- [Go's `regexp` documentation](https://pkg.go.dev/regexp): Go's linear-time guarantee.
- [The Rust `regex` README](https://github.com/rust-lang/regex): the `O(m * n)` worst case and the missing features.
- [The ripgrep README](https://github.com/BurntSushi/ripgrep): the Rust engine by default, PCRE2 behind `-P/--pcre2`.
- Russ Cox, [Regular Expression Matching Can Be Simple And Fast](https://swtch.com/~rsc/regexp/regexp1.html), January 2007: both Cox quotes.
- Ken Thompson, "Regular expression search algorithm," *Communications of the ACM* 11(6), June 1968, pp. 419–422: the origin of the method.
- [An additional non-backtracking RegExp engine](https://v8.dev/blog/non-backtracking-regexp), V8 blog, 11 January 2021: V8's second engine and its flag.

## Recap

You now understand:

- Whether a regex is slow depends on the pattern *and* the engine. `^(\w+)+$` makes PHP give up and Node.js stall; ripgrep's Rust engine answers at once, even on a million letters.
- Backtracking engines (PCRE2, V8) go back and retry. Linear-time engines (RE2, Go's `regexp`, Rust's `regex`) read the text once and never go back.
- Linear time costs backreferences and lookarounds, which PHP code relies on — so PHP keeps PCRE2.
- In PHP, `false` from `preg_match()` means the engine gave up, not "no match".
- Your protection is the pattern's shape (`++`, `(?>…)`), the limits and the input length, checked with a tool before you ship.

**Next:** [the catastrophic backtracking article](catastrophic-backtracking-in-php.md) measures how the time grows in PHP, what the JIT and the limits change, and which rewrites fix it.

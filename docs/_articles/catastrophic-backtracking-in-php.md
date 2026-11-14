---
date: 2026-08-19 11:23:00 +00:00
last_modified_at: 2026-10-16 12:00:00 +00:00
author: younes
published: false
tags: [Performance, ReDoS]
description: 'Why /^(\w+)+$/ doubles its time with each letter, why preg_match() then returns false, and the one-character fix. Measured on PHP 8.4, JIT on and off.'
---
# Catastrophic backtracking in PHP: why one regex takes seconds

Here is a regex that looks completely harmless: `/^(\w+)+$/`. It checks that a string is made only of word characters.

Give it 26 letters followed by a `!`, and it needs about 2 seconds to say "no". Add one more letter, and it needs twice as long. Add another, and it doubles again.

This is *catastrophic backtracking*: the regex engine tries every possible way to split your input before it gives up. With PHP's default settings, it does not even get that far. It stops early, and `preg_match()` returns `false`, which a simple `if (preg_match(...))` silently reads as "no match".

The good news: the fix is a single character. `/^(\w++)+$/` answers in microseconds. Let's see where those seconds come from, why the usual fixes don't help, and why that one character does.

## Each extra letter doubles the time

The script below builds `n` letters `a` followed by one `!`, and times a single `preg_match()` call. The `!` is not a word character (a letter, a digit or `_`), so the pattern can never match. The engine just has to prove it.

```php
<?php

echo PHP_VERSION, ' / PCRE2 ', PCRE_VERSION, ' / jit ', ini_get('pcre.jit'), "\n";

// Arguments: the input length, then the backtrack limit (PHP's default: 1000000).
$n = (int) ($argv[1] ?? 26);
ini_set('pcre.backtrack_limit', (string) ($argv[2] ?? 1000000));

$subject = str_repeat('a', $n) . '!';

$start = hrtime(true);
$result = preg_match('/^(\w+)+$/', $subject);
$ms = (hrtime(true) - $start) / 1e6;

printf("n=%d  %10.2f ms  ret=%s  %s\n", $n, $ms, var_export($result, true), preg_last_error_msg());
```
{: data-file="splits.php" data-copy="" }

Look at the pattern closely. It has a *quantifier*, a symbol like `+` that says how many times something repeats, inside another quantifier. `\w+` repeats word characters, and the `+` after the group repeats the whole group.

For this first run, we raise PHP's backtrack limit (more on that below) so the engine can go all the way to the end. We also turn off the JIT, a speed-up we'll come back to later.

```console
$ php -d pcre.jit=0 splits.php 26 2147483647
8.4.26 / PCRE2 10.49 2026-09-28 / jit 0
n=26     2179.66 ms  ret=0  No error
```
{: data-copy="" }

`ret=0` means "no match", which is the right answer. It just took more than 2 seconds, for a string of 27 characters. Here are a few other lengths, each the median of three runs:

| Letters (n) | Time, JIT off |
|---:|---:|
| 18 | 8.50 ms |
| 20 | 34.27 ms |
| 22 | 136.42 ms |
| 24 | 539.62 ms |
| 26 | 2,179.66 ms |

<figure class="pipeline-figure" role="group" aria-labelledby="splits-chart-caption">
<svg viewBox="0 0 640 236" role="img" aria-label="Bar chart: match time grows from 8.5 milliseconds at 18 letters to 2.18 seconds at 26 letters, each two extra letters multiplying the time by four.">
  <title>One preg_match call, by input length</title>
  <line x1="48" y1="184" x2="616" y2="184" stroke="var(--line-soft)" stroke-width="1"/>
  <g>
    <rect x="64" y="183" width="80" height="1" fill="var(--ink-soft)"/>
    <rect x="172" y="181" width="80" height="3" fill="var(--ink-soft)"/>
    <rect x="280" y="174" width="80" height="10" fill="var(--ink-soft)"/>
    <rect x="388" y="144" width="80" height="40" fill="var(--ink-soft)"/>
    <rect x="496" y="24" width="80" height="160" fill="var(--amber)"/>
  </g>
  <g style="font-family: var(--font-mono); font-size: 13px;" text-anchor="middle" fill="var(--ink)">
    <text x="104" y="176">8.5 ms</text>
    <text x="212" y="173">34 ms</text>
    <text x="320" y="166">136 ms</text>
    <text x="428" y="136">540 ms</text>
    <text x="536" y="16" style="font-weight: 600; fill: var(--amber);">2.18 s</text>
  </g>
  <g style="font-family: var(--font-mono); font-size: 12px;" text-anchor="middle" fill="var(--ink-soft)">
    <text x="104" y="204">18</text>
    <text x="212" y="204">20</text>
    <text x="320" y="204">22</text>
    <text x="428" y="204">24</text>
    <text x="536" y="204">26</text>
    <text x="332" y="226">letters of input (n)</text>
  </g>
</svg>
<figcaption id="splits-chart-caption">
  One <code>preg_match()</code> call against <code>/^(\w+)+$/</code>, median of three runs. The last bar is 256&times; the first; the first four barely leave the axis.
</figcaption>
</figure>

Every 2 extra letters multiply the time by 4: each letter doubles it. The exact times move by a few percent from one run to the next. The doubling does not.

## Why: the engine tries every way to cut the letters

When the engine's first guess fails, it goes back and tries another one. This is called *backtracking*, and most of the time it is exactly what you want.

Here, though, the inner `\w+` can take all 26 letters. Or it can take 25 and leave 1 for a second round of the group. Or 24 and 2. Or 24, 1 and 1. Each way of cutting the letters into pieces is a different path, and the final `!` makes every single one of them fail.

A run of n letters can be cut in 2^(n−1) ways:

| Letters (n) | 1 | 2 | 3 | 4 | 22 | 26 |
|---|---:|---:|---:|---:|---:|---:|
| Ways to cut | 1 | 2 | 4 | 8 | 2,097,152 | 33,554,432 |

That is the doubling in the timing table. At 22 and at 26 letters, the times work out to about 15 million paths per second. So the engine is not broken, or even slow. It is searching a space that doubles with every letter.

A matching input is a completely different story. Without the `!`, the first guess succeeds, and the engine never looks back:

```console
$ php -d pcre.jit=0 -r '$t = hrtime(true); var_dump(preg_match("/^(\w+)+$/", str_repeat("a", 26))); printf("%.3f ms\n", (hrtime(true) - $t) / 1e6);'
int(1)
0.056 ms
```
{: data-copy="" }

This is why tests rarely catch the problem: valid input is fast. The slow input is the one that *almost* matches, and fails at the very end. Sending that kind of input to a server on purpose has a name: *ReDoS*, regular expression denial of service.

## PHP's safety net: `false` after 19 letters

PHP protects itself with a setting called `pcre.backtrack_limit`, `1000000` by default. PHP passes it to PCRE2, its regex engine, as the *match limit*: a number of steps in the search, not a number of seconds.

Run the script again without the second argument, so the default applies:

```console
$ php -d pcre.jit=0 splits.php 18
8.4.26 / PCRE2 10.49 2026-09-28 / jit 0
n=18        8.49 ms  ret=0  No error
$ php -d pcre.jit=0 splits.php 19
8.4.26 / PCRE2 10.49 2026-09-28 / jit 0
n=19       12.74 ms  ret=false  Backtrack limit exhausted
```
{: data-copy="" }

At 18 letters, the engine finishes and answers `0`. At 19 letters, it hits the limit and stops, after about 13 ms.

So now `preg_match()` returns `false`, and `preg_last_error_msg()` says `Backtrack limit exhausted`. That `false` is not "no match". It is the engine saying "I gave up before I knew".

The same switch, from `0` at 18 letters to `false` at 19, happens in the official `php:8.4-cli` image (PCRE2 10.44) and the `php:8.1-cli` image (PCRE2 10.39). A 20-character string is all it takes.

## Why `if (preg_match(...))` hides it

A lot of code tests the result with a plain `if`, and `if` treats `false` and `0` exactly the same way. The next script drops the `^`, so the pattern looks for a word at the end of the string. Both subjects end with `ok`, so both should match:

```php
<?php

echo PHP_VERSION, ' / PCRE2 ', PCRE_VERSION, ' / jit ', ini_get('pcre.jit'), "\n";

// Both subjects end with the word "ok", so both should match.
$subjects = ['aaaa!ok', str_repeat('a', 19) . '!ok'];

foreach ($subjects as $subject) {
    $result = preg_match('/(\w+)+$/', $subject, $m);

    // The usual check reads false and 0 the same way.
    $usual = $result ? 'match' : 'no match';

    // The strict check treats false as an error.
    $strict = match (true) {
        $result === false => 'error: ' . preg_last_error_msg(),
        $result === 1 => 'match "' . $m[0] . '"',
        default => 'no match',
    };

    printf("%-22s usual: %-8s  strict: %s\n", $subject, $usual, $strict);
}
```
{: data-file="false-is-not-zero.php" data-copy="" }

```console
$ php false-is-not-zero.php
8.4.26 / PCRE2 10.49 2026-09-28 / jit 1
aaaa!ok                usual: match     strict: match "ok"
aaaaaaaaaaaaaaaaaaa!ok usual: no match  strict: error: Backtrack limit exhausted
```
{: data-copy="" }

The short subject matches `ok`, as expected. The long one also ends with `ok`, but the engine gave up while it was still busy with the letters at the start. The usual check says "no match", which is simply wrong. The strict check shows what really happened: an error.

> **Warning:** a filter that uses `if (preg_match(...))` to find bad content lets that content through when the engine gives up. Compare the result with `=== false`, and log `preg_last_error_msg()`. The [error handling section of the PHP regex guide](../guides/regex-in-php.md#error-handling) lists the other error codes.

## Does the JIT fix it? It is about 7 times faster, that's all

The *JIT* (just-in-time compiler) turns the pattern into machine code. Without it, PCRE2 walks through the pattern step by step in its *interpreter*. PHP turns the JIT on by default.

Here is the same `splits.php` with each setting and the limit raised, again the median of three runs:

| Letters (n) | JIT off | JIT on |
|---:|---:|---:|
| 18 | 8.50 ms | 1.24 ms |
| 20 | 34.27 ms | 4.74 ms |
| 22 | 136.42 ms | 19.40 ms |
| 24 | 539.62 ms | 75.69 ms |
| 26 | 2,179.66 ms | 303.40 ms |
| 28 | not run | 1,223.79 ms |

<figure class="pipeline-figure" role="group" aria-labelledby="jit-chart-caption">
<svg viewBox="0 0 640 220" role="img" aria-label="Grouped bar chart: interpreter and JIT times side by side for each input length. Both series climb in the same proportions; the JIT bars are about seven times shorter but still quadruple with every two extra letters.">
  <title>The JIT moves the cliff, it does not remove it</title>
  <line x1="48" y1="180" x2="616" y2="180" stroke="var(--line-soft)" stroke-width="1"/>
  <g>
    <rect x="48" y="179" width="26" height="1" fill="var(--ink-soft)"/>
    <rect x="80" y="179" width="26" height="1" fill="var(--ink)"/>
    <rect x="150" y="177" width="26" height="3" fill="var(--ink-soft)"/>
    <rect x="182" y="179" width="26" height="1" fill="var(--ink)"/>
    <rect x="252" y="170" width="26" height="10" fill="var(--ink-soft)"/>
    <rect x="284" y="178" width="26" height="2" fill="var(--ink)"/>
    <rect x="354" y="143" width="26" height="37" fill="var(--ink-soft)"/>
    <rect x="386" y="175" width="26" height="5" fill="var(--ink)"/>
    <rect x="456" y="30" width="26" height="150" fill="var(--ink-soft)"/>
    <rect x="488" y="159" width="26" height="21" fill="var(--ink)"/>
    <rect x="590" y="96" width="26" height="84" fill="var(--ink)"/>
  </g>
  <g style="font-family: var(--font-mono); font-size: 12px;" text-anchor="middle" fill="var(--ink)">
    <text x="61" y="172">8.5</text>
    <text x="93" y="170">1.2</text>
    <text x="469" y="24">2,180</text>
    <text x="501" y="152">303</text>
    <text x="603" y="88" style="font-weight: 600;">1,224</text>
  </g>
  <g style="font-family: var(--font-mono); font-size: 12px;" text-anchor="middle" fill="var(--ink-soft)">
    <text x="77" y="200">18</text>
    <text x="179" y="200">20</text>
    <text x="281" y="200">22</text>
    <text x="383" y="200">24</text>
    <text x="485" y="200">26</text>
    <text x="601" y="200">28</text>
    <text x="332" y="218">letters of input (n) &middot; milliseconds</text>
  </g>
</svg>
<figcaption id="jit-chart-caption">
  Interpreter bars in grey, JIT bars in ink, labelled in milliseconds. The JIT staircase is seven times lower and climbs at the same slope; at 28 letters only the JIT ran.
</figcaption>
</figure>

The JIT is about 7 times faster at every length. But look at its column: it still doubles with each letter, from 303 ms at 26 letters to 1.2 seconds at 28. A 7 times faster engine moves the cliff by about three letters. It does not remove it.

With the default limit, the JIT also returns `false` at 19 letters, just sooner:

```console
$ php -d pcre.jit=1 splits.php 19
8.4.26 / PCRE2 10.49 2026-09-28 / jit 1
n=19        2.25 ms  ret=false  Backtrack limit exhausted
```
{: data-copy="" }

> **Note:** set the JIT on the command line (`php -d pcre.jit=1`), one process per run. PHP keeps each compiled pattern in a cache. An `ini_set('pcre.jit', '1')` after the pattern already ran does not recompile it, so you would time the interpreter twice.

## Does a `{1,100}` bound help? No, it makes it slower

Two fixes come up again and again: remove the capture group, or put a limit on the outer repeat. The next script times them next to the real fixes, on 24 letters and a `!`. It also checks that each pattern still accepts `ab_9` and rejects `ab!`:

```php
<?php

echo PHP_VERSION, ' / PCRE2 ', PCRE_VERSION, ' / jit ', ini_get('pcre.jit'), "\n";
ini_set('pcre.backtrack_limit', '2147483647'); // raised: time the full search

$patterns = [
    '/^(\w+)+$/',         // the original
    '/^(?:\w+)+$/',       // no capture
    '/^(?:\w+){1,100}$/', // outer repeat bounded
    '/^\w++$/',           // possessive
    '/^(\w++)+$/',        // possessive inside, group kept
    '/^(?>\w+)+$/',       // atomic group
    '/^\w+$/',            // nesting removed
];

foreach ($patterns as $pattern) {
    $start = hrtime(true);
    $result = preg_match($pattern, str_repeat('a', 24) . '!');
    $ms = (hrtime(true) - $start) / 1e6;
    // Same answers on two ordinary inputs?
    $ok = preg_match($pattern, 'ab_9');
    $bad = preg_match($pattern, 'ab!');
    printf("%-20s %10.3f ms  ret=%s  ab_9=%d ab!=%d\n", $pattern, $ms, var_export($result, true), $ok, $bad);
}
```
{: data-file="rewrites.php" data-copy="" }

```console
$ php -d pcre.jit=0 rewrites.php
8.4.26 / PCRE2 10.49 2026-09-28 / jit 0
/^(\w+)+$/              536.623 ms  ret=0  ab_9=1 ab!=0
/^(?:\w+)+$/            548.788 ms  ret=0  ab_9=1 ab!=0
/^(?:\w+){1,100}$/     1350.726 ms  ret=0  ab_9=1 ab!=0
/^\w++$/                  0.006 ms  ret=0  ab_9=1 ab!=0
/^(\w++)+$/               0.003 ms  ret=0  ab_9=1 ab!=0
/^(?>\w+)+$/              0.002 ms  ret=0  ab_9=1 ab!=0
/^\w+$/                   0.002 ms  ret=0  ab_9=1 ab!=0
```
{: data-copy="" }

<figure class="pipeline-figure" role="group" aria-labelledby="fixes-chart-caption">
<svg viewBox="0 0 640 220" role="img" aria-label="Bar chart of seven rewrite timings on the same input: about 537, 549 and 1,351 milliseconds for the original, the non-capturing and the bounded forms, and four bars at the axis - 0.006 milliseconds and less - for the possessive, atomic and flattened forms.">
  <title>Same input, seven patterns</title>
  <line x1="48" y1="180" x2="616" y2="180" stroke="var(--line-soft)" stroke-width="1"/>
  <g>
    <rect x="64" y="120" width="56" height="60" fill="var(--ink-soft)"/>
    <rect x="148" y="119" width="56" height="61" fill="var(--ink-soft)"/>
    <rect x="232" y="30" width="56" height="150" fill="var(--amber)"/>
    <rect x="316" y="179" width="56" height="1" fill="var(--ink)"/>
    <rect x="400" y="179" width="56" height="1" fill="var(--ink)"/>
    <rect x="484" y="179" width="56" height="1" fill="var(--ink)"/>
    <rect x="568" y="179" width="56" height="1" fill="var(--ink)"/>
  </g>
  <g style="font-family: var(--font-mono); font-size: 12px;" text-anchor="middle" fill="var(--ink)">
    <text x="92" y="112">537 ms</text>
    <text x="176" y="111">549 ms</text>
    <text x="260" y="22" style="font-weight: 600; fill: var(--amber);">1,351 ms</text>
    <text x="344" y="171">0.006 ms</text>
    <text x="428" y="171">0.003</text>
    <text x="512" y="171">0.002</text>
    <text x="596" y="171">0.002</text>
  </g>
  <g style="font-family: var(--font-mono); font-size: 11px;" text-anchor="middle" fill="var(--ink-soft)">
    <text x="92" y="200">(\w+)+</text>
    <text x="176" y="200">(?:\w+)+</text>
    <text x="260" y="200">{1,100} bound</text>
    <text x="344" y="200">\w++</text>
    <text x="428" y="200">(\w++)+</text>
    <text x="512" y="200">(?>\w+)+</text>
    <text x="596" y="200">\w+</text>
    <text x="332" y="218">one call, 24 letters and a &laquo;!&raquo;, JIT off</text>
  </g>
</svg>
<figcaption id="fixes-chart-caption">
  The last four bars do not leave the axis: that is the fix. The amber bar is the popular &laquo;just bound it&raquo; advice &mdash; slower than the disease, because <code>{1,100}</code> caps the rounds, not the choices per round.
</figcaption>
</figure>

Removing the capture changes nothing: `(?:…)`, a group that does not capture, takes the same half-second.

The bound makes things worse: about 1.35 seconds, 2.5 times the original. That surprises a lot of people, but `{1,100}` limits the *number of rounds*, not the length of each round, and 24 letters never need more than 24 rounds anyway. All 2^23 ways to cut the letters are still there. With the JIT on, the bounded form is still the slowest of the three, at about twice the original.

## The fix: possessive quantifiers and atomic groups

Now look at the last four lines of that output. A few microseconds (thousandths of a millisecond) instead of half a second, and the same answers: `ab_9=1 ab!=0`.

- A *possessive quantifier* (`++`, `*+`, `?+`) takes as much as it can and never gives any of it back. `\w++` takes all the letters; when `$` then fails, there is nothing left to retry.
- An *atomic group* (`(?>…)`) does the same for a whole group: once the group has matched, the engine never goes back inside it.
- Removing the nesting also works here, because `(\w+)+` and `\w+` accept exactly the same strings.

Each of these removes the choices, so the engine has one path to follow instead of 2^(n−1).

A possessive quantifier is safe here because nothing after `\w++` needs a word character. When something does, the meaning changes: `/^\w++a$/` never matches, not even `ba`.

> **Warning:** `/^\w++$/`, `/^(?>\w+)+$/` and `/^\w+$/` have no capture group, so code that reads `$matches[1]` breaks. `/^(\w++)+$/` keeps the group. Whichever you choose, run your tests on the new pattern.

## If you want to try it

Every script here runs on plain PHP 8.1 or newer, with nothing to install, and finishes in a few seconds.

- Run `splits.php` at lengths 28 and 30 with the limit raised and the JIT off: each 2 extra letters still multiply the time by about 4.
- In `rewrites.php`, change `\w++` back to `\w+` in one rewrite, and watch the milliseconds come back.
- Time a matching subject, `str_repeat('a', 24) . 'b'`, on the original pattern: the first guess succeeds, so the engine never goes back.

## Finding these patterns before an attacker does

PHPRegex, the library behind this site, reads a pattern and spots this problem without running an attack. Install its command-line tool in your project:

{% include install.html package="php-regex/php-regex" dev=true inline=true %}

Then ask it about the original pattern (the output is trimmed where you see `…`):

```console
$ vendor/bin/regex analyze '/^(\w+)+$/'
…
  [3/4] ReDoS analysis
  Status     : Exponential backtracking (proven)
  Severity   : CRITICAL (score 10)
  Mode       : THEORETICAL
  Confidence : MEDIUM
  Attack: "0" x n . "!"
…
```
{: data-copy="" }

"Exponential" is the doubling we measured. The `Attack` line is the input that triggers it: a run of word characters, then a `!`, the same shape as our `aaa…a!`. Add `--redos-mode=confirmed`, and the tool replays that input on your own PHP:

```console
$ vendor/bin/regex analyze '/^(\w+)+$/' --redos-mode=confirmed
…
  Replayed on PCRE2 10.49: preg_match fails from length 17 (backtrack_limit 100000, JIT off).
…
```
{: data-copy="" }

The replay uses a limit ten times lower than PHP's default, so it fails sooner: 17 characters is 16 letters and the `!`, where the default limit held until 19 letters. The same command reports `safe (proven)` for `/^(\w++)+$/`, `/^\w++$/`, `/^(?>\w+)+$/` and `/^\w+$/`. And it still reports `/^(?:\w+){1,100}$/` as exponential, just as we measured.

To check a whole codebase, the linter finds each pattern in your PHP files. Here it ran on a folder holding one class that validates usernames with the original pattern:

```console
$ vendor/bin/regex lint app --redos
…
  app/UsernameValidator.php:7:33
      → /^(\w+)+$/
    WARN Exponential backtracking (proven). Severity: CRITICAL, confidence: MEDIUM.
…
    WARN Nested quantifiers can cause catastrophic backtracking.
…
```
{: data-copy="" }

The same checks work from PHP code: the [ReDoS demo script](https://github.com/php-regex/php-regex/blob/2.x/demo/redos/analysis.php) analyzes `/^(\w+)+$/` and proposes the possessive, atomic and simplified rewrites shown above. The [CLI guide](../guides/cli.md) lists every command and option.

## In short

- A quantifier inside a quantifier, as in `/^(\w+)+$/`, gives a failing input of n letters 2^(n−1) paths: each extra letter doubles the time.
- With PHP's default `pcre.backtrack_limit`, 19 letters and a `!` make `preg_match()` return `false`. The engine gave up; it did not answer "no match".
- `if (preg_match(...))` reads that `false` as "no match". Compare with `=== false`, and read `preg_last_error_msg()`.
- The JIT is about 7 times faster, but still doubles per letter. Removing the capture or adding a `{1,100}` bound does not help.
- A possessive quantifier (`\w++`) or an atomic group (`(?>…)`) leaves the engine one path: microseconds, same answers.

If you want to go further, the [ReDoS guide](../guides/redos.md) explains each verdict PHPRegex returns and the fix for each dangerous shape, and the [tutorial chapter on performance and ReDoS](../tutorial/08-performance-redos.md) shows more shapes that backtrack badly, such as overlapping alternatives. New to regular expressions? The [basics chapter](../tutorial/01-basics.md) covers delimiters, `^`, `$` and `\w` in a few pages.

## How I measured this

All times come from PHP 8.4.26 (a Homebrew build) on an Apple M1 Max, measured on 16 October 2026. This PHP is linked against the system's PCRE2 10.49, released 28 September 2026. A stock PHP 8.4 bundles PCRE2 10.44 instead: see [which PCRE2 ships with which PHP](which-pcre2-ships-with-which-php.md).

Each run is a fresh process, with the JIT set by `-d pcre.jit=0` or `-d pcre.jit=1`. Timing tables use a backtrack limit of `2147483647`; every other run uses PHP's default of `1000000`. Each table cell is the median of three runs.

Two results were checked again in Docker: the switch from 18 to 19 letters, and `false-is-not-zero.php`. Both hold in the official `php:8.1-cli` (PHP 8.1.34, PCRE2 10.39) and `php:8.4-cli` (PHP 8.4.26, PCRE2 10.44) images.

## Sources

If you would rather check than trust:

- [PHP manual, PCRE runtime configuration](https://www.php.net/manual/en/pcre.configuration.php): the `pcre.backtrack_limit`, `pcre.recursion_limit` and `pcre.jit` settings and their defaults.
- [PHP manual, `preg_match()`](https://www.php.net/manual/en/function.preg-match.php): the function returns `false` on failure, distinct from `0` for no match.
- [PCRE2 API documentation](https://www.pcre.org/current/doc/html/pcre2api.html): the match limit, which PHP sets from `pcre.backtrack_limit`, and how it counts steps with and without the JIT.
- [PCRE2 pattern documentation, atomic grouping and possessive quantifiers](https://www.pcre.org/current/doc/html/pcre2pattern.html#atomicgroup): the engine's own definition of both constructs.
- [PCRE2 JIT documentation](https://www.pcre.org/current/doc/html/pcre2jit.html): the JIT gives the same results as the interpreter, only faster.

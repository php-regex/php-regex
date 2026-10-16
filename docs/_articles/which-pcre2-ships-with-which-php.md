---
date: 2026-07-16 12:00:00 +00:00
last_modified_at: 2026-10-16 12:00:00 +00:00
author: younes
published: false
description: "The PCRE2 version behind every PHP release from 7.3 to 8.6, what the 10.43 bump bought, and how to judge one pattern for an engine you do not run — every released row verified against the php-src tags and run in the official images."
---
# Which PCRE2 ships with which PHP

Since PHP 7.3, PHP compiles every `preg_*` call with PCRE2, the regex engine bundled into php-src and built into your PHP by default. (Before 7.3, PHP carried the original PCRE, which ended at 8.41. Some distribution packages and Homebrew builds link the system's own PCRE2 instead of the bundled one — more below.) The bundled version is not a setting: it moves only when PHP moves, and each release accepts a slightly different pattern language. That is why the same file can compile on one PHP version and be refused by another — the probe below shows it, and the table after maps it from PHP 7.3 to the 8.6 branch.

Save this probe — it names its engine, then asks one question of it:

```php
<?php

echo PHP_VERSION, ' / PCRE2 ', PCRE_VERSION, "\n";

// The lookbehind branch matches one, two or three a's — not one fixed length.
var_dump(preg_match('/(?<=a{1,3})f/', 'aaaf', $m), $m);
```
{: data-file="lookbehind.php" data-copy="" }

Run it on PHP 8.2 (the official `php:8.2-cli` image — nothing but Docker to install) and the engine refuses the pattern:

```console
$ docker run --rm -i php:8.2-cli php < lookbehind.php
8.2.34 / PCRE2 10.40 2022-04-14

Warning: preg_match(): Compilation failed: lookbehind assertion is not fixed length at offset 0 in Standard input code on line 6
bool(false)
NULL
```
{: data-copy="" }

Run the same file on PHP 8.4 and it matches:

```console
$ docker run --rm -i php:8.4-cli php < lookbehind.php
8.4.26 / PCRE2 10.44 2024-06-07
int(1)
array(1) {
  [0]=>
  string(1) "f"
}
```
{: data-copy="" }

The pattern did not change. The engine did. PHP 8.2 bundles PCRE2 10.40, PHP 8.4 bundles 10.44, and bounded variable-length lookbehind — a branch inside a lookbehind that can match more than one length — arrived in PCRE2 10.43. On a stock build — the php.net tarball, or the official images used here — your regex dialect is pinned by your PHP version. The construct gets [its own section below](#what-pcre2-1043-bought).

## The matrix

Every row for a released PHP — 7.3 through 8.5 — was checked twice on 16 October 2026: against the bundled library header at the php-src release tags, and in the official Docker images. The method and sources have their own section below.

| PHP | Released | PCRE2 at `.0` | Across the series |
|---|---|---|---|
| 7.3.0 | 2018-12-06 | 10.32 | never bumped, through 7.3.33 |
| 7.4.0 | 2019-11-28 | 10.33 | 10.34 in 7.4.6, 10.35 in 7.4.12, through 7.4.33 |
| 8.0.0 | 2020-11-26 | 10.35 | never bumped, through 8.0.30 |
| 8.1.0 | 2021-11-25 | 10.37 | 10.39 from 8.1.1, through 8.1.34 |
| 8.2.0 | 2022-12-08 | 10.40 | never bumped, through 8.2.34 |
| 8.3.0 | 2023-11-23 | 10.42 | never bumped, through 8.3.35 |
| 8.4.0 | 2024-11-21 | 10.44 | never bumped, through 8.4.26 |
| 8.5.0 | 2025-11-20 | 10.44 | never bumped, through 8.5.11 |
| 8.6 | *GA planned 2026-11-19* | 10.44 on the branch | — |

Three rows carry more history than the table shows:

- **PHP 7.3 is the boundary release.** Everything before it — through 7.2.34 — shipped PCRE, not PCRE2, ending at PCRE 8.41.
- **Two series bumped mid-flight.** 7.4 went from 10.33 to 10.34 in 7.4.6 and to 10.35 in 7.4.12 — the only minor to bump twice; 8.1 went from 10.37 to 10.39 in 8.1.1, one patch release after its debut.
- **PHP 8.5 repeats its predecessor's engine to the byte** — same 10.44, but php-src no longer passes `PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK` when it compiles your patterns; the [same version, different dialect](#same-engine-version-different-dialect) section shows the pattern that proves it.

> **Note:** the 8.4 row hides a trap for anyone reading php.net's own changelog: the PCRE2 bumps to 10.43 and 10.44 are listed there under PHP 8.4.**1**, yet the `php-8.4.0` tag already carries version 10.44 in its bundled library header, both upstream commits predate the PHP 8.4 branch cut, and the first 8.4 alpha tag already carries it too. **The tag, not the changelog entry, is the source of truth** — one reason this table was verified against the tags first and the running images second.

> **Note:** the 8.6 row is a snapshot, not a promise. The timetable on wiki.php.net puts GA at 19 November 2026; the branch bundles 10.44 as of 16 October 2026, and php-src has pulled an engine bump back before — master took 10.45 in February 2025, took 10.46 in August 2025, and reverted both in November 2025, leaving 8.5, 8.6 and master all on 10.44. Nothing past 10.44 has ever shipped inside a PHP release, so the constructs the 10.45+ engines advertise are reachable in PHP only through an externally linked PCRE2.

## How to know what you are running

The obvious probe lies. `phpversion()` is supposed to return an extension's version, but for `pcre` it returns the PHP version, not the engine's. Three probes, two answers:

```php
<?php
var_dump(PHP_VERSION, phpversion('pcre'), PCRE_VERSION);
```
{: data-copy="" }

```text
string(6) "8.4.26"
string(6) "8.4.26"
string(16) "10.49 2026-09-28"
```

The first two lines print the same string — both PHP's version. The third, `PCRE_VERSION`, carries the engine's version and release date — 10.49 here, which no stock PHP build ships. One `php -i` line names the library and how PHP got it:

```console
$ php -i | grep 'PCRE Library Version'
PCRE Library Version => 10.49 2026-09-28
```
{: data-copy="" }

That machine runs 10.49 on a PHP whose stock builds bundle 10.44 — the configure line shows `--with-external-pcre`, so this PHP links the system PCRE2 the same way distribution packages do. Bundled and linked engines answer to different upgrade clocks: the bundled one moves only with a PHP release, the linked one moves with the distribution. When two machines that report the same PHP version disagree about a pattern, this is the first line to check — and `PCRE_VERSION` is the documented constant to check it with.

## What PCRE2 10.43 bought

One construct separates PHP 8.3 from 8.4 in daily pattern writing: the bounded variable-length lookbehind from the opening. PCRE2 10.43 accepts a lookbehind branch that matches a range of lengths, up to 255 characters by default, which is what lets a lookbehind say "one to three `a` — then require `f`" in a single assertion:

```php
<?php
// Refused while the engine is 10.40 or 10.42 — what PHP 8.2 and 8.3 bundle.
// Matched from 10.43 — the engine PHP 8.4 bundles.
preg_match('/(?<=a{1,3})f/', 'aaaf', $matches);
```

A myth worth killing while we are here, because it circulates among people who should know better: **alternatives of different fixed lengths do not need 10.43**. `(?<=ab|cde)` — two branches, lengths 2 and 3 — compiles and matches on PCRE2 10.40 in the official PHP 8.2 image:

```php
<?php

echo PHP_VERSION, ' / PCRE2 ', PCRE_VERSION, "\n";

// Two branches, two fixed lengths — 2 and 3.
var_dump(preg_match('/(?<=ab|cde)f/', 'abf', $m), $m);
```
{: data-file="mixed-lengths.php" data-copy="" }

```console
$ docker run --rm -i php:8.2-cli php < mixed-lengths.php
8.2.34 / PCRE2 10.40 2022-04-14
int(1)
array(1) {
  [0]=>
  string(1) "f"
}
```
{: data-copy="" }

What 10.43 added is a branch that is itself not one length, like `a{1,3}`. If you have been avoiding mixed-length alternatives out of compatibility caution, you were giving up nothing.

> **Warning:** if you have been writing `{1,3}` inside lookbehinds and shipping to PHP 8.2, you have a `false` return and a warning waiting in production.

The construct-by-construct version table lives in [PCRE vs other engines](../concepts/pcre.md); which analysis layer reads which construct is the [feature support matrix](../reference/feature-support-matrix.md). When either page and this article ever differ, those pages win.

## Same engine version, different dialect

The matrix has one divergence no version number explains. This file compiles on PHP 8.4 and returns `bc`, not `c`:

```php
<?php

echo PHP_VERSION, ' / PCRE2 ', PCRE_VERSION, "\n";

// \K inside the lookbehind — an assertion, not part of the match.
$r = preg_match('/(?<=a\Kb)c/', 'abc', $m);
var_dump($r, $m);
```
{: data-file="keep.php" data-copy="" }

```console
$ docker run --rm -i php:8.4-cli php < keep.php
8.4.26 / PCRE2 10.44 2024-06-07
int(1)
array(1) {
  [0]=>
  string(2) "bc"
}
```
{: data-copy="" }

The `\K` sits inside the lookbehind — an assertion, which is not part of the match — and still resets where the reported match starts. PHP 8.5 refuses the same file on the same PCRE2 10.44:

```console
$ docker run --rm -i php:8.5-cli php < keep.php
8.5.11 / PCRE2 10.44 2024-06-07

Warning: preg_match(): Compilation failed: \K is not allowed in lookarounds (but see PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK) at offset 10 in Standard input code on line 6
bool(false)
NULL
```
{: data-copy="" }

The difference is one extra option. PCRE2 10.38 introduced a lockout — `\K` inside a lookaround became a compile error by default, and `PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK` is the option that restores the old behaviour. The engines PHP bundled through 8.1.0 predate the lockout, so `\K` was allowed there by default; from PHP 8.1.1, the first release whose bundled engine knows the option, through 8.4, php-src passes it; PHP 8.5 stops passing it, and the default takes over. A `\K` inside a lookaround is therefore a PHP 8.5 compatibility break hiding behind an unchanged engine version, and the match it produced on 8.4 was never a shape to build on: the reported match starts where an assertion, not the match path, reset it.

## Judging a pattern for a fleet you do not run

Reading the matrix is one thing; enforcing it in a codebase is another. The parser that judges these patterns ships in PHPRegex, and `compatibility()` judges a pattern at every point the library's own behavior changes at — every PHP gate, every PCRE2 release from 10.39 to 10.48 — so the oldest engine in your fleet is asked, not assumed:

{% include install-prerelease.html package="php-regex/regex-toolkit" %}

```php
<?php
use PHPRegex\Toolkit\Regex;

// The facade also accepts a pinned target: validation, lint and the other
// checks then judge for PHP 8.2 / PCRE2 10.40, not for this machine.
$regex = Regex::create(['php_version' => '8.2', 'pcre_version' => '10.40']);

$compatibility = $regex->compatibility('/(?<=a{1,3})f/');
var_dump($compatibility->isValidEverywhere());
```

```text
bool(false)
```

The ranged-lookbehind pattern comes back with the exact rule it breaks:

```text
Variable-length lookbehind needs PCRE2 10.43, which PHP bundles from 8.4.
```

The `\K` pattern from the previous section gets its verdict in the same vocabulary, with the fix attached:

```text
\K is not allowed in a lookaround from PHP 8.5, which compiles without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK.
Hint: Move the \K out of the lookaround.
```

The complete script, with the report loop and the verdict listing, is [runnable in the repository](https://github.com/php-regex/php-regex/blob/2.x/demo/parser/version-targets.php).

## How this table stays true

The matrix was not assembled from blog posts. Each cell for a released PHP was checked against the bundled library header — `ext/pcre/pcre2lib/pcre2.h` — at the exact release tags of php-src, then confirmed by running the engine inside the official `php:7.3-cli` through `php:8.5-cli` images on 16 October 2026. The 8.6 row rests on the branch header alone — no tag and no image exist until the release does. Release dates come from php.net's own release data; the PCRE2 feature dates come from the upstream ChangeLog; the 8.6 schedule comes from the php.net timetable page.

From its floor — PHP 8.1.1, judged as PCRE2 10.39 — the map is the one the parser's target judging uses, so a drifted 8.1-to-8.6 row would fail the test suite rather than mislead a reader; the older rows rest on the tag checks alone. Before PHP 8.6 goes GA on 19 November 2026, the last row gets re-checked against the branch — engine bumps have been reverted upstream before, and a matrix that goes stale silently is worse than no matrix.

Sources, for checking rather than trusting:

- php-src release tags and their bundled `pcre2.h`: [github.com/php/php-src](https://github.com/php/php-src)
- PHP release changelogs and dates: [php.net/ChangeLog-8.php](https://www.php.net/ChangeLog-8.php), [php.net/ChangeLog-7.php](https://www.php.net/ChangeLog-7.php)
- The PCRE2 migration: [RFC: PCRE2 migration](https://wiki.php.net/rfc/pcre2-migration) and the UPGRADING note in the `php-7.3.0` tag
- PCRE2 releases and dates: [the PCRE2 ChangeLog](https://github.com/PCRE2Project/pcre2/blob/master/ChangeLog)
- The PHP 8.6 timetable: [wiki.php.net/todo/php86](https://wiki.php.net/todo/php86)
- `PCRE_VERSION` and the other PCRE constants: [php.net/pcre.constants](https://www.php.net/pcre.constants)

## Recap

You now understand:

- which PCRE2 every PHP from 7.3 to 8.5 bundles, and that the 8.6 branch held 10.44 at verification — with the two mid-series bumps (7.4, 8.1) that break the "one minor, one engine" assumption
- that `phpversion('pcre')` reports the PHP version, and `PCRE_VERSION` or `php -i` reports the engine — the first thing to compare when two machines on "the same PHP" disagree
- that bounded variable lookbehind needs PCRE2 10.43 — every stock PHP from 8.4 bundles it — that mixed-length *alternatives* never needed it, and that `\K` inside a lookaround is a PHP 8.5 break with no engine version attached
- how to have every verdict judged for the fleet's oldest engine instead of the machine you are on

**Next:** the construct-by-construct version tables live in [PCRE vs other engines](../concepts/pcre.md); the [feature support matrix](../reference/feature-support-matrix.md) maps each construct to the analysis layers that read it.

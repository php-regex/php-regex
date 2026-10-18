---
date: 2026-07-14 15:47:00 +00:00
last_modified_at: 2026-10-16 12:00:00 +00:00
author: younes
published: false
tags: [PCRE2, Compatibility]
description: "Which PCRE2 version each PHP release bundles, from 7.3 to 8.6, and how to check yours with PCRE_VERSION, since phpversion('pcre') prints the PHP version; then why a lookbehind fails on 8.3 but not 8.4, and why 8.5 refuses \\K in lookarounds."
---
# Which PCRE2 ships with which PHP, and how to check yours

PHP 8.4 and 8.5 run every `preg_*` call on PCRE2 10.44. PHP 8.3 runs them on 10.42, and PHP 8.2 on 10.40. This is why `/(?<=a{1,3})f/` works on PHP 8.4 but fails on 8.3 with "Compilation failed: lookbehind assertion is not fixed length". To see the engine your PHP really uses, print `PCRE_VERSION`: `phpversion('pcre')` gives you the PHP version instead.

## Before you start

- **You should already know:** what `preg_match()` does, and what a lookbehind `(?<=…)` is ([the lookarounds chapter](../tutorial/06-lookarounds.md) teaches them). Every other term — bundled, external linking, lookaround — is defined where it first appears. New to regex? Start with the [basics chapter](../tutorial/01-basics.md).
- **You will learn:** which PCRE2 version your PHP really runs, how to check it in one line, and the patterns that change between PHP versions.
- **Running the examples:** plain PHP 8.1 or newer, nothing to install; the side-by-side runs also use Docker and the official `php:X.Y-cli` images. Reading time: about 12 minutes.

## One pattern, two PHP versions

PCRE2 is the regex engine inside PHP. It is the library that compiles and runs every pattern you pass to `preg_match()`, `preg_replace()` and the other `preg_*` functions. PHP ships its own copy of it, the *bundled* PCRE2, and each PHP release picks one version.

This file uses a lookbehind: `(?<=…)`, a check that some text comes right before the current position. That text is not part of the match ([the lookarounds chapter](../tutorial/06-lookarounds.md) teaches them). Here the lookbehind asks for one, two or three `a` before the `f`.

```php
<?php

echo PHP_VERSION, ' / PCRE2 ', PCRE_VERSION, "\n";

// One, two or three "a" before the "f": the lookbehind has no single length.
var_dump(preg_match('/(?<=a{1,3})f/', 'aaf'));
```
{: data-file="lookbehind.php" data-copy="" }

Run the same file on PHP 8.3, then on PHP 8.4:

```console
$ docker run --rm -i php:8.3-cli php < lookbehind.php
8.3.35 / PCRE2 10.42 2022-12-12

Warning: preg_match(): Compilation failed: lookbehind assertion is not fixed length at offset 0 in Standard input code on line 6
bool(false)
$ docker run --rm -i php:8.4-cli php < lookbehind.php
8.4.26 / PCRE2 10.44 2024-06-07
int(1)
```
{: data-copy="" }

The file did not change; the engine did. On PHP 8.3, `preg_match()` returns `false` with a warning: `false` means the engine refused the pattern, not "no match". PHP 8.4 matches, because PCRE2 10.43 (16 February 2024) added lookbehinds whose length can vary, up to 255 characters.

So your regex *dialect*, the set of patterns the engine accepts, depends on your PHP version. More exactly, it depends on the PCRE2 that your PHP runs, as the next sections show.

## Which PCRE2 does each PHP version bundle?

| PHP | Released | PCRE2 in `.0` | Changes during the series | Last release checked |
|---|---|---|---|---|
| 7.3 | 2018-12-06 | 10.32 | none | 7.3.33 |
| 7.4 | 2019-11-28 | 10.33 | 10.34 in 7.4.6, 10.35 in 7.4.12 | 7.4.33 |
| 8.0 | 2020-11-26 | 10.35 | none | 8.0.30 |
| 8.1 | 2021-11-25 | 10.37 | 10.39 from 8.1.1 | 8.1.34 |
| 8.2 | 2022-12-08 | 10.40 | none | 8.2.34 |
| 8.3 | 2023-11-23 | 10.42 | none | 8.3.35 |
| 8.4 | 2024-11-21 | 10.44 | none | 8.4.26 |
| 8.5 | 2025-11-20 | 10.44 | none | 8.5.11 |
| 8.6 | planned for 2026-11-19 | 10.44 in 8.6.0RC3 | — | 8.6.0RC3 |

<figure class="pipeline-figure" role="group" aria-labelledby="timeline-chart-caption">
<svg viewBox="0 0 640 250" role="img" aria-label="Step chart of the PCRE2 version each PHP release bundles, from 10.32 in PHP 7.3 up to 10.44 in PHP 8.4, then flat through 8.5 and the 8.6 release candidate.">
  <title>The engine staircase, and its flat tail</title>
  <g stroke="var(--line-soft)" stroke-width="1">
    <line x1="70" y1="190" x2="610" y2="190"/>
    <line x1="70" y1="147.5" x2="610" y2="147.5"/>
    <line x1="70" y1="105" x2="610" y2="105"/>
    <line x1="70" y1="62.5" x2="610" y2="62.5"/>
    <line x1="70" y1="20" x2="610" y2="20"/>
  </g>
  <polyline points="70,190 137.5,168.75 205,147.5 272.5,126.25 340,62.5 407.5,41.25 475,20 610,20" fill="none" stroke="var(--ink)" stroke-width="2"/>
  <polyline points="475,20 610,20" fill="none" stroke="var(--amber)" stroke-width="2"/>
  <g fill="var(--ink)">
    <circle cx="70" cy="190" r="4"/>
    <circle cx="137.5" cy="168.75" r="4"/>
    <circle cx="205" cy="147.5" r="4"/>
    <circle cx="272.5" cy="126.25" r="4"/>
    <circle cx="340" cy="62.5" r="4"/>
    <circle cx="407.5" cy="41.25" r="4"/>
  </g>
  <circle cx="475" cy="20" r="4" fill="var(--amber)"/>
  <circle cx="542.5" cy="20" r="4" fill="var(--amber)"/>
  <circle cx="610" cy="20" r="4" fill="var(--amber)"/>
  <g style="font-family: var(--font-mono); font-size: 11px;" text-anchor="end" fill="var(--ink-soft)">
    <text x="62" y="194">10.32</text>
    <text x="62" y="151.5">10.35</text>
    <text x="62" y="109">10.37</text>
    <text x="62" y="66.5">10.40</text>
    <text x="62" y="24">10.44</text>
  </g>
  <g style="font-family: var(--font-mono); font-size: 12px;" text-anchor="middle" fill="var(--ink-soft)">
    <text x="70" y="212">7.3</text>
    <text x="137.5" y="212">7.4</text>
    <text x="205" y="212">8.0</text>
    <text x="272.5" y="212">8.1</text>
    <text x="340" y="212">8.2</text>
    <text x="407.5" y="212">8.3</text>
    <text x="475" y="212">8.4</text>
    <text x="542.5" y="212">8.5</text>
    <text x="610" y="212">8.6&thinsp;RC</text>
    <text x="340" y="238">the engine each PHP bundles at its .0 release</text>
  </g>
</svg>
<figcaption id="timeline-chart-caption">
  Every PHP climbs one step of the staircase; from 8.4 on, nobody has &mdash; the amber tail is two releases and a candidate long, and no PHP has ever bundled a PCRE2 newer than 10.44. The two mid-series bumps (7.4 twice, 8.1 once) are in the table above.
</figcaption>
</figure>

Three things the table tells you:

- **PHP 7.3 is the first PHP with PCRE2.** PHP 7.2 and older used the original PCRE library, which ended at 8.41 in PHP 7.2. [Why PCRE2 is a separate library](why-pcre2-is-a-separate-library.md) tells that story.
- **Only two series changed engine after their first release:** 7.4 (twice) and 8.1 (once, in 8.1.1). For every other series, the patch number does not change the engine.
- **No PHP release bundles anything newer than PCRE2 10.44**, released on 7 June 2024. PHP 8.5 kept it, and so does the 8.6.0 release candidate. Newer engines reach PHP only through a system library, as shown below.

## How do I check which PCRE2 my PHP uses?

Ask PHP three questions and compare the answers. The last two lines show how to compare engine versions in code.

```php
<?php

echo 'PHP_VERSION        ', PHP_VERSION, "\n";
echo "phpversion('pcre') ", phpversion('pcre'), "\n";
echo 'PCRE_VERSION       ', PCRE_VERSION, "\n";

// Compare engine versions with the two number constants.
$engine = PCRE_VERSION_MAJOR.'.'.PCRE_VERSION_MINOR;
var_dump(version_compare($engine, '10.43', '>='));
```
{: data-file="which-engine.php" data-copy="" }

Here it runs on a Mac with PHP from Homebrew, then in the official PHP 8.4 image:

```console
$ php which-engine.php
PHP_VERSION        8.4.26
phpversion('pcre') 8.4.26
PCRE_VERSION       10.49 2026-09-28
bool(true)
$ docker run --rm -i php:8.4-cli php < which-engine.php
PHP_VERSION        8.4.26
phpversion('pcre') 8.4.26
PCRE_VERSION       10.44 2024-06-07
bool(true)
```
{: data-copy="" }

The first two lines are always the same: `phpversion('pcre')` returns the PHP version, not the engine version. Only `PCRE_VERSION` names the engine, with its release date. And look at the two machines: same PHP 8.4.26, two different engines.

> **Warning:** `version_compare(phpversion('pcre'), '10.43', '>=')` is `false` on every PHP 8 release, because `8.x` is lower than `10.x`. A feature check written that way never turns on. Use `PCRE_VERSION_MAJOR` and `PCRE_VERSION_MINOR`, as above.

## Bundled, or the system's PCRE2?

The Homebrew PHP above does not use the bundled copy. It uses the PCRE2 installed on the system, 10.49. This is called *external linking*, and PHP does it when it is built with the `--with-external-pcre` option. You can look for that option in the build settings:

```console
$ php -i | grep -o -- --with-external-pcre
--with-external-pcre
```
{: data-copy="" }

The same command prints nothing in the official `php:8.4-cli` image, which uses the bundled copy. Linux packages often link the system library too. Alpine's `php84` package is one example, checked on 16 October 2026:

```console
$ docker run --rm alpine:3.22 sh -c 'apk add -q php84 && php84 -r "echo PHP_VERSION, \" / PCRE2 \", PCRE_VERSION, PHP_EOL;"'
8.4.16 / PCRE2 10.46 2025-08-27
```
{: data-copy="" }

So the engine can change in two ways. A bundled engine changes only when you upgrade PHP. A linked engine changes when the system updates its PCRE2 package, even if PHP stays the same.

The practical reading: the table tells you what a php.net build or an official Docker image runs. For any other build, `PCRE_VERSION` is the only reliable answer. A linked engine can also be *older* than the bundled one; then it refuses patterns that the table says your PHP accepts.

## How do I fix "lookbehind assertion is not fixed length"?

If your code must also run on PHP 8.3 or older, give the lookbehind branches that each have one fixed length. A *branch* is one alternative between `|` signs. `(?<=a|aa|aaa)` has three branches, of length 1, 2 and 3:

```php
<?php

echo PHP_VERSION, ' / PCRE2 ', PCRE_VERSION, "\n";

// Three branches, each with one fixed length: 1, 2 and 3.
var_dump(preg_match('/(?<=a|aa|aaa)f/', 'aaf'));
```
{: data-file="lookbehind-split.php" data-copy="" }

```console
$ docker run --rm -i php:8.2-cli php < lookbehind-split.php
8.2.34 / PCRE2 10.40 2022-04-14
int(1)
```
{: data-copy="" }

PHP 8.2, with PCRE2 10.40, accepts it and matches. Branches of *different* fixed lengths were always allowed. What PCRE2 10.43 added is a single branch whose own length varies, like `a{1,3}` or `ab?`.

> **Note:** a lookbehind with no maximum length, such as `(?<=a+)`, is refused on every PHP version, 8.5 included. The error is "length of lookbehind assertion is not limited". PCRE2 10.43 allows a length that varies, but it still needs a maximum.

## Same engine, new rule: `\K` in PHP 8.5

PHP 8.5 bundles exactly the same PCRE2 10.44 as PHP 8.4. Yet one kind of pattern works on 8.4 and fails on 8.5. It uses `\K`, which means "start the reported match here". The text before `\K` must be present, but it is not part of the result.

```php
<?php

echo PHP_VERSION, ' / PCRE2 ', PCRE_VERSION, "\n";

// \K inside a lookbehind: "start the reported match here".
var_dump(preg_match('/(?<=a\Kb)c/', 'abc', $m), $m[0] ?? null);

// The same match without \K: "bc", when an "a" comes before it.
var_dump(preg_match('/(?<=a)bc/', 'abc', $m), $m[0] ?? null);
```
{: data-file="keep.php" data-copy="" }

```console
$ docker run --rm -i php:8.4-cli php < keep.php
8.4.26 / PCRE2 10.44 2024-06-07
int(1)
string(2) "bc"
int(1)
string(2) "bc"
$ docker run --rm -i php:8.5-cli php < keep.php
8.5.11 / PCRE2 10.44 2024-06-07

Warning: preg_match(): Compilation failed: \K is not allowed in lookarounds (but see PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK) at offset 10 in Standard input code on line 6
bool(false)
NULL
int(1)
string(2) "bc"
```
{: data-copy="" }

On PHP 8.4, both patterns return `bc`. On PHP 8.5, the first one fails to compile. A *lookaround* is a lookbehind or a lookahead `(?=…)`, and PCRE2 10.38 (1 October 2021) made `\K` inside one an error by default. It also added an option, `PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK`, to allow it again.

Up to 8.4, PHP passed that option, so `\K` kept working. PHP 8.5 compiles your patterns without it, as its UPGRADING notes say. The fix is to move `\K` out of the lookaround: `(?<=a)bc` returns the same `bc` on both versions. The lesson: the engine version is not the whole dialect, because the options PHP passes to the engine count too.

## Try it yourself

- Run the probe above on every `php:X.Y-cli` image you deploy to, and keep the output next to your `composer.json`: that table is your compatibility contract.
- Lint a real pattern from your own project for your oldest server: `vendor/bin/regex lint src/ --php-version=8.2`.
- Add `/(?<=a\Kb)c/` to a project that targets PHP 8.5, and read the linter's answer before your users find it for you.

## How this was measured

The table was checked on 16 October 2026 in two ways. First, the bundled library header `ext/pcre/pcre2lib/pcre2.h` at each php-src release tag, and on the PHP-8.6 branch. Second, a run of each official `php:X.Y-cli` image, with its bundled engine and default settings. You can repeat the second check for PHP 8.1 and newer with this probe:

```php
<?php

// 1 = the pattern compiled and matched; false = the engine refused it.
$try = fn (string $pattern, string $subject) => var_export(@preg_match($pattern, $subject), true);

printf(
    "%-9s %-17s ranged=%-5s split=%-5s keep=%s\n",
    PHP_VERSION,
    PCRE_VERSION,
    $try('/(?<=a{1,3})f/', 'aaf'),
    $try('/(?<=a|aa|aaa)f/', 'aaf'),
    $try('/(?<=a\Kb)c/', 'abc'),
);
```
{: data-file="matrix.php" data-copy="" }

```console
$ for v in 8.1 8.2 8.3 8.4 8.5 8.6-rc; do docker run --rm -i php:$v-cli php < matrix.php; done
8.1.34    10.39 2021-10-29  ranged=false split=1     keep=1
8.2.34    10.40 2022-04-14  ranged=false split=1     keep=1
8.3.35    10.42 2022-12-12  ranged=false split=1     keep=1
8.4.26    10.44 2024-06-07  ranged=1     split=1     keep=1
8.5.11    10.44 2024-06-07  ranged=1     split=1     keep=false
8.6.0RC3  10.44 2024-06-07  ranged=1     split=1     keep=false
```
{: data-copy="" }

Each line is one PHP version with its engine. The ranged lookbehind starts to work at PHP 8.4. The split lookbehind works everywhere. The `\K` pattern stops working at PHP 8.5, on the same engine.

The same probe prints 10.35 for PHP 7.4.33. PHP 7.3 has no `fn`; with a plain function instead, PHP 7.3.33 prints 10.32. Images get new patch releases, so a later run may print higher patch numbers.

## Check your patterns with PHPRegex

Both patterns that failed above compile on the Mac used here: PCRE2 10.49, and a PHP 8.4 that still allows `\K` in lookarounds. Your laptop says "fine", and a server on PHP 8.3 or 8.5 says otherwise. To catch this before deployment, you need to judge patterns for the servers, not for the machine you run.

PHPRegex, the library behind this site, does that. It reads the regex patterns in your PHP files and judges each one for a target PHP and PCRE2 version. Its command-line tool is a dev dependency:

{% include install-prerelease.html package="php-regex/php-regex" dev=true inline=true %}

Take a project whose `composer.json` requires `"php": ">=8.2"`, with this file:

```php
<?php

var_dump(preg_match('/(?<=a{1,3})f/', 'aaf'));
var_dump(preg_match('/(?<=a\Kb)c/', 'abc'));
```
{: data-file="lib/patterns.php" data-copy="" }

Plain `php lib/patterns.php` prints `int(1)` twice on the Mac above. The linter does not agree:

```console
$ vendor/bin/regex lint lib/
PHPRegex 2.0.0-DEV by Younes ENNAJI

Runtime   : PHP 8.4.26, PCRE2 10.49
Target    : PHP 8.2, PCRE2 10.40 (composer.json require.php)
Processes : 10
PCRE JIT  : 1
Backtrack : 1000000
Recursion : 100000

  [1/3] Scanning files
  Scanned 1 files, found 2 patterns.

  [2/3] Analyzing patterns
  [3/3] Suggesting optimizations
  lib/patterns.php:3:21
      → /(?<=a{1,3})f/
    FAIL Variable-length lookbehind needs PCRE2 10.43, which PHP bundles from 8.4.
         ↳         (?<=a{1,3})f
                   ^
         ↳ Give each branch of the lookbehind a fixed length, or target PHP 8.4+.

  lib/patterns.php:4:21
      → /(?<=a\Kb)c/
    FAIL On PHP 8.5 and later: \K is not allowed in a lookaround from PHP 8.5, which compiles without PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK.
         ↳         (?<=a\Kb)c
                             ^
         ↳ Up to PHP 8.4 a \K in a lookaround resets the start of the match from inside it: "(?=\Ka)" matches an empty string in "a", and "(?<=\Ka)b" matches "ab" in "ab". No spelling keeps that in general: r...


  FAIL 2 invalid patterns, 0 warnings, 0 optimizations.
  Time: 0.07s  Memory: 12 MB  Processes: 2
  If PHPRegex helps, a GitHub star is appreciated: https://github.com/php-regex/php-regex
```
{: data-copy="" }

The `Target` line shows what happened. The tool read the oldest PHP that `composer.json` allows, 8.2, with the PCRE2 it bundles, 10.40. It refused the ranged lookbehind there. It also checked the later PHP versions where a rule changes, and found the PHP 8.5 `\K` break.

The command exits with code 1, so it can fail a CI job. The `Processes`, `Time` and `Memory` lines depend on your machine.

If your servers link a system PCRE2, add `--pcre-version=10.46` (or whatever `PCRE_VERSION` prints there). PHPRegex judges PHP 8.1 and newer with PCRE2 10.39 and newer; a lower floor is judged as PHP 8.1, and the command says so. The [CLI guide](../guides/cli.md#target-php-and-pcre2) lists every way to set the target. To run the same check from PHP code, see the [version targets demo](https://github.com/php-regex/php-regex/blob/2.x/demo/parser/version-targets.php).

## Sources

Sources, for checking rather than trusting:

- [php-src on GitHub](https://github.com/php/php-src): each release tag holds the bundled engine's version in `ext/pcre/pcre2lib/pcre2.h`. The tag, not a changelog entry, is the primary source for what a release ships.
- [PHP 8.5.0 UPGRADING](https://github.com/php/php-src/blob/php-8.5.0/UPGRADING): the PCRE entry that drops `PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK`.
- [The PCRE2 ChangeLog](https://github.com/PCRE2Project/pcre2/blob/master/ChangeLog): release dates, the 10.43 entry adding variable-length lookbehind, the 10.38 entry locking out `\K` in lookarounds.
- [PHP 8.6 timetable](https://wiki.php.net/todo/php86): the planned release date.
- [PCRE constants on php.net](https://www.php.net/manual/en/pcre.constants.php): `PCRE_VERSION`, `PCRE_VERSION_MAJOR` and `PCRE_VERSION_MINOR`.

## Recap

You now understand:

- Your PHP version picks the bundled PCRE2: 10.40 on PHP 8.2, 10.42 on 8.3, 10.44 on 8.4 and 8.5. No PHP release bundles anything newer.
- A PHP linked to a system PCRE2 runs that library instead, so the same PHP version can run different engines.
- `PCRE_VERSION` names the engine; `phpversion('pcre')` only repeats the PHP version.
- "lookbehind assertion is not fixed length" means an engine older than 10.43; split the lookbehind into fixed-length branches.
- PHP 8.5 refuses `\K` inside a lookaround on the same engine as 8.4; move the `\K` out.

**Next:** other constructs also depend on the PCRE2 release, such as loose `\p{...}` property names and `a{,2}`. [PCRE vs other engines](../concepts/pcre.md#pcre-version-in-php) lists each one with the PHP version it needs.

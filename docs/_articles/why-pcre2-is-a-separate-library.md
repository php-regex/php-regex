---
date: 2026-10-16 15:38:00 +00:00
author: younes
published: false
description: "PCRE2 is a separate library with a new API, not PCRE 9; the old PCRE ended at 8.45 in 2021. Check which one your PHP runs and which patterns PHP 7.3 broke or let through, measured in the official PHP 7.2, 7.3 and 7.4 images."
---
# PCRE vs PCRE2: why PCRE2 is a separate library

PCRE2 is not a new version of PCRE. It is a separate library, first released as 10.00 on 5 January 2015, and programs written for the old PCRE cannot use it without changes. The old PCRE is finished: its last release, 8.45, came out on 15 June 2021. PHP switched in 7.3.0, and a few patterns changed in both directions: some stopped compiling, some started.

The snippets run with plain PHP 7.2 or newer, with nothing to install. To compare PHP versions, the article runs them in the official `php:<version>-cli` Docker images; only the last section needs Composer and PHP 8.1.

## Which library does my PHP use?

The constant `PCRE_VERSION` holds the version of the regex engine inside your PHP — the code that reads and runs your patterns. Its first number tells you which library it is: 8 for the old PCRE, 10 for PCRE2.

```php
<?php

// PCRE_VERSION is the engine's version; 8.x is the old PCRE, 10.x is PCRE2.
$library = (int) PCRE_VERSION >= 10 ? 'PCRE2' : 'PCRE (the old library)';

echo 'PHP ', PHP_VERSION, ' uses ', $library, ' ', PCRE_VERSION, "\n";
```
{: data-file="which-engine.php" data-copy="" }

Here is the same file on three PHP versions:

```console
$ docker run --rm -i php:7.2-cli php < which-engine.php
PHP 7.2.34 uses PCRE (the old library) 8.41 2017-07-05
$ docker run --rm -i php:7.3-cli php < which-engine.php
PHP 7.3.33 uses PCRE2 10.32 2018-09-10
$ docker run --rm -i php:8.4-cli php < which-engine.php
PHP 8.4.26 uses PCRE2 10.44 2024-06-07
```
{: data-copy="" }

PHP 7.2 is the last PHP with the old PCRE. Every PHP from 7.3.0 on reports a 10.x version, so it runs PCRE2. Do not use `phpversion('pcre')` for this check: it returns the PHP version, not the engine's. [Which PCRE2 ships with which PHP](which-pcre2-ships-with-which-php.md) explains why, and lists the engine of every PHP release.

## Why a new library, and not "PCRE 9"?

PCRE stands for *Perl Compatible Regular Expressions*. Philip Hazel first released it in 1997 for the Exim mail server, and it became the engine behind PHP's `preg_*` functions. Over 18 years it grew into the 8.x series.

On 5 January 2015, the same author released PCRE2 10.00. His announcement on the project's mailing list says it plainly: "You should treat this as a new project, not just a drastic update to PCRE1." It also explains the numbers: "We have started the version numbers from 10.00 so as to avoid any confusion with PCRE1 versions."

The change that made it a new project is the API — the set of functions a program calls to use a library. In the announcement's words, PCRE2 "does not replace the pcre-8.36 release, because the API is not compatible." The PCRE2 manual gives the reason: "After nearly two decades, the limitations of the original API were making development increasingly difficult." It adds: "The new API is more extensible."

For a program written in C, that is real work. A match that used to call `pcre_exec()` now calls `pcre2_match()`, with different arguments. PHP itself is such a program. The PHP proposal for the move says the code that uses PCRE "needs to be rewritten to match the PCRE2 API."

For you, as a PHP developer, the functions stay the same: you still call `preg_match()`. The pattern language stays very close too. What can change is how the engine reads a few patterns, as the PHP 7.3 section below shows.

## Was PCRE2 made for speed? No

Two guesses come up often, and both are wrong. The first is speed. The JIT is a compiler that turns a pattern into machine code, so it runs faster. It was already in PCRE 8.20, released on 21 October 2011.

The second is wider text: strings stored in 2 or 4 bytes per unit instead of 1. PCRE 8.30 added 16-bit strings on 4 February 2012, and PCRE 8.32 added 32-bit strings on 30 November 2012. Both features existed years before PCRE2. The only reason the project gives for PCRE2 is the API.

## The old PCRE is finished

The 8.x series did not stop in 2015. The announcement promised that "further 8.xx releases will happen if bugs are fixed", and they did, for six more years. The last one is PCRE 8.45, released on 15 June 2021. Its ChangeLog says: "This is the final release of PCRE1."

The project's website, pcre.org, says the old library "is now at end of life, and is no longer being actively maintained". It adds that "new projects should use PCRE2 instead." The old source code sits in a read-only (archived) repository on GitHub, next to the active PCRE2 one.

Finished does not mean gone: programs built against the old library keep running it. On the Mac used for this article, Homebrew keeps `pcre` 8.45 next to `pcre2` 10.49, as a dependency of The Silver Searcher (`ag`). PHP 7.2 and older run the old PCRE too, but PHP stopped at 8.41 and never shipped 8.45.

## What changed when PHP 7.3 switched?

PHP 7.3.0, released on 6 December 2018, was the first PHP with PCRE2, version 10.32. The proposal for the move passed 26 votes to 0, in a vote that closed on 13 November 2017. PHP's upgrade notes for 7.3 warned in one line: "character ranges in classes are now more strictly interpreted".

A character class is a list of allowed characters in square brackets, like `[abc]`. Inside it, a hyphen between two characters makes a range: `[a-z]` means any letter from `a` to `z` (the [character classes chapter](../tutorial/02-character-classes.md#character-ranges) covers the rules).

Before it can match, the engine compiles the pattern: it checks the syntax and turns it into an internal program. This probe compiles three patterns and prints what `preg_match()` returns. The first is an ordinary pattern, as a control that should give the same result everywhere:

```php
<?php

echo 'PHP ', PHP_VERSION, ' / ', PCRE_VERSION, "\n";

foreach (['/\d+/', '/[\w-.]+/', '/[a-\d]+/'] as $pattern) {
    error_clear_last();
    $result = @preg_match($pattern, 'abc-123.txt');
    $error = error_get_last()['message'] ?? '';

    printf("%-10s %-6s %s\n", $pattern, var_export($result, true), $error);
}
```
{: data-file="boundary.php" data-copy="" }

The same file on the last PHP with PCRE, the first with PCRE2, and the next one:

```console
$ docker run --rm -i php:7.2-cli php < boundary.php
PHP 7.2.34 / 8.41 2017-07-05
/\d+/      1      
/[\w-.]+/  1      
/[a-\d]+/  false  preg_match(): Compilation failed: invalid range in character class at offset 4
$ docker run --rm -i php:7.3-cli php < boundary.php
PHP 7.3.33 / 10.32 2018-09-10
/\d+/      1      
/[\w-.]+/  false  preg_match(): Compilation failed: invalid range in character class at offset 3
/[a-\d]+/  1      
$ docker run --rm -i php:7.4-cli php < boundary.php
PHP 7.4.33 / 10.35 2020-05-09
/\d+/      1      
/[\w-.]+/  false  preg_match(): Compilation failed: invalid range in character class at offset 3
/[a-\d]+/  false  preg_match(): Compilation failed: invalid range in character class at offset 5
```
{: data-copy="" }

The control matches on every engine: most patterns did not change. The other two changed in opposite directions, and each one is a different kind of bug.

### `[\w-.]` worked on PHP 7.2 and fails from 7.3

`\w` is a shorthand for "any word character": a letter, a digit or `_`. A range from `\w` to `.` makes no sense, so the old PCRE read the hyphen as a plain `-`. On PHP 7.2, `[\w-.]` means "a word character, a hyphen or a dot". PCRE2 refuses it: `preg_match()` returns `false` with the warning `Compilation failed: invalid range in character class`.

> **Warning:** `false` does not mean "no match". It means the pattern could not run at all. Code like `if (!preg_match('/^[\w-.]+$/', $name))` reads `false` like `0`, so after the upgrade it rejects every file name, with only a warning in the log. The [error handling section of the regex guide](../guides/regex-in-php.md#error-handling) shows how to tell the two apart.

The fix is to move the hyphen to the start or the end of the class, where it is always a plain character. `[\w.-]` means the same thing on every engine:

```php
<?php

echo 'PHP ', PHP_VERSION, ' / ', PCRE_VERSION, "\n";

// The hyphen moved to the end of the class: now it is always a literal "-".
foreach (['abc-123.txt', 'abc 123'] as $subject) {
    printf("%-12s %s\n", $subject, var_export(preg_match('/^[\w.-]+$/', $subject), true));
}
```
{: data-file="fixed.php" data-copy="" }

```console
$ docker run --rm -i php:7.2-cli php < fixed.php
PHP 7.2.34 / 8.41 2017-07-05
abc-123.txt  1
abc 123      0
$ docker run --rm -i php:8.4-cli php < fixed.php
PHP 8.4.26 / 10.44 2024-06-07
abc-123.txt  1
abc 123      0
```
{: data-copy="" }

Both engines accept the file name and reject the one with a space. One pattern, one meaning, on the old library and the new.

### `[a-\d]` failed on PHP 7.2 and compiled on 7.3 only

The third pattern went the other way. The old PCRE refuses `[a-\d]`, because a range cannot end with a shorthand like `\d` (any digit). PHP 7.3 accepts it, and PHP 7.4 refuses it again. On PHP 7.3, it also does not mean what it looks like:

```php
<?php

echo 'PHP ', PHP_VERSION, ' / ', PCRE_VERSION, "\n";

foreach (['b', 'd', 'e', '5', '-'] as $char) {
    $result = @preg_match('/^[a-\d]$/', $char);
    printf("%s => %s\n", $char, var_export($result, true));
}
```
{: data-file="range-meaning.php" data-copy="" }

```console
$ docker run --rm -i php:7.3-cli php < range-meaning.php
PHP 7.3.33 / 10.32 2018-09-10
b => 1
d => 1
e => 0
5 => 0
- => 0
```
{: data-copy="" }

`b` and `d` match, but the digit `5` does not: on PHP 7.3, `[a-\d]` is the range `[a-d]`. By default, PHP 7.3 compiles patterns with the option `PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL`, which reads a misplaced escape as a plain character. PCRE2 10.32 applied it here and read `\d` as the letter `d`.

PCRE2 10.33 made this an error again; its ChangeLog says such escapes "were being treated as literals". PHP 7.4.0, released on 28 November 2019, bundled 10.33, and every PHP since then refuses the pattern.

This is the quiet direction. A pattern that starts to compile raises no warning, so nothing tells you that it runs with a meaning nobody chose.

## What should I check before upgrading PHP?

The steps are the same whether you cross the PCRE to PCRE2 line or move between two PCRE2 versions:

1. Print `PCRE_VERSION` on the PHP you leave and on the PHP you move to. A move from 8.x to 10.x crosses the line this article describes.
2. Run your tests on the target PHP, for example in its official Docker image, as above. A test that runs a pattern catches both directions.
3. On the new version, look in the logs for warnings that contain `Compilation failed`. Each one is a pattern the new engine refuses.
4. Check the patterns no test runs with a linter — a tool that checks code without running it — set to the target version.

## Checking every pattern for the PHP you deploy to

PHPRegex is a PHP library that reads regex patterns the way PCRE2 does. Its command-line linter finds the patterns in your code and judges each one for the PHP version you name, without running your code.

One limit first: PHPRegex judges PHP 8.1 and newer only, with PCRE2 10.39 or newer. It cannot tell you what PHP 7.2 thought of a pattern. It can tell you what the PHP you move to will think of it.

{% include install-prerelease.html package="php-regex/php-regex" dev=true %}

Here is a file from a project that started on PHP 7.2:

```php
<?php

// Written for PHP 7.2: letters, digits, "_", "-" and ".".
function isSafeFileName(string $name): bool
{
    return preg_match('/^[\w-.]+$/', $name) === 1;
}
```
{: data-file="src/upload.php" data-copy="" }

Lint the `src/` folder for PHP 8.1:

```console
$ vendor/bin/regex lint src/ --php-version=8.1
PHPRegex 2.0.0-DEV by Younes ENNAJI

Runtime   : PHP 8.4.26, PCRE2 10.49
Target    : PHP 8.1, PCRE2 10.39 (--php-version)
Processes : 10
PCRE JIT  : 1
Backtrack : 1000000
Recursion : 100000

  [1/3] Scanning files
  Scanned 1 files, found 1 patterns.

  [2/3] Analyzing patterns
  [3/3] Suggesting optimizations
  src/upload.php:6:23
      → /^[\w-.]+$/
    FAIL Invalid range in character class: a character type, POSIX class, or Unicode property cannot be a range endpoint at position 4.
         ↳         ^[\w-.]+$
                       ^


  FAIL 1 invalid patterns, 0 warnings, 0 optimizations.
  Time: 0.08s  Memory: 12 MB  Processes: 10
  If PHPRegex helps, a GitHub star is appreciated: https://github.com/php-regex/php-regex

$ echo $?
1
```
{: data-copy="" }

The command ran on PHP 8.4.26, but the `Target` line shows what it judged for: PHP 8.1, with the PCRE2 10.39 that PHP 8.1 bundles. It points to line 6, column 23, and gives the reason PCRE2 refuses the range. It exits with code 1, so a CI job (the automatic checks on each push) fails before the pattern reaches production.

The same kind of change did not stop at PHP 7.3. PHP 8.4 accepts some lookbehinds (checks on the text just before a match) that PHP 8.3 refuses. PHP 8.5 refuses `\K`, which resets the start of the match, inside a lookbehind where PHP 8.4 accepts it.

[Which PCRE2 ships with which PHP](which-pcre2-ships-with-which-php.md) lists those changes. [The CLI guide](../guides/cli.md#target-php-and-pcre2) shows the other ways to set the target, including reading it from `composer.json`.

## Sources

Sources, for checking rather than trusting:

- The PCRE2 10.00 announcement by Philip Hazel, 5 January 2015, source of the "new project", "not compatible" and "10.00" quotes: [lists.exim.org](https://lists.exim.org/lurker/message/20150105.162835.0666407a.en.html)
- The reason for the new API, in the project's manual: [pcre2.html](https://pcre.org/current/doc/html/pcre2.html)
- The release history: JIT in 8.20, 16-bit in 8.30, 32-bit in 8.32 and the final 8.45 in the [PCRE1 ChangeLog](https://raw.githubusercontent.com/PCRE2Project/pcre1/trunk/ChangeLog); the 10.33 range fix in the [PCRE2 ChangeLog](https://raw.githubusercontent.com/PCRE2Project/pcre2/main/ChangeLog)
- The end-of-life wording and the 1997 origin: [pcre.org](https://www.pcre.org/); the archived [PCRE2Project/pcre1](https://github.com/PCRE2Project/pcre1) repository
- PHP's side: the [PCRE2 migration RFC](https://wiki.php.net/rfc/pcre2-migration) and its vote; the PCRE note in the [PHP 7.3.0 UPGRADING file](https://github.com/php/php-src/blob/php-7.3.0/UPGRADING); the bundled versions and the 7.3 default option in `ext/pcre/` at the `php-7.2.34`, `php-7.3.0` and `php-7.4.0` tags of [php-src](https://github.com/php/php-src)

All outputs were run on 16 October 2026: the probes in the official images, with bundled engines and default settings, and the lint run on macOS with PHP 8.4.26 linked to PCRE2 10.49.

## Recap

You now understand:

- PCRE2 is a separate library with a new API, not PCRE 9: its numbers start at 10.00 to avoid any confusion.
- The reason was the API; the JIT and the 16-bit and 32-bit strings were already in PCRE 8.x.
- The old PCRE ended with 8.45 on 15 June 2021, and PHP 7.2 was the last PHP to use it.
- PHP 7.3 changed patterns in both directions: `[\w-.]` stopped compiling, and `[a-\d]` started to compile as `[a-d]` until PHP 7.4.
- `PCRE_VERSION` tells you which library runs; tests, or a linter set to your target PHP, tell you which patterns change.

**Next:** [which PCRE2 ships with which PHP](which-pcre2-ships-with-which-php.md) gives the engine in every PHP from 7.3 to 8.6, and the patterns that change between them.

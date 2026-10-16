---
date: 2026-10-16 14:50:00 +00:00
author: younes
published: false
description: "Why PCRE2 is a separate library in a separate repository rather than PCRE 9 — the project's own stated reason, the API break an embedder met, the boundary PHP crossed in 7.3 measured pattern by pattern, and where PCRE1 still runs today."
---
# Why PCRE2 is a separate library, not a new version

Two regex libraries are installed on this machine, side by side, and both are alive:

```console
$ brew list --versions pcre pcre2
pcre 8.45
pcre2 10.49
```
{: data-copy="" }

The Silver Searcher on the same machine links the old one — `otool -L` on its binary shows `libpcre.1.dylib` — while PHP runs the new one, PCRE2 10.49. One library, forked into two, both shipping in binaries you can run today; if that fork reminds you of Doctrine ORM 1 and 2, or Symfony 1 and 2, it should. This is that story, told with the project's own words and a measured pattern-by-pattern walk across the boundary PHP crossed in 7.3.

## The break, in the project's own words

PCRE — Perl Compatible Regular Expressions, by Philip Hazel, written for the Exim mail server and first released in 1997 — grew for eighteen years into the 8.x series. On 5 January 2015 the same author released PCRE2 10.00, and the announcement leaves no room for reading it as an upgrade: "You should treat this as a new project, not just a drastic update to PCRE1. A lot has changed, though the underlying structure of the code is much the same. We have started the version numbers from 10.00 so as to avoid any confusion with PCRE1 versions," it read — and on compatibility, flatly: "This does not replace the pcre-8.36 release, because the API is not compatible."

Compatible it was not: an application embedding PCRE could not drop in PCRE2. The current manual states the reason plainly — not performance, not features, but the interface itself: "After nearly two decades, the limitations of the original API were making development increasingly difficult. The new API is more extensible, and it was simplified by abolishing the separate 'study' optimizing function."

What an embedder met, concretely: `pcre_exec()` became `pcre2_match()`, and the functions grew *contexts* — objects holding the uncommon parameters and the memory-management hooks, so that the API could keep growing without another break. Match results moved into an opaque block you create and query by function, with offsets as `PCRE2_SIZE` and unset slots as `PCRE2_UNSET` instead of raw ints. Patterns gained an explicit length — subjects already had one — and the new replacement strings take one too, so all three may contain binary zeros. The three width libraries carried over, renamed `libpcre2-8`, `-16`, `-32`, now selected from a single header by one `#define PCRE2_CODE_UNIT_WIDTH` — and `pcre2_substitute()` arrived as a first-class find-and-replace.

## What did *not* motivate the fork

A myth worth killing, because it circulates in both directions: **PCRE2 was not a rewrite for the JIT, and not a rewrite for the wider character widths — both predate it.** The JIT entered PCRE1 at 8.20, October 2011, in Zoltan Herczeg's integration; 16-bit strings arrived at 8.30, February 2012, and 32-bit at 8.32, November 2012 — work the PCRE2 manual credits to Zoltan Herczeg and Christian Persch, respectively. The project's only stated motive is the one quoted above: two decades of API limitations. And for anyone wondering whether the language changed — no: C before, C after, the same author, "the underlying structure of the code is much the same."

## One lineage, two repositories, a decade after the fork

The 8.x series did not stop in 2015 — "further 8.xx releases will happen if bugs are fixed," the announcement said, and they did, for six more years. PCRE1's final release is 8.45, 15 June 2021: "expected to be the final release of the older PCRE library, and new projects should use PCRE2 instead." The two-repo shape, visible today on GitHub, is the Doctrine/Symfony shape: `PCRE2Project/pcre2` active — last pushed 15 October 2026 — while `PCRE2Project/pcre1` is an archive, created in January 2025 — ten years to the month after PCRE2 10.00 — described in one line: "Archive of PCRE1 (unmaintained since 2018)." The archive was created years after maintenance ended. Version numbering carries the lineage on purpose — 8.x, then 10.x, no 9 — "so as to avoid any confusion"; why 10 rather than 9, the project has never said.

## The boundary PHP crossed, measured

PHP shipped PCRE1 through 7.2 — 8.41, never 8.45 — and crossed at 7.3.0 (6 December 2018, bundling PCRE2 10.32) after a 26-to-0 RFC vote. php-src's UPGRADING file warned that "character ranges in classes are now more strictly interpreted"; the RFC's own backward-compatibility list was milder — four bullets, each played down: the `S` modifier becoming a no-op, `X` the default, a newer Unicode engine, invalid patterns more likely to fail compilation — and silent on the range reversals the probe below shows.

"More strictly" turns out to undersell it — the strictness *moved*, in both directions. This probe, one file, run through both official images:

```php
<?php

echo PHP_VERSION, ' / ', PCRE_VERSION, "\n";

foreach (['/[\w-x]a/', '/[a-\d]b/', '/x{2,1}/', '/a\g{+2}/', '/(?(VERSION>=10.43)a)/'] as $p) {
    error_clear_last();
    $r = @preg_match($p, 'w-xab');
    $err = error_get_last()['message'] ?? '';
    printf("%-22s ret=%-5s err=%s\n", $p, var_export($r, true), $err === '' ? '-' : $err);
}
```
{: data-file="boundary.php" data-copy="" }

```console
$ docker run --rm -i php:7.2-cli php < boundary.php
7.2.34 / 8.41 2017-07-05
/[\w-x]a/              ret=1     err=-
/[a-\d]b/              ret=false err=preg_match(): Compilation failed: invalid range in character class at offset 4
/x{2,1}/               ret=false err=preg_match(): Compilation failed: numbers out of order in {} quantifier at offset 5
/a\g{+2}/              ret=false err=preg_match(): Compilation failed: subpattern name expected at offset 4
/(?(VERSION>=10.43)a)/ ret=false err=preg_match(): Compilation failed: malformed number or name after (?( at offset 10
```
{: data-copy="" }

```console
$ docker run --rm -i php:7.3-cli php < boundary.php
7.3.33 / 10.32 2018-09-10
/[\w-x]a/              ret=false err=preg_match(): Compilation failed: invalid range in character class at offset 3
/[a-\d]b/              ret=1     err=-
/x{2,1}/               ret=false err=preg_match(): Compilation failed: numbers out of order in {} quantifier at offset 5
/a\g{+2}/              ret=false err=preg_match(): Compilation failed: reference to non-existent subpattern at offset 6
/(?(VERSION>=10.43)a)/ ret=1     err=-
```
{: data-copy="" }

Read the first two rows together. `[\w-x]` — a class shorthand, a hyphen, a literal — **compiles on PCRE1 and is refused on PCRE2**; `[a-\d]` — a literal, a hyphen, a class shorthand — is refused on PCRE1 and compiles on PCRE2 10.32. That acceptance lasted exactly PHP 7.3's lifetime: PCRE2 10.33 made a class shorthand at the end of a range an error again, and every PHP from 7.4 refuses what 7.3 accepted — measured on the official images:

```console
$ docker run --rm php:7.3-cli php -r 'var_dump((bool) @preg_match("/[a-\d]b/", "ab"));'
bool(true)
$ docker run --rm php:7.4-cli php -r 'var_dump((bool) @preg_match("/[a-\d]b/", "ab"));'
bool(false)
```
{: data-copy="" }

A pattern that validates on one engine of 2018 can fail on its successor, and a pattern that failed can start working: one direction breaks your assertions loudly, the other changes nothing you ever asserted and passes silently. The third row is the control — nonsense stays nonsense. The fourth shows the same rejection for a different reason: syntax PCRE1 never had, read by PCRE2 as a reference it cannot satisfy here. The fifth is the future arriving: `(?(VERSION>=10.43)...)`, a version condition PCRE2 grew and PCRE1 never had, reads as a feature check on one side and a malformed group on the other.

What the migration brought PHP, beyond the boundary: forward relative references `\g{+2}`, the `(*LIMIT_MATCH=...)` family of in-pattern limits, and JIT support on a wider set of platforms — the RFC's own list.

## Where PCRE1 still runs

Nowhere new, and everywhere old. On this machine, measured: The Silver Searcher 2.2.0 links `libpcre.1.dylib` — the transcript above; macOS's own system libraries ship PCRE2 only, and so do git and every current PHP. A Homebrew PHP 7.0.33 still runs PCRE1:

```console
$ /opt/homebrew/opt/php@7.0/bin/php -i | grep 'PCRE Library Version'
PCRE Library Version => 8.38 2015-11-23
```
{: data-copy="" }

PHP never bundled 8.45 — its last PCRE1 was the 8.41 frozen in 7.2. The pcre1 repository is a read-only archive; the 8.45 tarball it preserves still powers binaries that were built against it and never rebuilt. That is the end state of every one-library-two-repos story: the old repository becomes a tomb, and the old library lives on exactly as long as the binaries that link it.

## Sources

For checking rather than trusting:

- The 10.00 announcement, 5 January 2015, including "not compatible" and the version-numbering rationale: [Philip Hazel on the pcre-dev list](https://lists.exim.org/lurker/message/20150105.162835.0666407a.en.html)
- The stated reason and the API differences: the [PCRE2 manual](https://pcre.org/current/doc/html/pcre2.html) and [pcre2api](https://pcre.org/current/doc/html/pcre2api.html)
- Both ChangeLogs, including 8.20's JIT integration, 8.30's 16-bit and 8.32's 32-bit work, and 8.45's "final release" note: [PCRE1](https://raw.githubusercontent.com/PCRE2Project/pcre1/trunk/ChangeLog) and [PCRE2](https://raw.githubusercontent.com/PCRE2Project/pcre2/main/ChangeLog)
- PCRE1's end-of-life wording and the 1997 origin: [pcre.org](https://www.pcre.org/)
- The repositories: [PCRE2Project/pcre2](https://github.com/PCRE2Project/pcre2) and the archive [PCRE2Project/pcre1](https://github.com/PCRE2Project/pcre1)
- PHP's migration: the [PCRE2 migration RFC](https://wiki.php.net/rfc/pcre2-migration) (Anatol Belski, 2017) and the UPGRADING note in the `php-7.3.0` tag
- The boundary transcripts: official `php:7.2-cli` and `php:7.3-cli` images, run 16 October 2026, by the probe printed above

## Recap

You now understand:

- why PCRE2 is a separate library rather than PCRE 9 — the API was the reason, stated by the project: two decades of interface limitations, "not compatible," versions "from 10.00 so as to avoid any confusion"
- what did *not* motivate the fork — the JIT and the 16/32-bit widths both shipped in PCRE1 years earlier — and that the language did not change, the code structure staying, in the announcement's words, "much the same"
- that the PHP 7.2 → 7.3 boundary moves pattern strictness in *both* directions, measured: `[\w-x]` accepted then refused; `[a-\d]` refused, then accepted for exactly PHP 7.3's lifetime, then refused again from 10.33
- where PCRE1 lives today: an archived repository, a final 8.45, and the binaries — like the `ag` on this machine — that still link it

**Next:** [which PCRE2 ships with which PHP](which-pcre2-ships-with-which-php.md) maps every engine PHP bundled since that crossing; the [engine comparison page](../concepts/pcre.md) places PCRE2 among the other engines your patterns meet.


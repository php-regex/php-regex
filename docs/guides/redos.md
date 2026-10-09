---
redirect_from:
  - /REDOS_GUIDE/
  - /REDOS_GUIDE.html
  - /redos_guide/
  - /redos_guide.html
---
# ReDoS Guide

ReDoS (Regular Expression Denial of Service) happens when a regex takes exponential or high polynomial time to reject certain inputs. This guide explains what PHPRegex proves about a pattern, how to read its verdict and the attack it hands you, what confirmed mode adds, and how to fix a vulnerable pattern.

PHPRegex proves a pattern safe, or hands you the input that kills it. Where it cannot do either, it says so, and its structural heuristics decide.

> **Note:** ReDoS analysis is disabled by default in the linter and the integrations. Enable it explicitly:
> - CLI: `vendor/bin/regex lint src/ --redos`, or `checks.redos.enabled: true` in `regex.json`
> - PHPStan: include `rules.neon`, or set `phpRegex.checks.redos.enabled: true` (see [the PHPStan guide](guides/phpstan.md))
> - Symfony: `php_regex.redos.enabled: true`; Laravel: `redos.enabled` in `config/php-regex.php`
>
> `Regex::redos()`, `vendor/bin/regex analyze` and `vendor/bin/regex debug` always run it.

## What Is ReDoS?

PCRE is a backtracking engine. When a pattern can match the same input in several ways, the engine tries them one after the other before it gives up, and the number of ways can grow exponentially with the length of the input.

```
Pattern: /(a+)+$/
Input:   "aaaaaaaaaaaaaaaaaaa!"
```

`(a+)` can take 1 to n characters, and the outer `+` can repeat the group 1 to n times. The `!` makes `$` fail, and the engine tries every way of splitting the `a`s between the two quantifiers before it reports no match.

## How ReDoS shows up in PHP

In PHP, a vulnerable pattern rarely hangs: it fails silently. Measured with PHP 8.4 and PCRE2 10.49, the default `pcre.backtrack_limit` of 1,000,000 and the JIT on:

- `/(a+)+$/`, `/(\w+\s?)+$/` and `/^(\d+)*$/` make `preg_match()` return `false` ("Backtrack limit exhausted") on 19 characters followed by `!`, after about 2 ms. `/(a|aa)*$/` fails from 29 bytes, `/a*a*a*$/` from 1,412. Code that reads the result as a boolean lets through, or rejects, whatever it is given.
- A polynomial pattern is a CPU problem the limit does not see. `/a*a*$/` never trips it, but takes about 20 ms on 10,000 characters, 0.4 s on 50,000 and 6.5 s on 200,000.
- PCRE's optimizations defuse some ambiguity, never add any. `/(a+)+b/` rejects 5,000 `a`s at once, because the `b` every match needs is absent; with a `b` after a character the loop cannot take, as `a…a!b`, it fails from 21 bytes like `/(a+)+$/`.
- Lookaround bodies backtrack too: `/(?=(a+)+b)/` fails from 21 bytes on `a…acb`.
- Atomic groups and possessive quantifiers do not backtrack: `/^(?>a+)+$/` rejects 5,000 `a` and a `!` in a few hundredths of a millisecond.
- The functions that look for every match cost more than `preg_match()`: `/a*b|a/` takes no measurable time with `preg_match()` on 80,000 `a`, and about a second with `preg_match_all()`.
- `preg_match()` called without `$matches` does not stop at an empty match: it searches again for a non-empty one. `/a*|(b+)+$/` returns `1` on 25 `b` and a `!` when `$matches` is passed, and `false` ("Backtrack limit exhausted") when it is not.

## What the verdict means

For every pattern inside the subset of PCRE it models, `RedosAnalyzer` builds an automaton of PCRE's backtracking, with the alternatives and quantifiers tried in PCRE's order, and computes the **complexity class** of one match attempt:

| complexity | what it means | severity |
|---|---|---|
| `linear` | no input makes an attempt backtrack beyond a linear number of steps | `safe` |
| `polynomial`, degree 2 | an input makes an attempt cost n² steps | `medium` |
| `polynomial`, degree 3 or more | n³ steps or more | `high` |
| `exponential` | 2ⁿ steps | `critical` |

`proof` says who decided:

| proof | meaning | severity |
|---|---|---|
| `proven` | the model proved the class | from the class, as above |
| `heuristic` | the pattern holds a construct outside the model: the structural heuristics decided | the heuristics' severity |
| `budget_exceeded` | the model ran out of its budget: the heuristics decided | the heuristics' severity |
| `not_analyzed` | no analysis ran: the mode was off, the pattern ignored, or the analysis failed | `safe` (off, ignored) or `unknown` (error) |

Every consumer prints the same headline, from `RedosAnalysis::headline()`. It never says a bare "safe":

| headline | when |
|---|---|
| `safe (proven)` | proven linear |
| `Polynomial backtracking, degree 3 (proven)` | proven polynomial, with its degree |
| `Exponential backtracking (proven)` | proven exponential |
| `Potential backtracking (heuristic)` | the heuristics found a risk, inside or over the budget |
| `no risk found (heuristic)` | the heuristics found nothing |
| `not analyzed (budget exceeded)` | over the budget, and the heuristics found nothing |
| `not analyzed (analysis error)` | the analysis failed, or the pattern is invalid |

### The guarantee

`safe (proven)` means: the model of the pattern holds no ambiguity at all, neither exponential nor polynomial. So in one match attempt at one start position, no input drives a backtracking engine that follows PCRE's order beyond a linear number of steps, on the pattern as analysed. PCRE's optimizations can only lower that cost.

An ambiguity the analysis finds but cannot turn into a working attack is never called linear: the verdict falls back to the heuristics, and `abstractions` says why:

```bash
vendor/bin/regex analyze '/(\w*a\w+)+\b/'
```

```
  Status     : Potential backtracking (heuristic)
  Severity   : CRITICAL (score 10)
  Mode       : THEORETICAL
  Confidence : HIGH
  Model: ambiguity without witness at offset 1
```

The limits of the guarantee, each one deliberate:

- **One match attempt.** `preg_match()` retries an unanchored pattern at every start position, and `preg_match_all()`, `preg_replace()` and `preg_split()` look for every match. Those retries can multiply the cost of an attempt by the length of the subject: a degree-k per-attempt verdict costs up to n^(k+1) steps over an unanchored search (`/a*a*$/`, quadratic per attempt, takes 12, 87 and 659 ms on 500, 1,000 and 2,000 `a` and a `b` without the JIT, eight times as long each time the length doubles). When one attempt is proven linear, the analysis looks for the run that makes the search quadratic: see [The cost of an unanchored search](#the-cost-of-an-unanchored-search). A pattern that matches inside the run is not reported there: `/a*b|a/` is `safe (proven)`, and the `preg_match_all()` figure above is that multiplication. The second search `preg_match()` runs without `$matches` after an empty match is covered: for a pattern that can match empty, the analysis also models an attempt where an empty match at its start does not count, which is why `/a*|(b+)+$/` is exponential.
- **The pattern as analysed.** A bounded repeat `{m,n}` whose `n` is above 16 is analysed as `{m,}`, and so is any bounded repeat whose copies can read the same input in two ways. An atomic group or possessive quantifier whose body is more than one run over one set, or than an alternation of one-character branches (read as one character of their union, `(?>a|\d)`), is kept as written. Each of them is listed in `abstractions` and printed on a `Model:` line, so you can see what the proof is about. The class is the class of that model: a bounded repeat analysed as unbounded can be reported exponential where PCRE's cost stays polynomial, and confirmed mode then says the attack was not reproduced.
- **Lookarounds may fail.** The model does not evaluate what a lookahead or lookbehind requires: it counts every run through one as possibly failing, so that the engine may keep backtracking past it. A lookaround never turns a vulnerable pattern into `safe (proven)` (`/(a+)+(?=b)/` is exponential, and PHP fails on it from 20 bytes). A witness of a pattern holding a lookaround is asked of the running PCRE2 before it proves anything, on a few pumps, its attempt pinned where it starts: the attempt must fail there, and, when the way to the loop crosses a lookaround, it must match once the suffix is replaced with one the pattern accepts, so the lookarounds on the way hold. A lookbehind at the start of the pattern leads the witness with what it asks for (`/(?<=\[)…/` gives `"[" . "**" x n . "\n"`). When no witness passes, a lookaround the attempt crosses before the loop, and never after it, is taken to hold, so that a success after it counts, and the witness is looked for again; when none passes still, the heuristics decide. So `/(?<![a-z-])background-color\s*:\s*[^;]+;?/i`, whose witness PHP matched at once, is no longer proven polynomial. The body of an atomic lookaround is analysed as its own search, and its class joins the pattern's.
- **Word boundaries and anchors are exact.** `\b` and `\B` are modelled from the word class of the characters around them, at every attempt start and inside lookarounds; `^`, `$`, `\A`, `\z`, `\Z`, `/m` and `/D` as PCRE reads them, `^` under `/m` included.
- **Inline options as PCRE reads them.** An option set inside one alternative also holds in the alternatives after it, up to the end of the group around it: in `/x(?s)|(?:.*\n.*\n)+x/` the dots of the second alternative cross newlines, and the pattern is exponential. `r` (caseless restrict) and the ASCII options `a`, `aD`, `aS`, `aW`, `aP` and `aT` are read per scope, like `i` and `s`, so `/^(?r)(?:k|\x{212A})*$/iu` is `safe (proven)` (the Kelvin sign no longer matches `k`) and `/^(?^i)(?:k|\x{212A})*$/ur` is exponential. `(?^)` clears `i`, `m`, `n`, `s`, `x`, `xx` and `r`, and keeps `U` and the ASCII options. Under `xx` a class skips its unescaped spaces and tabs, and a lone `x` takes `xx` off: `/^(?xx)(?:[a b]|\x20)*$/` is proven linear, `/^(?xx)(?x)(?:[ a]|\x20)*$/` exponential. `\b` and `\B` under `aW` read the ASCII `\w`.
- **One engine.** Character classes under `/u` and `/i` are computed by asking the running PCRE2, so `(\p{L}|\d)+$` is proven safe under `/u` and `(\w|é)+$` exponential (`é` is a word character there). A class the analysis can only over-approximate is listed in `abstractions`, and then never yields `safe (proven)`. A verdict is deterministic for one PHPRegex analysis version and one PCRE2 release; the result carries both. The probes for `r` and the ASCII options need PCRE2 10.43: a running PCRE2 older than that, judging a pattern for a newer target, cannot compile them, so each such class is read as any character and listed in `abstractions`. The pattern is then never `safe (proven)`, and usually gets a heuristic verdict: two CI rows on different PCRE2 releases can disagree on it.

Outside the model, the heuristics decide and `proof` is `heuristic`: backreferences, conditionals, recursion and subroutine calls, backtracking control verbs, callouts, `\X` and `\R`, non-atomic lookarounds (`(?*…)`, `(*napla:…)`, `(?<*…)`, `(*naplb:…)`), `\b` and `\B` under two different ASCII scopes in one pattern (`(?aW:\b)…\b`), and a bounded repeat `{m,n}` (`n` above 1) whose body can match the empty string.

A proven vulnerable verdict certifies the class of the model. Its confidence is `high` only once the running PCRE has reproduced it (see [Confirmed mode](#confirmed-mode)); until then it is `medium`. A proven safe verdict has `high` confidence.

## The witness

Every proven vulnerable verdict carries a witness, the family of inputs that drives the worst case: a prefix, a pump repeated n times, and a suffix that makes the attempt fail.

```bash
vendor/bin/regex analyze '/(a|aa)*$/'
```

```
  [3/4] ReDoS analysis
  Status     : Exponential backtracking (proven)
  Severity   : CRITICAL (score 10)
  Mode       : THEORETICAL
  Confidence : MEDIUM
  Attack: "aaa" x n . "!"
  Hotspot:   1-5
```

Read `"aaa" x n . "!"` as PHP: `str_repeat("aaa", $n) . "!"`. Each part is a PHP double-quoted literal, with everything outside printable ASCII escaped (`\x00`, `\n`, `\t`, `\xFF` without `/u`, `\u{202E}` under `/u`), so it pastes into PHP and gives the same bytes; an empty prefix or suffix is left out. `RedosWitness` builds the raw input:

```php
use PHPRegex\Redos\RedosAnalyzer;

$analysis = (new RedosAnalyzer())->analyze('/(a+)+$/');

echo $analysis->headline(), "\n";        // Exponential backtracking (proven)
echo $analysis->witness->render(), "\n"; // "a" x n . "!"

var_dump(preg_match('/(a+)+$/', $analysis->witness->build(30))); // bool(false)
echo preg_last_error_msg(), "\n";                                 // Backtrack limit exhausted
```

`toArray()` gives the three parts, escaped and unquoted, as the JSON output carries them.

A short witness can look harmless on the engine and still be the attack. PCRE2 looks for the last code unit every match needs (`;` in `/color\s*:\s*[^;]+;/`) before it tries the pattern, and gives up at once on a subject without it, but only on a subject shorter than a cap: 5,000 code units for an anchored pattern, 5,000,000 for an unanchored one (PCRE2 10.49). Past it the search is skipped and the cost is paid. Measured without the JIT, `/^color\s*:\s*[^;]+;/` on its witness `"color:" . " " x n` takes 0.00 ms at 4,996 bytes, 6.4 ms at 5,016 and 75 to 200 ms at 16,006; `/color\s*:\s*[^;]+;/` 0.16 ms at 4,999,006 bytes, and was stopped after 8 seconds at 5,000,106. When no failing subject can hold that code unit (any `;` lets `[^;]+;` match), the witness is still the attack: it needs a subject past the cap, which PHP's default `post_max_size` of 8M lets in. The verdict stays proven.

## Confirmed mode

ReDoS analysis defaults to **theoretical** mode: the pattern is read, never run. **Confirmed** mode runs the patterns at or above the threshold on the running PCRE, without the JIT and under the limits of `ConfirmationOptions` (backtrack limit 100,000 by default):

- an **exponential** witness is replayed: built with one pump, then two, up to 64, until `preg_match()` fails. PCRE gives up at once on a subject that lacks a literal every match needs, so the replay tries several suffixes (the bare one, the shortest one the loop cannot take, and that one followed by each required literal) and publishes the one that reproduced. Each is then tried once more at 5,000 bytes or just past, where PCRE2 stops looking for the code unit an anchored pattern requires (`#^<iframe(?:"[^"]*"|'[^']*'|[^>])*>#i` reproduces only there: its witness holds no `>`). Only a failure on the backtrack limit counts as reproduced; the replay stops at a fixed amount of work, and then reports `replayed: false`;
- a **polynomial** verdict is not replayed: the backtrack counter does not measure polynomial work, and a timing would depend on the machine. It is still reported, with `replayed: null`;
- a **heuristic** verdict is run on inputs of growing length, as in 1.x.

When the running PHP cannot set the limits of the replay (`ini_set()` in
`disable_functions`), nothing is replayed: the verdict keeps its severity and
its proof, `replayed` is `null`, and the confirmation's evidence reads
`engine limits unavailable`. A proven exponential pattern is still reported
at its severity, so it cannot slip under a threshold. A heuristic verdict
whose replay was skipped is reported by `regex lint` as a warning, its
message noting that it was not replayed and why.

```bash
vendor/bin/regex analyze '/(a+)+b/' --redos-mode=confirmed
```

```
  [3/5] ReDoS analysis
  Status     : Exponential backtracking (proven)
  Severity   : CRITICAL (score 10)
  Mode       : CONFIRMED
  Confidence : HIGH
  Attack: "a" x n . "!b"
  Replayed on PCRE2 10.49: preg_match fails from length 18 (backtrack_limit 100000, JIT off).
  Hotspot:   1-3
```

The length counts the bytes of the replayed input, here 17 `a`, the `!` and the `b`. The suffix `!b` is what makes PCRE run the loop: without the `b` it would give up at once, and without the `!` the pattern would match.

The line names the call form the replay ran: `preg_match fails` when the
witness reproduces with `$matches`, and `preg_match() without $matches fails`
for the patterns that only blow up on PHP's second try — an empty first match
makes `preg_match()` retry the offset with `NOTEMPTY_ATSTART | ANCHORED` when
no `$matches` was passed. The analysis models both forms; a verdict is only
replayed the way it was proven.

When the replay never fails, the verdict stays and its confidence stays `medium`:

```bash
vendor/bin/regex analyze '/^(-?[a-z]+){1,7}\.json$/i' --redos-mode=confirmed
```

```
  Status     : Exponential backtracking (proven)
  Severity   : CRITICAL (score 10)
  Mode       : CONFIRMED
  Confidence : MEDIUM
  Model: {1,7} at offset 1 analysed as {1,} (its copies read the same input in two ways)
  Attack: "-AA" x n . "!.json"
  Not reproduced on PCRE2 10.49 (PCRE's optimisations defuse it).
```

Here the model read `{1,7}` as `{1,}`; PCRE, held to seven copies, never reached the backtrack limit on the replayed inputs.

In PHP:

```php
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosMode;

$analysis = (new RedosAnalyzer())->analyze('/(a+)+b/', mode: RedosMode::Confirmed);

echo $analysis->witness->render(), "\n";                      // "a" x n . "!b"
var_dump($analysis->replayed);                                // bool(true)
echo $analysis->confidenceLevel()->value, "\n";               // high
echo $analysis->confirmation->samples[0]->inputLength, "\n"; // 18
echo $analysis->confirmation->samples[0]->pregError, "\n";   // Backtrack limit exhausted
```

Only a verdict the engine reproduced, or a proven one it could not replay because `ini_set()` is disabled, at `high` or above, is an error: it makes `analyze`, `debug` and `lint` exit with 1. A theoretical verdict is a warning, whatever its severity, so no build turns red from a theoretical verdict, nor from one the engine ran without reproducing it (a `lint` run still fails on a pattern that does not compile, or on a lint rule of error severity).

## Severity and thresholds

Severities are ordered `safe` < `low` < `unknown` < `medium` < `high` < `critical` (`RedosSeverity::rank()`). A threshold is `low`, `medium`, `high` or `critical`; a pattern is reported at or above it. The default is `high` for the linter, Symfony and Laravel, and `critical` for PHPStan.

A proven quadratic pattern (`/\d+\d+$/`, `/(\w+)(\w+)$/`) is `medium`: below the default thresholds. Lower the threshold to `medium` to see them.

## The cost of an unanchored search

The verdict is about one match attempt. An unanchored `preg_match()` starts an attempt at each position of the subject until one matches. When the attempt is proven linear, the analysis also looks for a **search-cost witness**: a prefix, a run repeated n times, then a breaker, such that every attempt started inside the run reads to its end and then fails. The search then costs about n²/2 steps in PCRE2's interpreter, and `preg_match_all()`, `preg_replace()` and `preg_split()` retry the same way. The prefix, often empty, is there when the first attempt would match the bare run: the trim regex `/^\s+|\s+$/` matches `" " x n` at once, not `"!" . " " x n . "!"`. The breaker holds the last code unit PCRE2 requires (`>` for `/\s*=>/`, `b` for `/a+b|cb/`, where every alternative ends with it): PCRE2 gives up at once on a subject without it. Measured with PHP 8.4.26 and PCRE2 10.49:

| pattern | attack | `pcre.jit=0` | `pcre.jit=1` |
|---|---|---|---|
| `/\s+$/` | `" " x n . "x"` | 123, 497, 1,992 ms for n = 5,000, 10,000, 20,000 | 1.9 ms for n = 800,000 |
| `/^\s+\|\s+$/` | `"!" . " " x n . "!"` | 491, 1,963 ms for n = 10,000, 20,000 | 4.0 ms for n = 800,000 |
| `/a+b/` | `"a" x n . "cb"` | 24, 97 ms for n = 10,000, 20,000 | 0.0 ms |
| `/(?:ab)+c/` | `"ab" x n . "dc"`, 5,000 and 10,000 characters | 51, 201 ms | 3.5, 13.8 ms |
| `/(?:a\|b)+c/` | `"ab" x n . "dc"`, 5,000 and 10,000 characters | 331, 1,325 ms | 27.5 ms, then `false` ("JIT stack limit exhausted") |
| `/^\s+$/` | `" " x n . "x"` | 0.2 ms for n = 20,000 | 0.0 ms |

The cost is stated for the interpreter: `pcre.jit=0`, a PHP built without JIT, or a pattern that starts with `(*NO_JIT)`. The JIT may avoid it for some patterns, here every loop on one character, not for all. `pcre.backtrack_limit` does not stop it: the limit counts each attempt apart, so with the default limit `/\s+$/` spends two seconds on 20,000 spaces and returns `0` without an error; it trips only when one attempt exceeds it.

An attempt tied to the start of the search is immune when every alternative is: `^` without `m`, `\A`, `\G`, the `A` modifier, and a leading `.*` under `s` (PCRE2 anchors it; so is a leading class of every character without `/u`, such as `[\s\S]*`, and under `/u` a class whose characters and ranges cover every code point, the surrogates included, such as `[\x00-\x{10FFFF}]*`, but not `[\s\S]*`, where `\s` and `\S` are Unicode properties, nor `[\x00-\x{D7FF}\x{E000}-\x{10FFFF}]*`), as are `^` under `m` and a leading `.*` without `s` when the run cannot hold a newline. A possessive or atomic `.*` under `s`, or a class PCRE2 reads as the same any character, jumps to the end of the subject in one step: a run read through it costs nothing, a run another loop reads still does (`/\s+$|x.*+y/s` is quadratic). A bounded repeat above 16, such as `\s{1,100}`, reads at most its bound per attempt: no run is read through it. A witness is looked for only when one attempt is proven linear: a worse per-attempt verdict already covers the search. When none is found, `search_cost` is `null`, which does not prove the search linear: the search proof reports a run through a lookaround only once confirmed mode replayed it, never a run some attempt may match inside, and stops at the analysis budget.

The result is `RedosAnalysis::$searchCost`, a `RedosSearchCost` (`degree` 2, the `prefix`, the `run` and the `breaker`, `replayed`; `build($n)` gives the attack, `render()` writes it), and the `search_cost` key of the JSON output. The severity is that of a proven quadratic attempt, `medium`, below the default thresholds, and it is a warning, never an error:

- `regex lint --redos` reports it as `regex.lint.redos.search` from `--redos-threshold=medium` (or `checks.redos.threshold` set to `medium` in `regex.json`); `--disable-rule=regex.lint.redos.search`, or `"redos.search": false` in `checks.lint.rules`, turns it off. The per-attempt verdict keeps its own id, `regex.lint.redos`.
- PHPStan reports it as `regex.redos.search`, with the message `Quadratic search (ReDoS): <pattern>`, from `threshold: medium`.

`vendor/bin/regex lint app --redos --redos-threshold=medium -v` on `preg_match('/\s+$/', $subject);` prints the whole hint:

```
  app/Whitespace.php:3:12
      → /\s+$/
    WARN Quadratic search: one attempt is linear (proven); an unanchored search is quadratic in PCRE2's interpreter (pcre.jit=0, a build without JIT, or (*NO_JIT)); the JIT may avoid it for some patterns. Severity: MEDIUM.
         ↳ Attack: " " x n . "!". pcre.backtrack_limit does not stop it: the limit counts each attempt apart. preg_match_all(), preg_replace() and preg_split() retry the same way. Anchor the pattern when every match starts at a known place (^, \A, \G or the A modifier), or bound the length of the run.
```

In confirmed mode, when the threshold is `medium` or lower, the witness is replayed without the JIT: `replayed` says whether an attempt started further from the end of the run takes more steps. The JIT is not measured: some pattern and subject pairs crash PHP under it (PCRE2 10.40 to 10.49), so the analysis never runs your pattern with it; the table above is how it fared on these probes, not a verdict. The step counter sees nothing inside an atomic or possessive repeat of a single character set, such as `/a++b/`: that witness, which the model reads exactly, stays reported with `replayed: false`. A witness that crosses a lookaround is reported only once that replay confirms it, and a pattern holding `\G`, which holds wherever the replay pins an attempt, is never confirmed that way.

## Outside the model: heuristics and budget

A pattern holding a construct the model does not cover is judged by the structural heuristics, as in 1.x, and says so:

```bash
vendor/bin/regex analyze '/(a)\1+$/'
```

```
  Status     : Potential backtracking (heuristic)
  Severity   : MEDIUM (score 5)
  Mode       : THEORETICAL
  Confidence : MEDIUM
```

The model works within a budget counted in states and steps, never in time, so the same pattern gets the same verdict on every machine. Past it, the heuristics decide:

```bash
vendor/bin/regex analyze '/^(?:(?:\d{1,16}\.){1,16}\d{1,16})(?:,(?:\d{1,16}\.){1,16}\d{1,16}){1,16}$/'
```

```
  Status     : Potential backtracking (heuristic)
  Severity   : LOW (score 2)
  Mode       : THEORETICAL
  Confidence : LOW
  Note: analysis budget exceeded, the heuristics decided
```

`RedosOptions` sets the budget on a `RedosAnalyzer` you build yourself:

| option | default | role |
|---|---|---|
| `maxStates` | `2000` | character-reading states the pattern's automata may hold |
| `maxSteps` | `250_000` | states created, product pairs visited and character classes scanned, the memory of the search charged by size |
| `boundedRepeatCutoff` | `16` | largest bounded-repeat maximum unrolled; past it, `{m,n}` is analysed as `{m,}` |

```php
use PHPRegex\Redos\RedosAnalyzer;
use PHPRegex\Redos\RedosOptions;

$analyzer = new RedosAnalyzer(options: new RedosOptions(maxStates: 100, maxSteps: 5_000));
$analysis = $analyzer->analyze('/^(?:\d{1,16}|[a-f]{1,16})+$/');

echo $analysis->proof->value, "\n"; // budget_exceeded
echo $analysis->headline(), "\n";   // Potential backtracking (heuristic)
```

With the default budget, the same pattern is `Exponential backtracking (proven)`. The budget bounds the memory of the analysis as well as its work: a pattern built to exhaust it ends with `budget_exceeded`.

`Regex::redos()` uses the default budget. The configuration files have no key for it in 2.0.

## A ceiling without a witness

A verdict says what an attack costs, from the input that mounts it. When the model finds an
ambiguity but no input to drive it, as in `/(.*)'(.*)'(.*)/i`, the verdict stays
`unknown` — and a ceiling may still be proven. `RedosAnalysis::$upperBoundDegree` is the
degree `d` of a bound `n^d` on the steps of one match attempt:

```php
use PHPRegex\Parser\RegexParser;
use PHPRegex\Redos\RedosAnalyzer;

$analyzer = new RedosAnalyzer(RegexParser::create());

$analyzer->analyze("/(.*)'(.*)'(.*)/i")->upperBoundDegree; // 3: at most n^3, attack or not
$analyzer->analyze('/^a+b$/')->upperBoundDegree;           // 1
$analyzer->analyze('/(a+)+$/')->upperBoundDegree;          // null: no polynomial ceiling
```

The argument: when no state of the pattern's automaton has two distinct paths back to itself
on one word, two states of a strongly connected part are joined by at most one path on any
word (Weber and Seidl, 1991). A path then crosses at most `c` loops of the automaton, and an
attempt follows at most `n^c` partial paths, which bounds a backtracking matcher's search
tree. The automaton reads at least what the pattern reads, bounded repeats it treats as
unbounded included, so the bound holds for the pattern.

It is an upper bound, worded "at most": `/^(?:a|b)*c+d*$/` gets 3 though its attempts are
linear. It is `null` when such a state exists — witnessed or not — and when the pattern
leaves the model. Never below a proven degree, it turns about a fifth of the corpus
patterns left `unknown` into ones with a polynomial ceiling.

## Reading the result

`RedosAnalysis` keeps the fields of 1.x (`severity`, `score`, `confidence`, `findings`, `hotspots`, `recommendations`, `confirmation`, …) and adds:

| field | type | meaning |
|---|---|---|
| `complexity` | `RedosComplexity` | `linear`, `polynomial`, `exponential`, or `unknown` when nothing was proven |
| `degree` | `?int` | the degree of a polynomial verdict, 2 or more; `null` otherwise |
| `proof` | `RedosProof` | `proven`, `heuristic`, `budget_exceeded` or `not_analyzed` |
| `witness` | `?RedosWitness` | the attack of a proven vulnerable verdict |
| `replayed` | `?bool` | whether the witness made the running PCRE fail; `null` when no replay was attempted |
| `abstractions` | `list<string>` | what the model analysed differently from the pattern as written |
| `pcreVersion` | `string` | the PCRE2 release the verdict was computed with |
| `analysisVersion` | `string` | `RedosAnalyzer::ANALYSIS_VERSION`, raised when the model changes |
| `searchCost` | `?RedosSearchCost` | the witness of a quadratic unanchored search, looked for when one attempt is proven linear; `null` when none was found, never a proof of a linear search |

`isSafe()` keeps its meaning (`safe` or `low`); `isProvenSafe()` is true only for a proven linear verdict:

```php
$analyzer = new RedosAnalyzer();

$analyzer->analyze('/^[a-z0-9-]+$/')->isProvenSafe();    // true:  safe (proven)
$analyzer->analyze('/(a)?(?(1)a|b)/')->isSafe();         // true:  no risk found (heuristic)
$analyzer->analyze('/(a)?(?(1)a|b)/')->isProvenSafe();   // false
```

The JSON output (`vendor/bin/regex analyze --format=json`, `debug --format=json`, `lint --format=json`) carries the same fields in snake case (`pcre_version`, `analysis_version`, `search_cost`), and the witness as its three escaped parts; the [JSON output reference](reference/json-output.md#redos-analysis-redos_analysis) lists every key:

```json
"complexity": "exponential",
"degree": null,
"proof": "proven",
"witness": {
    "prefix": "",
    "pump": "a",
    "suffix": "!"
},
"replayed": true,
"abstractions": [],
"pcre_version": "10.49",
"analysis_version": "1",
"search_cost": null
```

## On real code

On the repository's corpus of real-world patterns, the model proves the class of about 95 % of them; the heuristics decide for about 5 %, mostly patterns outside the model, a few with an ambiguity the analysis could not witness, almost none because of the budget. Analysing a pattern takes about a third of a millisecond at the median and about 5 ms at the 99th percentile.

Compared with the 1.x heuristics, many former `medium` findings, most of them on unanchored patterns, are now proven safe, and a smaller number of patterns rate higher, each with its attack. In confirmed mode, about seven in eight of the corpus' proven exponential verdicts reproduce on PCRE2; the others are reported as not reproduced, through a bounded repeat analysed as unbounded or a loop that PCRE's optimizations defuse.

## Using PHPRegex

### CLI

```bash
# Theoretical mode (default)
vendor/bin/regex analyze '/(a+)+$/'

# Replay the attack on the running PCRE
vendor/bin/regex analyze '/(a+)+$/' --redos-mode=confirmed

# Heatmap, findings and attack
vendor/bin/regex debug '/(a+)+$/'

# Every pattern of a code base
vendor/bin/regex lint src/ --redos --no-lint --no-optimize
```

See [the CLI guide](guides/cli.md) for the output of each command.

### PHP

```php
use PHPRegex\Toolkit\Regex;

$analysis = Regex::create()->redos('/(a+)+b/');

echo $analysis->severity->value, "\n";   // critical
echo $analysis->headline(), "\n";        // Exponential backtracking (proven)
echo $analysis->witness->render(), "\n"; // "a" x n . "!b"
```

## How to Report a Vulnerability Responsibly

Before filing a security issue:

1. Run **confirmed** mode, and keep the attack and the replayed length it prints.
2. Include the pattern, the input lengths, the timings, the JIT setting and the PCRE limits.
3. Verify the issue in the real code path (not just synthetic tests).

See [SECURITY.md](https://github.com/php-regex/php-regex/blob/2.x/SECURITY.md) for reporting channels.

## Repairs with proofs

`RedosRepairer` rewrites a pattern open to catastrophic backtracking and keeps the rewrites
the model proves linear, each with what the automata prove about it:

```php
use PHPRegex\Optimizer\RedosRepairer;

$repairs = (new RedosRepairer())->repair('/href="([^"]+)*"/');

$repairs[0]->pattern;       // '/href="([^"]*)"/'
$repairs[0]->sameSubjects;  // true: the automata prove it matches the same subjects
$repairs[0]->sameMatches;   // false: on 'href=""', $1 is '' where it was unset
$repairs[0]->isCertified(); // true: same subjects, proven linear
```

It tries a repeat of a repeat flattened, `(?:a+)+` to `a+`; the optimizer's own rewrite;
each greedy quantifier made possessive, then all of them. A rewrite proven to match other
subjects is dropped. One the automata cannot judge stays listed with `sameSubjects` set to
`null`, after the certified ones: the possessive forms mostly, as `/^(\w++\s?)+$/` for
`/^(\w+\s?)+$/`, linear for sure, the same subjects for you to check. With ReDoS checks on,
the PHPStan rule prints a certified repair in the tip of the error.

## Fixing Vulnerable Patterns (Verify Behavior)

These rewrites remove the ambiguity, but can change what the pattern matches or captures. Run the analysis again on the rewrite, and validate it with tests.

### Use possessive quantifiers or atomic groups

```
Vulnerable: /(a+)+b/     Exponential backtracking (proven)
Safer:      /a++b/       safe (proven)
Safer:      /(?>a+)b/    safe (proven)
```

Possessive quantifiers and atomic groups never give back what they matched.

### Simplify nested repeats

```
Vulnerable: /(a+)+b/     Exponential backtracking (proven)
Equivalent: /a+b/        safe (proven)   (verify captures)
```

### Remove overlapping alternatives

```
Vulnerable: /(a|aa)+$/   Exponential backtracking (proven)
Safer:      /a+$/        safe (proven)
```

`(a|b)+c` is not ambiguous, and is proven safe: only alternatives that can match the same text cost anything.

### Avoid repeating a group that can match nothing

```
Vulnerable: /(a*)*$/     Exponential backtracking (proven)
Safer:      /a*$/        safe (proven)
```

`(a?)+` is proven safe: PCRE stops repeating a group once an iteration matched nothing.

### Separate adjacent quantifiers

```
Vulnerable: /a+a+$/      Polynomial backtracking, degree 2 (proven)
Safer:      /a+$/        safe (proven)
Safer:      /a++a+$/     safe (proven)   (if the split must be preserved, verify behavior)
```

### Bound your repeats

```
Vulnerable: /(\d+)+$/    Exponential backtracking (proven)
Safer:      /\d{1,10}$/  safe (proven)
```

## Quick Reference: Vulnerable vs Safer (Verify Behavior)

```
(a+)+        -> a++        or (?>a+)
(a|aa)+      -> a+
(\d+)+       -> \d++       or \d{1,10}
(.+)+        -> .++        or .{1,100}
(a*)*        -> a*
a+a+         -> a+         or a++a+
(\w+\d+)+    -> (?>\w+\d+)+
```

`(?>\w+\d+)+` is judged `no risk found (heuristic)`: its atomic body is more than one run over one set, so the model lists it in `abstractions` and the heuristics decide.

## Defense in Depth

- Analyze patterns before they reach production, in CI with `regex lint --redos` or PHPStan.
- Check the return value of `preg_*` for `false`, and `preg_last_error()`: a vulnerable pattern fails silently.
- Limit the length of untrusted input before matching it.
- Prefer deterministic patterns, possessive quantifiers and atomic groups in hot paths.

---

Previous: [Cookbook](COOKBOOK.md) | Next: [Architecture](ARCHITECTURE.md)

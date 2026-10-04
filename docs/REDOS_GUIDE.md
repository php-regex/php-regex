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

- **One match attempt.** `preg_match()` retries an unanchored pattern at every start position, and `preg_match_all()`, `preg_replace()` and `preg_split()` look for every match. Those retries can multiply a linear attempt by the length of the subject: `/a*b|a/` is `safe (proven)`, and the `preg_match_all()` figure above is that multiplication. PCRE's start-of-match optimizations defuse most of them (`/\s+$/` and `/\d+x/` stay instant on 80,000 characters), not all of them. The second search `preg_match()` runs without `$matches` after an empty match is covered: for a pattern that can match empty, the analysis also models an attempt where an empty match at its start does not count, which is why `/a*|(b+)+$/` is exponential.
- **The pattern as analysed.** A bounded repeat `{m,n}` whose `n` is above 16 is analysed as `{m,}`, and so is any bounded repeat whose copies can read the same input in two ways. An atomic group or possessive quantifier whose body is more than one run over one set is kept as written. Each of them is listed in `abstractions` and printed on a `Model:` line, so you can see what the proof is about. The class is the class of that model: a bounded repeat analysed as unbounded can be reported exponential where PCRE's cost stays polynomial, and confirmed mode then says the attack was not reproduced.
- **Lookarounds may fail.** The model does not evaluate what a lookahead or lookbehind requires: it counts every run through one as possibly failing, so that the engine may keep backtracking past it. A lookaround never turns a vulnerable pattern into `safe (proven)` (`/(a+)+(?=b)/` is exponential, and PHP fails on it from 20 bytes); it can make a safe one look vulnerable, which confirmed mode then reports as not reproduced. The body of an atomic lookaround is analysed as its own search, and its class joins the pattern's.
- **Word boundaries and anchors are exact.** `\b` and `\B` are modelled from the word class of the characters around them, at every attempt start and inside lookarounds; `^`, `$`, `\A`, `\z`, `\Z`, `/m` and `/D` as PCRE reads them, `^` under `/m` included.
- **One engine.** Character classes under `/u` and `/i` are computed by asking the running PCRE2, so `(\p{L}|\d)+$` is proven safe under `/u` and `(\w|é)+$` exponential (`é` is a word character there). A class the analysis can only over-approximate is listed in `abstractions`, and then never yields `safe (proven)`. A verdict is deterministic for one PHPRegex analysis version and one PCRE2 release; the result carries both.

Outside the model, the heuristics decide and `proof` is `heuristic`: backreferences, conditionals, recursion and subroutine calls, backtracking control verbs, callouts, `\X` and `\R`, non-atomic lookarounds (`(?*…)`, `(*napla:…)`, `(?<*…)`, `(*naplb:…)`), the `xx` option, and a bounded repeat `{m,n}` (`n` above 1) whose body can match the empty string.

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

## Confirmed mode

ReDoS analysis defaults to **theoretical** mode: the pattern is read, never run. **Confirmed** mode runs the patterns at or above the threshold on the running PCRE, without the JIT and under the limits of `ConfirmationOptions` (backtrack limit 100,000 by default):

- an **exponential** witness is replayed: built with one pump, then two, up to 64, until `preg_match()` fails. PCRE gives up at once on a subject that lacks a literal every match needs, so the replay tries several suffixes (the bare one, the shortest one the loop cannot take, and that one followed by each required literal) and publishes the one that reproduced. Only a failure on the backtrack limit counts as reproduced; the replay stops at a fixed amount of work, and then reports `replayed: false`;
- a **polynomial** verdict is not replayed: the backtrack counter does not measure polynomial work, and a timing would depend on the machine. It is still reported, with `replayed: null`;
- a **heuristic** verdict is run on inputs of growing length, as in 1.x.

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
vendor/bin/regex analyze '/(a+)+(?!a)/' --redos-mode=confirmed
```

```
  Status     : Exponential backtracking (proven)
  Severity   : CRITICAL (score 10)
  Mode       : CONFIRMED
  Confidence : MEDIUM
  Attack: "a" x n
  Not reproduced on PCRE2 10.49 (PCRE's optimisations defuse it).
```

Here the model counted the negative lookahead as possibly failing, while after `(a+)+` has taken every `a` it always succeeds: PHP matches at once.

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

Only a verdict the engine reproduced, at `high` or above, is an error: it makes `analyze`, `debug` and `lint` exit with 1. A theoretical verdict is a warning, whatever its severity, so no build turns red from a verdict PHP has not reproduced.

## Severity and thresholds

Severities are ordered `safe` < `low` < `unknown` < `medium` < `high` < `critical` (`RedosSeverity::rank()`). A threshold is `low`, `medium`, `high` or `critical`; a pattern is reported at or above it. The default is `high` for the linter, Symfony and Laravel, and `critical` for PHPStan.

A proven quadratic pattern (`/\d+\d+$/`, `/(\w+)(\w+)$/`) is `medium`: below the default thresholds. Lower the threshold to `medium` to see them.

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

`isSafe()` keeps its meaning (`safe` or `low`); `isProvenSafe()` is true only for a proven linear verdict:

```php
$analyzer = new RedosAnalyzer();

$analyzer->analyze('/^[a-z0-9-]+$/')->isProvenSafe();    // true:  safe (proven)
$analyzer->analyze('/(a)?(?(1)a|b)/')->isSafe();         // true:  no risk found (heuristic)
$analyzer->analyze('/(a)?(?(1)a|b)/')->isProvenSafe();   // false
```

The JSON output (`vendor/bin/regex analyze --format=json`, `lint --format=json`) carries the same fields, `pcre_version` and `analysis_version` in snake case, and the witness as its three escaped parts:

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
"analysis_version": "1"
```

## On real code

On the repository's corpus of real-world patterns, the model proves the class of about 95 % of them; the heuristics decide for about 5 %, mostly patterns outside the model, a few with an ambiguity the analysis could not witness, almost none because of the budget. Analysing a pattern takes about a third of a millisecond at the median and about 5 ms at the 99th percentile.

Compared with the 1.x heuristics, many former `medium` findings, most of them on unanchored patterns, are now proven safe, and a smaller number of patterns rate higher, each with its attack. In confirmed mode, about five in six of the corpus' proven exponential verdicts reproduce on PCRE2; the others are reported as not reproduced, half of them through a bounded repeat analysed as unbounded, the rest through a lookaround or a lazy loop that PCRE's optimizations defuse.

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

See [SECURITY.md](../SECURITY.md) for reporting channels.

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

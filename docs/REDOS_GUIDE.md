# ReDoS Guide

ReDoS (Regular Expression Denial of Service) happens when a regex takes exponential time to match certain inputs. This guide explains the risky shapes, how PHPRegex detects them, and how to mitigate them.

> **Note:** ReDoS analysis is disabled by default for performance. Enable it explicitly:
> - CLI: `--redos` flag or `checks.redos.enabled: true` in `regex.json`
> - PHPStan: include `rules.neon`, or set `phpRegex.checks.redos.enabled: true` (see [the PHPStan guide](guides/phpstan.md))
> - Symfony: `php_regex.redos.enabled: true` in bundle configuration

## What Is ReDoS?

Most PCRE engines use backtracking. When a pattern has multiple ways to match the same input, the engine may explore a huge number of paths before it can fail or succeed.

Example:

```
Pattern: /(a+)+b/
Input:   "aaaaa!"
```

`(a+)` can consume 1..n characters, and the outer `+` can repeat the group 1..n times. When the final `b` fails, the engine tries many combinations of those choices.

## Philosophy & Accuracy

PHPRegex separates what is guaranteed from what is heuristic:

- **Guaranteed:** parsing, AST structure, error offsets, and syntax validation for the targeted PHP/PCRE version.
- **Heuristic:** ReDoS analysis is structural and conservative; treat findings as potential risk unless confirmed.
- **Runtime matters:** PCRE version, JIT, and backtrack/recursion limits change practical impact.

## Confirmation Mode (Bounded Evidence)

ReDoS analysis defaults to **theoretical** mode. You can opt into **confirmed** mode to run a bounded confirmation pass that:

- Generates witness inputs based on the AST hotspot
- Runs a short, limited micro-benchmark
- Captures JIT setting and PCRE limits

Example CLI:

```bash
bin/regex analyze '/(a+)+$/' --redos-mode=confirmed
```

Example PHP:

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Redos\RedosMode;

$analysis = Regex::create()->redos('/(a+)+$/', mode: RedosMode::Confirmed);
if ($analysis->isConfirmed()) {
    echo "Confirmed with bounded evidence\n";
}
```

**Note:** confirmation is intentionally conservative and bounded; it may not detect all potential issues.

## Runtime Context (Why It Matters)

Practical risk depends on runtime settings:

- **PCRE version** affects engine behavior and optimizations.
- **JIT** can drastically change performance characteristics.
- **Backtrack/recursion limits** cap how much work the engine can do before failing.

PHPRegex reports these values in the CLI so you can interpret findings in context.

## How to Report a Vulnerability Responsibly

Before filing a security issue:

1. Use **confirmed** mode and capture a reproducible PoC.
2. Include pattern, input lengths, timings, JIT setting, and PCRE limits.
3. Verify the issue in the real code path (not just synthetic tests).

See [SECURITY.md](../SECURITY.md) for reporting channels.

## Risky Pattern Shapes

PHPRegex focuses on structural patterns that cause backtracking blowups:

- Nested unbounded quantifiers: `(a+)+`, `(.*)*`
- Overlapping alternation inside repetition: `(a|aa)+`
- Backreference loops inside repetition: `(\w+)\1+`
- Quantifiers over empty-match subpatterns: `(a?)+`, `(a*)*`, `(\b)+`
- Ambiguous adjacent quantifiers: `a+a+`, `(\w+)(\w+)`
- Very large bounded repeats: `{1,10000}` (lower severity but still slow)

These are not always unsafe, but they are the common sources of catastrophic backtracking.

## How PHPRegex Detects ReDoS

PHPRegex analyzes the AST without executing the pattern:

```
/pattern/flags
  |
  v
Lexer -> Parser -> RegexNode
                   |
                   v
             RedosProfiler
                   |
                   v
             RedosAnalysis (severity, findings, hints)
```

Key heuristics in `RedosProfiler` include:

- Star-height detection (nested unbounded quantifiers)
- Alternation overlap detection via `CharSetAnalyzer`
- Backreference loops combined with repetition
- Empty-match repetition inside quantified groups
- Ambiguous adjacent quantifiers with overlapping character sets
- Atomic groups and possessive quantifiers reducing severity

## Using PHPRegex

### CLI

```bash
# Theoretical mode (default)
bin/regex analyze '/(a+)+$/'

# Confirmed mode (bounded evidence)
bin/regex analyze '/(a+)+$/' --redos-mode=confirmed
```

### PHP

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Redos\RedosMode;

$analysis = Regex::create()->redos('/(a+)+b/', mode: RedosMode::Theoretical);
echo $analysis->severity->value;
```

## Fixing Risky Patterns (Verify Behavior)

These techniques may reduce backtracking but could change matching results or capture behavior. Always validate with tests.

### Use possessive quantifiers

```
Risky:      /(a+)+b/
Safer:      /a++b/   (verify behavior)
```

Possessive quantifiers do not backtrack.

### Use atomic groups

```
Risky:      /(a+)+b/
Safer:      /(?>a+)b/   (verify behavior)
```

Atomic groups commit to the first successful match inside the group.

### Simplify nested repeats

```
Risky:      /(a+)+b/
Equivalent: /a+b/   (verify captures)
```

### Avoid empty-match repetition

```
Risky:      /(a?)+/
Safer:      /a*/
Safer:      /a+/   (if empty should not match)
```

### Avoid ambiguous adjacent quantifiers

```
Risky:      /a+a+/
Safer:      /a+/
Safer:      /a++a+/   (if the split must be preserved, verify behavior)
```

### Prefer character classes over alternation

```
Risky:      /(a|b)+c/
Safer:      /[ab]+c/
```

### Bound your repeats

```
Risky:      /(\d+)+/
Safer:      /\d{1,10}/
```

## Quick Reference: Risky vs Safer (Verify Behavior)

```
(a+)+        -> a++        or (?>a+)
(a|aa)+      -> a+
(\d+)+       -> \d++       or \d{1,10}
(.+)+        -> .++        or .{1,100}
(a?)+        -> a*         or a+
a+a+         -> a+         or a++a+
(a|b)+       -> [ab]+
(\w+\d+)+    -> (?>\w+\d+)+
```

## Defense in Depth

- Validate patterns before they reach production.
- Use input length limits when matching against untrusted data.
- Prefer deterministic patterns in hot paths.

---

Previous: [Cookbook](COOKBOOK.md) | Next: [Architecture](ARCHITECTURE.md)

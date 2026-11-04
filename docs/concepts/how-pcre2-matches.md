---
description: "How PCRE2 matches a pattern, read from its own traces: items, match attempts, backtracking, leftmost-first alternation, atomic groups, lookbehind, captures mid-match and the two limits PHP exposes."
---
# How PCRE2 Matches

PCRE2 is a backtracking matcher: it walks the compiled pattern one item at a time, moves a cursor over the subject, and when an item fails it goes back to the last choice it made and takes the other branch. PHP exposes none of that walk — `preg_match()` returns `1`, `0` or `false` — but PCRE2 does, through its callout interface: compiled with `PCRE2_AUTO_CALLOUT`, a pattern calls back before every item, and `pcre2test` prints those calls as a trace. Every trace on this page is such a print, from PCRE2 **10.44** (the release PHP 8.4 and 8.5 bundle), pinned in the repository as `tests/Documentation/Fixtures/pcre2-trace/10.44.txt`; every count was measured on the same release, as the playground's [Step through tab](#the-step-through-tab) runs it.

## The compiled program and its items

`pcre2_compile()` turns the pattern into a program of **items**: a literal, a class, a quantified atom, a group's opening and closing brackets, an anchor, an alternation bar, "End of pattern". Each item sits at a byte offset in the pattern, and a callout fires before each one, with the cursor's position in the subject. `pcre2test` prints one block per callout:

```
--->abcd
 +7 ^^       c
```

The first line is the subject. On the second, `+7` is the offset of the next item in the pattern, the first `^` marks where the current match attempt started, the second `^` marks the cursor, and `c` is the item about to be tried. A line with one `^` has the cursor at the start of the attempt, or before it: inside a lookbehind the cursor moves back past the start, and `pcre2test` then shows the start alone. Reading a trace is reading those blocks in order.

## The match attempt and the bumpalong

A match does not start once: when an attempt fails, PCRE2 moves the start to the right and tries again — the **bumpalong** — until a match is found or the subject runs out. It does not always try every offset: its start-of-match optimizations skip positions where the pattern cannot begin, offset 0 included (the lookbehind example below starts at offset 2, where the `b` it needs sits behind the cursor). Each start it does try is a `New match attempt` line. Here is `/a++a/` on `aaa`, two attempts, no match:

```
New match attempt
--->aaa
 +0 ^       a++
--->aaa
 +3 ^  ^    a
Backtrack
No other matching paths
New match attempt
--->aaa
 +0  ^      a++
--->aaa
 +3  ^ ^    a
No match
```

The second attempt starts at offset 1 (the first `^` has moved). There is no third: PCRE2's start-of-match optimizations know the pattern needs an `a` first and that the remaining subject is too short, so the match ends there. `preg_match('/a++a/', 'aaa')` returns `0`.

## Backtracking

When an item fails and an earlier item still has an untried way to match — a quantifier that can give back a character, an alternation with a branch left — PCRE2 returns to it. A callout reached by such a return carries the `PCRE2_CALLOUT_BACKTRACK` flag, printed as a `Backtrack` line before the block. The flag says at least one backtrack happened since the previous callout, never how many.

The classic case is `/^(a+)+$/` on `aab`, the pattern behind most ReDoS reports. The pinned trace, with what each block does:

```
New match attempt
--->aab
 +0 ^       ^          anchored: the one and only attempt, at offset 0
--->aab
 +1 ^       (
--->aab
 +2 ^       a+         the inner a+ takes "aa", greedily
--->aab
 +4 ^ ^     )+         about to close group 1 on "aa"
--->aab
 +2 ^ ^     a+         the outer + tries a second iteration: a+ needs an a, sees b
Backtrack
--->aab
 +6 ^ ^     $          the outer + gives up iterating and tries $ at offset 2: b is there, $ fails
Backtrack
--->aab
 +4 ^^      )+         the inner a+ gave back one a; group 1 will close on the first a
--->aab
 +2 ^^      a+         the outer + iterates again from offset 1
--->aab
 +4 ^ ^     )+         a+ took the second a; group 1 will close on it (offsets 1–2)
--->aab
 +2 ^ ^     a+         a third iteration: nothing but b left
Backtrack
--->aab
 +6 ^ ^     $          $ at offset 2 fails again
Backtrack
--->aab
 +6 ^^      $          the second iteration undone, $ at offset 1 fails too
No match
```

Twelve callouts for three characters. The `Backtrack` lines are where the matcher returned to a choice. PCRE2 tried every way to split the `a`s between the inner `+` and the outer `+` before admitting there was none — and the number of ways doubles with each `a`. Measured with the playground's module, PCRE2 10.44, on `n` times `a` followed by `b`, under PHP's default `pcre.backtrack_limit` of 1 000 000:

| subject | callouts | outcome |
|---|---|---|
| `aab` | 12 | no match |
| 10 `a` and `b` | 3 072 | no match |
| 15 `a` and `b` | 98 304 | no match |
| 18 `a` and `b` | 786 432 | no match |
| 19 `a` and `b` | 1 199 985 counted when PCRE2 stopped | match limit exceeded |
| 20 `a` and `b` | 1 199 985 counted when PCRE2 stopped | match limit exceeded |

The count is 3 × 2ⁿ. From 19 `a` on, PCRE2 gives up with `PCRE2_ERROR_MATCHLIMIT` before it finishes; `preg_match()` returns `false` and `preg_last_error()` is `PREG_BACKTRACK_LIMIT_ERROR`. The [ReDoS deep dive](redos.md) is about recognising that shape before it runs.

## Leftmost-first alternation

An alternation is not "the longest branch wins": it is the first branch that lets the rest of the pattern succeed. `/(a|ab)(c|bcd)(d*)/` on `abcd` captures `a`, `bcd` and an empty string, not `ab`, `c`, `d`:

```
New match attempt
--->abcd
 +0 ^        (
--->abcd
 +1 ^        a          first branch of group 1: a matches
--->abcd
 +2 ^^       |          the bar means: branch done, leave the group — group 1 becomes "a"
--->abcd
 +6 ^^       (
--->abcd
 +7 ^^       c          first branch of group 2: c against b fails
Backtrack
--->abcd
 +9 ^^       b          second branch: b, c, d
--->abcd
+10 ^ ^      c
--->abcd
+11 ^  ^     d
--->abcd
+12 ^   ^    )          closes group 2 on "bcd"
--->abcd
+13 ^   ^    (
--->abcd
+14 ^   ^    d*         nothing left: d* takes nothing
--->abcd
+16 ^   ^    )          closes group 3 on an empty string
--->abcd
+17 ^   ^    End of pattern
 0: abcd
 1: a
 2: bcd
 3:
```

The one backtrack is inside group 2. Group 1's second branch, `ab`, is never tried, because the pattern succeeded with the first: PCRE2 does not look for a better split once it has one. Thirteen callouts, and `preg_match()` fills `$matches` with the same three strings.

## Atomic groups and possessive quantifiers

An atomic group `(?>…)` and a possessive quantifier (`a++`, `a*+`, `a?+`) tell PCRE2 that once the group has matched, nothing inside it may be revisited. `/(?>a+)a/` on `aaa`:

```
New match attempt
--->aaa
 +0 ^       (?>
--->aaa
 +3 ^       a+          takes all three a
--->aaa
 +5 ^  ^    )
--->aaa
 +6 ^  ^    a           needs a fourth a: fails
Backtrack
No other matching paths
New match attempt
--->aaa
 +0  ^      (?>
…
No match
```

Without the atomic group, the failure of the last `a` would send the matcher back into `a+` to give one `a` back, and `/(a+)a/` would match `aaa`. Here `No other matching paths` says it: the group is sealed, the attempt is over, and the bumpalong moves on. Eight callouts in all; `/a++a/` above is the same story in four. `preg_match()` returns `0` for both.

## Auto-possessification

PCRE2 does a part of that work itself. At compile time it looks at what follows a repeated item, and when the next item can never match a character the repeat could have taken, it makes the repeat possessive, because backtracking into it could never help. `/a+[bc]/` on `aaac`:

```
New match attempt
--->aaac
 +0 ^        a+
--->aaac
 +2 ^  ^     [bc]
--->aaac
 +6 ^   ^    End of pattern
 0: aaac
```

Three callouts, no backtrack: `a+` took `aaa`, `[bc]` took `c`. Had the subject been `aaaa`, the matcher would not have tried giving back an `a` to `[bc]` one at a time — an `a` is never a `b` or a `c`. The pattern is matched as written, with the same answer, in fewer steps; PHP compiles its patterns the same way, so this is what `preg_match()` runs. The playground shows the steps as compiled, so a backtrack that never happened is never drawn; the absence is the explanation.

## Lookbehind

A lookbehind is tried from the cursor backwards, and the cursor does not move when it succeeds. `/(?<=b)a+/` on `xba`:

```
New match attempt
--->xba
 +0   ^     (?<=        the attempt starts at offset 2: the start optimizations skipped x and b, since the match must begin with an a
--->xba
 +4   ^     b           tried one character back, at offset 1: b is there
--->xba
 +5   ^     )           the lookbehind succeeded; the cursor is still at offset 2
--->xba
 +6   ^     a+
--->xba
 +8   ^^    End of pattern
 0: a
```

Five callouts, one attempt, match `a`. The offsets inside the lookbehind are the pattern's: `+4` is the `b` of `(?<=b)`.

## Captures as PCRE2 holds them

A capture is set when its group's closing bracket is passed, and unset again when a backtrack undoes that. `pcre2test`'s `callout_capture` modifier prints the groups set so far before each item: in the `/^(a+)+$/` trace above, group 1 reads `aa` after the first `)+`, then `a` after the inner `a+` gave one back, and it is reported as unset (`last capture = 0`) in the block the backtrack returns to. Group 0 is not set until the match ends. `$matches` is the final state only — and PHP's own rule on it: an unset group is written as `""`, and trailing unset groups are dropped unless `PREG_UNMATCHED_AS_NULL` is passed.

## The two limits

PCRE2 keeps a counter it increments each time round its main matching loop — of the same order as the callouts in these traces — and stops at the **match limit**, which PHP sets from `pcre.backtrack_limit` (1 000 000 by default). It also bounds how deep the backtracking can nest, the **depth limit**, which PHP sets from `pcre.recursion_limit` (100 000). The first exhausted gives `preg_match()` a `false` with `PREG_BACKTRACK_LIMIT_ERROR` ("Backtrack limit exhausted"), the second `PREG_RECURSION_LIMIT_ERROR` ("Recursion limit exhausted"). The table above is the first limit at work. With `pcre.jit=1`, PHP's default on servers, a JIT-compiled pattern counts differently and may fail with `PREG_JIT_STACKLIMIT_ERROR` instead; the browser's engines and the module below run without JIT.

## The Step through tab

The playground's "Step through" tab is these traces, interactive: a list of steps (`New match attempt`, `Try <item>`, `Backtracked before this item`), a cursor in the pattern and one in the subject, the captures at the current step, and the syntax tree's node of the current item lit. It runs the real PCRE2 — the upstream sources of the release the browser's PHP bundles, unmodified, compiled to WebAssembly behind the callout interface — with the compile options, extra options and limits PHP's wrapper passes at that exact PHP version, read from `php_pcre.c` at its tag. A line under the list says which PCRE2 traced, whose options were applied, and whether the browser's PHP agrees on the outcome and the captures; a disagreement is printed in red with both answers.

What it does not do, so that what it shows is never guessed:

- One `pcre2_match()`: the first match only. `preg_match_all()`'s later matches are not traced.
- No JIT. The outcome agrees with the browser's PHP, which runs without JIT too.
- Auto-callouts keep the outcome: PCRE2 documents that they change nothing but the steps it reports, and the self-check compares the trace with the engine's own `preg_match()` whenever it can. It says "not compared" when the subject was cut to its first 1 000 bytes or when the engine did not answer the check.
- "Backtracked before this item" is a flag, never a count: at least one backtrack since the previous step.
- Recording stops at 20 000 steps, or earlier when the recorded steps pass 8 MiB (hundreds of groups rewritten at every step); the match goes on to PCRE2's own answer, and the tab says so. A trace that takes more than 10 seconds is stopped and tried once more; when the second try runs out of time too, the tab says so and shows no answer. A 15-`a` subject against `/^(a+)+$/` is already past the cap.
- The subject is read up to 1 000 bytes, cut on a character boundary; a longer subject is traced on its first 1 000 bytes and is not compared with the engine.
- A pattern with an explicit callout `(?C…)` is refused: its callouts would interleave with the automatic ones.
- A PHP version without a row in the options table, or a PCRE2 release without a module, gets a sentence in the tab, not a trace of another release.

## Credits

PCRE2 is written by Philip Hazel and Zoltán Herczeg with contributors, and distributed under the BSD-3-Clause licence; the trace module is its sources, unmodified. The browser's PHP is [php-wasm](https://github.com/seanmorris/php-wasm). The trace format read here is the one `pcre2test` documents under `auto_callout` and `callout_extra` in the PCRE2 manual.

## Related

- [ReDoS Deep Dive](redos.md) - what makes the backtracking count explode, and the proof model behind the verdicts
- [PCRE vs Other Engines](pcre.md) - which PCRE2 release each PHP bundles
- [Regex in PHP](../guides/regex-in-php.md) - the `preg_*` functions and their error codes

---
date: 2026-10-16 14:40:00 +00:00
author: younes
description: "The same pattern measured through a linear engine and a backtracker — Rust's regex answering in 0.015 s where PCRE2 exhausts its match limit and V8 takes 2.9 s — what RE2's linear-time guarantee costs, and why PHP still ships the backtracker."
---
# What RE2 gives up to never backtrack

One file, one pattern, two engines — and the two engines live inside the same binary. `ripgrep` matches with the Rust `regex` crate by default and with PCRE2 behind a `--pcre2` flag; the pattern is the nested-quantifier classic `/^(\w+)+$/`:

```console
$ php -r 'file_put_contents("attack.txt", str_repeat("a", 26) . "!\n");'
$ time rg -c '^(\w+)+$' attack.txt
rg -c '^(\w+)+$' attack.txt  0.01s user 0.00s system 64% cpu 0.015 total

$ time rg --pcre2 -c '^(\w+)+$' attack.txt
rg: attack.txt: PCRE2: error matching: match limit exceeded
rg --pcre2 -c '^(\w+)+$' attack.txt  0.03s user 0.00s system 93% cpu 0.037 total
```

Process startup is all the Rust engine spent: a search for an absent literal over the same file costs the same 0.024 s, and the answer — *no match* — is delivered rather than abandoned. PCRE2, in the same binary, hit its own match limit — the ten-million default, which ripgrep leaves untouched — and gave up. For the unbounded comparison, PHP's PCRE2 with the JIT off and the limit raised needs 2 139.44 ms on this input, measured in [the backtracking anatomy](measured-anatomy-of-catastrophic-backtracking.md); the same input under Node's V8 takes 2 868 ms:

```console
$ node -e 'const t=Date.now(); const r=/^(\w+)+$/.test("a".repeat(26)+"!"); console.log("match:", r, "ms:", Date.now()-t);'
match: false ms: 2868
```

| Engine | Nature | The n = 26 attack |
|---|---|---|
| Rust `regex` (via ripgrep) | automaton | 0.015 s, process startup, answer delivered |
| PCRE2 (via ripgrep `--pcre2`) | backtracker | match limit exceeded |
| PCRE2 (PHP, JIT off, limit raised) | backtracker | 2 139.44 ms |
| V8 Irregexp (Node 24) | backtracker | 2 868 ms |

A common belief says catastrophic behavior is a property of the pattern. It is not: it is a property of the *pair* of pattern and engine — the four rows above are one pattern disagreeing with itself across engines.

## The theory, which is older than PHP

A backtracking matcher tries one path through the pattern at a time, remembers where to return, and on failure reconsiders — exponentially many paths in the worst case, which the anatomy article measures at fifteen to sixteen million tries per second. A finite-automaton matcher never chooses: it keeps a *set* of simultaneously-possible states and advances all of them one input character at a time — for a pattern of length m run on text of length n, the Thompson NFA simulation runs in O(mn). That simulation is as old as Ken Thompson's 1968 paper, which compiled the expression into machine code. RE2 and the Rust crate keep the same worst-case bound with other machinery — lazy DFAs, a Pike VM, even a bounded backtracker that refuses large inputs — every engine in the family paying the same price: O(mn), by construction. Russ Cox's 2007 article — "Regular Expression Matching Can Be Simple And Fast (but is slow in Java, Perl, PHP, Python, Ruby, ...)" — reintroduced the comparison and measured it: "the Thompson NFA implementation is a million times faster than Perl." Cox also documented in 2007 exactly what this article measured above: "PCRE stops getting the right answer at n=23, because it aborts the recursive backtracking after a maximum number of steps." PCRE2 10.49's limit-exhausted `false` is that same abort, nineteen years later — the mechanism that makes a backtracker *safe enough* where an automaton is *safe by construction*.

## What the linear guarantee costs

RE2, Google's production engine for this family — "designed and implemented with an explicit goal of being able to handle regular expressions from untrusted users without risk," in production since 2006 — states the trade itself: "the match time is linear in the length of the input string," with memory bounded by a budget the engines "fail gracefully when exhausted" — and to keep those guarantees it drops "backreferences and look-around assertions," the operators whose semantics require knowing where a previous attempt went.

The Rust engine makes the same choice, and its error messages are the price list. Feed it the two features PHP developers reach for daily:

```console
$ rg '\w+\s\1' attack.txt
rg: regex parse error:
    (?:\w+\s\1)
            ^^
error: backreferences are not supported

Consider enabling PCRE2 with the --pcre2 flag, which can handle backreferences
and look-around.

$ rg '(?<=a)b' attack.txt
rg: regex parse error:
    (?:(?<=a)b)
       ^^^^
error: look-around, including look-ahead and look-behind, is not supported

Consider enabling PCRE2 with the --pcre2 flag, which can handle backreferences
and look-around.
```

A backreference can reshape what the pattern means depending on what an earlier capture matched — a pattern whose language changes with its own match history is no longer a regular language at all, and no automaton can speak it. A lookaround is different: `(?<=a)b` still denotes an ordinary regular language, and RE2 refuses it on engineering grounds, not semantic ones — a zero-width assertion gating the run does not fit machinery that advances every position at once, and RE2's stated principle is to drop "constructs for which only backtracking solutions are known to exist." The price list is real either way; the two items on it are refused for different reasons.

## The trade runs both ways

RE2's own documentation is careful about the other direction: "It is not a goal to be faster than all other engines under all circumstances." When an early alternative is the one that matches, a backtracker walks a single path and wins on constant factors, while the automaton keeps every alternative's states alive in parallel. What the automaton sells is not speed but *shape*: the worst case is the same as the average case, which is why the engine family lives where inputs are untrusted and latency must be predictable — RE2's original home, scanning the web's text at Google.

And ripgrep's error message is the honest summary of the whole situation: the linear engine by default, PCRE2 one flag away — "which can handle backreferences and look-around." The tool ships both worlds and lets each pattern pick its price.

## What this means for PHP

PHP embeds PCRE2; you do not choose engines per call. The practical reading of this comparison is a classification exercise for your patterns:

- A pattern stripped of what linear engines refuse — backreferences, lookarounds, recursion, conditionals, backtracking-control verbs, `\K` — could run on an automaton: its worst case is an engineering problem, not a law of nature. When such a pattern still blows up, the fix exists inside PCRE2: possessive quantifiers and atomic groups collapse the paths, measured at five orders of magnitude in [the anatomy article](measured-anatomy-of-catastrophic-backtracking.md).
- A pattern whose semantics depend on history — backreferences most of all — is refused by every mainstream linear engine, and a backreference-driven language has no equivalent anywhere; the protections are the shape of the pattern, the limits with their quiet `false`, and the length of input you allow through — the guide's third defense.

The syntax-by-syntax comparison against JavaScript, Python and .NET lives in [PCRE vs other engines](../concepts/pcre.md); this article owns the safety axis that page does not draw.

## Sources

For checking rather than trusting:

- RE2, its guarantees and its non-goals: [github.com/google/re2](https://github.com/google/re2) — "the match time is linear in the length of the input string," "backreferences and look-around assertions are not supported"
- The 2007 measurement, and PCRE's 2007-era abort: Russ Cox, [Regular Expression Matching Can Be Simple And Fast](https://swtch.com/~rsc/regexp/regexp1.html), January 2007
- The 1968 origin: Ken Thompson, "Regular expression search algorithm," *Communications of the ACM* 11(6), June 1968, pp. 419–422
- The linear engine measured here: [ripgrep](https://github.com/BurntSushi/ripgrep) and the Rust [regex crate](https://docs.rs/regex/) it uses; every timing was produced on this machine (PHP 8.4.26 / PCRE2 10.49 externally linked, Node 24, ripgrep 15.2.0) by the commands shown here and in the anatomy article

## Recap

You now understand:

- why an automaton engine cannot catastrophically backtrack — every engine in the family is bounded to O(mn) by construction, an idea as old as Thompson's 1968 paper
- what that family gives up, measured in the engine's own error messages: backreferences and lookarounds, the operators that need the history of a single attempt
- that the trade runs both ways — a backtracker wins on constant factors when an early alternative is the one that matches; the automaton wins when inputs are untrusted and worst-case latency is the product
- where your patterns sit: history-free and fixable inside PCRE2, or history-dependent and limit-guarded — and why ripgrep ships PCRE2 one flag away from its linear default

**Next:** the [engine comparison page](../concepts/pcre.md) carries the construct-by-construct matrix across PCRE2, JavaScript, Python and .NET; [the backtracking anatomy](measured-anatomy-of-catastrophic-backtracking.md) measures the cliff this article's engines refuse to fall into.

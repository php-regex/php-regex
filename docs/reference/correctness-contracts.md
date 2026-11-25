---
description: "The formal guarantee of each PHPRegex analysis — parser, lint, optimizer, the ReDoS model, the automata solver — sound, complete or best-effort."
---

# Safety and Correctness Contracts

This page documents the formal guarantees of each analysis feature: what language is modeled, and whether the results
are **sound** (no false negatives), **complete** (no false positives), or **best-effort**.

## Parser & Validation

**Parser**
- **Semantics:** Parses PCRE syntax into an AST. Intended to preserve byte offsets and structure.
- **Guarantee:** Best-effort PCRE compatibility. Syntax acceptance can be more permissive than PCRE in edge cases.
- **Fallbacks:** Use `Regex::validate()` (with runtime validation enabled) for stricter PCRE checks.

**Validator (`Regex::validate`)**
- **Semantics:** AST-based validation plus optional runtime PCRE compile check.
- **Guarantee:** Best-effort. With runtime validation enabled, PCRE compilation errors are surfaced.
- **Fallbacks:** If runtime validation is disabled, only structural checks run.

## Linting & Optimization

**Lint**
- **Semantics:** Heuristic diagnostics for readability, correctness, and maintainability.
- **Guarantee:** Best-effort; warnings may be conservative. Some rules skip unsupported constructs.
- **Fallbacks:** Unsupported nodes are ignored for that specific rule.

**Optimizer**
- **Semantics:** Applies semantic-preserving rewrites to simplify patterns.
- **Guarantee:** Sound for supported transformations; does not claim completeness. A rewrite holds under every
  `LC_CTYPE` locale: it introduces no `\w`, `\s` or POSIX class that reads the locale's tables without `u`, so
  `[a-zA-Z0-9_]` stays a class, and `[0-9]` becomes `\d`, the ten ASCII digits under every locale, only without
  `u` and `(*UCP)`. A rewrite compiles wherever the original does: one that PCRE refuses for the target, as a count
  above 65535 or a lookbehind branch of two lengths before PCRE2 10.43, is withdrawn, whatever the options say.
- **Fallbacks:** Unsafe rewrites are skipped when uncertainty is detected.

## ReDoS Analysis

**ReDoS Analyzer**
- **Semantics:** A prioritized NFA (nondeterministic finite automaton whose branches are tried in PCRE's
  left-to-right order) models PCRE's backtracking order; the analyzer computes the complexity class of one
  match attempt (linear, polynomial of degree k, exponential) and, for a vulnerable pattern, a witness
  string built as `prefix + pump repeated n times + suffix` — a concrete input that triggers the worst case.
- **Guarantee:** A `safe (proven)` verdict is **sound** for the model: it is given only when the model holds no
  exponential and no polynomial ambiguity, so in one match attempt at one start position no input drives a
  backtracking engine that follows PCRE's order beyond a linear number of steps, on the pattern as analysed. PCRE's
  optimizations can only lower that cost. The second search `preg_match()` runs without `$matches` after an empty
  match is covered. A proven vulnerable verdict is **not complete**: it certifies the class of the model, and
  confirmed mode reports whether the running PCRE2 reproduces it (`replayed`).
- **Limits:** Per match attempt. An unanchored search retries the attempt at each start position, and the every-match
  functions (`preg_match_all`, `preg_replace`, `preg_split`) retry the same way, so a degree-k per-attempt verdict
  costs up to n^(k+1) steps over an unanchored search. When one attempt is proven linear, the search cost is looked for
  apart (below). Lookaround constraints are not evaluated (a lookaround may fail); a witness through one is
  checked on the running PCRE2 instead, on a few pumps: its attempt fails, and the lookarounds on its way to the loop
  hold. A witness may need a subject past PCRE2's cap on its search for a required code unit (5,000 code units
  anchored, 5,000,000 unanchored) before its cost shows;
  `{m,n}` above 16, and a bounded repeat whose copies can read the same input in two ways, are analysed as `{m,}`; an
  atomic body that is more than one run over one set, or than an alternation of one-character branches, is kept as written. Each abstraction is listed in
  `abstractions`. Verdicts are deterministic per analysis version and PCRE2 release.
- **Fallbacks:** Backreferences, conditionals, recursion, subroutine calls, verbs, callouts, `\X`, `\R`, non-atomic
  lookarounds, `\b` and `\B` under two different ASCII scopes in one pattern, bounded repeats whose body can match empty, a pattern over the analysis budget, an
  ambiguity the analysis cannot witness, and a witness crossing an over-approximated atomic body or character class
  are judged by the structural heuristics (best-effort), with `proof: heuristic` or `budget_exceeded`. An
  invalid pattern is never proven safe: `proof: not_analyzed`, severity `unknown`.

**Search cost**
- **Semantics:** For a pattern whose one attempt is proven linear, a witness `prefix . run x n . breaker` read from the
  same automaton: every attempt started inside the run reads to its end without matching, then fails on the breaker,
  which holds the last code unit PCRE2 requires before any attempt, read as its compiler reads it: the one every
  alternative ends with included (`b` for `/a+b|cb/`). The prefix keeps the first attempt from matching the bare run
  (`"!"` for `/^\s+|\s+$/`). The unanchored search then costs about n²/2 steps in PCRE2's interpreter (`pcre.jit=0`,
  a build without JIT, `(*NO_JIT)`). It is reported as `search_cost`, the lint issue
  [`regex.lint.redos.search`](rules.md) and the PHPStan identifier
  [`regex.redos.search`](../guides/phpstan.md), at severity `medium`, a warning.
- **Guarantee:** None in the other direction: `search_cost: null` means no witness was found, not that the search is
  linear. A reported witness holds on the model; confirmed mode, from a threshold of `medium` or lower, replays it
  without the JIT and counts the steps of attempts pinned at two offsets (`replayed`), under the backtrack and
  recursion limits of its `ConfirmationOptions`; `replayed` is `null` where those limits stop the count. Measured with PHP 8.4.26 and PCRE2 10.49:

  | pattern, subject | `pcre.jit=0` | `pcre.jit=1` |
  |---|---|---|
  | `/\s+$/`, `" " x n . "x"`, n = 10,000 / 20,000 | 497 / 1,992 ms | 0.0 ms; 1.9 ms at n = 800,000 |
  | `/^\s+\|\s+$/`, `"!" . " " x n . "!"`, n = 10,000 / 20,000 | 491 / 1,963 ms | 4.0 ms at n = 800,000 |
  | `/a+b/`, `"a" x n . "cb"`, n = 10,000 / 20,000 | 24 / 97 ms | 0.0 ms |
  | `/(?:ab)+c/`, 5,000 / 10,000 characters | 51 / 201 ms | 3.5 / 13.8 ms |
  | `/(?:a\|b)+c/`, 5,000 / 10,000 characters | 331 / 1,325 ms | 27.5 ms / `false`, JIT stack limit exhausted |
  | `/^\s+$/`, `" " x n . "x"`, n = 20,000 | 0.2 ms | 0.0 ms |

- **Limits:**
  - **The JIT** is neither modelled nor measured: it stayed linear on every loop over one character probed and was
    quadratic, or gave up, on loops over a longer word, and the analysis never runs a pattern under it (some pattern
    and subject pairs crash PHP there, PCRE2 10.40 to 10.49).
  - **`pcre.backtrack_limit`** is counted per attempt and does not stop the cost: at the default limit `/\s+$/` takes
    123, 497 and 1,992 ms on 5,000, 10,000 and 20,000 spaces and an `x`, and returns `0` without an error.
  - **A lookaround on the run** is reported only once confirmed mode replays it.
  - **`\G`** never is: a pattern holding `\G` is not confirmed by that pinned replay, because `\G` holds wherever an
    attempt is pinned.
  - **No witness** is found for a pattern some attempt may match inside the run; a run read through a bounded repeat
    above the unrolling cutoff (`\s{1,100}`, read as unbounded by the model, at most its bound per attempt on the
    engine); a start verb such as `(*NO_DOTSTAR_ANCHOR)` (the per-attempt verdict is then heuristic); and a search
    proof over the shared budget.
  - **Atomic repeats**: the engine's step counter sees nothing inside an atomic or possessive repeat of a single
    character set (`/a++b/`), so the replay runs the pattern with each such character in a group of its own
    (`/(?:a)++b/`), which changes no match and which the counter sees, as it sees a repeat of a longer word
    (`/(?:ab)++c/`, `/(?>a+b)+c/`). A repeat of every character that PCRE2 moves to the end of the subject in one step
    (`.*+` under `s`) stays as written, and so does a pattern where `\Q` may quote the group or whose delimiter is its
    `?`, `:` or `)`: a witness through such a repeat, read exactly by the model, is reported with `replayed: false`.
  - **Errors**: a library failure inside the search proof leaves `search_cost` null and the per-attempt verdict as
    proven; any other error is a bug, and the analysis reports it as an error.

**Search cost of the functions that look for every match**
- **Semantics:** For a pattern whose one attempt is proven linear and whose `search_cost` is null, a witness of the
  same form, read as PHP's retry after an empty match runs: every attempt inside the run refuses an empty match at its
  own start (`PCRE2_NOTEMPTY_ATSTART`), reads to the end of the run without matching, then fails; and the pattern
  matches the empty string inside the run, where `preg_match()` stops. `preg_match_all()`, `preg_replace()`,
  `preg_filter()`, `preg_replace_callback()`, `preg_replace_callback_array()` and `preg_split()` go on after each
  match and, after an empty one, retry the same offset with `PCRE2_NOTEMPTY_ATSTART | PCRE2_ANCHORED`
  (`ext/pcre/php_pcre.c`, since PHP 5.6.13), then move one character on: about n² steps. Each search of the loop
  starts where the last match ended, so `\G`, the `A` modifier and a leading `.*` tie nothing; `^` without `m` and
  `\A` do. It is reported as `every_match_search_cost`, and as the lint issue `regex.lint.redos.search` and the
  PHPStan identifier `regex.redos.search` at the calls of those functions that no literal limit bounds, at severity
  `medium`, a warning. Measured with PHP 8.4.28 and PCRE2 10.49, `/(?:a+\d)?/` on `"a" x n`, n = 2,000 / 4,000 /
  8,000 / 16,000: `preg_match_all()` 2.3 / 8.6 / 34 / 131 ms with `pcre.jit=0`, 1.8 / 6.8 / 27 / 106 ms with
  `pcre.jit=1`; `preg_match()` 0.01 ms.
- **Guarantee:** None in the other direction, as for `search_cost`. Confirmed mode replays the retry pinned at two
  offsets, `(*NOTEMPTY_ATSTART)` with the `A` modifier, without the JIT (`replayed`).
- **Limits:**
  - **The JIT** does not avoid it: PCRE2's JIT takes no `PCRE2_ANCHORED` at match time, so the retry always runs in
    the interpreter.
  - **A match that is short but not empty** after a long read (`/a(?:a+\d)?/`, `/a*b|a/`) costs the same loop the
    same way and is not reported.
  - **A pattern several calls read**, through a constant or a variable, is reported once, where its literal is
    written, with one finding for each function of those calls that looks for every match. Through a variable, a call
    runs in its loop the value of the latest assignment before it when that one runs first on every path to the call:
    a statement of the body, or of the block of braces the call stands in, that no later assignment reaches through a
    loop the block holds. `$p = '/a/'; preg_match_all($p, $s); $p = '/b/';` is no finding for `/b/`. The head of a
    `foreach` over literals gives each of them to a call in its body the body does not write it in first, and a
    composition joins what each variable holds where it is read. Where an assignment in a branch or a loop of its own
    may stand between, in a `switch` or in a block of the alternative syntax, the call names no loop.
  - The other limits of the search cost hold, but for `\G`, which the pinned replay of the retry confirms, as each
    call of the loop starts at that offset.

## Automata Solver

**Compare / Equivalence / Subset / Intersection**
- **Semantics:** The solver builds its NFA from the pattern's normalized form, where every character set — classes,
  `\w\s\d`, dot, POSIX classes, `\p{...}` properties, extended classes and case-insensitive folds — has already been
  asked from the PCRE2 that runs in the PHP process, then determinizes and compares languages using
  breadth-first search (BFS) over the product automaton (the state machine built by combining the two
  automata so that every shared or diverging path can be walked). Counter-examples are shortest strings
  in the modeled language.
- **Guarantee:** **Sound and complete** for the supported regular subset, **relative to the running PCRE2**: what a
  class, property or fold matches is what that engine matches, never a table the library maintains. Verdicts are
  deterministic for one PCRE2 release, and every result carries it in `pcreVersion`. Without `/u` the alphabet is the
  256 bytes; with `/u` it is the code points `U+0000`-`U+10FFFF` minus the surrogate block `U+D800`-`U+DFFF`, which
  no valid subject contains. POSIX classes (`[[:alpha:]]`, negated included), Unicode properties (`\p{L}`, `\P{L}`,
  scripts such as `\p{Greek}`), Perl extended classes (`(?[ \p{L} - [aeiou] ])`) with their set operations, and `\C`
  are answered, asked of the engine like every other atom.
- **Limitations:** Case-insensitive matching folds single code points, as the engine folds them: under `/iu`, `k`
  matches the Kelvin sign U+212A, `s` the long s U+017F and `å` the angstrom sign U+212B, while the Turkish dotless
  `i` stays apart, as in PCRE. Folds that produce several code points (the Turkish `İ`, the `DŽ` digraph) are not
  modeled. The flags `i`, `s`, `u`, `D`, `m` and `r` are read, and `x`, `U`, `n`, `J`, `S` and `X` change nothing a
  language says. `A` is refused, and so is a newline convention other than `\n`, such as `(*CR)`, when `$`, `\Z`,
  `/m` or `(?m)` would read the newline it sets, and so are `(*NOTEMPTY)` and `(*NOTEMPTY_ATSTART)`, which refuse an
  empty match without the JIT and which PHP's JIT ignores. `matchEquivalent()` reads the same flags except `m`, which
  it refuses, refuses the same start options, the newline conventions where `$` or `\Z` reads them, and refuses
  `(*CRLF)` in any pattern: under it the dot reads a `\r` alone but not one before a `\n`.
- **Lookarounds and anchors:** Lookaheads and lookbehinds, positive and negative, are read, and so are `\b` and `\B`,
  as the lookarounds they stand for. `^`, `$`, `\A`, `\z` and `\Z` are read wherever they stand, under `/m` too:
  `/(?:^|,)a/` and `/^\d+$/m` are answered. A lookaround inside a lookaround, an anchor or word boundary inside a
  lookaround, and a non-atomic lookaround (`(?*...)`, `(*napla:...)`) are refused.
- **Fallbacks:** None — there is no approximation. A construct outside the subset raises `ComplexityException` with
  one message per reason (backreferences, subroutines, callouts and control verbs, conditionals, nested lookarounds,
  anchors inside a lookaround, non-atomic lookarounds, atomic groups, `\K` and `\G`, unsafe possessives, the flag `A`,
  a newline convention other than `\n`, `(*NOTEMPTY)` and `(*NOTEMPTY_ATSTART)`, a surrogate code point named under
  `/u`, which PCRE refuses to compile); the list is in
  [the logic solver reference](logic-solver.md#what-the-solver-refuses). Atomic groups and possessive quantifiers commit to
  what they first matched and never retry — ordered behaviour the solver cannot read as a pure language
  (`/^a*+a$/` matches nothing at all), so they are refused — except a possessive quantifier nothing that follows can
  take back from: the first characters of everything after it, through the followers that may be skipped, share none
  with its atom (`a++` before `(?:ab)?` stays refused — PCRE rejects `aaab` there — while `b++` before `c?d` holds).
  Symfony's `[^/]++` route requirements keep being analyzed.

**Match modes**
- **FULL:** Models the exact match language `L(P)` — the set of all strings that pattern P matches in full
  (as if the pattern is wrapped in `\A(?:P)\z`). A `^` or `$` at the edge of the pattern is redundant
  under FULL because the whole string is already constrained.
- **PARTIAL:** Models search semantics `Σ* L(P) Σ*` (where `Σ*` means "any string" — the alphabet is every
  possible character). If a pattern is start-anchored (`\A`, or `^` without `/m`) the
  leading `Σ*` is removed; if it is end-anchored (`\z`, or `$` without `/m`) the trailing `Σ*` is removed, `$` still
  letting a final newline follow. Under `/m`, `^` and `$` do not anchor the search: they also hold at the line breaks
  inside the subject, so `/^a/m` finds the `a` of `"x\na"`.
- **Anchors in PARTIAL:** An anchor reads where it stands. `^` and `\A` hold at the start of the subject, `\z` at its
  end, `$` and `\Z` at the end or before a final newline, as PCRE's do without `/D`. Under `/m`, `^` also holds after
  a newline that more follows, and `$` before any newline. So `/a\Ab/` matches nothing, and `/(?:^|,)a/` finds an `a`
  at the start or after a comma. `\G` and `\K` are refused in any position.

## Symfony Bridge Analyzers

**Routes (`regex:routes`)**
- **Semantics:** Analyzes compiled Symfony route regexes with automata comparisons, pairing the routes in declaration order as the router's first match does: shadowed when an earlier route covers a later one, an overlap when they share some paths only. The trailing slashes of a request are read as the application's `symfony/routing` reads them: a route with no variable takes its path with any number of them before 6.4.51, 7.4.24 and 8.1.12, and on 8.0, and with one at most since.
- **Guarantee:** Sound for supported regex subset and route conditions considered in analysis.
- **Fallbacks:** Unsupported flags or host requirements are reported and skipped; route conditions are treated as unknown.

**Security Access Control (`regex:security`)**
- **Semantics:** Models access_control as search semantics (`Σ* L Σ*`) to match `preg_match` behavior, a path and a host as written, the host under `i` and the path under `s` where the application's `symfony/http-foundation` compiles it so (6.4.46, 7.4.19 and 8.1.7 on, never 8.0), as Symfony matches them (`{...}s` or `{...}`, `{...}i`).
- **Guarantee:** Sound for supported regex subset and listed rule constraints.
- **Fallbacks:** `allow_if`, IP constraints, and request matchers are reported in notes and excluded from automata checks.

**Firewall ReDoS (`regex:security`)**
- **Semantics:** Runs the ReDoS analyzer on firewall patterns.
- **Guarantee:** The analyzer's (see above); reports above-threshold findings with their verdict and attack.
- **Fallbacks:** `request_matcher` firewalls are skipped with a reason.

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
- **Guarantee:** Sound for supported transformations; does not claim completeness.
- **Fallbacks:** Unsafe rewrites are skipped when uncertainty is detected.

## ReDoS Analysis

**ReDoS Analyzer**
- **Semantics:** A prioritized NFA models PCRE's backtracking order; the analyzer computes the complexity class of one
  match attempt (linear, polynomial of degree k, exponential) and, for a vulnerable pattern, a witness
  `prefix . pump x n . suffix`.
- **Guarantee:** A `safe (proven)` verdict is **sound** for the model: it is given only when the model holds no
  exponential and no polynomial ambiguity, so in one match attempt at one start position no input drives a
  backtracking engine that follows PCRE's order beyond a linear number of steps, on the pattern as analysed. PCRE's
  optimizations can only lower that cost. The second search `preg_match()` runs without `$matches` after an empty
  match is covered. A proven vulnerable verdict is **not complete**: it certifies the class of the model, and
  confirmed mode reports whether the running PCRE2 reproduces it (`replayed`).
- **Limits:** Per match attempt. An unanchored search retries the attempt at each start position, and the every-match
  functions (`preg_match_all`, `preg_replace`, `preg_split`) retry the same way, so a degree-k per-attempt verdict
  costs up to n^(k+1) steps over an unanchored search. When one attempt is proven linear, the search cost is looked for
  apart (below). Lookaround constraints are not evaluated (a lookaround may fail);
  `{m,n}` above 16, and a bounded repeat whose copies can read the same input in two ways, are analysed as `{m,}`; an
  atomic body that is more than one run over one set is kept as written. Each abstraction is listed in
  `abstractions`. Verdicts are deterministic per analysis version and PCRE2 release.
- **Fallbacks:** Backreferences, conditionals, recursion, subroutine calls, verbs, callouts, `\X`, `\R`, non-atomic
  lookarounds, the `xx` option, `\b` and `\B` under two different ASCII scopes in one pattern, bounded repeats whose body can match empty, a pattern over the analysis budget, an
  ambiguity the analysis cannot witness, and a witness crossing an over-approximated atomic body or character class
  are judged by the structural heuristics (best-effort, as in 1.x), with `proof: heuristic` or `budget_exceeded`. An
  invalid pattern is never proven safe: `proof: not_analyzed`, severity `unknown`.

**Search cost**
- **Semantics:** For a pattern whose one attempt is proven linear, a witness `prefix . run x n . breaker` read from the
  same automaton: every attempt started inside the run reads to its end without matching, then fails on the breaker,
  which holds the last code unit every match requires. The prefix keeps the first attempt from matching the bare run
  (`"!"` for `/^\s+|\s+$/`). The unanchored search then costs about n²/2 steps in PCRE2's interpreter (`pcre.jit=0`,
  a build without JIT, `(*NO_JIT)`). It is reported as `search_cost`, the lint issue `regex.lint.redos.search` and the PHPStan identifier
  `regex.redos.search`, at severity `medium`, a warning.
- **Guarantee:** None in the other direction: `search_cost: null` means no witness was found, not that the search is
  linear. A reported witness holds on the model; confirmed mode, from a threshold of `medium` or lower, replays it
  without the JIT and counts the steps of attempts pinned at two offsets (`replayed`). Measured with PHP 8.4.26 and PCRE2 10.49:

  | pattern, subject | `pcre.jit=0` | `pcre.jit=1` |
  |---|---|---|
  | `/\s+$/`, `" " x n . "x"`, n = 10,000 / 20,000 | 497 / 1,992 ms | 0.0 ms; 1.9 ms at n = 800,000 |
  | `/^\s+\|\s+$/`, `"!" . " " x n . "!"`, n = 10,000 / 20,000 | 491 / 1,963 ms | 4.0 ms at n = 800,000 |
  | `/a+b/`, `"a" x n . "cb"`, n = 10,000 / 20,000 | 24 / 97 ms | 0.0 ms |
  | `/(?:ab)+c/`, 5,000 / 10,000 characters | 51 / 201 ms | 3.5 / 13.8 ms |
  | `/(?:a\|b)+c/`, 5,000 / 10,000 characters | 331 / 1,325 ms | 27.5 ms / `false`, JIT stack limit exhausted |
  | `/^\s+$/`, `" " x n . "x"`, n = 20,000 | 0.2 ms | 0.0 ms |

- **Limits:** The JIT is neither modelled nor measured: it stayed linear on every loop over one character probed and
  was quadratic, or gave up, on loops over a longer word, and the analysis never runs a pattern under it (some pattern
  and subject pairs crash PHP there, PCRE2 10.40 to 10.49). `pcre.backtrack_limit` is counted per attempt and does not stop the cost: at the default limit `/\s+$/`
  takes 123, 497 and 1,992 ms on 5,000, 10,000 and 20,000 spaces and an `x`, and returns `0` without an error. A
  lookaround on the run is reported only once confirmed mode replays it, and a pattern holding `\G` is never confirmed
  by that pinned replay (`\G` holds wherever an attempt is pinned); a pattern some attempt may match inside the run, a
  run read through a bounded repeat above the unrolling cutoff (`\s{1,100}`, read as unbounded by the model, at most
  its bound per attempt on the engine), a start verb such as `(*NO_DOTSTAR_ANCHOR)` (the per-attempt verdict is then
  heuristic), and a search proof over the shared budget give no witness. The step replay counts nothing inside an
  atomic or possessive repeat of a single character set (`/a++b/`): that witness, read exactly by the model, is
  reported with `replayed: false`; a repeat of a longer word (`/(?:ab)++c/`, `/(?>a+b)+c/`) is counted. A library
  failure inside the search proof leaves `search_cost` null and the per-attempt verdict as proven; any other error is a
  bug, and the analysis reports it as an error.

## Automata Solver

**Compare / Equivalence / Subset / Intersection**
- **Semantics:** The solver builds its NFA from the pattern's normalized form, where every character set — classes,
  `\w\s\d`, dot, POSIX classes, `\p{...}` properties, extended classes and case-insensitive folds — has already been
  asked from the PCRE2 that runs in the PHP process, then determinizes and compares languages using BFS over the
  product automaton. Counter-examples are shortest strings in the modeled language.
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
  modeled. The flags `i`, `s`, `u`, `D` and `m` are read, and `x`, `U`, `n`, `J`, `S` and `X` change nothing a
  language says. `A` and `r` are refused, and so is a start option such as `(*CRLF)` when `$`, `\Z` or `/m` would
  read the newline it sets. `matchEquivalent()` reads the same flags except `m`, which it refuses.
- **Lookarounds and anchors:** Lookaheads and lookbehinds, positive and negative, are read, and so are `\b` and `\B`,
  as the lookarounds they stand for. `^`, `$`, `\A`, `\z` and `\Z` are read wherever they stand, under `/m` too:
  `/(?:^|,)a/` and `/^\d+$/m` are answered. A lookaround inside a lookaround, an anchor or word boundary inside a
  lookaround, and a non-atomic lookaround (`(?*...)`, `(*napla:...)`) are refused.
- **Fallbacks:** None — there is no approximation. A construct outside the subset raises `ComplexityException` with
  one message per reason (backreferences, subroutines, callouts and control verbs, conditionals, nested lookarounds,
  anchors inside a lookaround, non-atomic lookarounds, atomic groups, `\K` and `\G`, unsafe possessives, the flags `A`
  and `r`, a newline convention other than `\n`, a surrogate code point named under `/u`, which PCRE refuses to
  compile); the list is in
  [the logic solver reference](logic-solver.md#what-the-solver-refuses). Atomic groups and possessive quantifiers commit to
  what they first matched and never retry — ordered behaviour the solver cannot read as a pure language
  (`/^a*+a$/` matches nothing at all), so they are refused — except a possessive quantifier nothing that follows can
  take back from: the first characters of everything after it, through the followers that may be skipped, share none
  with its atom (`a++` before `(?:ab)?` stays refused — PCRE rejects `aaab` there — while `b++` before `c?d` holds).
  Symfony's `[^/]++` route requirements keep being analyzed.

**Match modes**
- **FULL:** Models the exact match language `L(P)` (as if the pattern is wrapped in `\A(?:P)\z`). A `^` or `$` at the
  edge of the pattern is redundant under FULL because the whole string is already constrained.
- **PARTIAL:** Models search semantics `Σ* L(P) Σ*`. If a pattern is start-anchored (`\A`, or `^` without `/m`) the
  leading `Σ*` is removed; if it is end-anchored (`\z`, or `$` without `/m`) the trailing `Σ*` is removed, `$` still
  letting a final newline follow. Under `/m`, `^` and `$` do not anchor the search: they also hold at the line breaks
  inside the subject, so `/^a/m` finds the `a` of `"x\na"`.
- **Anchors in PARTIAL:** An anchor reads where it stands. `^` and `\A` hold at the start of the subject, `\z` at its
  end, `$` and `\Z` at the end or before a final newline, as PCRE's do without `/D`. Under `/m`, `^` also holds after
  a newline that more follows, and `$` before any newline. So `/a\Ab/` matches nothing, and `/(?:^|,)a/` finds an `a`
  at the start or after a comma. `\G` and `\K` are refused in any position.

## Symfony Bridge Analyzers

**Routes (`regex:routes`)**
- **Semantics:** Analyzes compiled Symfony route regexes with automata comparisons.
- **Guarantee:** Sound for supported regex subset and route conditions considered in analysis.
- **Fallbacks:** Unsupported flags or host requirements are reported and skipped; route conditions are treated as unknown.

**Security Access Control (`regex:security`)**
- **Semantics:** Models access_control as search semantics (`Σ* L Σ*`) to match `preg_match` behavior.
- **Guarantee:** Sound for supported regex subset and listed rule constraints.
- **Fallbacks:** `allow_if`, IP constraints, and request matchers are reported in notes and excluded from automata checks.

**Firewall ReDoS (`regex:security`)**
- **Semantics:** Runs the ReDoS analyzer on firewall patterns.
- **Guarantee:** The analyzer's (see above); reports above-threshold findings with their verdict and attack.
- **Fallbacks:** `request_matcher` firewalls are skipped with a reason.

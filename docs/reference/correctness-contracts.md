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
- **Limits:** Per match attempt: the retries of an unanchored search and the every-match functions (`preg_match_all`,
  `preg_replace`, `preg_split`) are not counted. Lookaround constraints are not evaluated (a lookaround may fail);
  `{m,n}` above 16, and a bounded repeat whose copies can read the same input in two ways, are analysed as `{m,}`; an
  atomic body that is more than one run over one set is kept as written. Each abstraction is listed in
  `abstractions`. Verdicts are deterministic per analysis version and PCRE2 release.
- **Fallbacks:** Backreferences, conditionals, recursion, subroutine calls, verbs, callouts, `\X`, `\R`, non-atomic
  lookarounds, the `xx` option, bounded repeats whose body can match empty, a pattern over the analysis budget, an
  ambiguity the analysis cannot witness, and a witness crossing an over-approximated atomic body or character class
  are judged by the structural heuristics (best-effort, as in 1.x), with `proof: heuristic` or `budget_exceeded`. An
  invalid pattern is never proven safe: `proof: not_analyzed`, severity `unknown`.

## Automata Solver

**Compare / Equivalence / Subset / Intersection**
- **Semantics:** For supported regexes, the solver builds an NFA and DFA and compares languages using BFS over the
  product automaton. Counter-examples are shortest strings in the modeled language.
- **Guarantee:** **Sound and complete** for the supported regular subset **under byte-based semantics**.
- **Limitations:** Unicode-aware semantics beyond case are not modeled. Case-insensitive matching folds Unicode case
  exactly, as single-code-point classes: under `/iu`, `k` matches the Kelvin sign U+212A, `s` the long s U+017F and `å`
  the angstrom sign U+212B, while the Turkish dotless `i` stays apart, as in PCRE.
- **Fallbacks:** Unsupported constructs raise `ComplexityException`. Atomic groups and possessive quantifiers commit to
  what they first matched and never retry — ordered behaviour the solver cannot read as a pure language
  (`/^a*+a$/` matches nothing at all), so they are refused — except a possessive quantifier nothing that follows can
  take back from: the first characters of everything after it, through the followers that may be skipped, share none
  with its atom (`a++` before `(?:ab)?` stays refused — PCRE rejects `aaab` there — while `b++` before `c?d` holds).
  Symfony's `[^/]++` route requirements keep being analyzed.

**Match modes**
- **FULL:** Models the exact match language `L(P)` (as if the pattern is wrapped in `\A(?:P)\z`). Explicit `^`/`$`
  anchors are redundant under FULL because the whole string is already constrained.
- **PARTIAL:** Models search semantics `Σ* L(P) Σ*`. If a pattern is start-anchored (`^`) the leading `Σ*` is removed;
  if it is end-anchored (`$`) the trailing `Σ*` is removed.
- **Anchor rules in PARTIAL:** Anchors must appear only at the outer boundary of each alternative (first/last token) and
  must be consistent across alternatives. Nested anchors (e.g., inside a group) are rejected with `ComplexityException`.

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

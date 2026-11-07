CHANGELOG
=========

The mutants a release produces are part of what it promises: a patch
release keeps every mutator's operators and their order, so a suite's
mutation score moves only with the code under test. New operators, new
mutators and deliberate changes to what is dropped come in a minor release,
listed here. A fix in the shared analysis that corrects a wrong verdict may
ship in a patch, listed here as a behavioural fix. The text of a kill string
may change in any release; Infection's identifier of a mutant does not
include it.

2.0
---

 * First release: six Infection mutators for the regex literals of
   `preg_*()` calls — `RegexQuantifier`, `RegexAnchor`, `RegexCharClass`,
   `RegexAlternation`, `RegexFlag` and `RegexEscape`.
 * A mutant the automata prove behaves as the original for the call is not
   produced: whether a match exists for `preg_match()` with a pattern and a
   subject alone and for `preg_grep()`, the matches in every run for every
   other call.
 * Each produced mutant carries the subject that kills it, checked against
   the running engine for the call as written, as `(?#kill: "…")` before the
   closing delimiter — `(?(DEFINE)kill: "…")` under the `#` delimiter.
 * Mutants the engine refuses, mutants whose backtracking the ReDoS
   analysis proves exponential where the original's is not, and mutants alike another one already
   produced are left out.

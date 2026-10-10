---
description: "Which PCRE constructs each PHPRegex layer reads: the parser, the lint and optimizer rules, the ReDoS model, and the automata solver's regular subset."
---

# Feature Support Matrix

This matrix summarizes which PCRE constructs are parsed and which are supported by each analysis layer.
For how closely validation matches PHP's engine on PCRE2's official test suite, see
[PCRE2 Conformance](pcre2-conformance.md).

Legend:
- **Yes**: Fully supported for the listed layer.
- **Partial**: Supported with limitations or approximations (see Notes).
- **No**: Not supported; will be ignored or rejected.

| Construct / Feature                          | Parser | Lint / Optimizer | ReDoS | Automata Solver |
|----------------------------------------------|--------|------------------|-------|-----------------|
| Literals & escaped literals                   | Yes    | Yes              | Yes   | Yes             |
| Character classes (`[...]`, negation)         | Yes    | Yes              | Yes   | Yes             |
| Character class ranges (`a-z`)                | Yes    | Yes              | Yes   | Yes             |
| Extended class ops (`(?[ \w - [x] ])`)        | Yes    | Partial          | Partial | Yes           |
| Dot (`.`)                                     | Yes    | Yes              | Yes   | Yes             |
| Alternation (`\|`)                            | Yes    | Yes              | Yes   | Yes             |
| Quantifiers (`* + ? {m,n}`)                   | Yes    | Yes              | Yes   | Yes             |
| Lazy / possessive quantifiers                 | Yes    | Partial          | Partial | Partial (possessive only) |
| Capturing groups                              | Yes    | Yes              | Yes   | Yes             |
| Non-capturing groups                          | Yes    | Yes              | Yes   | Yes             |
| Named groups                                  | Yes    | Yes              | Yes   | Yes             |
| Inline flags (`(?i)`, `(?-i)`)                | Yes    | Partial          | Partial | Partial        |
| Anchors (`^`, `$`)                            | Yes    | Yes              | Yes   | Yes             |
| Assertions (`\b`, `\B`, `\A`, `\z`, `\Z`, `\G`) | Yes    | Partial          | Partial | Partial (all but `\G`) |
| Lookahead / lookbehind                        | Yes    | Partial          | Partial | Partial        |
| Backreferences (`\1`, `\k<name>`)             | Yes    | Partial          | Partial | No             |
| Subroutines (`(?&name)`, `(?R)`)              | Yes    | Partial          | Partial | No             |
| Conditionals                                  | Yes    | Partial          | Partial | No             |
| Unicode properties (`\p{...}`)                | Yes    | Partial          | Partial | Yes            |
| POSIX classes (`[[:alpha:]]`)                 | Yes    | Partial          | Partial | Yes            |
| Script runs / extended classes                | Yes    | Partial          | Partial | Partial (extended classes only) |
| PCRE verbs (`(*FAIL)`, `(*SKIP)`, ...)         | Yes    | Partial          | Partial | No             |
| `\K` keep reset                               | Yes    | Partial          | Partial | No             |

Notes:
- **Automata solver**: lookarounds, anchors, `\b` and `\B` are read wherever they stand. The `Partial` cells of its
  column mean part of the row is refused: a lookaround inside a lookaround, an anchor or word boundary inside a
  lookaround, the non-atomic `(*napla:...)`, a possessive quantifier the solver cannot prove inert, `\G` (with
  `\K`), atomic groups, backreferences, subroutines, conditionals, verbs and the flag `A` — one message per reason.
  The constructs it reads, the flags it takes (`i`, `s`, `u`, `D`, `m` and `r`; `x`, `U`, `n`, `J`, `S` and `X`
  change nothing a language says) and every refusal are stated once, in
  [the logic solver reference](logic-solver.md) and [Correctness Contracts](correctness-contracts.md).
- **Automata solver** asks every character set from the running PCRE2, so its verdicts are engine-relative and each
  result carries the release that answered (`pcreVersion`).
- **Lint / Optimizer / ReDoS** rules are intentionally conservative and may skip unsupported constructs rather than
  fail the whole analysis.

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
| Class ops (`&&`, `--`)                        | Yes    | Partial          | Partial | Yes           |
| Dot (`.`)                                     | Yes    | Yes              | Yes   | Yes             |
| Alternation (`\|`)                            | Yes    | Yes              | Yes   | Yes             |
| Quantifiers (`* + ? {m,n}`)                   | Yes    | Yes              | Yes   | Yes             |
| Lazy / possessive quantifiers                 | Yes    | Partial          | Partial | Yes           |
| Capturing groups                              | Yes    | Yes              | Yes   | Yes             |
| Non-capturing groups                          | Yes    | Yes              | Yes   | Yes             |
| Named groups                                  | Yes    | Yes              | Yes   | Yes             |
| Inline flags (`(?i)`, `(?-i)`)                | Yes    | Partial          | Partial | Partial        |
| Anchors (`^`, `$`)                            | Yes    | Yes              | Yes   | Yes (outer boundaries) |
| Assertions (`\b`, `\B`, `\A`, `\z`, `\G`)      | Yes    | Partial          | Partial | Partial (outer boundaries) |
| Lookahead / lookbehind                        | Yes    | Partial          | Partial | No             |
| Backreferences (`\1`, `\k<name>`)             | Yes    | Partial          | Partial | No             |
| Subroutines (`(?&name)`, `(?R)`)              | Yes    | Partial          | Partial | No             |
| Conditionals                                  | Yes    | Partial          | Partial | No             |
| Unicode properties (`\p{...}`)                | Yes    | Partial          | Partial | Yes            |
| POSIX classes (`[[:alpha:]]`)                 | Yes    | Partial          | Partial | Yes            |
| Script runs / extended classes                | Yes    | Partial          | Partial | Partial (extended classes only) |
| PCRE verbs (`(*FAIL)`, `(*SKIP)`, ...)         | Yes    | Partial          | Partial | No             |
| `\K` keep reset                               | Yes    | Partial          | Partial | No             |

Notes:
- **Automata solver** supports the regular subset: literals, character classes and ranges, dot, POSIX classes,
  Unicode properties, extended classes, `\C`, alternation, groups and quantifiers, with the `i`, `s` and `u` flags
  (inline flags applied where they hold). It rejects lookarounds, backreferences, subroutines, conditionals, verbs
  and `\K`, with one message per reason — see
  [the logic solver reference](logic-solver.md#what-the-solver-refuses).
- **Automata solver** asks every character set from the running PCRE2, so its verdicts are engine-relative and each
  result carries the release that answered (`pcreVersion`). Without `/u` the alphabet is the 256 bytes; with `/u` it
  is the code points minus the surrogate block `U+D800`-`U+DFFF`.
- **Lint / Optimizer / ReDoS** rules are intentionally conservative and may skip unsupported constructs rather than
  fail the whole analysis.

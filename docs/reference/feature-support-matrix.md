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
| Inline flags (`(?i)`, `(?-i)`)                | Yes    | Partial          | Partial | No             |
| Anchors (`^`, `$`)                            | Yes    | Yes              | Yes   | Yes (outer boundaries) |
| Assertions (`\b`, `\B`, `\A`, `\z`, `\G`)      | Yes    | Partial          | Partial | No             |
| Lookahead / lookbehind                        | Yes    | Partial          | Partial | No             |
| Backreferences (`\1`, `\k<name>`)             | Yes    | Partial          | Partial | No             |
| Subroutines (`(?&name)`, `(?R)`)              | Yes    | Partial          | Partial | No             |
| Conditionals                                  | Yes    | Partial          | Partial | No             |
| Unicode properties (`\p{...}`)                | Yes    | Partial          | Partial | No             |
| POSIX classes (`[[:alpha:]]`)                 | Yes    | Partial          | Partial | No             |
| Script runs / extended Unicode escapes        | Yes    | Partial          | Partial | No             |
| PCRE verbs (`(*FAIL)`, `(*SKIP)`, ...)         | Yes    | Partial          | Partial | No             |
| `\K` keep reset                               | Yes    | Partial          | Partial | No             |

Notes:
- **Automata solver** supports the regular subset only: literals, character classes, dot, alternation, groups, and
  quantifiers. It rejects lookarounds, backreferences, subroutines, conditionals, verbs, and `\K`.
- **Automata solver** operates on **UTF-8 bytes** (0-255). Unicode-aware semantics (e.g., `\p{L}`, `\w` under `/u`)
  are not modeled.
- **Lint / Optimizer / ReDoS** rules are intentionally conservative and may skip unsupported constructs rather than
  fail the whole analysis.

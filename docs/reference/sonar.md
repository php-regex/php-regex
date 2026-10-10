---
description: "The PHPRegex identifier behind each of SonarPHP's 25 regex rules — covered, covered in part, or out of scope — for teams moving from Sonar or running both."
---

# SonarPHP Regex Rules

SonarPHP checks the regular expressions of PHP code with 25 rules. This page names, for
each of them, the PHPRegex identifier that reports the same defect, says whether PHPRegex
covers it fully or in part, and why a rule is left out. A team moving from SonarPHP, or
running both, can find every Sonar finding here and silence it once. Each rule carries its
Sonar S-id, so its compliance examples can be pulled up in the Sonar product the team runs.

Lint identifiers (`regex.lint.*`) are reported by `regex lint`, the PHPStan rule with lint
enabled, the language server and the framework commands; each is described in the
[Lint Rule Reference](rules.md). A rule marked off by default runs once
`checks.lint.rules` in `regex.json` turns it on. The PHPStan rule never runs those: it
lints with no rule turned on beyond the defaults, so S5857, S6326 and S6397 are reported
by `regex lint`, the language server and the Symfony and Laravel commands only.

| Sonar rule | What it reports | PHPRegex | Coverage |
|---|---|---|---|
| S5361 | `preg_replace()` where `str_replace()` does | the Rector rules ([Rector guide](../guides/rector.md)); PHPStan `regex.trivialMatch` for `preg_match()` | covered |
| S5842 | a repeated part that can match the empty string | `regex.lint.quantifier.emptyRepeat` | covered; silent where an empty alternative, a quantified lookaround or a nested quantifier already reports the repeat, even when the configuration turns that rule off |
| S5843 | an overly complex expression | `regex.lint.complexity` | partial: PHPRegex keeps its own complexity score |
| S5850 | anchors that bind to one alternative only | `regex.lint.anchor.alternationPrecedence` | covered; silent, as in SonarPHP, when an anchor sits anywhere but the start of the first alternative and the end of the last, and when every alternative is anchored on one side |
| S5855 | redundant alternatives | `regex.lint.alternation.duplicateDisjunction` | partial: identical alternatives only |
| S5856 | an invalid pattern | the validation every entry point runs (`regex lint` errors, PHPStan core and `regex.invalidForTarget`) | covered |
| S5857 | a reluctant quantifier a negated class would replace | `regex.lint.quantifier.lazyToClass` (off by default) | covered for `.*?` and `.+?` before one character, when the automata prove the rewrite writes the same `$matches` |
| S5867 | `[a-zA-Z]` where a Unicode class would serve | none | out of scope: `\p{L}` matches other text than `[a-zA-Z]`, so the advice changes what the pattern matches |
| S5868 | a grapheme cluster inside a class | `regex.lint.unicode.multibyteInClassWithoutU` | partial: a multibyte character without `/u` only; under `/u` a combining-mark table would be needed |
| S5869 | a character twice in a class | `regex.lint.charclass.redundant`, `regex.lint.charclass.duplicateChars` | covered |
| S5994 | an atom a possessive quantifier always starves | `regex.lint.quantifier.possessiveImpossible` | covered for atoms that read one character |
| S5996 | a boundary that can never match | `regex.lint.anchor.impossible.start`, `regex.lint.anchor.impossible.end`, `regex.lint.anchor.impossible.boundary` | covered; `\b` and `\B` between atoms that read one character |
| S6001 | a back reference to a group not matched before it | `regex.lint.backref.undefined`, `regex.lint.backref.useless` | covered |
| S6002 | a contradictory lookahead | `regex.lint.lookaround.impossible` | covered on the regular subset the automata read; silent past it |
| S6019 | a reluctant quantifier followed only by what can match nothing | `regex.lint.quantifier.lazyEnd` | covered |
| S6035 | single-character alternatives a class would replace | the optimization suggestions of `regex lint` and PHPStan `regex.optimization` | out of scope as a lint rule: the optimizer reports the shorter pattern |
| S6323 | an empty alternative | `regex.lint.alternation.empty` | covered |
| S6326 | several spaces in a row | `regex.lint.literal.multipleSpaces` (off by default) | covered |
| S6328 | a replacement that refers to a group the pattern does not have | PHPStan `regex.replacement.undefinedGroup` | covered in PHPStan only: `regex lint` reads patterns, not the replacement argument |
| S6331 | an empty group | `regex.lint.group.empty` | partial: `(?:)` and `(?>)`; an empty capturing group `()` is a placeholder that numbers a group and is not reported, nor is a group a quantifier repeats (`a(?:)?` is not `a?`) or one that keeps an escape from a digit (`\1(?:)0`) or braces from a count (`a{(?:)2}`) |
| S6353 | a quantifier or class written longer than needed | the optimization suggestions of `regex lint` and PHPStan `regex.optimization` | out of scope as a lint rule: the optimizer reports the shorter pattern |
| S6393 | a pattern without valid delimiters | the validation every entry point runs | covered |
| S6395 | a non-capturing group without a quantifier | `regex.lint.group.redundant` | partial: a group around one atom only |
| S6396 | a superfluous curly-brace quantifier | `regex.lint.quantifier.useless`, `regex.lint.quantifier.zero` | covered |
| S6397 | a class of a single character | `regex.lint.charclass.single` (off by default) | covered; a class of one metacharacter, the delimiter, a multibyte character without `/u`, or a character that reads otherwise bare (`[\1]`, the `[0]` of `\1[0]`) is left alone |

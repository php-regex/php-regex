CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * The lookaround bodies of a pattern share one allowance: their states count
   together against `maxNfaStates` and `maxDfaStates`, and their work against
   one `maxTransitionsProcessed` budget, whose diagnostic names the
   `lookaround bodies` phase. A lookaround repeated by a quantifier is compiled
   once: `/(?:(?=a{99})){100}a{99}/` no longer builds a hundred automata.
 * `Language::strings()` and `nonMembers()` bound the search for each next
   string, a million steps and 50,000 remembered states for one length, and
   throw a `ComplexityException` past either: the second string of
   `/^(?:b|a{400})$/` took 6 seconds to find, `a{2000}` more than two
   minutes, and every state it remembered stayed in memory as long as the
   language did.
 * `matchEquivalent()` visits each state once when it checks that nothing
   but the end follows an end anchor: `/$(?:|)(?:|)…/` with thirty empty
   alternations answers at once instead of walking 2^30 paths.
 * `TrivialMatchClassifier::classify()` stops at 5,000 bytes, as
   `matchedLiteral()` does, while it combines the strings of a sequence: a
   pattern of twenty nested `(?:…){4}` around `a`, 4^20 bytes spelled out, no
   longer exhausts memory, and the answer is `null`.

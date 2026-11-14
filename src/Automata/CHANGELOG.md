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
 * The product of a pattern's automaton with its lookarounds reads each state
   on the classes of the alphabet, the characters that belong to the same
   sets, where it read every range, and counts each move against a
   `maxTransitionsProcessed` budget of its own, whose diagnostic names the
   `lookaround product` phase. Twelve lookaheads of Unicode classes,
   `/^(?=.*\p{Lu})(?=.*\p{Ll})(?=.*\p{N})...{12,}$/u`, are refused for
   `maxNfaStates` in 0.3 s where they took 39 s, none of it counted, and a
   password rule of six lookaheads is answered in 20 ms where it took 470 ms.
   A pattern whose determinization ran out of budget on the ranges the
   product had cut its transitions into can now be answered.
 * The determinization reads the moves of a state once for each class of the
   alphabet, the ranges every set of the automaton holds alike, where it read
   them once for each range, and the minimization splits its groups on one
   range of each such class. The automata are the same; the password rule
   `/^(?=.*\p{Lu})(?=.*\p{Ll})(?=.*\p{N})(?=.*\p{P}).{8,}$/u` compiles in
   0.3 s where it took 1.4 s, and with `.{12,}` or `.{16,}`, which ran out of
   the default budget, it is answered.
 * `Language::countOfLength()` and `size()` multiply once for each state a
   move leads to, however many ranges spell its characters, and leave out the
   states no string is accepted from: `size()` of `/[ab]{0,100}/` answers its
   31 digits in some 60 ms where it took 4.7 s, and the counts stay exact.
   `countOfLength()` of a negative length is `"0"`.
 * `rewritesAlikeInPlace()` fills at most 65,536 cells, across all the sites
   of the two patterns, when it pairs their parts by those both keep, and
   throws a `ComplexityException` past them: the optimizer keeps a pattern of
   six thousand optional parts behind a lookbehind as written instead of
   reaching a peak of nearly 1 GB to prove its rewrite.
 * `matchEquivalent()` counts at every state it adds against `maxNfaStates`,
   and counts building its alphabet against `maxTransitionsProcessed`: a
   literal of fifteen thousand distinct characters under `u` is refused in
   under a second instead of being compared for more than a minute, and one of
   a thousand reaches the default work budget.
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

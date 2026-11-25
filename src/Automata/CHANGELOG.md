CHANGELOG
=========

2.0
---

 * Without u, the sets of a pattern are read as the target's PCRE2 reads
   them: under `(*UCP)` a byte read caselessly has the tables' case before
   10.35, `/(*UCP)\xC9/i` matching `\xC9` alone for PHP 7.4 with PCRE2
   10.34; and under another locale's tables a class or a caseless byte
   the engine's paths read apart, the interpreter comparing a character
   alone by the small letters of the tables, is refused with a
   `ComplexityException`, where the answer held for one path alone. Under
   macOS's `tr_TR.ISO8859-9`, `intersection('/^\xFD$/i', '/^I$/')` was
   empty, though the JIT matches `I` with `/\xFD/i`.
 * `Language` gains a boolean algebra: `union()`, `intersect()`, `minus()`,
   `complement()`, `isSubsetOf()` and `shortestExample()`, the least of the
   shortest strings. A language read as UTF-8 and one read as bytes are
   refused together (`Exception\IncompatibleArgumentException`, an
   `InvalidArgumentException`); a product past 100,000
   pairs of states is refused with the `product` phase.
 * `Ordered\FirstMatchPartition` reads a first-match list, routes or
   `access_control` rules, all at once: each candidate keeps the strings no
   earlier candidate takes, `Eff(i) = L(i) \ (L(1) ∪ ... ∪ L(i - 1))`, with
   the least shortest string it keeps, the earlier ones that take some of
   its strings and whether only several of them together take them all. A
   candidate is a set of tuples of patterns (a method, a scheme, a host, a
   path), joined by a separator past Unicode, never through their text.
 * `SolverOptions::$strategy` chooses how `intersection()`, `subsetOf()` and
   `equivalent()` search: `SolverStrategy::Lazy`, the default, walks the
   product of the NFAs as it is reached, the right side of an inclusion
   determinized one reached state at a time; `SolverStrategy::Eager` walks
   the product of the minimized DFAs, as before. Both give the same witness,
   the least of the shortest. `(a|b)*a(a|b){15}` meets `b+` in under a
   millisecond, where its DFA ran past the 10,000-state limit. The lazy
   search stops at the depth of the shortest witness, keeps its pairs under
   `maxDfaStates` as it reaches them, and past either limit leaves the answer
   to the eager search: 2,000 branches `a|a|…`, four million pairs of NFA
   states, are answered from their three-state DFA. With a DFA cache, or a
   pattern read as UTF-8 beside one read as bytes, the search stays eager.
 * A determinized state holds one target per class of its automaton's
   alphabet, the ranges held once by an alphabet every state shares:
   `^[\p{L}\p{N}]{3,64}$/u ⊆ ^\w{3,64}$/u` takes 22 ms and 1.4 MB, where it
   took 377 ms and 44 MB, and the work budget charges one unit per class
   written. Witnesses are unchanged.
 * An atomic group or `\R` that commits to nothing reads as its body:
   `^(?>\d+)-x$` is equivalent to `^\d+-x$`, and `^a\Rb$` is answered. A
   commitment that keeps a match a follower needs, `^(?>a|ab)c$`, stays
   refused, and so does one a lookbehind ends with that a character may
   take further, `(?<=\R)`.
 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `equivalent()`, `intersection()` and `subsetOf()` compare a pattern read
   as UTF-8 with one read as bytes, `(*UTF)` or `u` on one side only, on the
   valid UTF-8 strings: the one read as bytes reads each character as its
   bytes. `/(*UTF)\Aé\z/` and `/\Aé\z/` are equivalent, where they were told
   apart on `Ã©`, which neither matches; `/(*UTF).*(?:^\/admin\/users).*/` is
   a subset of `/.*(?:^\/admin).*/`. A string that is not valid UTF-8 is not
   compared.
 * `matchEquivalent()` reads a call of a group, `(?1)`, `(?-1)`, `(?+1)`,
   `(?&n)`, `(?P>n)` or `\g<n>`, as the body of the group run where the
   call stands: the engine backtracks into it, the groups inside it are
   given back the values they held before it, and it keeps the options the
   group was written under. `/(a|ab)(?1)c/` and `/(a|ab)(?:a|ab)c/` are
   proven to write the same `$matches`. A call that may run its own group
   again, `(?R)` included, and a call that returns groups, `(?1(2))`, stay
   refused, with a message of their own.
 * `matchEquivalent()` refuses a pattern that holds a possessive quantifier
   inside a group a call runs, a group a DEFINE holds included, or anywhere
   in a pattern that calls itself whole, and `runsOfEveryCall()` names no
   run for it: PCRE2's JIT, which PHP runs by default, misses matches of
   such a pattern that its interpreter finds, and the match solver reads
   the interpreter. With the JIT, `/(a++)c(?1)d/` finds no match in
   `acacad` from PCRE2 10.34 to 10.49, where `/(a+)c(?1)d/` and the
   interpreter match `acad`. `/(a+b?)c(?1)d/` and `/(a++b?)c(?1)d/` are
   refused too, though no subject was found on which they differ.
 * `matchEquivalent()` runs a group a `(?(DEFINE)...)` holds where a call
   of it stands, under the options in force where the DEFINE stands, not
   where the call does: `/(?&n)c(?(DEFINE)(?<n>a|ab))/` and
   `/(?:a|ab)c(?(DEFINE)(?<n>a|ab))/` are proven to write the same
   `$matches`, and `/(?i:(?(DEFINE)(?<n>a)))(?&n)/` is told apart from
   `/(?i:(?(DEFINE)(?<n>a)))a/` on `A`. Such a group takes its number and
   its name, as a group repeated no time does: `/(?(DEFINE)(?<n>x))(a)/`
   and `/(?(DEFINE)(?<m>x))(a)/`, proven to write the same `$matches`, are
   told apart on `a`.
 * `matchEquivalent()` proves two patterns that differ only in possessive
   quantifiers outside every group a call runs, where a call may run its
   own group again, by reading through every call: a call that runs its
   group again leads back to where the group starts, and the end of the
   group to every place a call of it returns to, however deep the calls
   run. Where nothing that may follow a possessive quantifier starts as its
   body does, it gives nothing back: `/a+b(x(?1)?y)/` and
   `/a++b(x(?1)?y)/` are proven to write the same `$matches`. Only
   quantifiers over one character are read so: a possessive quantifier
   over a group, capturing or not, keeps a frame of the JIT for each run of
   the call around it, and `/x(?R)?(?:ab)++c/` runs out of the JIT stack
   some 3,000 calls deep where `/x(?R)?(?:ab)+c/` matches.
 * PCRE2 makes a character repeated before an atomic group, or a
   possessive repeat of a group, that may match nothing possessive, as if
   the group could not be skipped: `/a+(?:b)?+a/` finds nothing in `aa`,
   with and without the JIT, from PCRE2 10.34 to 10.49, where `/a+(?:b)?a/`
   finds `aa`. The two were proven to write the same `$matches`, and
   `/^a+(?:b)?+a$/` to match the strings of `/^a+(?:b)?a$/`.
   `matchEquivalent()` now proves such a repeat only where its possessive
   spelling gives nothing back, `/a+(?:b)?+c/` and `/a+(?:b)?c/` alike, and
   `equivalent()`, `intersection()` and `subsetOf()` refuse it. Two
   patterns whose brackets tell such a group apart no longer read alike:
   `/(?:a)+(?:b)?+a/` and `/a+b?+a/` match `aa`, where `/a+(?:b)?+a/` does
   not. The optimizer no longer rewrites `/a+(?:b|c)?+a/` into
   `/a+[bc]?+a/`, which matches `aa`.
 * `matchEquivalent()` refuses a possessive repeat of a group that holds a
   capturing group for a target before PCRE2 10.44: the JIT of 10.39 to
   10.42 leaves the group set from an earlier start of the match, and
   `/a(a)*+b/` writes `a` to group 1 on `aacab`, where `/a(a)*b/` leaves it
   unset.
 * Without `u`, under a target that names no locale but C,
   `matchEquivalent()`, `equivalent()`, `intersection()` and `subsetOf()`
   refuse a possessive quantifier, or an atomic group, proven on a byte past
   ASCII against `\w`, `\s`, their negations, a POSIX class, or a class
   under `i` that holds some of the letters and the bytes past ASCII but not
   all: PHP hands PCRE2 the tables of the locale a program sets, and under
   en_IE.ISO8859-1 `/c\xE9++b?\w/` finds nothing in `c\xE9\xE9`, where
   `/c\xE9+b?\w/` finds it whole. `[^.]++\.` under `i`, as a route host
   compiles, is still read. A target that names its locale is read under
   its tables.
 * Without `u`, PCRE2 makes a repeat of the byte 0xFF possessive before
   `\w`, and a repeat of `\w` possessive before that byte, lazy ones too,
   as if 0xFF were no word character, whatever the tables say: under
   fr_FR.ISO8859-1, where `\w` takes 0xFF, `/\xFF+\w/` finds nothing in
   `\xFF\xFF` and `/\w+\xFF/` nothing in `a\xFF\xFF`, with and without the
   JIT, in PCRE2 10.49, where `/\xFF+[\w]/`, `/(?:\xFF)+\w/` and
   `/[\w]+\xFF/` find them. `/\xFF+\w/` and `/\xFF+[\w]/` were proven to
   write the same `$matches`, and to match the same strings, and
   `/(?:\xFF)+\w/` to read like `/\xFF+\w/`. `matchEquivalent()`,
   `equivalent()`, `intersection()`, `subsetOf()`, `language()` and
   `compile()` now refuse a pattern with such a repeat, of the byte, of a
   class that names it, or of U+0178, which folds to it under UTF, with
   `\w` outside a class, or that byte, anywhere after it, and such a
   pattern no longer reads like another, unless the target names a locale
   whose tables leave 0xFF out of `\w`. A target that names no locale but
   C may run under any, and is refused too.
 * `matchEquivalent()` refuses an end anchor inside a loop that may run no
   time, `*`, `*?` or `{0,}`, as it refuses one inside `+`, when another turn
   of the loop or what follows it reads more: `/(?:a$)*\n/` was proven
   to write the `$matches` of `/\n/`, where `preg_match()` takes `a\n` at 0
   for the one and `\n` at 1 for the other. The optimizer no longer offers
   such a rewrite.
 * `equivalent()`, `intersection()`, `subsetOf()`, `language()` and
   `compile()` read `^` and `$` under `m`, and `$` and `\Z`, under `(*CR)`,
   `(*NUL)`, `(*ANYCRLF)` and `(*ANY)` as PCRE2 does, where they were
   refused: a line ends at `\r` under the first, at NUL under the second,
   and under the last two at a `\r`, a `\n` or both together, under `(*ANY)`
   at the vertical tab, the form feed and NEL too, and at U+2028 and U+2029
   under `/u`. `^` then holds between a `\r` and a `\n`:
   `/(*ANYCRLF)\r^\n/m` matches `"\r\n"`. `(*CRLF)` stays refused there.
 * A search under `(*CRLF)`, `(*ANYCRLF)` or `(*ANY)` that may find a match
   only from between a `\r` and the `\n` after it is refused: PCRE2 skips
   that start where the pattern writes neither, and after its first try
   where a `^` under `m` or a `.*` leads it, which the JIT does not always
   do. `/(*CRLF)(?<=\s)\s/` was read as finding `"\n"` in `"\r\n"`, where
   `preg_match()` finds nothing; the whole string is still read.
   `matchEquivalent()` refuses a pattern under `(*ANYCRLF)` or `(*ANY)` that
   may match empty or start with a `\n`: `/(*ANYCRLF)\sa/` was proven to
   write the `$matches` of `/(*ANYCRLF)\sa|[^\s\S]\n/`, where without the
   JIT the one finds nothing in `"\r\na"` and the other `"\na"`.
 * `equivalent()`, `intersection()` and `subsetOf()` refuse a dot or `\N`
   under `(*CRLF)`, which reads a `\r` alone but not one before a `\n`:
   `/(*CRLF)a.\n/` was read as matching `"a\r\n"`, where `preg_match()`
   finds nothing.
 * `matchEquivalent()` refuses a newline convention other than `\n`, such as
   `(*CR)`, where `$` or `\Z` reads it, as `equivalent()` does:
   `/(*CR)a$/` was proven to write the `$matches` of `/a$/`, where
   `preg_match()` finds nothing in `a\n` with the one and `a` with the other.
   The lint no longer calls the lazy marker of `/(*CR)a\r*?(?:$|b)/` useless.
   The last convention the options name holds: `/(*CR)(*LF)a$/` is read.
 * `equivalent()`, `intersection()` and `subsetOf()` refuse a newline
   convention other than `\n` under an inline `(?m)` as under `/m`:
   `/(*CR)(?m)^a/` was proven equivalent to `/(?m)^a/`, where `preg_match()`
   finds `a` in `\ra` with the one and nothing with the other. `compare` no
   longer passes them.
 * `equivalent()`, `intersection()`, `subsetOf()` and `matchEquivalent()`
   refuse `(*NOTEMPTY)` and `(*NOTEMPTY_ATSTART)`, which refuse an empty
   match without the JIT and which PHP's JIT ignores: `/(*NO_JIT)(*NOTEMPTY)a?/`
   was proven alike `/a?/`, where `preg_match()` finds no match in `b` with
   the one and `""` with the other.
 * `matchEquivalent()` counts the groups of a body repeated no time, `{0}`:
   `/(?<x>a){0}b/` was proven to write the `$matches` of `/b/`, where
   `preg_match_all()` writes an empty group `x` for the one and none for the
   other. A group it cannot number there, inside a `(*scs:...)`, is refused.
 * `equivalent()` and `matchEquivalent()` refuse an anchor, an assertion or a
   lookaround in a group repeated no time, which PCRE2 still reads to choose
   where it tries a match: `/(?:x|(?=a)){0}\w/` was proven alike `/\w/`, where
   `preg_match()` finds no match in `\tb` with the one and `b` with the other.
   `matchEquivalent()` refuses a verb, a callout or a conditional there too, as
   it does anywhere else. The optimizer and the Infection judge no longer take
   such a pair as proven.
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

CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * Under `m`, `^` holds after a newline only where a character follows,
   never at the end of the subject: `preg_match()` gives 0 for `/\n^/m` on
   `"a\n"`. The model counted a success there, and proved linear a loop
   that fails every way through it: `/\A(?:\n|\n)+(?:^\z|[^\n])/m` and
   `/(?:\n|\n)+?(?:^\z|.)/mu` are exponential, no longer safe (proven),
   and PHP exhausts its backtrack limit on 18 newlines without the JIT, 20
   with it. A loop whose `^` holds only once it gives a newline back, as
   in `/\A(?:\n|\n)+^/m`, gets no proven verdict, as `/^(?:a|a)+./` does
   not.
 * A loop is no longer set aside on a success the analysis widens. A
   bounded repeat read as unbounded, and an atomic body kept with every way
   through it, made the continuation after an ambiguous loop look sure to
   succeed where the engine fails: `/^(?:a|a)+[^x]{0,20}(?:x|\z)/` was safe
   (proven) while PHP exhausts its backtrack limit on 20 `a` and 30 `b`, and
   so were `/^(?:a|a)+(?:[^x]|[^x]){0,3}(?:x|\z)/`, `/^(?:a+)+(?>.*|y)\b/`
   and `/^a*a*[^x]{0,20}(?:x|\z)/`, quadratic. A success now sets a loop
   aside only on a path that takes one round at most of such a repeat and
   goes through no such body: `/^(?:a|a)+[^x]*(?:x|\z)/`,
   `/^(?:a|a)+.{0,20}/` and `/^(?:a|a)+(?>[^x]*)(?:x|\z)/` stay safe
   (proven).
 * `RedosAnalysis::$everyMatchSearchCost`, and `every_match_search_cost` in
   its JSON: the search cost of the functions that look for every match.
   After an empty match PHP tries the same offset again with
   `PCRE2_NOTEMPTY_ATSTART | PCRE2_ANCHORED`, in PCRE2's interpreter whatever
   `pcre.jit`, so a pattern that matches the empty string at each position
   of a run and whose retry reads to its end, as `/(?:a+\d)?/`, `/(?:a+b)?/`
   or `/\G(?:a+\d)?/`, costs `preg_match_all()` and its kin n² there, while
   `preg_match()` stops at once: 2.3, 8.6, 34 and 131 ms on 2,000 to 16,000
   `a` with `pcre.jit=0`. Looked for only when one attempt is proven linear
   and `$searchCost` is null, which keeps its meaning; confirmed mode replays
   the retry. Severity `medium`, hidden at the default `high` threshold.
 * The search cost reads the options that refuse an empty match. Under
   `(*NOTEMPTY)` no attempt ends on the empty string, so
   `/(*NOTEMPTY)(?:a+\d)?/`, which tries `a+\d` at every position of a run
   of `a`, is reported quadratic, where `/(?:a+\d)?/` matches the empty
   string at once. Under `(*NOTEMPTY_ATSTART)` only the attempt at the
   search start refuses it: `/(*NOTEMPTY_ATSTART)(?:^|a+b)/`, whose empty
   match holds at the subject's start alone, is quadratic, while
   `/(*NOTEMPTY_ATSTART)(?:a+\d)?/`, which matches the empty string at the
   next position, is not reported. A match `\K` leaves empty is empty too:
   `\K` sets the start of the match where it stands, so `/(*NOTEMPTY)a+\K/`
   and `/(*NOTEMPTY)a+\Kb?/`, which give back every `a+` at every position
   of a run of `a`, are reported quadratic, where `/a+\K/` matches the
   empty string at the run's end at once. Under `(*NOTEMPTY_ATSTART)` that
   empty match stands past the search start: `/(*NOTEMPTY_ATSTART)a+\K/`
   is not reported.
 * One attempt reads that refusal too. `/(*NOTEMPTY)^(?:a|a)+\K/` and
   `/(*NOTEMPTY)(?:a|a)+\K/`, which give back every way through
   `(?:a|a)+` before they fail, are exponential, no longer safe (proven):
   PHP exhausts its backtrack limit on 2,000 `a` in the interpreter, where
   `/^(?:a|a)+\K/` matches the empty string at once. When the attempt
   succeeds once the loop gives a character back to read after `\K`, as
   `/(*NOTEMPTY)^(?:a|a)+\K.?/` does, it is safe (proven), as
   `/^(?:a|a)+./` is.
 * A loop read in two ways that no suffix makes fail is proven linear. In
   `/^(?:a|a)+./` the dot reads whatever character the loop can go on with,
   once the loop gives it back: the engine abandons no run inside the loop
   longer than a character, and the attempt takes the steps `/^(?:a|b)+./`
   takes, 35 then 67 on 16 then 32 `a` and a `!` in the interpreter.
   `/^(?:a|aa)+./`, `/^(?:a|a)*./`, `/(*NOTEMPTY)^(?:a|a)+\K.?/` and
   `/(*NOTEMPTY)^(?:a\K|a)+/`, critical by the heuristics, are safe
   (proven). `/^(?:a|a)+$/` and `/^(?:a|a)+.$/`, which a suffix makes fail
   after every way out of the loop, stay exponential, and
   `/^(?:a|a|ab)+(?:[ab]*c|a)/`, which reads `[ab]*` again from each
   position of the loop before it fails, quadratic, is not proven linear.
   The run abandoned inside the loop is held to the margin of an acyclic
   stretch, 1,024 ways, each way into the loop counted with each way the
   run reads it and every empty route counted: `/^(?:a|a)+.{20}/`, whose
   dots succeed only twenty characters back, is not proven either (PHP
   exhausts its backtrack limit on 60 `a` and a newline), nor is a loop ten
   groups `(?:|)` lead into (1,049,601 steps on one `a` and a newline). A
   `^` under m, which the model takes to hold after a newline that ends the
   subject, keeps the loop out of the proof: `/\A(?:\n|\n)+\n^(?:\z|.)/m`
   takes 285 then 18,429 steps on 6 then 12 newlines.
 * The step replay of confirmed mode counts the characters a possessive or
   atomic repeat of one character set reads, which PCRE2's step counter
   does not see: it runs the pattern with each such character in a group of
   its own, as `(?:a)++`, which changes no match. `/a++b/` and `/(?>a+)b/`
   are replayed, no longer `replayed: false`, and so are
   `/(*NOTEMPTY)a++\K/` and `/(*NOTEMPTY)(?:a++\K)/`, which read the rest
   of the run at every attempt and give nothing back: 1.0, 4.0 then 15.9 ms
   on 2,000, 4,000 then 8,000 `a` in the interpreter. A witness through an
   atomic body the model keeps with every way through it,
   `/(*NOTEMPTY)(?>a+\K)/` or `/(?>a+|c)b/`, is now reported in confirmed
   mode. A repeat of a set of every character that PCRE2 may read as its
   any character, and move to the end of the subject in one step, stays as
   written: `/(?>.*+|a+)b/s`, whose atomic group commits to `.*+` at once,
   is not reported.
 * The options a pattern opens with are read by what each changes, and no
   longer keep every pattern but one opening with `(*UCP)` alone out of the
   proof. Those that change no match one attempt makes keep the verdict of
   the bare pattern: `(*NO_JIT)`, the limits, `(*NOTEMPTY)`,
   `(*NOTEMPTY_ATSTART)`, `(*NO_AUTO_POSSESS)`, `(*NO_START_OPT)`,
   `(*NO_DOTSTAR_ANCHOR)`, `(*BSR_ANYCRLF)`, `(*BSR_UNICODE)`, `(*LF)` and
   `(*UTF)` under u. `/(*NO_JIT)(?:a|ab)+c/`, `/(*LIMIT_MATCH=100)(?:a|ab)+c/`
   and `/(*CR)(?:a|ab)+c/`, critical by the heuristics, are safe (proven), as
   `/(?:a|ab)+c/` is, and so is `/(*UCP)\w+|b/`, an option before a
   top-level alternation. A limit the pattern sets does not reach the checks
   the proof asks of the engine, which run under PHP's default limits. Under
   a newline convention the dot and `\N` are read as PCRE2 reads them:
   `/(*CR)^(?:.|\r)+b/` is safe (proven), `/(*CR)^(?:.|\n)+b/` exponential.
   `(*CASELESS_RESTRICT)` is read as r. Kept out of the proof: `(*UTF)`
   without u, under which PHP hands PCRE2 a subject it does not check; under
   a convention other than LF, an anchor that reads a newline; the dot and
   `\N` under `(*CRLF)`, which take a carriage return unless a line feed
   follows it; and caseless matching under `(*TURKISH_CASING)`. The search cost
   reads a leading `.*` after an option as PCRE2 does: `/(*UCP).*[xy]/s`,
   anchored, is no longer reported quadratic, while
   `/(*NO_DOTSTAR_ANCHOR).*[xy]/s` and `/(*NO_START_OPT).*[xy]/` are. The
   heuristics read a limit before a recursion as reading nothing:
   `/(*LIMIT_MATCH=1000000)(?R)?a/` is reported as the recursion loop PCRE
   stops at.
 * An atomic group or a possessive repeat over greedy unbounded repeats of
   one run, each taken at most once at least, is read as the longest run it
   commits to: `/^(?:a+)++$/` is proven linear where its body was kept with
   every way through it, and a ReDoS repair now offers the possessive on the
   outer loop, `/^(?:a+)++$/` for `/^(?:a+)+$/`. A body that splits a run
   into many copies, `(?:a+){30,}+`, is kept as written: PCRE tries every
   way to split it before it fails.
 * A pump that goes round bounded repeats analysed as unbounded alone is
   proven once PHP's PCRE2, under its default limits, gives up at the
   backtrack limit on the witness, its attempt pinned to the start:
   `/^(?:a|a){1,40}$/` and `/^(?:\d{1,3}\.?){1,30}$/`, safe and low by the
   heuristics, are exponential (proven), critical. A bound the engine never
   reaches the limit within, `/^(?:a|a){1,16}$/`, keeps the heuristics'
   verdict.
 * The heuristics read the body of an atomic group, of a possessive repeat
   and of a repeat ending with `(*COMMIT)`, `(*PRUNE)` or `(*SKIP)` as PCRE
   runs it: the engine backtracks inside it until it has matched, so only
   the loops that end the body are left alone. `/(?>(a+)+b)/`,
   `/^(?:(?:a+)+b)++/`, `/^(*atomic:(?:a+)+b)/` and
   `/^(?:(?:a+)+b(*COMMIT))+/`, which were safe, are critical, and so is
   `/^(?>(\w+\s?)+:)/`: an item that may match nothing keeps no two
   iterations of a loop apart.
 * A `(*SKIP:NAME)` with no `(*MARK:NAME)` PCRE finds going back from it,
   which PCRE ignores, and a verb inside a lookaround or an atomic group,
   which acts there alone, no longer stop the loop around them in the
   heuristics: `/(?:\w+\s?(*SKIP:n))+:/` and
   `/^(?:\w+\s?(?=(*COMMIT)))+:/`, which were safe, are critical. A mark
   written before the skip inside a lookaround or an atomic group, or on a
   branch the match may not take, is not found: `/^(?=(*MARK:n))(?:\w+\s?(*SKIP:n))+:/`,
   which was safe, is critical.
 * The heuristics read a subroutine call in a loop as the group it calls,
   as PCRE runs it at each iteration: `/^(?:(?1))+(a|a)$/` and
   `/^(?:(?&w)\s?)+$(?(DEFINE)(?<w>[a-z]+))/`, which were medium, are
   critical. Each call is read through once from each place it runs from,
   and a call met again while its group is read, a recursion, is left
   there: `/(?(DEFINE)(?<wrap>(?&atom))(?<atom>a|a))^(?&wrap)+$/`, whose
   DEFINE block reaches the call of `atom` first, was medium and is
   critical. A branch that is a call starts as the group it calls:
   `/^(?:[a-z]|(?&c))+$(?(DEFINE)(?<c>[a-z0-9]))/` and `/^(a)(?:a|(?1))+$/`,
   which were medium, are critical, and a branch that recurses into the
   whole pattern is left out while the others are still compared:
   `/^x(?:a|a|(?R))+$/`, which was medium, is critical. A call outside any loop is read so too:
   `/^(?:(?>x((?:a+)+))|y(?1)$)/`, which was low, is critical where the call
   runs the nested loops the atomic group kept safe.
 * The heuristics read a branch whose first characters they cannot list, a
   backreference or a Unicode property, as one that may start as any other
   branch, whichever comes first: `/^(a)(?:a|\1)+$/` and
   `/^(["'])(?:[a-z]|\p{Ll})+\1$/u`, which were medium while their branches
   swapped were critical, are critical.
 * The heuristics read a character written as an escape, `\xC2`, `\x{C2}`,
   `\302`, `\o{302}`, `\N{U+C2}` or `\cA`, and a class of them, as the
   characters PCRE reads, above ASCII too, under PCRE2's own tables (under
   another locale's, as those tables say): a UTF-8 sequence spelled
   byte by byte beside an ASCII class,
   `/^((?:[a-z]|[\xC2-\xDF][\x80-\xBF])+)%?\1/` and WordPress's `SHOW
   TABLES LIKE` pattern, which were critical, are medium: PCRE reads the
   first in 20, 28 and 36 steps on 8, 12 and 16 units. Above ASCII a negated
   class, `\D`, `\W` and `\S` take every byte they do not name, the dot
   every byte but the newlines of the convention in force unless s is set,
   `\h` the no-break space and `\v` and `\R` the next line:
   `/^((?:[^a]|é)+)\1$/` and `/^((?:.|é)+)\1$/`, which were medium, are
   critical. Under `(*ANY)` the next line is a newline the dot does not
   take, whichever branch comes first: `/(*ANY)^((?:.|\x85)+)\1$/` and
   `/(*ANY)^((?:\x85|.)+)\1$/` are medium, and critical under s.
   Under u a character above ASCII ends a repeat as it starts the next
   one: `/^((?:é+é)+)\1$/u`, which was medium, is critical, as
   `/^((?:a+a)+)\1$/` is.
 * Under another locale's tables than PCRE2's own, where they read nothing
   above ASCII, the heuristics read the bytes above ASCII a pattern writes
   and those a negated class takes, as under C. Under fr_FR.ISO8859-1,
   `"/^(x)[^a]+\xC3+\\1$/"` with a raw `\xC3`, `'/^(x)[^a]+é+\1$/u'`,
   `"/^(x)\\w+\xE9+\\1$/"` and `"/^(x)\xC9+\xE9+\\1$/i"`, where the
   Latin-1 tables put `\xE9` in `\w` and pair it with `\xC9`, were safe
   and are medium: PCRE takes 31, 123 and 499 steps on `x` then 8, 16 and
   32 times the byte or the letter.
 * Under another locale's tables than PCRE2's own, the heuristics read the
   other case of a character under i as those tables give it, of an ASCII
   one under u or (*UCP) too, where they left it unknown: the finding on
   two loops that meet that way is no longer of low confidence, and
   `/^(x)a+B+\1$/i`, `"/^(x)\xC9+\xEA+\\1$/i"` and `/^(x)[^a]+A+\1$/i`,
   which were medium under fr_FR.ISO8859-1, are safe, as under C: PCRE
   takes 3 steps on `x` then 8, 16 or 32 times the letter. A case stays
   unknown where the tables of either side of PCRE2 10.43 hold it
   otherwise, µ and ÿ under a UTF-8 locale on macOS, and where the
   interpreter matches a character alone otherwise than a class of it
   does, `i` and `I` under tr_TR.ISO8859-9 on macOS.
 * Under another locale's tables than PCRE2's own, the heuristics read the
   bytes of `\d`, `\s` and `\w` without UCP as those tables give them,
   `(*UTF)` alone included, where they left them unknown: the finding on
   two loops that meet that way is no longer of low confidence, and
   `"/^(x)\\W+\xE9+\\1$/"`, `"/^(x)\\s+\xA0+\\1$/"` and
   `"/^(x)\\d+\xB2+\\1$/"`, with raw bytes, which were medium under
   fr_FR.ISO8859-1, are safe: PCRE takes 3 steps on `x` then 8, 16 or 32
   times the byte. Two loops bounded below 1,000 that meet are low there
   as under C: `"/^(x)\\w{1,999}\xE9{1,999}\\1$/"`, which was safe, is
   low, where PCRE takes 31, 123 and 499 steps. Under `(*UTF)` a code
   point above ASCII the tables name is read by the byte it starts with,
   and taken where any of the engine's paths takes it: the JIT takes é in
   `\w+` under fr_FR.ISO8859-1, the interpreter does not.
 * The heuristics read the POSIX classes without UCP as the tables of the
   target's locale fill them, where they left them unknown, and under i
   read `[:lower:]` and `[:upper:]` as every letter, as the engine does.
   `"/^(x)[[:alpha:]]+\xE9+\\1$/"`, with a raw `\xE9`, which was medium
   with low confidence under C and fr_FR.ISO8859-1 alike, is safe under
   C, where PCRE takes 3 steps on `x` then 8, 16 or 32 times the byte, and
   medium with medium confidence under fr_FR.ISO8859-1, where the Latin-1
   tables put `\xE9` in `[:alpha:]` and PCRE takes 32, 124 and 500 steps;
   `"/^(x)[[:^alpha:]]+\xE9+\\1$/"` is the other way round.
 * The heuristics compare first and last characters in the case the engine
   reads them: under i, set by the modifier, an option setting or a group,
   a letter meets its other case, and where `(?-i)` turns i off again it
   does not. `/^((?:a|A)+)\1$/i`, `/(?i)^((?:a|A)+)\1$/` and
   `/^((?:a|(?-i:A))+)\1$/i`, which were medium, are critical, as PCRE goes
   exponential on them (1 024 and 16 384 steps on 8 and 12 letters then
   `!`), and `/^((?:[^a]|A)+)\1$/i`, which was critical, is medium: `[^a]`
   leaves `A` out under i. Under iu `k` meets the Kelvin sign and `s` the
   long s, unless r keeps ASCII apart (`/^((?:k|\x{212A})+)\1$/iur`, which
   was critical, is medium), and under (*UCP) a Latin-1 letter meets its
   other case. A backreference in a loop whose branches start apart in
   either case reads one way under i too: `/^(a|b\1)+$/i`, which was
   critical, is medium, where PCRE reads it in 3 004 and 6 004 steps on 1 000
   and 2 000 `a`.
 * An item that reads nothing, an option setting above all, no longer
   keeps a loop apart from what stands before or after it, nor two loops
   apart: the heuristics read the pattern as its twin without the item.
   `/^((?i)a+)\1$/`, `/(?i)^((?-i)(?:a|A)+)\1$/`, `/^(x|(?i)a+)\1$/`,
   `/^((?:a|A)+(?i))\1$/` and `/^(a+(?i)A+)\1$/`, which were safe, are
   medium, as `/^(a+)\1$/i`, `/^((?:a|A)+)\1$/` and `/^(a+[aA]+)\1$/` are,
   and PCRE takes the same steps on each and on its twin. The finding of
   two loops that meet so names them with the items between them, as
   written: `a+(?i)A+`, not `a+A+`.
 * An item that may match empty, an optional one, a run or a count that may
   be empty, an alternation with an empty branch or a group of those, and a
   lookaround, which reads nothing, no longer keep a loop apart on their
   own: the heuristics read past them to the first item that always reads
   a character, and the loop stays apart only where each item on the way
   starts or ends apart from it. `/^((?:x)?(?:a|A)+)\1$/`, `/^(x?a+)\1$/`,
   `/^(a+x?)\1$/`, `/^((?!x)a+)\1$/` and `/^(a+x?a+)\1$/`, which were safe,
   are medium, as their twins without the item are, and PCRE takes the same
   steps on each and on its twin, 31 and 69 on 8 and 12 `a` then `!` for
   `/^(a+x?a+)\1$/`. `/^[a-z](?:[a-z0-9_](?!__))*[a-z0-9]+$/`, which was
   safe and which PCRE reads in 171 and 595 steps on 16 and 32 letters then
   `!`, is medium, and `/^(?:(?!x)a+)*$/`, which was medium, is critical, as
   `/^(?:a+)*$/` is. An item that always reads a character the loop does not
   still keeps it apart, `/^(xa+)\1$/` and `/^(a+[^a]a+)\1$/` stay safe, and
   so does a verb that cuts backtracking: `/^(a+(*COMMIT))\1$/`, which was
   medium, is safe, PCRE giving up in 5 steps whatever the length. Read past
   such items, a loop whose runs a later item always ends is apart:
   `/^((?:\w+(?=,),)+)\1$/`, which was critical, is medium, as
   `/^((?:\w+,)+)\1$/` is. Past eight such items, nothing further keeps a
   loop apart, of the loops it meets further only the first is named, and
   past 1,024 such pairs in one sequence the loops further are taken to
   meet, so that a run of loops that may all match empty costs work and
   findings that grow with its length, not with its square: in
   `/^(x)(?:ba+c?d?e?f?g?h?i?j?k?a+z)+\1$/` the two `a+` still meet, and
   the pattern is high, as with fewer items between them.
 * Without a proof of the whole pattern, a loop whose branches start alike
   is critical only where the loop, read alone before an end that never
   matches, is not proven linear: `/^((?:a|ab)+)\1$/`, `/^((?:ab|abc)+)\1$/`
   and `/^((?:a|Ab)+)\1$/i`, which were critical, are medium, where PCRE
   takes 36 and 52 steps on 8 and 12 `ab` then `!`; `/^((?:a|a)+)\1$/`,
   `/^((?:a|ab|b)+)\1$/`, `/^((?:a|aa)+)\1$/` and `/^((?:\w|\d)+)\1$/`, which
   PCRE reads exponentially, stay critical. A loop holding an anchor, an
   assertion or a lookaround, a reference or a call to a group outside it,
   or in a pattern that sets options inline, keeps the finding. The loops
   put to that proof share one budget of steps, the one the proof of the
   whole pattern has: past it, a loop keeps the finding, and a pattern of
   many such loops no longer costs a budget for each.
 * The heuristics read a loop that ends the body of another against the
   first characters of the next iteration: Friedl's unrolled C comment,
   `/(?C1)\/\*[^*]*\*+([^\/*][^*]*\*+)*\//`, which was critical, is safe
   again, its run of stars never taking the `[^\/*]` that starts the next
   iteration.
 * Where the proof does not decide, the heuristics read a count of more than
   16 as a loop, as the proof does, and a count of variable length inside a
   loop as a nested loop: `/^(?:a|a){1,40}(*MARK:y)$/`, which was safe,
   `/^(?:\d{1,3}\.?){1,30}(*MARK:y)$/` and `/^(?:(?1)){1,30}(a|a)$/`, which
   were low, are critical.
 * In confirmed mode, a heuristic verdict runs on inputs that end with the
   code unit every match requires, which PCRE2 looks for before it tries
   the pattern: `/^(x)?(?:\w+\s?)+:\1/`, critical in theoretical mode, was
   rejected at once on inputs without its `:`, never confirmed and dropped.
   An input holds no more bytes than its length, the code unit left out
   where a character and the one that fails the match do not fit before
   it, and each sample gives the length of the input it ran on.
 * In confirmed mode, a heuristic verdict on a pattern holding `(*SKIP:NAME)`
   is replayed under the JIT, as PHP runs the pattern by default, and its
   confirmation reports `jit_setting: true`, as for `/(?:a|a)+(*SKIP:x)$/`.
   Where `ini_set()` refuses `pcre.jit`, nothing is run and the
   confirmation's evidence is `engine limits unavailable`.
 * A backreference to a group of variable length in a loop is no longer
   critical when the body of the loop reads its input one way, each
   alternation choosing its branch by the first character and each count
   fixed: `/^(a|b\1)+$/`, the PCRE2 manual's example, which PCRE2 reads in
   linear steps, is medium as any loop is. Where the options in force make
   the branches start alike, as `i` does in `/^(a|A\1)+$/i`, the loop stays
   critical.
 * The heuristics read a body repeated `{0}` as PCRE runs it: never where it
   stands. One holding no group gives no hotspot, as `x+x+` in
   `/(?:x+x+){0}^(?:a+)+$/` gave two, which a repair then tried first; the
   groups one holds run only through a call, and are read on their own, as
   those of a `DEFINE` block are.
 * The search-cost witness builds only the sixteen pairs of alphabet classes it
   keeps, and charges their choice to the analysis budget: a run whose class
   six hundred later letters split no longer builds every ordered pair of the
   classes, 105 MB, before keeping sixteen.
 * The search-cost replay counts under the backtrack and recursion limits of
   the `ConfirmationOptions` given, not up to ten million steps under the
   process's recursion limit; where they stop the count, or `ini_set()` is
   disabled or refuses one, an exact witness is reported with `replayed: null`.
 * Where `ini_set()` refuses a PCRE limit (fixed by `php_admin_value`), the
   confirmation and the replay of a proof are skipped, their evidence
   `engine limits unavailable`, as where `ini_set()` is disabled: the refusal
   no longer counts as a run that did not reproduce.
 * Under `U`, `(?U)` or `(?U:…)`, an optional recursion such as `(?R)?` is
   lazy and tried last: it no longer reads as an immediate recursion loop
   that hides the backtracking after it, `/(?R)?(a+)+$/U` is reported as
   the exponential pattern PCRE cannot finish.
 * An ignored pattern written with modifiers, `/(a+)+b/i`, skips that
   pattern only: the same body under the same modifiers, in any order and
   between any delimiters. Any other entry still skips its body under any
   modifiers.
 * `IgnoreList` is public: it reads a ReDoS ignore list the way the
   analysis does, and the lint reads its own with it.
 * A proof of a linear attempt counts the work inside its lookaheads: a loop
   that reads, on the same characters, into a loop of a lookahead's body (as
   in `/^(?:(?=a+)a)+$/`, quadratic) refuses the proof, and `abstractions`
   lists `lookaround at offset N read again at each step of a loop`.
 * The structural heuristics read a lookaround as running on its own, and
   report a lookaround holding an unbounded repeat inside a loop.
 * A `RedosAnalysis` that proves one attempt linear carries no `findings`,
   `hotspots` or `recommendations`, and its `vulnerablePart`, `trigger`,
   `falsePositiveRisk`, `suggestedRewrite` and `culpritNode` are `null`: the
   structural heuristics no longer run once the proof settles the verdict.
 * `RedosAnalysis::jsonSerialize()` carries `upper_bound_degree`, the
   `$upperBoundDegree` it left out.
 * The structural heuristics measure a capture with the parser's
   `LengthRangeCalculator`: `(\cA)` is one character, as `(\x01)` is, `\R`
   one or two, a lookaround none. A script run can match nothing only when
   its body can, and a `DEFINE` block is analysed outside the loops around it.
 * A Unicode class is read as the union of its members, each member read
   from the engine once per process, a negated one as the rest of the
   alphabet and every general category from one read: the analysis of a
   pattern holding Unicode classes reads far fewer sets, each with the JIT.
 * `RedosAnalysis::$searchCost`, a `RedosSearchCost`: the witness (prefix,
   run, breaker) of a quadratic unanchored search when one attempt is proven
   linear (`search_cost` in JSON).
 * A witness through a lookaround is checked on the running PCRE2 before it
   proves a class: its attempt must fail, and reach the loop through the
   lookarounds on its way; a lookbehind at the start leads the witness.
 * The confirmed replay tries each witness once more at 5,000 bytes or just
   past, where PCRE2 stops looking for the code unit an anchored pattern
   requires.
 * `ConfirmationOptions` throws `InvalidRegexOptionException` for a value
   below its floor, as `RedosOptions` does, where it clamped it.

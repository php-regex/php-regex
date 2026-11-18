CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * Without UTF mode the tree holds a multibyte character of a class as its
   bytes, wherever it stands, as PCRE2 reads it: `/[à-é]/` is `\xC3`, the
   range `\xA0` to `\xC3`, then `\xA9`, where it was one range from `à` to
   `é`, which the explanation, the lint rules and the sample generator read
   as code points. `/^[é]$/` now has a sample, and a reversed range names
   the bytes PCRE2 reads: `/[é-a]/` is "Invalid range "\xA9-a"", where it
   was "é-a". The pattern prints back as written.
 * What follows an operand of an extended class, where an operator, a `)`
   or the `]` is due, is refused as PCRE2 names it: a character that starts
   no operand is `regex.extended_class.unexpected_character`, `/(?[\d a])/`
   at offset 7, where it was `regex.extended_class.missing_operator`, which
   a class, an escape, a `(` or a `!` there stays.
 * A nested class an extended class leaves open is read as written to the
   end of the pattern, with no `]` added, and a fault there comes before the
   missing `]`: `/(?[[\c/` is `regex.control_char.invalid` at offset 6 and
   `/(?[[\p{Lu}|-[:alpha:]/` is `regex.range.invalid_bounds` at 21, where
   both were `regex.charclass.unclosed`. A range to an unknown POSIX class is
   refused as the range, before PCRE2 reads the name: `/(?[[a-[:foo:]])/` is
   `regex.range.invalid_bounds` at 13, where it was `regex.posix.invalid`.
 * The braced name of a `\p{` or `\P{` in an extended class is read as PCRE2
   reads it, past the `]` of a nested class, a `\Q` or a `-`, up to its `}`
   or to where PCRE2 gives up on it: `/(?[[\p{-\Q\cé]])/` is
   `regex.unicode.property_malformed` at offset 13, where it was
   `regex.range.reversed` at 11, `/(?[\p{L é])/` is refused at 9, where it
   was 12, and `/(?[[\p{]}])/` is `regex.unicode.property_invalid` at 9,
   where it was `regex.unicode.property_malformed` at 11.
 * The options PCRE2 10.43 added, `r` and `a` with the class letter after
   it, are read with the opener of the level past 250 as `i` is: 250 `(`
   then `(?^a[` is `regex.group.syntax` at offset 255, where it was
   `regex.group.nested_too_deep` at 254, and an option setting `(?a)` there
   opens no level.
 * A condition on a relative zero, `/(?(+0)a)/`, is `regex.backref.zero`, as
   `(?(0)` and `\g{-0}` are, where it was `regex.backref.relative`. A zero
   that no `)` follows is refused on the number, as PCRE2 refuses it before
   it looks for the `)`: `/(?(0x)a)/` and `/(?(+0 )a)/` are
   `regex.backref.zero`, where they were `regex.conditional.unclosed`.
 * The characters a node may start and end with, which the linter's rules,
   the ReDoS heuristics and the optimizer's possessive rewrite compare, are
   read past a lookaround, in either direction, positive or negative and in
   its alphabetic forms, and past a substring scan: they read nothing.
   `(?!x)a` and `(?<=x)a` start with `a`, where they were read as starting
   with `x`, `a(?=x)` and `a(?<!x)` end with it, and a lookaround alone
   reads nothing. An alternation that may match empty through one branch
   lets the items around it lead too: `(?:|b)a` starts with `a` or `b`,
   where it was read as starting with `b` alone. So does a repeat of an
   item that may match empty, whatever its count: `(?=b){1}b` starts with
   `b`, where it was read as starting with nothing, and `(a?)+b` with `a`
   or `b`, where it was `a` alone. The ReDoS heuristics rate
   `/(x)[^a]+(?:(?=b){1}b)+\1/` medium, where it was safe: PCRE takes 74,
   274 and 1 058 steps on `x` then 8, 16 and 32 `b`. Two branches overlap on
   the characters they take, never on what a lookaround looks at:
   `regex.lint.overlap.charset` no longer reports `/(?:[^$]+|(?<=\\)\$)+/`
   or `/^(?:>(?:(?!!)|!))+$/`, and reports `/^(?:(?!x)a|a)+$/`, which the
   ReDoS heuristics, where it was medium, now rate critical: PCRE takes 94,
   1 534 and 393 214 steps on 4, 8 and 16 `a` then `!`.
 * Those characters follow what the start options set, read once for the
   pattern rather than again at each option setting. `(*CASELESS_RESTRICT)`
   keeps `k` and the Kelvin sign apart from the start, as the `r` modifier
   does, until `(?-r)` or `(?^)` turns it off. `(*TURKISH_CASING)` pairs `i`
   with `İ` and `I` with `ı`, never `i` with `I`, unless `r` holds. `\R`
   takes CR and LF alone under `(*BSR_ANYCRLF)`. The ReDoS heuristics rate
   `/(*CASELESS_RESTRICT)[^\x{212A}]+(?-i:k)+!/iu` and
   `/(*TURKISH_CASING)[^I]+i+!/iu` medium, where they were safe: PCRE takes
   30, 122 and 498 steps on 8, 16 and 32 `i` for the second. They rate
   `/(*BSR_ANYCRLF)\R+\x85+$/` safe, where it was medium.
 * A mark written short accepts an `=` in its name, `/(*:foo=bar)/`, and
   four characters after it, `/(*:x)a=b/`: the check that only the limits
   take a value read it as `(*MARK:`, four characters longer, and refused
   that `=` as `regex.verb.invalid`.
 * `(*TURKISH_CASING)` under the `r` modifier is refused where the opening
   run of settings ends, `regex.verb.conflicting_casings`, as with
   `(*CASELESS_RESTRICT)`: `/(*UTF)(*TURKISH_CASING)a/r` was valid. A pattern
   that also leaves out UTF mode is refused for that first, as PCRE2 does:
   `/(*TURKISH_CASING)(*CASELESS_RESTRICT)a/` is
   `regex.verb.turkish_casing_without_utf`.
 * `PatternPrinter`, and with it `PatternFormatter` and the optimizer, keeps
   the `|` of a conditional whose "no" branch is written empty, `(?(c)X|)`:
   it wrote `(?(c)X)`, which PCRE gives, in a lookbehind, the length of `X`
   alone, so `/(?<!(?(?=a)bb|))c/` compacted matched `c`. The formatter's
   check of its own output reads the two apart.
 * A substring scan, `(*scs:(1)...)`, adds no length to the lookbehind that
   holds it, and repeated directly it is measured as a lookahead is, as PCRE2
   does: `/(q)(?<=(*scs:(1)a+))/` was refused as unbounded, and
   `/(q)(?<=b{1,200}(*scs:(1)a{100}))/` as longer than 255.
 * `validate()` reports `/(*UTF)(*TURKISH_CASING)a{2}{3}[a-[:alpha:]]/` at
   the repeat, offset 30, without the warning "Undefined array key 0": the
   class after the error, read up to it to look for an earlier one, holds
   no item yet, which the measure of the compiled length read as a pair of
   letters. A framework that turns warnings into exceptions threw there.
 * A callout opened by a character past ASCII, `(?Cé)`, and `(?+` followed
   by one, `(?+😀)`, are refused past that whole character in UTF mode from
   PCRE2 10.47, as PCRE2 reports them: `/(?Cé)/u` at offset 5 and
   `/a(?+😀)/u` at 8, where the offset fell inside the character, at 4 and 5.
 * A reference to a missing group in a lookbehind comes before the errors of
   the whole pattern only where the measure of that lookbehind meets it:
   one in a lookahead, in a DEFINE group or after an `(*ACCEPT)` there waits
   its turn, as PCRE2 orders them. `/\g{1}(?<=(?=\k{n}))/` is refused on
   `\g{1}`, offset 5, where it was refused on `\k{n}`.
 * A missing group numbered below 10 is reported where the first back
   reference to it is written, as PCRE2 reports it, whichever reference the
   measure of a lookbehind meets first: `/\1(?<=\1)/` is
   `regex.backref.missing_group` at offset 2, where it was 8, and
   `/(?<=(?=\1)\1)/` at 9, where it was 12.
 * A PHP 7.3 or 7.4 target linked against PCRE2 10.45 or later reads `\x`
   with no hex digit as the letter `x`, as PHP does there: PCRE2 refuses it
   from 10.45, and PHP 7 compiles with `PCRE2_EXTRA_BAD_ESCAPE_IS_LITERAL`.
   `/\x/` matches `x` and `/[\x-a]/` is `regex.range.reversed` at offset 5,
   where both were `regex.escape.digits_missing`; under the `X` modifier,
   which turns the option off, `/\x/X` stays refused.
 * A reversed range in a class nested in an extended class comes before a
   range further on that no class takes, as PCRE2 reads them:
   `/(?[[^]--[a-\d]])/` is `regex.range.reversed` at offset 8, where it was
   refused for `a-\d` at 13.
 * `validate()` reports parentheses nested past the parser's own limit,
   `max_recursion_depth`, as PCRE2 does when it has refused them before that
   limit: `regex.group.nested_too_deep` at the level past 250, offset 251 for
   600 `(` in a row, or an error PCRE2 meets before that level, where it gave
   the limit, `regex.nesting.too_deep`, and no verdict. A limit met before
   that level stays the outcome. An error the parser meets before the level
   past 250 is no longer hidden by it: `)` followed by 300 `(` is
   `regex.group.unmatched_close` at offset 1, where it was refused at 252.
 * The level of parentheses past 250 is refused where PCRE2 refuses it, past
   its opener whole, wherever the parser stopped reading: 250 `(` then `(?:`
   is `regex.group.nested_too_deep` at offset 253 and `(?<n>` at 255, where a
   later error made it 252. An opener PCRE2 refuses for itself comes first:
   250 `(` then `(?%` is `regex.group.syntax` at 253 on PCRE2 10.49, and
   `(?<1` `regex.group.name_invalid`.
 * A possessive count of one, `X{1}+` or `X{1,1}+`, stays possessive where
   the automata read the pattern: it never gives back what `X` matched, as
   PCRE2 runs it. `TrivialMatchClassifier` named `/^(?:a|ab){1}+c\z/` one of
   `ac` and `abc`, where `preg_match()` refuses `abc`; it names none now,
   and the solver refuses it as it refuses `{2}+`.
 * A caret snippet in a JSON, Checkstyle or JUnit report stays under the
   character at fault once the invalid bytes before it are spelled `\xHH`:
   `Line 1: \xFF\xFE(` had its caret under the first `\xFF`.
 * `max_recursion_depth` is 512 by default, where it was 1024: PCRE refuses
   parentheses nested past 250, which the parser reads in 251 levels, and
   some 800 levels end PHP on a C stack of 1 MB.
 * `\C` under a leading `(*UTF)` or `(*UTF8)` is refused for every PHP, as
   under the `u` flag (`regex.escape.single_byte_in_utf`, at the `\C`): PHP
   8.4.29, 8.5.14 and 8.6.1 refuse it once compiled (GH-25452), and earlier
   releases, which run it, can crash matching it.
 * The build that runs, its PHP patch release included, is asked, once per
   process, two facts its release does not name: whether it carries the
   size-limit fix PCRE2 gives the distributors of 10.39 to 10.47, which
   moves the error a pattern too large is refused with, and whether its
   interpreter keeps what a subroutine call captured in the groups inside
   the called one, as the releases PCRE2 tagged before 10.43 do and the
   copies PHP bundles do not. The capture shape of such a target, a PHP
   linking a system PCRE2 10.42, lets those groups hold a value:
   `''|numeric-string` for group 2 of
   `/(?(DEFINE)(?<pair>(\d)(\d)))((?&pair)(?&pair))/`, where it said `''`.
 * `ClassSetProvider::query()` answers for a class holding the eight
   delimiters its probe was written with, `[/#~%!@;\x01]`: the probe takes
   another one, among the punctuation and the control characters PHP reads
   as delimiters, when the class leaves one of them out. The transpiler
   carries such a class under `/i` into the HTML pattern attribute, where it
   refused it.
 * `CaptureShapeAnalyzer` reads each case of a pattern with the start and
   the end of that case alone: a zero-width branch read first put what
   follows it at the start, and the next case kept that, so
   `/(?:(?=y)|(x))(^|a)/` read `0: 'x'` where `preg_match()` writes `'xa'`
   on `xa`. `CaptureShapeAnalyzer::ANALYSIS_VERSION` is `'21'`.
 * `PatternFormatter` keeps a `#` comment that ends in an escaped space as
   written wherever it lays it out: on a line of its own, after the start
   options and past ten indent levels, `# path\ ` came out as `# path\x20`,
   the spelling meant for a space of the pattern that ends its line. So does
   an inner line of a `(?#...)` comment, which also counts as written
   against the width.
 * The body of an alphabetic assertion nested in others, `(*pla:(*nla:a))`, is
   read from the pattern by its offsets, and the text a level read is dropped
   before the bodies inside it are read: every level kept three copies of its
   body. 240 nested `(*pla:` around a 60,000-byte comment, which PCRE compiles,
   peaked at 46 MB and take 3 MB; 900 around 93,000 bytes peaked at 264 MB, a
   fatal error under a 128 MB `memory_limit`, and take 11 MB. The tokens, the
   tree and the errors of a pattern are what they were.
 * `validate()` reports, for PHP 8.2.35 and later 8.2 releases with PCRE2
   10.40, the error that engine gives a pattern too large to compile that is
   wrong elsewhere too: those releases bundle 10.40 with the fixes PCRE2 keeps
   for it, one of which checks each branch against the size limit on its own,
   as 10.48 does. `/(?:[^a]){8190}aaa\d|\k<n>/` is `regex.pattern.too_large`
   at offset 0 there, and the missing group on PHP 8.2.34, as on any other
   10.40. No pattern changes verdict, so `PcreTarget::phpVersionBoundaries()`
   lists no new version.
 * `validate()` reports a version condition whose major is over 1000 where
   PCRE2 10.45 and 10.46 report it, past the last digit of the number: those
   releases read every number whole, and only the minor as two digits, until
   10.47. `/(?(VERSION>=99999999999)a)(c/` is `regex.conditional.version_syntax`
   at offset 23 for them, as from 10.47, where the library gave 16, the offset
   of 10.44 and earlier. No pattern changes verdict.
 * `validate()` reports an error PCRE meets measuring the lookbehinds before it
   estimates the compiled size, and one measure walks 10,000 branches at most:
   calls doubled level after level behind a branch reset are refused as
   `regex.lookbehind.too_complex` in milliseconds, as PCRE refuses them, instead
   of being expanded for close to a minute. A pattern PCRE compiles measures 2,001
   branches at most, so `PatternInfo::maxLookbehind` reads as before; on the tree
   of a pattern PCRE refuses, `analyze()` counts no lookbehind past that walk.
 * `CaptureGroupShape::$values` lists values of at most 4,096 bytes each and
   65,536 bytes together, and is `null` past either bound: nested repeats such
   as `/((?:(?:a{32}){32}){32})/` no longer spell their 32,768-byte value (and
   deeper nests, which PCRE refuses as too large, no longer spell a gigabyte
   one), and two thousand calls to a group of long values no longer
   build every call's values before the first product past the bound.
   `CaptureShapeAnalyzer::ANALYSIS_VERSION` is `'20'`.
 * `PatternFormatter` keeps the rest of a construct past ten indent levels
   on one line even when it holds a `#` comment, keeping only the line
   break that ends the comment: a hundred nested groups each opening on a
   comment, 1.4 KB under `x`, were laid out as 62 KB, they take 2 KB.
 * `JsonDumper` resolves at most 100,000 reference targets in one document,
   counted before duplicates are dropped, and throws `ResourceLimitException`
   (`regex.complexity`) past them: a pattern that names one group many times,
   under `(?J)`, no longer expands to hundreds of megabytes.
 * `LengthRangeCalculator` measures each node once per pattern, so a body
   that several calls share is read once: a pattern whose groups each call
   the previous one twice no longer takes time that doubles with each
   group. A call followed past 1024 nested calls, or a pattern past 100,000
   measured nodes, reads as unbounded from zero there.
 * `CaptureShapeAnalyzer` reads the body of a called group once for each
   fact it asks of it, however many calls run it: the shape of a pattern
   whose groups each call the previous one twice no longer takes time that
   doubles with each group, seconds at twenty of them. Every shape reads
   as before.
 * `RegexParser::tokenize()`, and `Regex::tokenize()` through it, hold the
   regex to `max_pattern_length` as `parse()` does, and throw a
   `ResourceLimitException` past it: they lexed a pattern of any length.
 * The mark `PatternPrinter` stands in for a bracket until the text is known
   keeps a fixed length, at most nineteen bytes on a 64-bit build: a comment
   holding twenty thousand of its bytes no longer lengthens it to as many, and
   five thousand brackets no longer copy it into 210 MB.
 * Outside Windows, the AST file cache and the class-set store no longer read
   write or clear an existing directory whose owner they cannot check, as when
   `ext-posix` is missing or `posix_geteuid()` is disabled: another user's
   files were trusted there.
 * There, the class-set store makes no directory and keeps no answer either,
   and it checks a directory it has just made before writing into it.
 * `PcreEngine` runs nothing where `ini_set()` refuses a limit the call asks
   for, as where `ini_set()` is disabled, and puts back a limit it already
   changed: the call no longer runs under a higher limit fixed by
   `php_admin_value`.
 * `FormatRefusal::note()` says in one sentence why the formatter gave a
   pattern back as written, the sentence the console prints.
 * An empty script run, `(*sr:)` or `(*asr:)`, parses as a `ScriptRunNode`.
 * The ini masks fit a 32-bit PHP: `IniFlag::INT_MASK` is an int on every
   width, `IniFlag::unsignedInt()` reads a limit as the engine's unsigned
   32-bit int, and the backtrack and recursion floors are compared through
   it. On a 32-bit PHP (the browser engine of the playground) the ReDoS
   confirmation threw a `TypeError` and the limit check raised a
   deprecation.
 * The `locale` option and `PcreTarget::$locale` name the `LC_CTYPE` locale
   the patterns run under, the runtime's by default: the size verdict
   counts exactly the classes whose length follows its character tables,
   read from the engine once per process. `PcreTarget::withLocale()` and
   `key()`, and `RegexParser::withTarget()`, a parser for another target
   with every other option.
 * `ParserOptions::DEFAULT_MAX_PATTERN_LENGTH`, `DEFAULT_MAX_LOOKBEHIND_LENGTH`
   and `DEFAULT_MAX_RECURSION_DEPTH` hold the default limits, which
   `RegexParser::DEFAULT_*` name as well.
 * `php_version` is read whole, each part after the major at most 99, and a
   PHP_VERSION_ID is at least `ParserOptions::OLDEST_PHP_VERSION_ID`, 70000;
   the constructor of `ParserOptions` checks the ranges of the limits.
 * `RegexException::$snippet` is the one snippet accessor:
   `VisualContextInterface::getVisualSnippet()` is gone, the interface declares
   `getPattern()`. A snippet is never cut inside a UTF-8 character.
 * `Exception\InternalFailureException`: the library could not finish its own
   work, one of its regexes giving up under PCRE limits PHP would not raise.
   `parse()`, `parseTolerant()` and `validate()` throw it, where they
   reported an error of the pattern.
 * `Attribute\RegexPattern`: marks a parameter that receives a regex pattern, for
   the tools that read it (`regex lint`).
 * `PatternInfo::$alwaysMatches` and `$alwaysMatchesFromAnyOffset`: proven
   when `preg_match()` cannot return 0 on any subject, from offset 0 or from
   any offset within the subject, so a static analysis tool types the call
   `1|false`. `/(.*)/` and `/^(.*)$/s` are proven, `/^(.*)$/` is not (it
   returns 0 on `"a\nb"`), nor is a pattern holding a backtracking verb or a
   start anchor after what may have consumed text (`/(?>a|)^/` returns 0 on
   `"a"`). `PatternInfoAnalyzer::ANALYSIS_VERSION` is `3`.
 * `RegexParser::validate()` keeps the results of the last 1024 patterns an
   instance validated, and `clearCaches()` empties them: a pattern validated
   again costs a lookup.
 * `ValidationResult::$ast` carries the tree of a valid pattern: one
   `validate()` call gives the verdict and the tree, where a consumer that
   needed both parsed the pattern again.
 * `PcreVerbNode::$name` and `$argument` read a verb once for every
   consumer: the name as PCRE2 documents it (`MARK` for `(*:x)`, `FAIL` for
   `(*F)`, `LIMIT_DEPTH` for `(*LIMIT_DEPTH=5)`) and what follows it, the
   name of a mark or of a cut, the value of a limit, null when the verb
   takes none. The JSON export writes both (`name`, `argument`) beside
   `verb`.
 * `ArrayCache` drops the tree read or written longest ago, where it dropped
   the one written first.
 * `PatternInfoAnalyzer` takes an optional `PcreTarget`, the running PHP's
   PCRE2 by default: its release decides which lookbehinds `maxLookbehind`
   counts, as PCRE2 measures them.
 * `PatternInfoAnalyzer::analyzePattern()` reads the facts of a delimited
   pattern with a `RegexParser`, at the parser's target, and throws what the
   parser refuses there, as `Regex::info()` does.

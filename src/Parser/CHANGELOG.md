CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * `ClassSetProvider::query()` answers for a class holding the eight
   delimiters its probe was written with, `[/#~%!@;\x01]`: the probe takes
   another one, among the punctuation and the control characters PHP reads
   as delimiters, when the class leaves one of them out. The transpiler carries such a class under `/i` into the
   HTML pattern attribute, where it refused it.
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

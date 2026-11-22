CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * The class rules read the bytes PCRE2 reads in a class without UTF mode:
   `regex.lint.charclass.redundant` reports a character written twice,
   `/[ää]/` or `/[ö¤ää]/`, and a byte written beside a character that
   starts with it, `/[aä\xC3]/`, where each multibyte character was left
   out as one it could not read. A byte two different characters share,
   the `\xC3` of `/[äö]/`, is no duplicate, nor is the `\xA4` of
   `/[a-ä]/`, which the range `a-\xC3` holds:
   `regex.lint.unicode.multibyteInClassWithoutU` reports that class, with
   the fix, `/u`.
 * The rules that compare first and last characters read an escape that
   names an ASCII character, `\x41`, `\101`, `\x{41}`, `\o{101}` or `\cA`,
   as that character, where they asked the engine or gave up:
   `/^(?:\x20*[\x22-\x7E])*$/`, which PCRE reads in 11 and 19 steps on 8 and
   16 units, no longer reports nested quantifiers, nor does the quoted
   text of the RFC 5322 address pattern of Symfony Mime and SwiftMailer,
   and the branches `[^\s\042\047=><`]+` and `\s*(?U:[^\s\042\047=><`]*)` of
   CodeIgniter's attribute pattern, which both start with `a`, are
   reported as overlapping. An escape above ASCII is left to the engine as
   before.
 * A byte above ASCII written as it is, not as an escape, is read as the
   target reads it. `\b` or `\B` after it, before the newline an end anchor
   lets through, is not reported as never matching where the engine
   matches: it is no word character under PCRE2's own tables, so, in PHP,
   `"/\xFF\$\\B\\n/"` matches `"\xFF\n"`, and may be one under u, (*UCP)
   or another locale's tables, so `"/(*UCP)\xFF\$\\b\\n/"` and
   `'/é$\b\n/u'` match too; all three were reported. So were `\B` after a
   class of the bytes above ASCII, `'/a[^\x00-\x7F]$\B\n/'` on
   `"a\xFF\n"`, and `\b` after a class of the characters above ASCII under
   u, `'/[^\x00-\x7E]$\b\n/u'` on `"é\n"`, and `\b` after a class read
   under the pattern's i where an option setting lifts it:
   `'/(?-i)[^a-z\x00-\x40\x5B-\x60\x7B-\x7E]$\b\n/i'` takes `A` and
   matches `"A\n"`. The character before the anchor is now read under the
   flags in effect where it stands. Without u a branch starting
   with such a byte is compared with the others by the engine under the
   target's locale: `"/(?:\\W|\xE9)+/"` overlaps under C and
   `"/(?:\\w|\xE9)+/"` under fr_FR.ISO8859-1, which were not reported.
 * Without u, where the engine judges no branch, one that matches empty
   above all, `regex.lint.overlap.charset` reads the bytes above ASCII a
   negated class takes: `"/(?:[^a]|\xC3|)+/"` with a raw `\xC3` is
   reported, and so is `"/(?:\\W|\xE9|)+/"` under C, where `\W` takes
   every byte above ASCII; neither was. `"/(?:\\W|\xE9|)+/"` is not
   reported under fr_FR.ISO8859-1, where `\xE9` is a letter. A case is
   read from that locale's tables: `"/(?:(?i:[^a])|\xC3|)+/"` is
   reported there, as under C, which it was not.
 * Without u, where the engine judges no branch,
   `regex.lint.overlap.charset` reads the bytes of `\d`, `\s` and `\w`
   from the tables of the target's locale, where it read them in ASCII:
   `"/(?:\\w|\xE9|)+/"` and `"/(?:\\W|\xD7|)+/"`, with raw bytes, are
   reported under fr_FR.ISO8859-1, where the Latin-1 tables put `\xE9` in
   `\w` and leave `\xD7` out; neither was.
 * Without u, where the engine judges no branch,
   `regex.lint.overlap.charset` reads the other case of a byte above ASCII
   read caselessly from the tables of the target's locale, where it left
   the branches unjudged: `"/(?:\xC9|\xE9|)+/i"`, with raw bytes, is
   reported under fr_FR.ISO8859-1, where the Latin-1 tables pair `\xE9`
   with `\xC9`, and so is `"/(?:\xC9|[\xC9\xCA]|)+/i"` under C, whose
   branches both take `\xC9`; neither was. A byte whose case the engine's
   paths read otherwise still leaves the branches unjudged.
 * `regex.lint.flag.useless.i` reads the case of a byte above ASCII, and
   under u of an ASCII character, from the tables of the target's locale:
   under fr_FR.ISO8859-1, where `"/\xE9/i"` matches `\xC9`, the flag is no
   longer called useless, which dropping it would have changed. A byte
   whose case the engine's paths or releases read otherwise keeps the
   flag too. Under C, only ASCII letters have a case without u, as before.
 * Under u, `regex.lint.flag.useless.i` reads every character of a range,
   where it read its two ends: `/[×-÷]/iu`, which holds `Ø` to `ö` and
   matches `ø` only with i, is no longer reported, nor is
   `/[\x{2000}-\x{2BFF}]/iu`, which holds the Roman numerals and the
   circled letters. A character has a case where PCRE2's tables of the
   target's release give it another, as well as where mbstring does:
   `/\x{A7CE}/iu`, which PCRE2 10.48 pairs with `\x{A7CF}` and the
   mbstring of PHP 8.4 does not, keeps its flag. A wide range is read from
   those tables at once, so `/[\x{4E00}-\x{9FFF}]/iu` is still reported.
 * Without u, `regex.lint.flag.useless.i` reads a byte above ASCII under
   `(*UCP)` as the character of the same value, from PCRE2's tables of the
   target's release, as PCRE2 does from 10.35 under any locale:
   `/(*UCP)\xE9/i`, which matches `\xC9` only with i, is no longer
   reported, nor is `/(*UCP)[\xD7-\xF7]/i`, which holds `\xD8` to `\xF6`.
   A byte whose other case is no byte matches no other one, and
   `/(*UCP)\xB5/i` is still reported. The tables of another locale give
   the case of ASCII alone there: `/(*UCP)\xD7/i` is reported under
   ru_RU.KOI8-R, whose tables pair `\xD7` with `\xF7`, which it was not.
   Before 10.35, (*UCP) leaves the case of a byte to those tables, as
   before.
 * An `exclude` of `regex.json` or `--exclude` leaves out the file or the
   directory its path names, from the working directory, behind `./` or
   absolute: `--exclude=src/Kernel.php`, `--exclude=./var/cache` and
   `--exclude=$PWD/build` left nothing out, as an exclude matched a
   directory below a linted path only. A directory name is still matched
   anywhere under the linted paths, and any exclude still replaces the
   default `vendor`.
 * A file named as a path to lint is read whatever its suffix: `regex lint
   bin/console` and `regex lint Legacy.PHP` found no pattern and passed. A
   directory is still walked for its `.php` files.
 * `regex.lint.overlap.charset` compares characters, not first bytes, past
   ASCII: under u, `/(?:ö|è)+/u` was reported because both start with the
   byte `\xC3`, and a byte read caselessly, `/(?:\xE9+|c)+/i` with a raw
   `\xE9`, met every other branch. PCRE gives those sets, as the target
   reads them, through the atomic or options group a branch opens with:
   `/(?:(?>ö)|è)+x/u` is no longer reported either, nor are a branch read
   past its optional first item, `/(?:ö?è|ê)+x/u`, or past a lookaround,
   which takes nothing, whatever options its body sets,
   `/^(?:(?=ö)ö|è)+$/u` and `/\A(?:(?=(?i:b))b|B)+\z/`, every branch of a branch
   reset group, `/(?:(?|ö|ê)|è)+x/u`, and an atom under the very options of
   `a` that stand where it does, which restrict `\d`, `\s`, `\w`, their
   negations and the POSIX classes alone, `/(?:(?a:[ö])x|èy)+z/u`; the
   pattern's own `/D` and `/S` are no `(?aD)` or `(?aS)`. The caseless
   restriction `(*CASELESS_RESTRICT)` sets holds there until `(?-r)` lifts
   it: `/(*CASELESS_RESTRICT)(?-r:\x{212A}|[^k])+/iu`, whose Kelvin sign
   then matches `k` and `K`, which `[^k]` refuses, is no longer reported.
   A lookahead before a branch narrows the characters it starts with, with
   or without u: a positive one to those its body starts with, a negative
   one to all but those its body refuses whatever follows them, so
   `/\A(?:(?=b)[ab]|a)+\z/` and `/\A(?:(?!a)[ab]|a)+\z/` are no longer
   reported, while `/\A(?:(?=a)[ab]|a)+\z/` and `/(?:(?!ab)a|a)+/` are; a
   lookahead the rule cannot read leaves the branch unjudged.
   `/(?:[à-ÿ]+|é)+/u`, `/(?:(?a:\p{Lu})x|\p{L}y)+z/u` and
   `/(?:(?aD:\w)x|éy)+z/uD`, which it missed, are reported. The message quotes each branch as the
   pattern writes it, `\d+` rather than `\\d+`, and `é` under u rather than
   `\xC3\xA9`, on thirty columns at most, an escape never cut, and without
   the layout and the comments `x` skips: a branch laid out over lines is
   quoted from its first atom.
 * `regex.lint.group.quantifiedCapture` and `regex.lint.quantifier.nested`
   name the group they report and quote it, so two findings on one
   pattern read apart: `Quantified capturing group 2 "(b)" with "+"` for
   `/(a)(b)+/`, `Nested quantifiers in named group "(?<run>a+)"` for
   `/(?<run>a+)+b/`, and a loop that captures nothing by its offset in
   bytes, `Nested quantifiers in "(?:a+)" at offset 1` for `/x(?:a+)+b/`.
   A quote is the group as the pattern writes it, on one line without the
   layout and the comments `x` skips, on thirty columns at most, as the
   branches of `regex.lint.overlap.charset` are quoted; neither keeps a
   comment group, `(?#...)`, any longer.
 * `regex.lint.quantifier.nested` looks for the inner loop past a count
   fixed above zero and inside it: `/^(?:\d{4}|[a-z]+)+$/`,
   `/^(?:[0-9]{2}\w+)+$/` and `/^(?:(?:a+){2})+$/`, which it missed, are
   reported. A separator inside the count still keeps the iterations apart,
   `/^(?:(?:\d+\.){3}\d+,)+$/` stays silent.
 * `regex.lint.quantifier.nested` also reads the loops of an iteration that
   only items which may match nothing come before: `/^(?:b?c+)+$/` and
   `/^(?:x*a+y*)+$/`, whose runs meet across iterations where the first
   loop matches nothing, are reported, and so is the package name pattern
   `/^[a-z0-9]([_.-]?[a-z0-9]+)*\/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$/`,
   which the ReDoS proof finds exponential. A separator after the run, as
   in `/^(?:b?c+,)+$/`, still keeps the iterations apart.
 * `regex.lint.quantifier.nested` reads every loop of an iteration against
   what follows it, the iteration read as a ring whose last item comes
   before its first: `/^\w+(?:\s+\w+\s*)*$/`, `/^(?:\s*,\s*\w+\s*)*$/`
   and `/^(?:\s*;\s*\w+\s*\w+)*$/`, which PCRE runs out of its backtrack
   limit on and the rule passed, are reported, whatever the number of
   items that may match nothing before a loop:
   `/^(?:a?b?c?d?e?f?g?h?i?j?k?l?m?n?o?p?q?r?s?t?\d+\d+)*$/` is. A separator may stand
   behind items that may match nothing and take none of the loop's
   characters: `/^(?:\s*\d+\s*,)*\s*\d+\s*$/` and `/^(\s*\w+\s*,)*$/`,
   which were reported, are not.
 * `regex.lint.quantifier.nested` no longer looks for the inner loop inside
   a lookaround, which holds without being tried again once it has
   matched: `/^(?:(?=\w+)\w)+$/` and `/^(?:(?<=\s{1,3})\w)+$/`, which PCRE
   reads in linear steps, are no longer reported. A non-atomic lookahead,
   `(*napla:...)`, is backtracked into, and `/^(?:(*napla:a+)a)+$/` still is.
 * A loop that ends an atomic group or a possessive body is no longer left
   alone by `regex.lint.quantifier.nested`, `regex.lint.dotstar.nested` and
   `regex.lint.overlap.charset` where a subroutine call runs a group between
   the two, without the atomic group: `/^(?:(?>x((?:a+)+))|y(?1)$)/` and
   `/^(?:x((?:a+)+)++|y(?1)$)/` are reported.
 * `regex.lint.backref.useless` no longer reports a backreference a loop
   runs after an earlier iteration has set its group, where an iteration may
   skip the reference: `/^(a|b\1)+$/`, which matches `aba`, and
   `/^(?:x(a)|\1)+$/`, which matches `xaa`.
 * `regex.lint.quantifier.useless` no longer reports a `{1}` that keeps an
   escape apart from the digit after it, `/(a)\1{1}0/` or `/\x4{1}1/`, whose
   removal would read `\10` or `\x41`; its tip names the count as written,
   `{1}?` in `/a{1}?b/`, which without the `?` would read `/a?b/`.
 * The lint reads pattern arguments it left out: a pattern built at the call
   from a local variable, an interpolated string or a heredoc
   (`"/^{$part}$/"`), an integer, a `sprintf()` whose format holds only
   `%s`, `%d` and `%%`, or an `implode()` of literals; a ternary, `?:`, `??`
   or a `match`, at the call or as the value of a variable, and a ternary
   or a `??` as the value of a constant; an array of patterns held in a
   local variable, the target of a `foreach` over literals, and an item of
   such an array (`$map['word']`, `self::MAP['word']`); a variable at file
   level, when nothing but assignments of literals stands between its
   assignment and its read;
   `self::X` in a closure written as
   an argument of `array_map()`, `array_filter()`, `usort()`,
   `preg_replace_callback()` and the other PHP functions that call it
   themselves; a global `const` or `define()` of another file of the
   project. A part that may take several values gives one pattern per
   value, 32 at most. More patterns read means more findings on unchanged
   code.
 * Of the values a variable may hold, a call is linted with those that pass
   the conditions on the way to it: in
   `if ($known[0] === '|' && preg_match($known, $s))` over a list of names
   and one `|...|` pattern, the names are no longer reported as patterns
   without delimiters. The lint reads a condition PHP evaluates on the value
   alone (a byte of it, `str_starts_with()`, `str_ends_with()`,
   `str_contains()`, `strpos()`, `substr()`, `strlen()`, `===` and `!==`,
   `==` and `!=` between values of one type, `<`, `<=`, `>` and `>=`
   between integers, and the boolean operators) over the right operand of
   `&&` and `||`, the branches of an `if` and the rest of a block after an
   early exit; any other condition keeps every value. Passing the variable
   to one of those six functions no longer leaves it unread; passing it to
   any other function still does. A function body calling `parse_str()` or
   `mb_parse_str()` with a single argument, which writes its variables on
   PHP 7, has none of its variables read, as one calling `extract()`.
 * `DIRECTORY_SEPARATOR`, `PHP_EOL` and `PATH_SEPARATOR` in a pattern give
   one pattern per value the constant takes, on Windows and elsewhere. An
   issue that holds for one value only is at most a warning, and its
   message ends by naming it: `Holds when DIRECTORY_SEPARATOR is "\".`
 * Every pattern argument of the PHP calls is counted. The report ends on
   the number no rule could verify, `Not verifiable: 3 pattern arguments
   (1 dynamic, 1 around a dynamic part, 1 around preg_quote())`, when there
   are some, and the JSON `stats` carry five counts, always present:
   `arguments_constant`, `arguments_finite_set`, `arguments_quoted_shell`,
   `arguments_raw_shell` and `arguments_opaque`.
 * `reportDynamic` in `regex.json`, off by default, lists each of these
   arguments as an info, at the argument: `regex.lint.source.dynamic` (the
   pattern is built at run time), `regex.lint.source.dynamicPart` (it holds
   a part built at run time) or `regex.lint.source.quotedPart` (it holds a
   `preg_quote()` text). Such a result has no `pattern` in the JSON report:
   the new `expression` key, `null` on every other result, holds the
   argument as the file writes it. None of the three is a lint rule, and a
   marker naming one is passed over when the list is off. An earlier
   release refuses a `regex.json` that holds `reportDynamic`, as any key it
   does not know.
 * `phpVersion` in `regex.json` takes a range, as a Composer constraint
   (`">=8.1"`, `"^8.1 <8.7"`) or as `{"min": "8.1", "max": "8.6"}`,
   and a range named there is judged as a `composer.json` `require.php`
   range: from its lowest PHP on PCRE2, then at each later version where a
   rule changes. A single version still names that version alone. An object
   in `regex.json` replaces the one of `regex.dist.json` whole. A constraint
   no PHP satisfies (`">=8.4 <8.2"`), or one naming PHP 10 or later, is
   refused.
 * The overlapping branches of an alternation are found without comparing
   every pair of them: branches whose first characters never meet are not
   compared, and the texts the others start with are read in byte order,
   where a text comes right before the texts it starts. A quantified list of
   2,000 names that all start alike took over ten seconds to lint, it takes
   a fraction of one; the findings are the same.
 * `regex.lint.lookaround.impossible`, `regex.lint.lookaround.edgeQuantifier`,
   `regex.lint.quantifier.possessiveImpossible` and the
   `regex.lint.anchor.impossible.*` rules read the option settings of a
   pattern once, not again for each item they ask the automata about, and
   the first reads the flags in force at each lookahead, and its place in
   its sequence, once for the pattern. A pattern of 4,000 lookaheads,
   possessive quantifiers or word boundaries took from 15 seconds to
   almost a minute to lint, it takes a fraction of one; the findings are
   the same.
 * A script run reads what its body reads, where the rules took it for an
   item that reads nothing: `regex.lint.quantifier.emptyRepeat` no longer
   reports `/(?:(*sr:ö)|è)+/u`, and `regex.lint.anchor.impossible.start`
   reports `/(*sr:a)^b/`, which never matches. `regex.lint.overlap.charset`
   reads a branch through its script run, which starts with what its body
   starts with, and asks the engine about it: `ö` and `è` no longer meet
   there on their shared UTF-8 lead byte. Where the engine cannot be asked
   about a branch, as a class holding every delimiter, the rule compares
   the first bytes, under `u` the ASCII ones alone: a lead byte two
   characters share is no overlap.
 * `regex.lint.overlap.charset` reads a negative lookahead of a repeated
   character, `(?!a+)`, as refusing `a`, where it left the branch
   unjudged, and one whose character needs what follows it to hold,
   `(?!a$)`, `(?!a\b)` or `(?!a(?=b))`, as refusing nothing, where it took
   `a` as refused: `/\A(?:(?!a$)[ab]|a)+\z/` is reported. Lookaheads that
   differ by the way taken, `(?:(?=a)|(?=b))[ab]`, let through what either
   way does, where the branch was left unjudged; past sixteen ways it still
   is.
 * The diff of a suggested rewrite skips the lines both sides share at the
   start and at the end before it looks for the longest run of kept lines:
   a 10,000-line pattern with one line changed took 15 seconds and 2.6 GB,
   it takes milliseconds. When the table of the changed lines left in the
   middle, one row and one column more than their counts, would hold more
   than 65,536 cells (255 lines against 255 fit, 256 against 255 do not),
   they are shown deleted then inserted whole, without the lines they share
   kept between the changes.
 * The value of a property read through `$this` looks into each trait of the
   file once when it checks for a trait the file does not declare: forty traits
   that each use the two before them no longer take exponential time to lint.
 * The statement a `@regex-target` doc comment covers is found for every
   marker of a file in one pass: 2,000 nested marked `if` bodies took some
   20 seconds, they take a tenth of one. A `}` or a `;` in inline HTML or
   in the text of a string, and the `}` that closes a `{$variable}` in a
   string, no longer end that statement. A file with room under
   `memory_limit` to be tokenized but not for the tables this pass builds
   keeps no marker, as one too large to tokenize.
 * The lock file the forked workers of `--jobs` share is created with no
   permission for anyone but its owner (`0600` under the usual umask): another
   user can no longer read or write it.
 * An editor link is left out when its address is not valid UTF-8 or holds a
   control character or a `>`, in the console report as in the Symfony and
   Laravel `regex:lint` output: an `ide` template could otherwise send the
   terminal escape sequences of its own through the OSC 8 hyperlink.
 * Looking for the parameters marked as patterns reads a file once, even one
   holding thousands of unclosed declarations or attributes: 500 lines of
   `function f(#[Language('SQL',` took about 20 seconds, they take
   milliseconds.
 * The character-set overlap of an alternation is no longer reported for two
   branches whose leading literal texts tell them apart,
   `associative-array|array`: the second character already decides which
   branch takes the input, so no backtracking between them follows. The
   texts are folded as PCRE folds under `/i`, a final sigma meeting its
   sigma, and the case a branch starts in is read at its first atom: a
   `(?i:` later in the branch leaves its start case-sensitive.
 * A character range of two consecutive characters, `[a-b]` or
   `[\x{5C4}-\x{5C5}]`, is no longer reported: it is correct as written,
   the list saves one character only, and a generated Unicode table holds
   thousands. A range of one character, `[a-a]`, stays a warning.
 * A class constant or an enum case of another file of the project is read
   as one of the same file is: the constants of every file are read once
   before the patterns, with the declarations, and a call naming a class its
   file does not declare resolves it through them. The pattern is reported
   once, at its literal, in the file that writes it, judged by the version
   guards of that file. A class declared in two files reads nothing; a
   project class hides a library's of the same name, and a linted file a
   declaration path reaches under another spelling is one file.
 * A pattern read through the backing value of an enum case,
   `Kind::Re->value` or `self::Re->value`, is read as one held in a class
   constant is: reported once, at the case. Parentheses around the case,
   `(Kind::Re)->value`, change nothing, with the tokenizer as with the
   parser.
 * The `matches` operator of the Twig templates under the linted paths is
   read as the `preg_match()` it compiles to: the pattern as Twig hands it
   to the engine (its backslash escapes folded), reported at the template,
   the line and the column of its quote with `twig:matches` as its source,
   silenced by a `{# @regex-ignore #}` comment as a PHP line is. The
   `TwigTemplatePatternSource` is one more pattern source, named `twig`.
 * The ReDoS ignore list reads an entry as `RedosAnalyzer` does: a delimited
   pattern without modifiers, `/(a+)+b/`, skips its body under any
   modifiers, where it skipped that string byte for byte only; one written
   with modifiers skips that pattern only, between any delimiters.
 * One catalogue describes every lint rule: what it reports, its default and
   the section of the docs that explains it. Every rule declares its
   identifier as a public constant (`ID`, or `ID_*` for a rule of several),
   the `regex.json` schema reads its descriptions and defaults, and every
   report links each finding to the docs on php-regex.com: `docs_url` in the
   JSON report, a `Read more:` line in the GitHub and Checkstyle messages.
 * The Checkstyle `source` is the issue's identifier, without a prefix.
 * Where the PHP cannot fork, Windows among others, `--jobs` above one runs
   in one process and the banner says so beside the count asked for.
 * The console report ends its lines with "\n" on every host, as the rest
   of the output does, where it ended them with "\r\n" on Windows.
 * The console report writes a hint one line per sentence behind its arrow,
   folded at 120 columns (the terminal's width when narrower, `COLUMNS` when
   the shell exports it) with the continuation under the text, the rewrites
   to try once under one `Suggested (verify behavior):` heading as dashed
   items, and an issue message folded under its badge; a hint past 200 bytes
   was cut at 197 with `...` unless `--verbose` was given, which dropped the
   fix a ReDoS hint names after its replay. A tool that reads the console
   line by line reads `--format=json` instead. The JSON `hint` stays one
   string, deduplicated; the GitHub, Checkstyle and JUnit `Suggestion` is
   the rewrite the console lists first.
 * `--jobs` reads a positive integer written in digits: a sign or a space
   around the number, which the option let through, is refused with the
   usage message.
 * The error for an `ide` value `regex.json` refuses names every placeholder
   a template may hold, `%f`, `%file%`, `%relFile%`, `%l`, `%line%`, `%c` and
   `%column%`, where it named `%f` and `%l` only though `%c` alone was taken.
 * `PatternLinter` throws `UnknownLintRuleException` for a rules map naming
   a rule it does not run, the nearest rule named, where a typo turned
   nothing off; the map takes each id short or in full.
 * `checks.lint.rules` takes a rule id in full as well as short, and a
   misspelt id is told the nearest one, in `regex.json` and on the command
   line; an inline marker reads a short id as that issue, where it was a
   note that silenced the whole line.
 * The Symfony and Laravel bridges run their `regex:lint` through the
   linter's own console run, on `symfony/console`, which the package
   suggests.
 * `regex.json` takes `locale`, the `LC_CTYPE` locale the patterns run
   under; the JSON report names it as `target.locale`.
 * `group.alwaysEmptyCapture` asks the automata about a capturing group only
   when no subject built from the pattern shows the engine filling it, and
   keeps each answer per pattern and target: the same findings, about eight
   times less time.
 * A rule whose every identifier is turned off, by default or in the
   configuration, no longer runs: it ran and its findings were dropped.
 * A guard on `PHP_VERSION_ID` or `version_compare(PHP_VERSION, …)` around a
   pattern narrows the versions it is judged for, in both extraction
   strategies.
 * `@regex-target` narrows the versions a pattern is judged for, and an issue
   of a narrowed pattern carries `target.guard` in the JSON report.
 * An inline marker may name the issues it silences, and the markers that
   silence nothing are reported (`reportUnmatchedIgnores`, on by default).
 * The workers, and the process that forked them, load their classes one at a
   time while the workers run: workers loading the classes of a phar their
   parent held open read each other's bytes through the one file offset
   they share, and died on a parse error.
 * On macOS, ending a worker the moment it is forked no longer ends the
   process that forked it: a worker is forked with SIGTERM blocked, and
   lets it through once set up.
 * The workers take the files and the patterns a few at a time, as each one
   finishes, and report their progress as they go; the inline ignore markers
   are read by the workers, and a file's source is read once for all its
   issues.
 * A PHP file the lint cannot read, or that does not fit in `memory_limit`,
   is reported as `regex.lint.source.unreadable`, an error, instead of being
   left out.
 * The patterns past one of the library's own limits are counted among the
   warnings in `stats.not_analyzed` of the JSON report and in the console
   summaries (`1 warnings (1 not analyzed) found`); the exit code is
   unchanged.
 * A PHP file the PHP parser cannot read is read with the tokenizer instead
   of being left out, and counted once in `stats.parser_fallbacks` of the
   JSON report; a first-class callable such as `preg_match(...)` or a
   partial application such as `preg_match(?, $s)` no longer drops the other
   patterns of its file. A file holding a NUL byte is read as PHP reads it:
   in a comment, a string or a pattern the file is valid and linted, where
   both readers skipped it; anywhere else the NUL is a syntax error, read
   with the tokenizer. An inline ignore on the first line of a file the lint
   could not read no longer silences its `regex.lint.source.unreadable`
   error.
 * The lint command reads the file of `--baseline` and `--generate-baseline`
   after a space too (`--baseline base.json`); either option with no file is a
   usage error.
 * `regex.lint.flag.useless.D` and `regex.lint.flag.useless.x`: a `D`
   modifier with no `$` read without `m`, an `x` modifier on a pattern with
   no whitespace and no `#` comment, at severity `warning`, on by default.
 * `regex.lint.redos.search`: the quadratic cost of an unanchored search whose
   every attempt is proven linear, under the ReDoS check, at severity `medium`;
   `redos.search` in the rules map of `regex.json` turns it off.
 * `regex.lint.quantifier.emptyRepeat`: an unbounded quantifier on an item
   that can match the empty string, `(a*)*`, `(?:a|b?)+`, at severity
   `warning`, on by default; silent where `alternation.empty`,
   `quantifier.assertion`, `quantifier.nested` or `dotstar.nested` reports the
   same repeat, as long as the rules map turns that rule on.
 * `regex.lint.anchor.alternationPrecedence`: an anchor on the first or last
   alternative while another alternative has none, `/^a|b|c$/`, at severity
   `warning`, on by default.
 * `regex.lint.quantifier.possessiveImpossible`: a possessive repeat that
   takes every character the next atom could read, `/a*+a/`, at severity
   `warning`, on by default.
 * `regex.lint.anchor.impossible.boundary`: `\b` between two word or two
   non-word characters, `\B` between a word and a non-word character,
   `/a\bb/`, at severity `warning`, on by default.
 * `regex.lint.lookaround.impossible`: a lookahead what follows it
   contradicts, `/(?=a)b/`, at severity `warning`, on by default.
 * `regex.lint.group.empty`: an empty `(?:)` or `(?>)` that can go, at
   severity `warning`, on by default; never one a quantifier repeats, nor one
   that keeps an escape from a digit (`(a)\1(?:)0`) or braces from a count
   (`a{(?:)2}`).
 * `regex.lint.charclass.single`: a class of one character, `[a]`, at
   severity `style`, off by default.
 * `regex.lint.literal.multipleSpaces`: two or more literal spaces in a row,
   at severity `style`, off by default.
 * `regex.lint.quantifier.lazyToClass`: `".*?"` where `"[^"\n]*"` matches the
   same text, at severity `perf`, off by default.
 * `regex.lint.quantifier.uselessLazy`: a lazy quantifier whose greedy form
   writes the same `$matches`, `(a+?)b`, proven by the automata, at severity
   `style`, off by default.
 * A function or static method declaring a parameter with the attribute
   `PHPRegex\Parser\Attribute\RegexPattern`, or PhpStorm's
   `#[Language('RegExp')]`, is a pattern function, as if
   configured: the linted paths, the configured paths and `vendor/` are read
   once for such declarations before the files are shared out between the
   workers; below a linted path `exclude` applies, `vendor/` and a configured
   path the run does not lint are read whatever it says. A configured spec wins over a declaration
   found that way, a project declaration over a copy in `vendor/`, and
   project declarations marking different parameters are all read.
   A call written unqualified in a namespace, `grep()` in `namespace App`, is
   matched first as that namespace's function, `App\grep()`, as PHP calls it;
   where the namespace declares its own `grep()`, marked or not, a global
   `grep()`, declared or configured, does not capture the call.
 * `regex.lint.compat.meaningChanges`: a pattern a later PHP of the
   project's range parses into another meaning, `/a{,3}/` from PHP 8.4, at
   severity `warning`, on by default. It also names a change of meaning
   that keeps the tree, from the release table, with its witness: a
   possessive `{1}+` on a group (PCRE2 10.34), a group that refers to
   itself, a repeated assertion holding a group and `(*UCP)` casing without
   UTF (10.35), a bare script name (10.40).
 * `regex.lint.group.alwaysEmptyCapture`: a capturing group that is empty or
   unset wherever the pattern matches, `a+(a*)`, proven by the automata, at
   severity `warning`, on by default.
 * `regex.lint.lookaround.edgeQuantifier`: a repeat past its minimum at the
   end of a lookahead or the start of a lookbehind, `(?=a{2,6})`, proven by
   the automata, at severity `perf`, off by default.
 * `regex.lint.anchor.dollarNewline`: a `$` that ends a pattern anchored at
   its start, read without `D` or `m`, which also matches before a trailing
   newline, `/^\d+$/` on `"123\n"`, at severity `info`, off by default; the
   tip is `\z` or the `D` modifier.
 * `regex.lint.lookaround.redundant`: a positive lookahead that ends a
   lookahead, or a positive lookbehind that starts a lookbehind,
   `(?=a(?=b))` for `(?=ab)`, at severity `style`, off by default.
 * `regex.lint.unicode.shorthandWithoutU`, renamed from
   `unicode.shorthand_without_u`, is off by default: most code means the
   ASCII `\w`, `\d` and `\s` it reports, at severity `style`.
 * The rules off by default are `anchor.dollarNewline`,
   `unicode.shorthandWithoutU`, `charclass.single`, `literal.multipleSpaces`,
   `quantifier.lazyToClass`, `quantifier.uselessLazy`,
   `lookaround.edgeQuantifier` and `lookaround.redundant`;
   `true` for its id in the rules map of `regex.json` turns one on.
 * `regex.lint.quantifier.lazyEnd` also reports a lazy quantifier followed
   only by items that may match nothing and hold no test that can fail,
   `/a+?b*/`.
 * `regex.lint.group.redundant` no longer reports an empty group (now
   `group.empty`), a group that keeps an escape from a digit (`(a)\1(?:0)`) or
   braces from a count (`a{(?:2)}`), or a group a quantifier repeats around
   anything but one character (`(?:\Qab\E)+`, `(?:^)+`); `(?:a)+` is still
   reported.
 * A file that calls a regex wrapper, or a static method declared in
   configuration or with `#[RegexPattern]`, only through an aliased import
   (`use Composer\Pcre\Preg as P;`, `use Composer\{Pcre\Preg as P}`) is
   read instead of skipped.
 * Without `nikic/php-parser`, the lint reads a nowdoc or a heredoc without
   interpolation, a pattern passed by name, and the string form of a call
   whose array form holds patterns in its keys (`Strings::replace($s, '/a/',
   'b')`), as the AST extractor does.
 * Without `nikic/php-parser`, the column and offset of a pattern are right
   after an argument holding braces (a closure body, `"{$x}"`), and a binary
   string, `b'/a/'`, is read as PHP reads it.
 * `Strings::replace()` of nette/utils is read as Nette reads it: the keys of
   an array of patterns only when its first key is a string and the
   replacement is no callable, the values otherwise. A key PHP stores as an
   int (an int, a float, `true`, `false`) makes a list; a replacement that is
   always an object or an array (`$this`, a cast, `clone`,
   `Closure::fromCallable()`) counts as a callable.
 * Without `nikic/php-parser`, `\X41` is read as `A`, a newline after the
   closing delimiter is kept, an attribute or an interpolation inside an
   array of patterns no longer hides or invents a key, and nested wrapper
   calls are read in linear time.
 * The extraction reads more calls: eight presets, all on by default
   (composer/pcre, nette/utils, spatie/regex, Laravel's `Str`, PHPUnit,
   webmozart/assert, beberlei/assert, Symfony's `Regex` constraint),
   `RegexIterator` and `filter_var()`, and a pattern held in a constant, a
   variable or a property the same file proves, reported once at its
   literal with `location` saying the way. A configured helper is read
   under `pattern:`, `patterns:` or `regex:`, or under the one name a
   `$<name>` suffix gives (`App\grep#1$expression`). Of the other sources,
   only the Symfony validator's occurrence of a pattern the PHP file already
   gives is dropped; a Laravel `regex:` rule stays.
 * A file cut short inside a call's arguments reads nothing from that call,
   instead of stopping the run with a TypeError. A named argument is matched
   with its case, as PHP does; nette/utils' `utf8` is read in its place too,
   and one that is neither `true` nor `false` leaves the pattern unread.
   Symfony's `Regex` reads only `pattern` from an array of options held in a
   constant, and a spread after the key leaves it unread.
 * The reading of names is bounded and stricter: a local assignment after
   the last read outside any loop is not read; a protected property is read
   only in a final class that extends none, and no property of a class using
   a trait of another file, nor through `self::`, `static::` or `$this->` in
   a closure; `array_walk()` over an object, and a `yield` in a by-reference
   generator, count as writes; a constant PHP defines keeps its value; a
   folded value past 64 KiB is not read, and a variable read many times is
   read once.
 * The lint reads a file PHP warns about as it tokenizes it without a word:
   a double-quoted literal holding an octal escape above `\377` printed
   `Warning: Octal escape sequence overflow` on stdout, by default, once for
   each reader of the file, in the middle of the report of `regex lint`, a
   checkstyle, JUnit or GitHub document included, and of the JSON report of
   `php artisan regex:lint`.

CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
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

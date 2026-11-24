# TODO

## ReDoS precision

- Since #445 a loop is set aside on a sure success only where no widened
  part lies on the way, which costs precision: of 19,000 fuzzed loop
  patterns, 242 linear ones lose "safe (proven)", mostly an atomic
  alternation whose first branch always matches, `(?>[^x]*|y)`, which the
  engine always commits to. Read such a body exactly. The patterns #445
  takes from the proof, `/^(?:a|a)+[^x]{0,20}(?:x|\z)/` and
  `/^(?:a+)+(?>.*|y)\b/`, are heuristic now: prove them exponential.
- Since #446 a loop whose success needs one more character after `^`
  under `m` is heuristic, though the engine is linear:
  `/\A(?:\n|\n)+^/m`, `/\A(?:\n|\n)+(?:^|x)/m` and
  `/\A(?:\n|\n)+(?:^$|[^\n])/mD` lost "safe (proven)", and
  `/^(?:a|a)+./` was already heuristic for the same reason. Let
  `AmbiguityFinder::disproved()`, with its `link()` and `chainOn()` uses,
  accept a success where every non-empty continuation is accepted.
- `regex.lint.anchor.impossible.start` and `.end` stay silent on
  patterns PCRE2 never matches: `/a(?=\n^)\n\z/m`, `/(*CRLF)a\Z\r\n^/m`,
  `/(*CRLF)a$\r^\n/m`, `/a\z^/m`, `/\n^\z/m` and `/a$^/m` (0 with
  `pcre.jit=0`).
- The automata leave `^` and `$` under `m` outside the model for
  `(*CR)`, `(*NUL)`, `(*ANYCRLF)` and `(*ANY)`. Model them: under
  `(*ANYCRLF)` and `(*ANY)`, `^` holds between `\r` and `\n`
  (`preg_match("/(*ANYCRLF)\r^\n/m", "\r\n")` is 1).
- The search cost misses `/(?:a\n)*(?:a{2}|b|\z)/m`: proven safe with no
  search cost, yet `preg_match()` on `"a\n"` × 1,000, 2,000 and 4,000 then
  `!` takes 23, 96 and 330 ms with `pcre.jit=0` (1.2, 4.6 and 18 ms with
  the JIT), each attempt linear.
- The overlap rule reads an escape past ASCII under u as unknown where it
  reads the raw letter: under C, `/(?:[a-z]|\x{E9}|[a-c]|)+/u` goes
  unreported while `/(?:[a-z]|é|[a-c]|)+/u` is reported
  (`regex.lint.overlap.charset`).
- `regex.lint.alternation.overlap` reads a `(?-i)` at the start of a
  branch as caseless: `/(?:(?-i)Ax|(?-i)ax)+/i` under C and
  `/(?:(?-i)\xC9x|(?-i)\xE9x)+/i` under fr_FR.ISO8859-1 are reported,
  though `/^(?:(?-i)Ax)$/i` does not match `ax` (PHP 8.4.28, PCRE2 10.49,
  JIT on and off).
- Decide whether an escape is literal text for the overlap rules: under
  fr_FR.ISO8859-1 the raw `"/(?:\xC9x|\xE9x)+/i"` is reported by
  `regex.lint.alternation.overlap` since #442 and the escaped
  `'/(?:\xC9x|\xE9x)+/i'` by `regex.lint.overlap.charset`, as
  `/(?:Ax|ax)+/i` and `/(?:\x41x|ax)+/i` split in ASCII.
- Three rules fold ASCII letters by C's pairs whatever the target's
  locale: `regex.lint.alternation.overlap` (`foldCharSet`),
  `regex.lint.quantifier.nested` (`NestedQuantifierRule::fold()`) and
  `regex.lint.quantifier.concatenation` (`foldByteSet()`), with
  `LoopShape::mayTakeAboveAscii`, all through the analyzer
  `PatternLinter` builds without the target. Under glibc's
  tr_TR.ISO-8859-9, where `i` pairs with `\xDD` and `I` with `\xFD`,
  `/(?:i|I)+$/i`, `/(?:i+I)+$/i` and `/i+I+$/i` are reported though their
  parts are disjoint (the engine returns 0 at once, the analysis says
  safe), and `/i+\xDD+$/i` is missed (medium). Read the pairs from the
  target's tables, as #429, #434 and #442 do; the literal fold already
  does, and reports `/(?:i|I)+$/i` under macOS's tr_TR, where the engine
  matches `i` with both.
- The automata scan bytes through `(*NO_JIT)` (`ClassSetProvider::probe()`),
  so where the JIT and the interpreter read a locale's case differently
  they see only the interpreter's: under macOS's tr_TR.ISO8859-9,
  `/(?=\xFD)i/i` is reported impossible, yet the JIT matches `I` (PHP
  8.4.28, PCRE2 10.49). Let a byte `CharacterTables` marks unsettled be
  taken either way, as `CharSetAnalyzer` holds what any path takes since
  #432. The same probe misses `/(?:\xF2|\xD3)+/i` under el_GR, where the
  JIT matches `\xD3` with `/\xF2/i` and the interpreter does not.
- Two oracles of `CharSetAnalyzerTest` fail with `pcre.jit=0` where the
  locales are installed: `preg_match('/(*UTF)^\w$/', 'é')` gives 0 under
  fr_FR.ISO8859-1 in the interpreter, and the Greek final sigma row holds
  `\xD3`, which the interpreter does not take. Assert what either path
  takes there, as the analyzer does.
- Under a locale other than C, `ImpossibleAnchorRule` counts every byte
  above ASCII as both a word character and not one: `/\W$\b\n/` is
  reported under C but not under glibc's tr_TR, and `/\xDD$\B\n/`, impossible
  there, is not reported. Read `\w` from the target's tables in
  `EndOfLineTail`. The rule gives no false verdict under tr_TR (20,740
  patterns against PHP 8.2, 8.4 and 8.6, JIT on and off).
- A short match that is not empty is as quadratic through the functions
  that look for every match, without any retry: each match reads to the
  end of the run before the next starts one character later.
  `/a(?:a+\d)?/`, `/a*b|a/` and `/a\K(?:a+\d)?/` take about 63 ms on
  16,000 `a`s through `preg_match_all()` (PHP 8.4.28, PCRE2 10.49).
  Report them through `RedosAnalysis::$everyMatchSearchCost` (#444).
- The every-match cost is reported once per literal, at its first call: a
  `$p` that `preg_match()` reads before `preg_match_all()` is not
  reported. Report it at each looping call of the literal.
- The every-match cost reads the native functions, `Safe\` and
  composer/pcre alone: Nette's `Strings`, Spatie's `Regex` and Laravel's
  `Str` methods that loop, and the `RegexIterator` modes that do, are not
  read yet; read their sources first.
- Psalm reports no ReDoS finding at all, the search cost included, where
  the linter and PHPStan do.
- The search cost misses a pattern whose attempt may match empty only
  where the subject ends: `/(?:a+\d)?$/` through `preg_match()` alone
  takes 1.5, 29, 70 and 356 ms on 2,000, 4,000, 8,000 and 16,000 `a`s
  with the JIT off (PHP 8.4.28, PCRE2 10.49), each attempt reading the
  run to its end, and `regex lint --redos` reports nothing.
- The proof leaves undecided an ambiguous loop that no suffix makes fail,
  and a heuristic finding stands where the engine is linear:
  `/^(?:a|a)+./`, and since #436 `/(*NOTEMPTY)^(?:a|a)+\K.?/` and
  `/(*NOTEMPTY)^(?:a\K|a)+/` (20 → 36 steps on 8 → 16 `a`s), reported
  critical. #443 proves such a loop linear when every attempt succeeds at
  its first try, and waits until after RC2: each of five reviews found
  loops it proves linear that exhaust the backtrack limit, the last
  `/^a+a{0,15}b+b{0,15}c+c{0,15}d+d{0,15}e+e{0,15}(?:f|f)+./` on 82
  characters (6.3 million steps). Merge 2.x into it, with #450's count,
  which rewrites the same parts of `AmbiguityFinder`, then review it again.
- Since #450 a linear verdict is refused where one attempt may try more
  than 1,024 paths at once, which takes the proof from linear patterns
  too: 1,194 of 66,963 fuzzed patterns proven linear before, and two of
  the corpus, of the `/^(RH|r)?…V((Z?F)?[N]{0,2})?…/` form, that take 4 to
  5 steps per character. Count the paths more exactly where they meet.
- The count of #450 slows some analyses: a chain of 26 `X+.{0,15}` takes
  216 ms against 10 ms before, and patterns of 5 to 10 `(?:X{0}|)` or
  `\K` groups around loops take 350 to 700 ms against 25 to 55 ms.
- The search cost keeps an atomic body other than one run with every way
  through it, so only the replay (confirmed mode) reports
  `/(*NOTEMPTY)(?>a+\K)/`, `/(*NOTEMPTY)(?>(?>a+)\K)/`,
  `/(*NOTEMPTY)(?>a+\K|b)/` and `/(?>a+|c)b/` (1.1, 4.1 and 16 ms on
  2,000, 4,000 and 8,000 `a`s with the JIT off); the theoretical mode
  leaves `search_cost` null, though `(?>a+\K)` is `(?>a+)\K`. Read such a
  body as the run it holds.
- The replay counts a possessive run's reads by putting each repeated
  character in a group of its own (#439), but not where the delimiter is
  `?`, `:` or `)` or the body holds `\Q`: `?a++b?`, `:a++b:` and
  `/\Qa\E++b/` stay unconfirmed. Replay those under a delimiter the body
  does not hold, as `NoJit` does for `*`, `_` and `)`.

## Counted benchmarks

- The counted figures of `ValidationBench::benchValidateKept` move with
  changes that never reach validation: `lookbehind-alternatives` reads
  106.2K or 106.7K instructions (141.1K or 150.6K cycles) and
  `many-backreferences` 51.77K or 52.16K (84.51K or 94.18K cycles), each
  figure the same on every rerun of one head; the cycles failed #446 and
  #443. Find what moves them, or give the method a wider cycles margin
  with `#[MetricTolerance]`.

## Engine reports

Found while fixing the bugs of #400; each is PCRE2's, not the library's.

- `pcre2_jit_match()`, which PHP calls when the JIT is on, ignores
  `(*NOTEMPTY)` and `(*NOTEMPTY_ATSTART)`, where `pcre2_match()` honours
  them, through the JIT too: `preg_match('/(*NOTEMPTY)a*/', 'b')` returns 1
  with the JIT and 0 with `pcre.jit=0`, and `(*NOTEMPTY_ATSTART)a*` on `b`
  matches at offset 0 with the JIT and at 1 without. pcre2pattern says the
  items act as the options do for whichever function matches, and both
  options work with `pcre2_jit_match()`. No issue or pull request names it
  on 2026-11-22 (10.43 to master): report it to PCRE2, with the fix of
  copying the two flags into the options as `pcre2_match()` does.
- Under `(*UTF)` without UCP and another locale's tables, PCRE2's paths
  disagree on whether a code point from 128 to 255 is in `\w`: under
  fr_FR.ISO8859-1, on `é`, `/(*UTF)\w/` gives 0 and
  `/(*NO_START_OPT)(*UTF)\w/` and `/(*UTF)[\w]/` give 1, with the JIT and
  without, while `/(*UTF)^\w$/` gives 1 with the JIT and 0 with
  `pcre.jit=0` (PHP 8.4.28, PCRE2 10.49; a separate process per JIT
  setting, since PHP's cache keeps the first compile). pcre2pattern says
  only that such characters "may be different" under locale tables; the
  answer should not depend on the path. Search PCRE2's tracker, then
  report it. `CharSetAnalyzer` holds what any path takes there (#432).
- The same paths disagree on case under a locale whose case maps are not
  inverse: macOS's tr_TR.ISO8859-9 gives `tolower(0xDD) = 'i'` and
  `toupper(0xFD) = 'I'`, while `I` and `i` pair with each other.
  `/\xFD/i` matches `I` with the JIT and not with `pcre.jit=0` (PHP 8.4.28,
  PCRE2 10.49): the interpreter compares the lowercase forms (`lcc`), the
  JIT the other case (`fcc`). Report it with the `\w` one.
- PCRE2's open pull requests fix the JIT's two other wrong answers, found
  on 10.49 and master: #1004 a zero-width branch of a variable-length
  lookbehind (`/(?<=a?b|\b)x/` on `x`, since 10.43) and #1005 the prefix
  scan (`/\x{100}.{2}|A/u` on `A`, since 10.45, so not in PHP's bundled
  10.44). Nothing to report: check that a release ships them.


## PCRE2 10.50

10.50 is due on 2026-11-30. On 2026-11-21, 10.49 is the newest release, and
the 140 patterns PCRE2's development branch added to `testinput2` and
`testinput5` since then all get from the library the verdict 10.49 gives.

- Once 10.50 is out, move the vendored suite to it
  (`tests/Fixtures/Pcre2/README.md`), and add 10.50 to
  `release-outcomes.json` and to the `pcre2test` builds of `engines.yml`.
  Error 204 reads "requires" where 10.49 writes "require", the wording
  `Pcre2TestdataExtractorTest` quotes.
- PCRE2#1072, in the 10.50 milestone: `/(?<=a(?(DEFINE)(?<=b+)))x/`
  compiles on 10.49, and the library agrees (`DefineSkippedInLookbehind`,
  from 10.35). Should 10.50 measure a lookbehind inside a DEFINE inside a
  lookbehind, it refuses that pattern with error 125, and the feature needs
  a last release.
- PCRE2#549, in the 10.51 milestone (due 2027-01-31), points every compile
  error offset at the failing character. The library gives PCRE2's offsets
  release by release: expect offset gates.

## Release 2.0.0

`v2.0.0-RC1` is out (2026-11-14, a pre-release from `a680f3979a`): the
phar, its signature and its checksum are attached and attested, the twenty
split repositories carry the tag, and Packagist serves it for each of
them. `2.x` keeps reporting `2.0.0-RC1` until the final bump:
`bin/bump --next` refuses a development version below a pre-release.
`yoeunes/regex-parser` is abandoned on Packagist in favour of
`php-regex/php-regex`, and lists the `v2.*` tags of the monorepo too.

In this order.

1. **The final bump.** `bin/bump 2.0.0` sets `Regex::VERSION` and
   `Regex::VERSION_ID`, dates the CHANGELOG section, and moves the install
   story from `2.x-dev` to the stable release: `docs/_data/product.yml`
   says `stable: true`, which the install include every page and article
   reads; `README.md` and `docs/llms.txt` lose the two `composer config`
   lines, the `:2.x-dev` suffix and the "2.0 is coming" section; and the
   sample outputs of the docs report `2.0.0`. Read the diff, then commit
   it on its own branch. `bin/release` refuses a final version whose docs
   still install from the dev channel. Run `bin/playground-bundle` on that
   branch too, after `wasm/pcre2-trace/build.sh` (it stops without the
   trace module), so that the home runs the library of the release: the
   bundle in `docs/assets/playground/` still dates from 2026-11-10.
2. **Before the tag**, check with `bin/status` that each of the twenty
   split repositories (the nineteen packages and the PHP 7.4 twin) carries
   a fresh 2.x head and a crawled dev version, and that every workflow
   `bin/release` reads answers for HEAD: a `TODO.md` commit pushed on
   `2.x` alone needs `gh workflow run spelling.yml --ref 2.x`.
   The split pushes are not atomic: between the first and the last webhook
   after the tag, a `^2.0` resolution fails on missing siblings.
3. **Tag 2.0.0**: `bin/release --dry-run 2.0.0`, then `bin/release
   2.0.0`, and check the assets, `gh attestation verify regex.phar --repo
   php-regex/php-regex` and the phar on PHP 8.1, as for the RC. Then
   `bin/status` and Packagist show `v2.0.0` for all nineteen packages
   (they require each other at `self.version`: one missing tag leaves the
   others uninstallable) and for the twin, and the "Latest" release
   carries the 2.0 `regex.phar`, which `self-update` and `releases/latest`
   serve.
   Run `uses: php-regex/php-regex@v2.0.0` once on a test repository: the
   action downloads that phar and checks its checksum.
4. `bin/bump --next 2.0.1`.
5. Submit `src/Linter/regex.schema.json` to schemastore.org (`fileMatch`:
   `regex.json`, `regex.dist.json`) for editor support without setup: it
   needs the stable public URL the tag gives it.


## PHPStan proof of concept

The proof of concept stays on the fork, as php-regex/phpstan-src#1: no pull
request goes to `phpstan/phpstan-src`.

- In `~/projects/php-regex/phpstan-src`, branch
  `read-regex-shapes-through-phpregex` is one local commit, `da58ce923a`,
  on the parent of the pushed `079eba6c9`: it pins the twin's `2.0.0-RC1`
  (composer.json named `11e3725`, never published, and the lock another
  commit) and folds in the two `preg_quote()` commits. The nsrt suite
  (1,903), the regex, rule and engine tests (340 patterns) and `make
  phpstan` pass with RC1. Before the force-push to the fork: run the full
  suite (`make tests`), which the description says passes; merge the
  second docblock of `RegexExpressionHelper::resolvePatternConcat()` into
  upstream's above it; then refresh the description of
  php-regex/phpstan-src#1: the RC1 line, the `preg_quote()` line, the
  #14490 line (the return type of `preg_match()` on a pattern sure to
  match; its own pattern keeps the 0) and the parse cost (395 literal
  patterns of PHPStan and its vendor, PHP 8.4, median of five runs:
  validating 97 ms and the capture shape 57 ms, against 88 ms for the
  generated parser; 30 ms against 10 ms for the first pattern, 6 MB more
  peak memory, a second read free, the same 3.7 s over twelve regex-heavy
  files; memoising the length calculator or the ACCEPT check, or walking
  the letter check once, each measured under 3 %).


## Linter precision

- `CharSetAnalyzer` reads `(*UCP)` without u as Unicode case past ASCII
  on every release, where PCRE2 does so from 10.35 alone (#440): with a
  target of PHP 7.4 and PCRE2 10.34, `regex.lint.overlap.charset` reports
  `"/(*UCP)(?:\xC9|\xE9|)+/i"`, though `/(*UCP)\xC9/i` matches `\xC9`
  alone there (php:7.4.10). Gate it on `UcpFoldsPastAsciiWithoutUtf`, as
  `UselessIFlagRule` does.
- `regex.lint.flag.useless.i` keeps an `i` the engine finds useless where
  a class holds both cases, `/[aA]/i`, `/(*UCP)[\xC9\xE9]/i`,
  `/(*UCP)[\xC0-\xFE]/i`, or an inline flag turns it off, `/(?-i)a/i`,
  `/(*UCP)(?-i)\xE9/i`; and keeps it for `/(*UCP)(?i)\xE9/i`, where the
  pattern's own `i` adds nothing.
- PHPStan's own `regexp.pattern` rule reports the php/pie loop too: it
  does not narrow a union of constant strings by `$v[0] === '|'`. The fix
  is committed, not pushed, in `~/projects/php-regex/phpstan-src-narrow-constant-string-offset`
  (branch `narrow-constant-string-offset`, `70e61331c` on upstream 2.3.x
  `9c990f5dd`): `IdenticalNarrowingHelper` keeps the constant strings whose
  byte at a constant offset is the literal, its `turbo-ext` mirror does
  the same, and an nsrt test covers both branches, negative and out of
  range offsets; `make tests` passes with and without the extension. Push
  it to the fork and open the pull request to phpstan/phpstan-src.
  phpstan-src#6614 (`substr()` or `$s[0]` against a longer string) is
  related, not the same.


## Optimizer precision

- Since #451 the possessive rewrite stays out of a pattern holding a
  non-atomic lookaround, past whose end the walk reads the outer text,
  where the engine reads it from where the lookaround starts:
  `/^(*napla:x(a+b?))x[ab-]*\1$/` would be offered `a++b?`, which misses
  `xaaba`. Read that end as followed by nothing known, so that the rest
  of such a pattern can be made possessive.
- A repeated group followed by other items is offered its possessive from
  the characters its body ends with, though it gives back whole runs:
  `/(?:b|ba)*c/` is offered `(?:b|ba)*+c`, which matches `c` alone in
  `bac`, and `/[a-z]+(?:-[a-z]+)*$/` is offered `(?:-[a-z]++)*+$`, which
  holds but which the check does not prove. Either refusal reruns the
  rewrite without any possessive, so the pattern ships greedy throughout.
  Read every character a run may give back, or leave such a group out as
  #451 does one that ends its sequence.
- A repeat that ends a group a call runs in a loop, `(?1)+` or `(?&n)*`,
  stays greedy even where the group never starts with what the repeat
  gives back, `/(xa+)c(?1)+d/`: the walk reads no first characters of a
  call.
- A group that calls itself is still refused, in a DEFINE or not:
  `/(?&n)c(?(DEFINE)(?<n>x(?&n)?ca+b?))/` is offered `ca++b?`, refused
  with the call message, and ships unchanged, though the engine finds the
  possessive form the same.

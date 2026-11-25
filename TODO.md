# TODO

## After 2.0.0

Land each after the 2.0.0 tag, with a review of its own.

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
- Since #450 a linear verdict is refused where one attempt may try more
  than 1,024 paths at once, which takes the proof from linear patterns
  too: 1,194 of 66,963 fuzzed patterns proven linear before, and two of
  the corpus, of the `/^(RH|r)?…V((Z?F)?[N]{0,2})?…/` form, that take 4 to
  5 steps per character. Count the paths more exactly where they meet.
  The count also still costs more than the analysis did before it:
  twenty-six loops `X+.{0,15}` take 491M instructions against 68M before
  #450 (`tests/Benchmark/data/redos/loop-chain.php`); merging the queue
  entries `readOneInput()` expands per step would move the verdicts at the
  budget's edge, so review the budget with it.
- Report the short matches the every-match cost leaves out, through
  `preg_match_all()` on 16,000 `a` (PHP 8.4.28, PCRE2 10.49,
  `pcre.jit=0`): a match past four run words, `/a{5}(?:a+\d)?/` (12.7
  ms); a first call whose first attempt fails, `/\Ba(?:a+\d)?/` and
  `/(?<=a)a(?:a+\d)?/` (64 ms); paths that may succeed through an
  abstracted bounded repeat, `/a(?:a+\d|a{20})?/` (3 ms).
- Price the loop nette/utils' `Strings::matchAll(..., lazy: true)` runs
  itself: `preg_match()` from the end of each match, with no retry.
  `/(?:a+\d)?/` costs it 1.2, 3.6 and 12.5 ms on 2,000, 4,000 and 8,000
  `a` (JIT on).
- Name the loop of two calls the extraction misses: a list of patterns
  held in a variable, `$l = ['/p/']; preg_replace($l, '', $s);`, names
  none in either strategy, where `preg_replace(['/p/'], '', $s)` names
  `preg_replace` (`literalsReadAt()` hands the array to `literalsIn()`,
  whose `arguments()` expands only an array read through a name); and in
  `function f($s, $c) { if ($c) { $p = '/p/'; preg_match_all($p, $s);
  ?>html<?php } }` the PHP-Parser strategy names none where the token one
  names `preg_match_all` (`AstValueCollector::significantAt()` does not
  skip `?>` and `<?php`). Both miss a search cost, never report a false
  one.
- The Psalm plugin reads the `preg_*` calls alone: the wrapper methods
  PHPStan reads (composer/pcre, nette/utils, spatie/regex, Laravel's
  `Str`) are neither validated nor checked for ReDoS there.
- The search cost reads the last code unit PCRE2 requires, not the first
  one: a first 0xFF under tables that give it another case is looked for
  in its own case by the interpreter (engine report below). Under
  ru_RU.KOI8-R, `/\xff+!/i` is reported quadratic with a run of `\xdf`,
  on which `pcre.jit=0` starts no attempt (0.0 ms on 20,000, against 130
  ms on a run of `\xff`; the JIT's scan does try it), and confirmed mode
  says `replayed: false`. Read the first code unit as `LastCodeUnit` reads
  the last, and pick a run both paths try.
- Since #471 no possessive is offered or proven inside a group a call
  runs, as PCRE2's JIT misses matches there (engine report below):
  `/(a+b?)c(?1)d/`, `/(x(?1)?ca+b?)c/` and the DEFINE forms #451 and #454
  made possessive ship greedy. Once PCRE2 fixes it, narrow the refusal to
  what a JIT fuzz confirms, then read a call inside a lookahead
  (`/(?=(?&n)c)(?(DEFINE)(?<n>x(?&n)?ca+b?))/`), a backreference after
  the group (`/(x(?1)?ca+b?)c\1/`), `(*ACCEPT)`, calls that return groups,
  `(?1(2))` and `(?R(1))`, and a call in a loop as the first characters
  of its group, `/(xa+)c(?1)+d/`.
- The check of the result refuses every pattern holding a lookaround, so
  the optimizer offers no possessive there: `/(?=x)xa+b/` into
  `/(?=x)xa++b/` and `/(*napla:x)xa+b/` into `/(*napla:x)xa++b/` are
  refused. Teach the match solver to read a lookaround, then read the end
  of a non-atomic one as followed by nothing known
  (`/^(*napla:x(a+b?))x[ab-]*\1$/` would be offered `a++b?`, which misses
  `xaaba`).
- Model `^`, `$` and `\Z` under `(*CRLF)` in the automata, and the dot and
  `\N` there, which read a `\r` alone but not one before a `\n`: both are
  refused, `/(*CRLF)a.\n/` and `/(*CRLF)\r^\n/m` find no match in `"a\r\n"`
  and `"\r\n"`. Read too the searches under `(*CRLF)`, `(*ANYCRLF)` and
  `(*ANY)` whose match may start between `\r` and `\n`, refused where the
  JIT and the interpreter skip it apart: `/(*ANYCRLF)^\n/m` on `"\r\n"` is
  1 with the JIT and 0 without, while `/(*ANYCRLF)\na/`, which both try
  there, is refused too.
- `Transpiler::transpile('/a+(?:b)?+a/', 'javascript')` writes `a+(?:b)?a`
  and `/a+(?>(?:b)?)a/` becomes `a+(?:(?:b)?)a`, with no warning, where
  PHP finds no match in `aa` and JavaScript does (engine report below).
  Warn where `AutoPossession` finds a repeat that could give back.
- `regex.lint.flag.useless.i` keeps an `i` the engine finds useless:
  `/[Ii\xDD\xFD]/i`, `/(*UCP)[iI]/i` and `/(*UCP)[iI\xDD\xFD]/i` under
  macOS's tr_TR.ISO8859-9, whose bytes the tables leave unsettled though
  every reading matches the same 256 bytes; `/(?r)[kK]/iu`;
  `/[aA[:upper:]]/iu` and `/[aA[:lower:]]/iu`; `/\x{149}/iu`; and a
  reference to a group holding a call, a lookaround or `\p{Nd}`,
  `/([\p{Nd}])\1/i`.
- The overlap rules miss two identical literal branches past ASCII beside
  an empty one under u, `/(?:é|é|)+/u` and `/(?:É|É|)+/iu` (backtrack
  limit on 20 `é` then `!`), and report branches no input starts alike:
  `/(?:(?>ab)|ac)+/`, `/(?:(*sr:öa)|öb)+x/u` and `/(?:a\d|aa)+/`. Read
  the leading text through an atomic group and a script run, and tell
  branches apart only where the items after the text can meet.
- Under u, the rules that compare runs or branches read an ASCII letter's
  case from Unicode, where PCRE2 reads it from the target's tables: under
  glibc's tr_TR.UTF-8, `/^I$/iu` does not match `i`, yet `/(?:i|I)+$/iu`,
  `/(?:i+I)+$/iu` and `/i+I+$/iu` are reported (without u they are not
  since #468).
- `LanguageSolver::readAlike()` says two patterns that read alike run
  alike wherever their text is spliced, but PCRE2 stops a lazy repeat at
  the end of a group: `(?:a?)` and `a?` read alike, yet under
  fr_FR.ISO8859-1 `/\xFF+?(?:a?)\w/` finds `\xFF\xFF` and `/\xFF+?a?\w/`
  does not. Routes never splice a requirement bare (the router wraps each
  in a named group, which stops the repeat too), so only the docblock is
  wrong: say "wherever spliced as a group", or read the lazy repeat.
- `regex:routes` reports a later route shadowed where it overlaps: with
  `/{x<admin>}` before `/{p<admin/>}` (GET), the compiled matcher sends
  `GET /admin//` to the later one, whose value ends on the slash the `/?`
  of the dumper takes as is, and so does `/admin` before it from
  symfony/routing 6.4.51, 7.4.24 and 8.1.12. Read that slash in
  `RouteConflictAnalyzer::redirectConflict()`.
- Before symfony/routing 6.4.51, 7.4.24 and 8.1.12, the matcher dumper
  reorders routes whose host variable shares its label or can match a
  dot (`/report/{id}` on `{sub}.example.com` and on
  `app-{sub}.example.com`), and `RouteConflictAnalyzer` reads declaration
  order. Model that reordering there, or skip such pairs on those
  releases.

## Open questions

- #464 asks a target for PCRE2 10.44, whose JIT clears the groups of a
  possessive repeat, through `PcreFeature::LongGroupNames`, which 10.44
  brought too. Give that behaviour a `PcreFeature` case of its own, with
  the line the backward-compatibility check needs, as #469 did.
- Decide what a target that names no locale means for classes above ASCII
  without u: `matchEquivalent('/\w/', '/[a-zA-Z0-9_]/')` answers alike
  under C, while `/\w/` matches `\xE9` under en_IE.ISO8859-1. The
  mutation judge reads plain classes that way.

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

## Engine reports

Each is PCRE2's, not the library's.

- PCRE2's auto-possessification makes a repeat possessive before an
  optional group inside an atomic group or a possessive group repeat:
  `/a+(?:b)?+a/`, `/a+(?>(?:b)?)a/` and `/a+(?>|b)a/` find no match in
  `aa` (10.34 to 10.49, JIT on and off), where `no_auto_possess` and Perl
  find it. No issue names it on 2026-11-25 (PCRE2#1034 and PCRE2#1036
  fixed the `(?R)` case): in `compare_opcodes()`, the `OP_BRAZERO` case
  recurses with a fresh `entered_a_group`. Report it.
- With the JIT, a possessive repeat in a capturing group a later call runs
  loses matches once an attempt that ran the call has failed:
  `/(a++)c(?1)d/` finds no match in `acacad` (10.34 to 10.49), where the
  interpreter and `no_start_optimize` find `acad`; auto-possessification
  reaches it from `/(a*b)c(?1)d/` on `bcbcbd`. Report it.
- In 8-bit mode without UTF or UCP, `pcre2_compile.c` flags a first or
  required code unit caseless only below 255, so 0xFF is looked for in
  its own case: under ru_RU.KOI8-R, `/a\xff/i` finds no match in
  `a\xdf`, JIT on and off, where `(*NO_START_OPT)` finds it. Report it
  with the case items above.
- Auto-possessification reads the byte 0xFF as no word character: under a
  locale whose `\w` holds it (fr_FR.ISO8859-1, fr_FR.ISO8859-15,
  ru_RU.KOI8-R, tr_TR.ISO8859-9), `/\xFF+\w/`, `/\xFF+?\w/` and
  `/[\xFF]+\w/` find no match in `\xFF\xFF`, and `/\w+\xFF/` none in
  `a\xFF\xFF`, JIT on and off, where `/\xFF+[\w]/`, `/(?:\xFF)+\w/` and
  `(*NO_AUTO_POSSESS)` find it (PHP 8.4.28, PCRE2 10.49); `(*UTF)`
  without UCP too, `/(*UTF)\x{FF}+\w/` on `ÿÿ`. No other byte and no
  other type differs. Look for a `chr < 255` in `compare_opcodes()`, as
  in the code unit item above, and report both together. The solver
  refuses the shape since #476, and the lint and the Modernizer keep the
  brackets that decide it since #477.
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
  JIT the other case (`fcc`). Report it with the `\w` one. Byte 0xFF
  splits the paths too under macOS's ru_RU.KOI8-R and fr_FR.ISO8859-15:
  `/\xFF/i` matches `\xDF` and `\xBE` with the JIT, and no other byte with
  `pcre.jit=0`.
- PCRE2's open pull requests fix the JIT's two other wrong answers, found
  on 10.49 and master: #1004 a zero-width branch of a variable-length
  lookbehind (`/(?<=a?b|\b)x/` on `x`, since 10.43) and #1005 the prefix
  scan (`/\x{100}.{2}|A/u` on `A`, since 10.45, so not in PHP's bundled
  10.44). Nothing to report: check that a release ships them.

## PHP

- PHP 8.5 writes the key `MARK` twice when a group named MARK sits beside
  a mark verb: `preg_match('/(?<MARK>a)(*MARK:m)/', 'a', $n)` gives four
  entries, `0`, `MARK => 'a'`, `1`, `MARK => 'm'` (8.5.13; 8.4.28 and
  8.6.0 write the key once, the mark over the group). A read by name gives
  the mark; `foreach`, `count()` and `var_export()` see both. Look for a
  php-src issue or an 8.5 fix; report it there if none. The playground
  driver and `PsalmPluginMarkWithOffsetsTest` read the key by name.

## Psalm

- Psalm types `preg_match()`'s matches under `PREG_OFFSET_CAPTURE` as an
  offset pair under every key, where PHP writes the name of a mark verb
  under `MARK` as a plain string, over the pair of a group named MARK:
  `preg_match('/(?<MARK>a)(*MARK:m)/', 'a', $n, PREG_OFFSET_CAPTURE)`
  gives `'MARK' => 'm'`. From 7.21.0 Psalm narrows the plugin's assertion
  by that type and traces `MARK: list{'a', int<0, max>}` where the plugin
  says `'m'|list{'a', int<0, max>}`
  (`PsalmPluginMarkWithOffsetsTest`). Report it to vimeo/psalm with that
  call.

## PHPStan

The proof of concept stays on the fork, as php-regex/phpstan-src#1; only the
narrowing fix goes to `phpstan/phpstan-src`.

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

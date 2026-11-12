# TODO

## Release 2.0.0

In this order.

1. **Repoint `yoeunes/regex-parser` on Packagist** to a repository holding
   1.x only, before anything is tagged. It still reads
   `php-regex/php-regex` and lists `2.x-dev` beside `1.x-dev`, so a `v2.0.0`
   tag would release the 1.x package as 2.0.0 (`bin/status` reports it ✗
   until then; `UPGRADE-2.0.md` promises it stays on 1.x). Afterwards, its
   dev channel lists `1.x-dev` only.
2. **The bump commit.** `bin/bump 2.0.0` sets `Regex::VERSION` and
   `Regex::VERSION_ID`, dates the CHANGELOG section, and moves the install
   story from `2.x-dev` to the stable release: `docs/_data/product.yml`
   says `stable: true`, which the install include every page and article
   reads; `README.md` and `docs/llms.txt` lose the two `composer config`
   lines, the `:2.x-dev` suffix and the "2.0 is coming" section; and the
   `2.0.0-DEV` sample outputs of the docs report `2.0.0`. Read the diff,
   then commit it on its own branch. `bin/release` refuses a final version
   whose docs still install from the dev channel.
3. **Before the tag**, check with `bin/status` that each of the nineteen
   split repositories carries a fresh 2.x head and a crawled dev version.
   The split pushes are not atomic: between the first and the last webhook
   after the tag, a `^2.0` resolution fails on missing siblings.
   `php-regex/regex-infection` is not on Packagist yet: submit
   `https://github.com/php-regex/regex-infection` and add its webhook
   first, or `bin/status` keeps reporting it ✗.
4. **Release candidate first.** The release path has never run on a real
   release (`v1.3.0` carries no checksum). `bin/release --dry-run
   2.0.0-RC1`, then `bin/release 2.0.0-RC1` (a GitHub pre-release): it
   drafts the release and pushes the tag; the phar workflow builds the
   phar, checks that its version is the tag's and that `regex.phar lsp`
   answers `initialize`, attests it, attaches it and its checksum, then
   publishes the draft; the split workflow tags the nineteen packages.
   Check both assets, `gh attestation verify regex.phar --repo
   php-regex/php-regex`, and the phar on PHP 8.1.
5. **Tag 2.0.0** the same way. Then `bin/status` and Packagist show
   `v2.0.0` for all nineteen packages (they require each other at
   `self.version`: one missing tag leaves the others uninstallable), and the
   "Latest" release carries the 2.0 `regex.phar`, which `self-update` and
   `releases/latest` serve.
   Run `uses: php-regex/php-regex@v2.0.0` once on a test repository: the
   action downloads that phar and checks its checksum.
6. `bin/bump --next 2.0.1`.
7. Submit `src/Linter/regex.schema.json` to schemastore.org (`fileMatch`:
   `regex.json`, `regex.dist.json`) for editor support without setup: it
   needs the stable public URL the tag gives it.


## PHP 7.4 twin

After the 2.0.0 tag, publish `php-regex/regex-parser-php74` once more from
the tagged commit, from the `twin` artifact of a green "php74 twin"
workflow run. The published `6eb39fa` is the build of 2.x at caea95400d.

Before that, the sure-match facts of #198 need a published twin: the build
of `always-matching-pattern` at 001b9b5526 was verified on PHP 7.4 and
committed on a clone of the mirror as `11e3725`, which waits for a push to
the mirror's `2.x`. The PHPStan fork (`read-regex-shapes-through-phpregex`,
one commit on upstream 2.3.x) already names that hash in `composer.json`;
once the mirror holds it, `composer update php-regex/regex-parser-php74`
there, amend, force-push, and refresh the description of its pull request
with the #14490 line (the return type of `preg_match()` on a pattern sure
to match).


## PHP 8.6 GA, 2026-11-19

1. That day, rename the `8.6-rc` rows of `engines-bundled` and
   `engine-claims` (`.github/workflows/engines.yml`), and the `8.6-rc` key of
   `EngineClaims::IMAGES` (`tests/TestUtils/EngineClaims.php`), to `'8.6'`:
   the `-rc` docker aliases vanish that day and an unrenamed row breaks CI.
   The `startsWith(matrix.php, '8.6')` conditions already match either
   spelling, and `engines-latest` needs no change. Check that the renamed
   image still bundles PCRE2 10.44, and if it ships mbstring again, remove
   the "Install multibyte support" step.
2. Once the tools' dependencies admit 8.6, drop it from the
   `--ignore-platform-req=php+` cases of `.github/actions/setup-php-project`
   and of `engines-bundled`.
3. Until then, watch `ext/pcre/pcre2lib` on the PHP-8.6 branch and on
   master: a late PCRE2 bump, or a downgrade like 8.5's at its release cut,
   reopens the engine maps. If it moves, re-run the engine-scoped corpus rows
   first.


## Blog articles

- The four launch articles wait for a read and a go: the tour's pull
  request publishes the blog list and the `## Articles` section of
  `docs/llms.txt` in the same stroke as the flip — one commit once they
  are validated.
- On the day of the 8.6 GA, re-check the 8.6 row of the version matrix in
  `which-pcre2-ships-with-which-php` against the PHP-8.6 branch: the article
  promises it. On 2026-11-10 the branch and master still bundle PCRE2
  10.44, as the row says.
- Next articles, in priority order: variable-length lookbehind across
  PHP 8.2–8.4, the capture shapes of `$matches`, why `pcre.backtrack_limit`
  does not protect under the PCRE2 JIT, regex equivalence with proofs,
  named groups with branch reset, measured data from the corpus,
  transpiling to JavaScript with a proof, `preg_match()` versus
  `str_contains()` measured, Symfony route requirements audited.


## PHPStan pull request

When the pull request is opened, state the parse cost in its description.
Measured over the 395 distinct literal patterns of the `preg_*` calls in
PHPStan and its vendor (PHP 8.4, median of five runs), with the twin
PHPStan ships: validating takes 97 ms and the capture shape 57 ms, against
88 ms for everything with the parser PHPStan generates today; the first
pattern of a process costs 30 ms against 10 ms, class loading included,
and the peak memory is 6 MB higher; a second read of a pattern is free; a
run over twelve regex-heavy files, those patterns included, takes the
same time with either parser (3.7 s), and PHPStan's benchmark suite calls
no `preg_*` function. The capture shape computes eight facts per group
and per arm, and nothing cheaper was found: memoising the length
calculator or the ACCEPT check, or walking the letter check once, each
measured under 3 %.


## PHPStan fork: preg_quote() placeholders

Everything below happens in the phpstan-src checkout
`~/projects/php-regex/phpstan-src`, branch `read-regex-shapes-through-phpregex`;
nothing changes in this repository. `RegexExpressionHelper::resolvePatternConcat()`
(`src/Type/Regex/RegexExpressionHelper.php`, the single choke point — its callers
are `RegexArrayShapeMatcher.php:726`, `PregMatchDynamicReturnTypeExtension.php:83`
and `RegularExpressionPatternRule.php:92`) replaces every `preg_quote()` with
`(?:.*)`, sound only where the pattern reads the quoted text as literal
characters. Six positions are not that: in a class, where
`'/[' . preg_quote($x, '/') . ']/'` folds to a class over the characters of the
placeholder; after `\`, where the escaped `(` eats the placeholder's and the
group never opens; after `(?`, where `'/(?<' . preg_quote($x, '/') . '>a)/'`
folds to a group name PCRE refuses, so a valid call is reported invalid; inside
`\Q…\E`; in braces; and before a quantifier or a comment an empty text leaves to
what precedes it.

`src/Psalm/QuotedPattern.php` in this repository is the contract to port:
`(?:[\s\S]*)` for any text, as `.*` misses `"\n"` without `s`; each quoted part
re-read in place with the probes `''`, `'\\\\E'`, `'0'`, `'n'`, `','`, `"'"`,
`'\.'`, `'\000'` (the forms `preg_quote()` emits) and the other parts empty;
each probe must parse, keep the exact group list (`CaptureShapeAnalyzer`), and
show its characters as literal nodes outside every class between the exact
offsets; `[*+?{#]` or `(?#` right after an empty text withholds; the first and
the last parts must carry the delimiters, as `preg_quote()` escapes the
delimiter only when it is given; any failure withholds, never guesses. A
withheld check returns a type with no constant strings, so the three callers
degrade; the probe logic sits in a class beside `RegexGroupParser`, which shows
how the pinned `php-regex/regex-parser-php74` build is consumed — anything new
must exist in that pinned build. The prose contract is "Patterns built with
`preg_quote()`" in `docs/guides/psalm.md`.

Red first, under `~/projects/php-regex/phpstan-src`: the six positions assert
the plain fallback type in `tests/PHPStan/Analyser/nsrt/preg_match_shapes.php`,
beside the existing `preg_quote` rows (386-422), which keep their types — a
quoted part in literal position loses nothing; and the `(?<` case leaves
`RegularExpressionPatternRuleTest` silent on a valid call. Green through
`make tests`, `make phpstan`, and the 329-pattern engine parity test. A separate
follow-up folds `preg_quote()` of a constant string to the literal it returns,
as `CallTypes::quote` does.

Two verdicts leak through the placeholder even where the quoted text is read
as literal characters, so the port above does not cover them (seen 2026-11-10,
PHP 8.4, PCRE2 10.49). `PregMatchDynamicReturnTypeExtension` reads the
placeholder as the pattern: `preg_match('/' . preg_quote($x, '/') . '/', $s)`
is typed `1|false` and `=== 0` on it is reported as always false, where `/abc/`
returns 0 on `zzz`. `RegularExpressionPatternRule` compiles the placeholder:
`'/(?<=' . preg_quote($x, '/') . ')\d+/'` is reported as
`length of lookbehind assertion is not limited`, where `/(?<=id\=)\d+/`
compiles; PHPStan 2.3.5 reports it too. As `PregCallAnalyzer::typeAroundQuotes()`
does here, the placeholder stands for the groups only: the return type and the
validity rule read a pattern with a quoted part as a pattern that is not
constant. Red first: a new row keeps `0|1|false` for that `preg_match()`, and
the lookbehind call leaves `RegularExpressionPatternRuleTest` silent. Under `x`
a quoted space is not a literal either (`/^a b$/x` matches `ab`, not `a b`), and
the probes hold no whitespace.


## Pull requests to merge before the tag

#335 first: the time-ratio tests it settles fail the PHP 8.1
lowest-dependencies cell of every other one. Each of the others then takes
`2.x` again before it is merged. #343, #345, #346 and #348 each move
`Regex::CACHE_VERSION`: after the first of them is in, the next ones take
`2.x` and run `php tests/Tools/update_cache_version.php`.

- #335: the linear-time tests read 10 to 12 where the read is linear,
  because a run of the cycle collector walks what the process holds and the
  larger read alone sets it off; every timed read of the suite now holds
  the collector. The PHP 8.1 lowest-dependencies cell is green on it.
- #334: a JSON run PHP stops at its time limit prints the envelope however
  long the shutdown takes (`JsonFatalErrorTest`, exit 255 on Windows).
- #336: the rewrite verification test asserts the threads the match check
  steps, not its time.
- #337: the two worker pool tests and the boundary rule test are judged by
  what the code did.
- #338: one macOS cell on a pull request, a ceiling on the matrices, `CI`
  reported for a pull request of prose alone, Box downloaded with a retry,
  no coverage driver in the phar builds. See "Pull request checks in
  stages".
- #339: `format` keeps a `#` comment that ends in an escaped space.
- #340: capture shapes read the body of a called group once per fact
  (twenty levels of a `(?(DEFINE)` call graph: 16 s to 2 ms).
- #341: without `u`, a transpiled set that reaches past ASCII carries a
  warning that names it and advises `u`; nothing is refused. In the Laravel
  command such a pattern now reads `PARTIAL`.
- #342: overlapping alternation branches are found without comparing every
  pair (2,000 branches sharing a start: 36 s to 0.2 s), with the same
  findings.
- #343: nested alphabetic assertion bodies are no longer kept in copies
  (900 levels: 264 MB to 11 MB). The lexer still cuts the text of a body
  once per level, so the time stays depth times size.
- #344: the playground bundle is rebuilt from the current sources, and the
  page no longer rewrites the sentences of an explanation.
- #345: the size limit is read as the PCRE2 10.40 of PHP 8.2.35 checks it
  (php-src backported upstream's fixes there), and
  `PHP_BUNDLED_10_40_DIVERGENCES` is gone. It adds a gate that is no point
  of the judged range (`PhpVersionGates::ERROR_ONLY`): the one decision of
  it to look at.
- #346: the lookaround product is read by classes of characters and
  counted (twelve Unicode-class lookaheads: 39 s to 0.3 s, same refusal).
  A few patterns refused for the determinize budget are now answered.
- #347: the suite names `posix_geteuid()` when it starts on a host without
  it, and the contributing guide lists it; the tests are not skipped there.
- #348: a ReDoS repair tries the blamed quantifiers first and keeps no tree
  per candidate (1,000 quantifiers: 65 s and 407 MB to 20 s and 21 MB),
  with the same repairs and no cut-off.

Before the release candidate, create the release key, set
`PHAR_GPG_PRIVATE_KEY` and `PHAR_GPG_PASSPHRASE` as secrets of the
repository, and publish the key's fingerprint beside the Phive line of
`docs/guides/cli.md`; without the secret a release goes out unsigned.


## Pull request checks in stages

- After #338 is merged, make `CI` the required check of `2.x` in the
  branch protection: a pull request of prose alone now reports it.
- No run of #338 reached stage 3 before its review ended: read its first
  complete run for the single `Tests (PHP 8.4, latest PCRE2)` macOS cell
  and for the ceilings (twenty runners for the matrices of one pull
  request). The path of a pull request of prose alone is held by tests
  only: open one and see `CI` reported.
- The two stops were read on GitHub (2026-11-10): "Stopped at stage 1, the
  quick checks: Static analysis failed. Stages 2 and 3 did not start." and
  "Stopped at stage 2, the reference: Reference, Coverage failed. Stage 3,
  the matrix, did not start."
- `packages.yml` starts about 33 jobs of fifteen seconds at once in stage
  2, with no ceiling.
- The Box pin (4.7.1 and its checksum, in `phar.yml` and `packages.yml`) is
  bumped by hand: Dependabot does not see it.
- The `tests/**` instruction of `.coderabbit.yaml` names
  `#[RequiresPhpExtension]` alone as an attribute gate, where the suite
  uses `#[RequiresFunction]` too: the review raises it each time.


## Pattern resolution without PHPStan

What a pattern argument resolves to is the library's own knowledge: the
extraction of `regex lint` and of the phar decides it, the PHPStan and Psalm
packages only answer what an expression is worth. Measured 2026-11-10 over the
328 repositories of the corpus, 44,405 pattern arguments of the `preg_*`
calls (PHP 8.4): the extraction reads 83.8 % of them (78.3 % literals, 5.5 %
through a name), the tokenizer and the PHP parser agreeing on every one of
51,861 arguments; constant folding as PHPStan does it reads 86.8 %. The
other 16.2 % are left out without a word: 3.1 % folding would read, 8.2 % a
static pattern around a part that is not (2.7 % `preg_quote()`, 5.4 % anything
else), 4.9 % nothing static at all.

Decided 2026-11-10, for 2.0: every argument ends in one of five states (one
pattern, a finite set of at most 32, a static pattern around `preg_quote()`
texts, a static pattern around another dynamic part, nothing static); the
states and `QuotedPattern` live in the parser package, internal; a reader
hands over literal parts and unknown ones, never a set of strings; the run
counts what it could not verify and lists it per site only when asked, as
`info`; a pattern around `preg_quote()` gets one error, invalid whatever the
text, and only where the text stands as literal characters. A part that is
not quoted is not reported: of 60 filled by a parameter and read by hand, 28
were data left unquoted, 23 a regex fragment meant to be one, 9 undecided,
and a parameter is the only unknown part of 14 % of them. That finding comes
back on a rule measured at 90 % on a hundred parts labelled by hand.

The 3.1 % folding reads, by what the extraction lacks, most first: a local
variable as an operand of a concatenation; an interpolated string; an array
of patterns (a `foreach`, a variable holding it, one of its elements); a
variable at file level; a variable assigned on several branches, a ternary or
a `match`; `sprintf()` and `implode()` of constants; a variable a closure
captures. `static::X` and `self::X` in a closure stay unread on purpose.


## Open questions

- The capture shape of `/(?:(?=y)|(x))(^|a)/` is wrong: on `xa` PHP writes
  `["xa","x","a"]`, and the shape reads `array{0: '', 1: '', 2: ''|'a'}|
  array{0: 'x', 1: 'x', 2: ''|'a'}` (2026-11-10, PHP 8.4, PCRE2 10.49).
  The at-start and at-end marks of `CaptureFacts` only grow, so the mark
  made for the zero-width branch still stands in the next case and `^`
  wins there; with the branches the other way round the shape is right
  (`'x'|'xa'`). A fix changes answers: its own pull request after #340,
  with `ANALYSIS_VERSION` moved. PHPStan and Psalm read this shape.

- The interpreter of the PCRE2 10.42 that Ubuntu 24.04 packages
  (`10.42-4ubuntu2.2`) keeps what a subroutine call captured in the groups
  inside the called one: `/(?(DEFINE)(?<pair>(\d)(\d)))((?&pair)(?&pair))/`
  on `1234` writes `2: 3` and `3: 4` in `pcre2test`, where its own JIT, the
  10.42 PHP bundles and Debian's 10.46 leave both unset (2026-11-10). PHP
  on that system answers one way or the other as the JIT runs the pattern
  or not, and the capture shape, which says `''` for both, is wrong there
  without the JIT. The call graph rows of #340 ask the interpreter and are
  held to the engines that give the captures back
  (`requiresCallCapturesGivenBack()`). Which Ubuntu patch does it, whether
  other packaged releases do, and whether the shape should say so for a
  system 10.42 are open; the engine tests that write `ini_set('pcre.jit',
  '0')` after a pattern was compiled do not ask the interpreter either.

- `format` writes an inner line of a `(?#...)` comment that ends in an
  escaped space with `\x20` (`/a(?#x\ ` newline `y)/x` gives `(?#x\x20`):
  the case #339 leaves. Those lines are measured for width, so the marker
  of #339 would have to count for nothing in `columns()`.

- Two findings of the overlap rule are false, kept by #342 as they were.
  `/(?:ö|è)+/u` is reported as overlapping sets: two code points are
  compared by their UTF-8 lead byte. `/(?:\xE9+|c|b+)+/i` reports `\xE9+`
  and `c`: a byte above ASCII read caselessly makes its set unknown, and
  an unknown set meets every other. PHP matches neither overlap. The
  set findings also double the backslashes of the branch they quote and
  spell non-ASCII as byte escapes under `u`, where the literal findings
  print `é` (`AlternationOverlapCharsetSpellingTest` pins the doubling).

- A password rule of Unicode classes is still slow or refused after #346,
  in the determinization: it writes one transition per range of the
  alphabet per state, about 2,100 ranges. Four `\p{..}` lookaheads take
  1.5 s with `.{8,}` and pass the default budget with `.{12,}`. The
  classes of the alphabet #346 reads the product by would cut that table.
  The forty `(?=.*a{2})` of the earlier note are answered in under a
  second: identical lookaheads merge; forty distinct characters reach the
  state limit.

- A ReDoS repair still proves one rewrite per quantifier after #348 (1,000
  quantifiers: about 20 s). The PHPStan rule reads `repair()[0]` alone: a
  call that stops at the first certified repair, or a prover that keeps
  work between rewrites, is the next gain. `/^(?:a+)++$/` is not proven
  linear ("atomic group at offset 1 over-approximated") although PHP
  rejects forty `a` and a `!` at once, so no repair offers a possessive on
  the outer loop. The heuristics blame quantifiers no subject reaches (the
  `x+` under `{0}` in `/(?:x+x+){0}^(?:a+)+$/`), so on that shape the
  blame orders nothing.

- After #343 the lexer still cuts the text of a nested assertion body once
  per level and checks its UTF-8 again: time stays depth times size. A
  script run node holds its body text, so `(*asr:` nested 3,200 deep peaks
  at 77 MB: changing that field is a contract decision. Plain `(` nested
  about 6,400 deep ends PHP on its C stack (800 under a 1 MB stack). The
  witness runner of the PHP 7.4 twin dies on a pattern literal nested past
  512 anywhere under `tests/` (`build/verify/Witness.php`, `json_encode()`
  depth), and every twin cell then fails with "no witness". On PHP 8.6
  `memory_get_usage()` stops counting call frames below some 900 levels:
  a memory ratio measured across that depth bends there.

- Upstream recommends the fixes PHP 8.2.35 backported
  (`pcre2-10.48-Fix-compiler-integer-overflows.patch`) to every
  distributor of PCRE2 10.39 to 10.47. When a PHP 8.3, 8.4 or 8.5 patch
  release or a distribution's libpcre2 applies them,
  `PatternTooLargeErrorOrderTest` and `PatternTooLargeOffsetTest` fail on
  that cell as they did on PHP 8.2: one more gate beside
  `BUNDLES_PCRE2_10_40_WITH_ITS_FIXES`, or a way for a target to name "a
  release with upstream's fixes". PHP 8.2.35 linked to a system 10.40
  without them is judged as patched: `PCRE_VERSION` cannot tell. The
  backport also touches the matcher and the JIT of PHP 8.2.35: what the
  library claims of matching on 10.40 was not re-read.

- With #341, `Add u.` can lead to a refusal on the Python target when the
  pattern holds `\w` or `\b`, which Python cannot write under `u`: the
  advice does not look at the target. `\p` without `u` is warned of, not
  refused, although PCRE reads the byte as Latin-1 there; `\h` and `\v`
  are written with the full Unicode set where PCRE takes 0xA0 and 0x85.
  The caret of a `TranspileException` snippet stands one column left of
  the construct. `ClassSetProvider::query()` answers nothing for a class
  holding all eight probe delimiters.

- `ForkedWorkerPoolTest`: the deadline of `withDeadline()` does not
  interrupt a blocking read of a channel, so a pool that blocked for ever
  in `shutdown()` would hang the suite; and
  `test_run_reads_a_slow_payload_name_past_the_socket_timeout` rests on a
  head start of 300 ms, not seen failing.

- `CaptureFacts::reaches()` walks the call graph again for each group:
  quadratic in the groups, nothing measurable at eighty levels.

- Some fifteen test helpers read a process's standard output to its end,
  then its standard error: a process that fills the second first would
  block. The `Scope` job of `ci.yml` counts a moved file twice against
  GitHub's limit of 3,000 files.

## Site and docs tour, 2026-11-11

A full pass over the site with a fresh eye, after the playground lost
its window (PR #355) and gained its popover (PR #357). Done in the tour's
pull request:

- The landing header keeps Docs, Tutorial and Blog at every width — a
  phone stood on the tool with no way to the pages but the footer.
- The documentation and the playground point at each other: the quick
  start's first two examples, two cookbook recipes and the trace article
  each link to the playground with the pattern preloaded
  (`/#pattern=…&subject=…`), pinned by a contract that runs every linked
  pattern through the engine.
- The four launch articles stay drafts (their content is not validated
  yet); the blog list and the `## Articles` section of `docs/llms.txt`
  go with them in the same commit when they are.

Deliberately left as they are:

- No tagline above the tool on the home: the tool speaks first; the
  footer tagline answers whoever scrolls.
- The `2.x-dev` install commands still wait for the 2.0.0 tag (the
  Release section above owns them).

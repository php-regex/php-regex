# TODO

## Release 2.0.0

The code is ready; what is left is the maintainer's, in this order.

1. **Repoint `yoeunes/regex-parser` on Packagist** to a repository holding
   1.x only, before anything is tagged: it still reads the monorepo, so a
   `v2.0.0` tag would release the 1.x package as 2.0.0 (`bin/status` reports
   it ✗ until then; `UPGRADE-2.0.md` promises it stays on 1.x).
2. **The bump commit.** `bin/bump 2.0.0` sets `Regex::VERSION`,
   `Regex::VERSION_ID` and dates the CHANGELOG section. In the same commit,
   the install story moves from `2.x-dev` to the stable release:
   - `docs/_includes/install-prerelease.html` (17 pages): drop the two
     `composer config` lines and the `:{{ site.data.product.release }}-dev`
     suffix;
   - outside the include: `docs/index.md` (hero install block),
     `docs/packages.md`, `docs/quick-start.md`, `README.md` ("2.0 is coming"
     and "Getting started"), `docs/llms.txt`;
   - `docs/_data/product.yml`: `version_label`;
   - the `PHPRegex 2.0.0-DEV` sample outputs in `docs/guides/cli.md`,
     `docs/tutorial/README.md`, `docs/tutorial/08-performance-redos.md` and
     `docs/tutorial/09-testing-debugging.md`;
   - `tests/Documentation/PackageSelectionDocumentationTest.php` pins the
     prerelease commands: it moves with them.
3. Push 2.x, wait for CI to pass, then `bin/release --dry-run 2.0.0` and
   `bin/release 2.0.0`: it drafts the GitHub release and pushes the tag; the
   phar workflow builds and attests the phar, attaches it and its checksum,
   then publishes the draft; the split workflow tags the sixteen packages.
4. After the tag: `bin/status` and Packagist show `v2.0.0` for all sixteen
   packages (they require each other at `self.version`: one missing tag
   leaves the others uninstallable), and the "Latest" release carries the
   2.0 `regex.phar`, which `self-update` and `releases/latest` serve.
5. `bin/bump --next 2.0.1`.

## Phar provenance: the GitHub side

`.github/workflows/phar.yml` builds, attests and attaches the phar on a
version tag. By hand: if Settings → Actions → General restricts the actions
allowed, allow `actions/attest-build-provenance` and `shivammathur/setup-php`.
After the 2.0.0 tag, check that the release went from draft to published
with both assets, and that `gh attestation verify regex.phar --repo
php-regex/php-regex` passes on the downloaded phar.

## Upstream PHPStan fixes (fork `php-regex/phpstan-src`)

PHPStan types `preg_match()` `$matches` with its own Hoa grammar
(`resources/RegexGrammar.pp`). A third-party extension cannot override it:
PHPStan's type-specifying extension always runs first and `$matches` out-types
are unioned (checked on 2.3.1). So the fixes go upstream, as small PRs: one
grammar fix plus one regression test each, official PCRE2 and PHP manual
references in the description, never a large rewrite.

Four branches are ready in the local `../phpstan-src` checkout, one commit each
on `2.3.x` (62ed20078), not pushed. To do: `git push origin <branch>`, then
open each PR against `phpstan/phpstan-src:2.3.x`.

1. `regex-grammar-backreference`: *Parse back references in regex patterns*
   (`\1`, `\g{-1}`, `\k<name>`… were read as literals: `/(a)(\1)/` typed
   group 2 as `'1'`).
2. `regex-grammar-short-escapes`: *Parse \x with fewer than two hexadecimal
   digits and short \0 escapes in regex patterns* (`/(\x4)/` gave `'x4'`,
   `/(\0)/` gave `''`).
3. `regex-grammar-single-letter-property`: *Parse single-letter Unicode
   properties like \pL in regex patterns* (`/(\pL)/u` gave `'pL'`).
4. `regex-lookaround-zero-width`: *Do not count regex lookarounds as matched
   characters* (`/(?=a)/` gave `non-empty-string`, `/\d+(?=px)/`
   `non-falsy-string` for `'0'`).

PRs 2 and 3 touch adjacent grammar lines: rebase the second once the first is
merged.

Checked before publishing: every test pattern behaves the same on PCRE2 10.35
(PHP 7.4) to 10.49; the regex tests pass on PHP 8.2, 8.3 and 8.5; on 11,628
constant `preg_match()` patterns from `corpus/`, with subjects from
`SampleGenerator`, no branch rejects a real match result that 2.3.x accepted
(2.3.x already rejects 209).

Left for later PRs, each verified against the engine:

- `\x30`, `\060` and `\o{60}` match `'0'` but are typed non-falsy; `\o{}` is
  not parsed (needs that fix first).
- `\Q...\E` is not parsed (`/\Q(a)\E(b)/` sees two groups); a grammar-only fix
  is not possible.
- Inline `(?x)`, `(?-x)` and `[#]` under `x` (upstream issue #11746).
- Inline `(?i)` keeps lowercase constants (`/(?i)(a)/` gives `'a'`).
- Groups inside a negative lookaround are typed as set (`/x(?!(a))(b)/`
  gives `'a'`, the engine `''`).
- A quantifier with a minimum of one over a maybe-empty atom is non-empty
  (`/((?:a?)+)/`).
- `\g<1>` and `\g'1'` (subroutine calls) are read as literals.

Parked, too large for upstream review: local branches
`regex-negative-lookaround-groups`, `regex-quoted-sequence`,
`regex-extended-mode`.

## Declared-type tightening

Every `mixed` type `src/` still declares sits in `phpstan-mixed-baseline.php`,
one reason per symbol, and the analysis reports any new one. Left: review
the reason given to each entry; a wrong reason is fixed in the baseline,
and a type that can be narrowed is narrowed and its entry lowered.

## PHP 8.6 closeout and 8.7 canary

The closeout is built: the analysis range reaches 8.6, both engine matrices
carry 8.6, the `preg_grep()` array-to-false boundary is pinned by tests, the
trim family keeps its pre-8.6 character set on every PHP, a scoped listener
fails the suite only on deprecations under `src/`, and a scheduled 8.7 canary
watches the future without gating anything. Left, dated:

1. **2026-11-19, GA day** — rename the `8.6-rc` row in `engines-bundled` to
   `'8.6'`: the `-rc` docker aliases vanish that day and an unrenamed row
   breaks CI. The `startsWith(matrix.php, '8.6')` conditions already match
   either spelling, and `engines-latest` needs no change. Re-verify the
   renamed image still bundles 10.44, and if it ships mbstring again, try
   removing the "Install multibyte support" step.
2. Until then, watch `ext/pcre/pcre2lib` on the PHP-8.6 branch and master:
   a late PCRE2 bump would reopen the engine maps. If it moves, re-run the
   engine-scoped corpus rows before anything else.

---

## Symfony Flex recipe

Flex guesses `PHPRegexSymfonyBundle` or `SymfonyBundle`, never
`PHPRegexBundle`, so `composer require php-regex/regex-symfony` registers
nothing (the guide says so). By hand: open a recipe PR on
symfony/recipes-contrib once 2.0.0 is on Packagist.

## Rewrite checks: what is left

The determinization budget, the shared rewrite check and the bounded DFA
cache are merged. Follow-ups:
- `Optimizer::__construct()` takes a `?DfaCacheInterface`, added in 2.0.0,
  that nothing reads any more: the check of a rewrite builds no DFA since
  the strings both sides match no longer prove one. Keep it or drop it
  before the tag;
- the strings matched no longer prove a rewrite: where the match solver
  does not read a pattern (a lookahead, `m`, a backreference), only a
  rewrite that reads alike is offered, and a pattern that looks before
  the match start (a lookbehind, `\b`, `^` under `m`) gets the same rule.
  On the whole corpus that costs 37 of 1,329 suggestions (40 lost, 3
  gained, the FAIL and WARN lines unchanged), every lost one a correct
  local rewrite: `(?<!\\)(?:_|%)` into `(?<!\\)[_%]`,
  `(?=\s*(?:,|\n))` into `(?=\s*[,\n])`, `/^\/\/\/\/x/m` into
  `/^\/{4}x/m`. Proving the rewritten subtree alone (it reads alike, or
  its own match check passes, and it holds no group) would win them back;
- the formatter writes a class under `(?xx)` without its spaces, so
  `(?xx)[\d -z]`, valid up to PCRE2 10.44 with a member `-`, comes back
  as `[\d-z]`, which reads back otherwise: `format()` throws
  `FormattingException` for it on those targets instead of escaping the
  hyphen.

## PHP 8.1 floor: after the merge

Merged into 2.x: every package requires `>=8.1`, Symfony `^6.4|^7.4|^8.0`,
Laravel `^10.37|^11.0|^12.0|^13.0`, PCRE2 10.39 judged with its own rules.
Left:
- the first GitHub run on 2.x (03699c27fe) failed in the PHP 8.1
  prefer-lowest cell (PHPUnit 10.5.0 runs regexes on every assertion, one
  ran under a test's recursion limit), the 8.2 and 8.3 bundled-engine
  cells (the capture-shape report compared patterns with `r` their engine
  refuses), PHPStan on 8.5 and 8.6 (`$argv` in the 8.1 smoke test) and
  PHPCS (four test files): each is fixed on its own commit; watch the next
  run. Reproduced on 2026-10-16 as the cell runs (Ubuntu 24.04, PHP 8.1
  from the ondrej PPA linking the system PCRE2 10.42, PHPUnit 10.5.0,
  Symfony 6.4.0, Laravel 10.37.3, a plain user): the suite died on
  `bin/corpus-lint` redeclaring `printUsage()` beside `bin/corpus`, and the
  Symfony configuration threw a plain `InvalidArgumentException`; both
  fixed, nothing else failed;
- the official `php:8.1-cli` image (bundled PCRE2 10.39, no intl, no
  pcntl) runs the suite to 168 failures and 24 errors, none of them a
  verdict the library gets wrong for that engine: 100 PCRE2-suite cases
  pinned to 10.40–10.48 (the library gives the verdict of 10.39 on all 100), tests
  that expect the property and script semantics of 10.40 and later or ask
  the running engine for a property it lacks, `Locale` and `pcntl_fork()`
  missing, two linear-time ratios on a 2-CPU VM, the 10.39 spelling
  "unknown property name after \P or \p" missing from the tests' message
  table, and `RuntimePcreDetectionTest`'s oracle reading six patterns 10.39
  refuses as compiled. No CI cell runs 10.39; gating those tests is left
  for when one does;
- PCRE2 10.39 also has matching bugs 10.40 fixed (`/[Aa]{2}/` misses `aa`):
  engine defects the library does not model, documented.

## The articles section: what is left

- The next articles, in priority order: variable-length lookbehind across
  PHP 8.2-8.4, capture shapes of `$matches`, why `pcre.backtrack_limit`
  does not protect under the PCRE2 JIT, regex equivalence with proofs,
  named groups with branch reset, measured data from the corpus,
  transpiling to JavaScript with a proof, `preg_match()` versus
  `str_contains()` measured, Symfony route requirements audited.
- Re-check the 8.6 row of the published version matrix against the
  PHP-8.6 branch before GA on 19 November 2026; the article promises it.
- The og:image URL is cache-busted by the build time (`head.html`), which
  re-caches social cards on every deploy; decide whether the manual `?v=`
  ratchet fits articles too.
- An articles pager (previous / next) once a second article exists.
- Publishing the four drafts: flip `published`, add each article's line
  under `## Articles` in `docs/llms.txt`, and give each `/blog/<slug>` its
  entry in the sitemap navigation-coverage check, with the blog-index
  justification.
- `head.html` writes `page.description` into the description, og and
  Twitter meta tags with `strip_html` only: a `"` in an article
  description cuts the attribute (the JSON-LD escapes it). Add `escape`,
  or forbid double quotes in descriptions.
- The pre-release install include renders its own `## Installation`
  heading, which splits an article's closing library section; an inline
  variant for articles would read better.

## Legacy targets (PHP 7.3 to 8.0): what is left

PHP 7.3 is the oldest PHP judged, each PHP with the PCRE2 its x.y.0 bundled
(8.1 with 10.39); PHP 7.2 and earlier ship PCRE1 and are never judged: a
`require.php` that also allows 7.3 or later is judged from the lowest such
PHP, with a note, and a version named below 7.3, or a `composer.json` that
allows nothing from 7.3 on, is refused. A release
older than 10.39 is judged with the 10.39 rules but for two changes the
library knows: the alphabetic assertion names (10.33) and an open count past
65535 read as text (10.37). Left:

- Per-release rules for PCRE2 10.33 to 10.39, one release at a time, each
  verified on a real build before it ships (pcre2test built from the release
  tarball, `php:7.3-cli`, `php:7.4-cli`, `php:8.0-cli`): script runs `(*sr:`
  and `(*asr:` and non-ASCII group names (10.33); the 65535-group limit,
  quantified `(*ACCEPT)`, `(*napla:` and `(*naplb:` (10.34); `(?*` and `(?<*`,
  the clamp of repeated assertions, de-atomised groups holding a recursive
  back reference (10.35). Until `(?*` is known, `/(?*a)b/` passes for PHP
  7.3, with the note that 10.32 is judged with the 10.39 rules. The Unicode
  tables of each release are not modelled: `\p{...}` names and membership
  below 10.39 are a documented blind spot. `\K` in a lookaround stays a rule
  of PHP 8.5, never one of a PCRE2 release.
- The `{1}+` pair, both directions: PCRE2 10.33 ignores a possessive `{1}+`
  on a group and 10.34 honours it (pcre2test 10.32/10.33: `/(a|ab){1}+c/`
  matches `abc`; 10.34, 10.35, 10.37: no match), so the old side is now
  verified. Write `X{1}+` as `X{1}` for code leaving 7.4.0-7.4.5, and
  `(G){1}+` as `(?>G)` the other way, each with the witness pair pinned: the
  tree is the same on both sides, so comparing trees never sees the flip.
- Discovery parity for the Rector rules: patterns folded from constants
  (as the `preg_match()` rule already does), calls of functions marked
  `#[RegexPattern]` or `Language('RegExp')`, and `preg_replace_callback_array()`.
- Report-only verdicts, second wave: a bare `\p{Script}` is the script on
  PCRE2 10.39 (PHP 8.1) and its script extensions from 10.40 (U+30FB for
  `\p{Katakana}`, U+00B7 for `\p{Latin}`), which the tree comparison does not
  see; UCP casing through `(*UCP)` between 7.4 and 8.0; de-atomisation (the
  witness pair works on the named recursion `(?P>g)`); script runs,
  non-atomic lookarounds, the group limit, quantified `(*ACCEPT)` and
  non-ASCII group names, as their rules land. Flag only: none has a spelling
  every release reads alike.
- PHP 7.3.33 and 7.4.33 compile, with no warning, some patterns PCRE2 itself
  refuses, to a pattern that matches nothing: `/\p{Bogus}/u` and
  `/\214748364/` (pcre2test 10.32 and 10.35 refuse both, as PHP 8.0 does).
  The library refuses them for those targets, and keeps refusing them. Add
  to the message that PHP 7.3 and 7.4 accept such a pattern silently and that
  it then never matches.
- The PHPStan and Psalm extensions judge a project for PHP 7.2 or earlier
  instead of refusing it as `regex lint` does: PHPStan with the version as
  given, Psalm raised to 7.3. Make both follow `regex lint`: refuse a
  version named below 7.3 with the same error, and judge a floor read from
  a `composer.json` range that also allows 7.3 or later from there, with
  the same note, a `config.platform.php` below 7.3 giving way to such a
  range.
- `validate`, `analyze` and the other pattern commands take a
  `--php-version` below 7.3 as given, with neither note nor refusal; only
  `lint` and the bridges' lint commands resolve the project target. Make
  them refuse it the same way, exit 2.
- The article on which PCRE2 ships with which PHP quotes the former `\K`
  hint ("Move the \K out of the lookaround."): refresh its sample output.
- Refused with a witness, do not build: the octal respell `\214748364` to
  `\x{8C}748364` (PHP 7.3.33 and 7.4.33 match nothing with the first on
  "\x8c748364" and match with the second); loose script names respelled
  canonically (U+00B7 is in `\p{latin}` from 10.40, where it means the
  script extension, and not on 10.39); moving any `\K` out of a lookaround
  (`(?<=\Ka)b` matches "ab" in "ab"); `${n}` respelled `\1` in a
  double-quoted replacement (`"\1"` is chr(1)).

## Checked and refused (do not re-litigate)

- A compile-once `Pattern` handle: parses are already cached per pattern +
  target; the handle would duplicate the frozen facade surface.
- nikic-style multi-error recovery: PCRE reports one error (the oracle the
  library is grounded on) and no internal consumer wants more; if anything,
  document the tolerant-mode truncation contract (`docs/reference/api.md:254`).
- One diagnostic god-DTO shared across packages: it couples Parser to lint
  and LSP vocabulary; keep the small boundary converters.
- An absolute-latency performance gate in CI: environment noise; keep the
  asymptotic tripwires already in the suite.
- "The AST is a dead end": wrong — `$ast->accept(new PatternPrinter())` is
  public and documented; only the formatting factories are internal.

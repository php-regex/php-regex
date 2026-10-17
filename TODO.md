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
3. Before the tag: `php-regex/regex-phpunit` exists on GitHub (empty, with
   the metadata of the sixteen others) and the split fills it on the next
   push of 2.x. Then submit it on Packagist and register its webhook like the
   sixteen others: without one, Packagist learns of the tag only through its
   two-week crawl. If `SPLIT_TOKEN` is limited to selected repositories, add
   `regex-phpunit` to it before that push.
4. Push 2.x and watch the first CI run on 2.x after the push, the PHP 8.1
   prefer-lowest cell in particular; once it passes, `bin/release --dry-run
   2.0.0` and `bin/release 2.0.0`: it drafts the GitHub release and pushes
   the tag; the phar workflow builds and attests the phar, attaches it and
   its checksum, then publishes the draft; the split workflow tags the
   seventeen packages.
5. After the tag: `bin/status` and Packagist show `v2.0.0` for all seventeen
   packages (they require each other at `self.version`: one missing tag
   leaves the others uninstallable), and the "Latest" release carries the
   2.0 `regex.phar`, which `self-update` and `releases/latest` serve.
6. `bin/bump --next 2.0.1`.

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
- The article on which PCRE2 ships with which PHP quotes the former `\K`
  hint ("Move the \K out of the lookaround."): refresh its sample output.

## Language server: hardening list from the symfony/language-tools review

Reviewed 2026-10-17 against symfony/language-tools (their editor stack:
amphp LSP server, per-feature providers, VS Code/Neovim/Zed extensions, a
CLI checker with SARIF and baselines). What transfers is a dozen specific
mechanisms, not the architecture. Built: the completion cursor fix, the
lifecycle gates and framing caps, dynamic watcher registration, document
versions on diagnostics pushes, the LSP/CLI parity test, and the
not-analyzed severity aligned with `regex lint`. Remaining:

1. **`$/setTrace` plus a stderr frame log**, UTF-8-safe truncation of
   pattern text, level also settable from initializationOptions. Nine
   editors are documented; today debugging them is printf-to-stderr.
2. **Subprocess smoke tests of the real `bin/regex-lsp`** (stdio to temp
   files, never pipes): garbage bytes answered -32700 with the session
   surviving, exit-without-shutdown returning 1, one full round trip
   asserting stdout never emits a non-frame byte. Every existing test
   drives the server in-process over memory streams.
3. **Honest degradation**: warn when vendor/ is absent on the host
   (containerized projects: library patterns silently unavailable) and
   when the 20000-file scan cap downgrades coverage; both are currently
   at most one log line nobody reads.
4. **Coding-agent configuration guide**: an OpenCode-class section in
   `docs/guides/lsp.md` with a validated config snippet — the cheapest
   new audience.
5. **`regex lsp` dispatch** so the existing phar carries the server
   (soft-gated on class_exists in the standalone CLI package); gives
   Neovim/Helix/Zed a one-download install.
6. **VS Code extension committed in-repo** (launch vendor/bin/regex-lsp
   or the phar via a serverPath setting; no binary bundling, no
   marketplace) replacing the hand-written-client section of
   `docs/guides/lsp.md:325-356`; publish to the marketplace only when
   someone asks.
7. Later, on evidence: a content-keyed workspace index cache (cold start
   re-reads up to 20000 files), didChangeConfiguration/regex.json reload
   (configuration is frozen at initialize), workDoneProgress around the
   initial scan (measure first — the initialize response already
   precedes the scan, so the real cost is moving it past `initialized`).

Decision needed: should partial analysis affect the `regex lint` exit
code? A run that gives up on patterns (limits, memory budget) exits 0
today; their checker has an explicit "incomplete" code that outranks
findings and refuses to publish its report. Saying yes is a behavior
change for every CI that treats 0 as clean.

Refused, with reasons: amphp event loop and fabpot/json-rpc-peer (our
protocol layer is ~200 dependency-free LOC already covered by a
malformed-frame matrix; the valuable pieces are items 2-4, not a
dependency); the runtime snapshot bridge and its consent gate (we never
execute user code; static discovery suffices); the DI container (15
hand-wired files); tree-sitter (ext-tokenizer already survives mid-edit
PHP); debounced refresh (diagnostics are millisecond-scale); incremental
text sync (full sync is spec-valid and we memoize); the nine-app dogfood
matrix and editor E2E (we have the corpus; pin parity first, smoke-test
the binary, E2E last); self-contained single-file binaries (platform QA
the phar already covers for anyone who can run an editor); a SARIF
formatter (the github formatter already annotates PRs; build it the day a
code-scanning user asks); utf-8 positionEncoding (our UTF-16 path is
correct and tested; revisit on a real encoding bug); a memory_limit floor
(the scan is per-file bounded; revisit on a real OOM report).

## PHPUnit companion package: regex-phpunit

A distributable `php-regex/regex-phpunit` package under `src/PHPUnit` (a
seventeenth split): PHPUnit constraints and assertions backed by the parser,
used by this suite too. PcreGates stays internal whatever happens — its
judged-matrix coupling is ours alone; reopen distribution of the gates
themselves only on real maintainer demand, after guarded-targets ships.

1. A constraint extending `PHPUnit\Framework\Constraint\Constraint` plus an
   assertions trait: `assertPatternMatches($pattern, $subject, $expected)` —
   the expected group names are validated against the parsed AST (a typo'd
   name is a named failure, not a silent pass), the assertion runs the match
   itself so capture semantics are exact under their flags (`null` requires
   `PREG_UNMATCHED_AS_NULL`, `[value, offset]` requires
   `PREG_OFFSET_CAPTURE`; no `''`-means-unmatched ambiguity), and a failure
   renders a `ComparisonFailure` trimmed to the groups under test, saying
   which construct failed.
2. `assertPatternCompiles()` and `assertPatternIsLinear()` — compile and
   prover verdicts, not guesses.
2. Dogfood it in the parity suites first, replacing hand-written match-array
   assertions file by file; that is also the cheapest real-world test of the
   package.
2. Spec decisions waiting: the PHPUnit matrix (`^10.5||^11||^12||^13` — CI on
   all four; `Constraint::evaluate()` and diff rendering differ across
   majors; 13.x needs PHP >=8.4.1, which per-consumer resolution costs the
   8.1 floor nothing), phpunit in require vs require-dev, engine-relative
   verdicts at the user's end (running engine vs declared target; a pattern
   that does not compile there fails loudly, never silently skips), the
   release train (post-2.0, so the tag is not hostage to a new package), and
   registering the name on Packagist early — free today, first-come.

Two internal test-support items on the way: a static-analysis fixture
pinning the PcreGates contract (gate literals inside
`PcreGateLimits::KNOWN_RELEASES`), and shared provider traits for the
release-map idiom duplicated in
`tests/Unit/NodeVisitor/UnicodePropertyReleaseTest.php:50` and `:78` and
three `tests/Unit/Lint/Rule/` files.

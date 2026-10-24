# TODO

## Suite reds older than the 2026-10-24 series

- `CaptureShapeParityTest` fails on `/^|\d{1,2}$/` (php-src bug78853,
  subject `"7"`), before and after the scan-whole change: under
  `PREG_SET_ORDER`, and for the callback of `preg_replace_callback()`, the
  shape still types group 0 as `''` where the engine writes `"7"` from the
  second match on.
- ~~`MonorepoArchiveTest`: fixed by adding `build/` to `.gitattributes`
  `export-ignore`.~~

## (*UCP) in the ReDoS prover

`/(*UCP)(?:\w|\xC9)+$/` exhausts the backtrack limit, yet the verdict is a
medium heuristic: a start verb is out of the prover's model, which the
verb rows of `RedosSoundnessFuzzTest` hold (`(*UCP)a+` and `(*UCP)\w+/i`
included). Treating every verb as zero-width proved `(*SKIP)`, `(*COMMIT)`,
`(*FAIL)`, `(*CR)` and `(*ANY)` patterns, and was reverted. Bringing
`(*UCP)` alone into the model means passing the start options to every
class-set query and moving the fuzz rows that name it.

## Composer manifests: the pre-tag review

The manifests are ready; what remains is on the release side:

- `yoeunes/regex-parser` lists a phantom `2.x-dev` in its dev channel
  (a stale crawl from the old name): after the repoint, check the dev
  channel lists 1.x only.
- The seventeen split pushes are not atomic: between the first and the
  last webhook after the tag, a `^2.0` resolution fails on missing
  siblings. Before `bin/release`, confirm every split repo carries a
  fresh 2.x head and a crawled dev version (`bin/status` reads both
  Packagist channels now); after, v2.0.0 on every stable endpoint.
- Post-tag: submit `regex.schema.json` to schemastore.org (`fileMatch`:
  `regex.json`, `regex.dist.json`) for zero-config editor support — it
  needs a stable public URL, so it waits for the tag.

## Release 2.0.0

The code is ready; what is left is the maintainer's, in this order.

1. **Repoint `yoeunes/regex-parser` on Packagist** to a repository holding
   1.x only, before anything is tagged: it still reads the monorepo, so a
   `v2.0.0` tag would release the 1.x package as 2.0.0 (`bin/status` reports
   it ✗ until then; `UPGRADE-2.0.md` promises it stays on 1.x).
2. **The bump commit.** `bin/bump 2.0.0` sets `Regex::VERSION`,
   `Regex::VERSION_ID` and dates the CHANGELOG section. In the same commit,
   the install story moves from `2.x-dev` to the stable release:
   - `docs/_includes/install-prerelease.html` (23 pages, the four articles
     through its `inline=true` form): drop the two `composer config` lines
     and the `:{{ site.data.product.release }}-dev` suffix, written once in
     its `install_commands` capture;
   - outside the include: `docs/index.md` (hero install block),
     `docs/packages.md`, `docs/quick-start.md`, `README.md` ("2.0 is coming"
     and "Getting started"), `docs/llms.txt`;
   - `docs/_data/product.yml`: `version_label`;
   - the `PHPRegex 2.0.0-DEV` sample outputs in `docs/guides/cli.md`,
     `docs/tutorial/README.md`, `docs/tutorial/08-performance-redos.md`,
     `docs/tutorial/09-testing-debugging.md` and the articles
     `docs/_articles/which-pcre2-ships-with-which-php.md` and
     `docs/_articles/why-pcre2-is-a-separate-library.md`, and the
     `"version":"2.0.0-DEV"` of the `initialize` answer in
     `docs/guides/lsp.md`;
   - `tests/Documentation/PackageSelectionDocumentationTest.php` and
     `tests/Documentation/ArticleInstallIncludeTest.php` pin the prerelease
     commands: they move with them.
3. Before the tag: `php-regex/regex-phpunit` exists on GitHub (empty, with
   the metadata of the sixteen others) and the split fills it on the next
   push of 2.x. Then submit it on Packagist and register its webhook like the
   sixteen others: without one, Packagist learns of the tag only through its
   two-week crawl. If `SPLIT_TOKEN` is limited to selected repositories, add
   `regex-phpunit` to it before that push.
4. Push 2.x and watch the first CI run on 2.x after the push, the PHP 8.1
   prefer-lowest cell in particular; once it passes, `bin/release --dry-run
   2.0.0` and `bin/release 2.0.0`: it drafts the GitHub release and pushes
   the tag; the phar workflow builds the phar, checks that its version is
   the tag's and that `regex.phar lsp` answers `initialize`, attests it,
   attaches it and its checksum, then publishes the draft; the split
   workflow tags the seventeen packages.
   That path has never run on a real release (`v1.3.0` carries no
   checksum): tag `2.0.0-RC1` first (`bin/release 2.0.0-RC1`, a GitHub
   pre-release), check both assets, `gh attestation verify regex.phar --repo
   php-regex/php-regex` and the phar on PHP 8.1, then tag 2.0.0.
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

## Upstream PHPStan fixes: push the branches and open the PRs

Six branches are ready in the local `../phpstan-src` checkout (fork
`php-regex/phpstan-src`), one commit each on `2.3.x` (62ed20078), not pushed:
one grammar fix in `resources/RegexGrammar.pp` and one nsrt regression test
each, the official PCRE2 and PHP manual references in the commit message. To
do, for each: `git push origin <branch>`, then open the PR against
`phpstan/phpstan-src:2.3.x`.

1. `regex-grammar-backreference`: *Parse back references in regex patterns*.
2. `regex-grammar-short-escapes`: *Parse \x with fewer than two hexadecimal
   digits and short \0 escapes in regex patterns*.
3. `regex-grammar-single-letter-property`: *Parse single-letter Unicode
   properties like \pL in regex patterns*.
4. `regex-lookaround-zero-width`: *Do not count regex lookarounds as matched
   characters*.
5. `regex-grammar-octal-brace-escape`: *Parse \o{...} octal escapes in regex
   patterns* (`/(\o{141})/` typed its group as `'o'` repeated 141 times; it
   matches `'a'`).
6. `regex-grammar-subroutine-call`: *Parse subroutine calls like \g<1> in
   regex patterns* (`/(a)(\g<1>)/` typed group 2 as `'g<1>'`).

Branches 2, 3 and 5 touch adjacent grammar lines: rebase each later one once
the earlier is merged. Branch 6 merges cleanly with all the others.

## PHP 8.6 closeout and the experimental 8.7 cell

The closeout is built: the analysis range reaches 8.6, both engine matrices
carry 8.6, the `preg_grep()` array-to-false boundary is pinned by tests, the
trim family keeps its pre-8.6 character set on every PHP, a scoped listener
fails the suite only on deprecations under `src/`, and an experimental 8.7
cell of the tests workflow watches the future without gating anything.
Left, dated:

1. **2026-11-19, GA day** — rename the `8.6-rc` rows of `engines-bundled`
   and `engine-claims` (`.github/workflows/engines.yml`), and the `8.6-rc`
   key of `EngineClaims::IMAGES` (`tests/TestUtils/EngineClaims.php`), to
   `'8.6'`: the `-rc` docker aliases vanish that day and an unrenamed row
   breaks CI. The
   `startsWith(matrix.php, '8.6')` conditions already match either
   spelling, and `engines-latest` needs no change. Re-verify the renamed
   image still bundles 10.44, and if it ships mbstring again, try removing
   the "Install multibyte support" step. Once the tools' dependencies admit
   8.6, drop it from the `--ignore-platform-req=php+` cases of
   `.github/actions/setup-php-project` and of `engines-bundled`.
2. Until then, watch `ext/pcre/pcre2lib` on the PHP-8.6 branch and master:
   a late PCRE2 bump would reopen the engine maps. If it moves, re-run the
   engine-scoped corpus rows before anything else.

---

## Symfony Flex recipe

Flex guesses `PHPRegexSymfonyBundle` or `SymfonyBundle`, never
`PHPRegexBundle`, so `composer require php-regex/regex-symfony` registers
nothing (the guide says so). By hand: open a recipe PR on
symfony/recipes-contrib once 2.0.0 is on Packagist.

## Exact size for the items still counted at their floor

"Known differences" in `docs/concepts/pcre.md` lists the items whose
compiled length the size verdict counts at its least, so a pattern near the
64 KiB limit PCRE2 refuses may be reported valid (never the reverse). Count
each exactly, checked with the first-pass length of pcre2test 10.32 to 10.49:

- a counted extended class that would match every character but for what a
  property it holds matches below 256 (`(?[ [^a] | !\D ]){2}` under `u`):
  the characters below 256 of each property, per release.

The DEFINE quirk of PCRE2 10.32 to 10.34 stays documented, not planned: it
would mean replaying their internal form of the pattern (10.35 fixed it).

## Architecture and contributor path: the last pass before 2.0.0

- The transpiler output is never run in its target engine: add a
  differential test against node and python.
- Then, once the branches above are merged: `new Optimizer()` and
  `new Transpiler()` without a parser, `Optimizer::optimize()` without
  arrays, `SampleGenerator` without its seed setters, `Regex::lint()`;
  the Psalm and PHPStan bridges without
  the whole linter (the target resolution and the PHP-source helpers move
  out of `regex-linter`); one placement and naming rule for exceptions; the
  test conventions (`#[Test]` everywhere, the four inline ignores moved to
  the config); release notes for 2.0.0 instead of the 805-line section.
- Internal, so it may follow the tag: split `Validator` (4,757 lines) by
  concern and `TokenParser` (4,583) by family of constructs.

## Parser and integrations: the last pass before 2.0.0

Parser structure: the extended-class parsing still in `TokenParser`
(`parseExtendedClass`, `parseOperandAt`) apart; the error-offset rule read
through `PcreTarget::faultOffset()` in `Validator`, `Lexer` and
`FirstErrorLocator` too, and the booleans `TokenParser` hands to
`GroupNameReader`, `ExtendedClassReader`, `PcreVerb` and
`VersionCondition` replaced by the target; the last messages without a
final period (`TokenStream::consume`, `GroupNameReader`, the unknown flag);
then `Validator` split by concern; `QuantifierNode` carrying its bounds instead
of 47 re-parses; the error-offset rule, the 65535 limit and the UTF-8 width
written once; the exception types and the snippet renderer unified; the
public renames (node properties, error codes, getters, `PcreFeature`) to
settle before the tag.

Integrations:

- `ExtractorInterface` is marked internal, yet the `extractor_service`
  setting asks users to implement it: making it public means adding it to
  the public surface (`tests/Support/PublicSurface.php` and
  `docs/reference/backward-compatibility.md`), and with it the
  `PatternOccurrence` an implementer must build, still internal.
- PHPStan: a repo-only rule ships in the public extension;
  `PatternChecker` split.
- CLI: `OutputFormat` split per method in the facade; the language
  server's error stream still written through a static.
- The target settings (PHP, PCRE2, locale) enter through one class, and
  every parser derived for another target keeps the locale.

## Analysis engines: the last pass before 2.0.0

- The character-set analysis gives an unknown set for a script run.
- A proven polynomial degree does not add what a lookahead in a loop reads
  again: `/^(?:(?!a*a*b)a)*$/` is proven of degree 2 (its upper bound says
  3) and takes cubic time, 1.0, 8.6 and 122 ms on 200, 400 and 800 `a` and
  a `!`. The witness of the body's own ambiguity holds: the class is right,
  the degree short. A lookahead that reads a run but stops at once,
  `(?=a+?)`, or that holds where the loop could go on, `(?!a+)` before
  `a`, loses its linear proof: telling when a body stops would keep it.
- The railroad layout, the solver's internal methods on its public class,
  and the semantic facts (nullability, length ranges, flag scope) read
  from one layer instead of five copies; the Rewriter split.

## Transpiler: after the oracle

The transpiled output now matches `preg_match()` in node and python on
the whole corpus. Left: atomic groups and possessive quantifiers for
Python 3.11+ (refused today, since emitting them sets a minimum Python
version), and the empty-repetition rules for JavaScript, which refuse some
patterns that would behave the same. A pattern without `u` is read by PHP
byte by byte and by the targets character by character: the docs should
say so where the transpiler is described.

## Explainers: after the fix

The explanations follow the flags, UCP and the newline convention now.
Left: `\R` under `(*BSR_ANYCRLF)` described as any Unicode line break,
`\h` and `\v` given their Unicode lists in byte mode, and the explainers
not knowing the target release or locale (`\w` says "from PCRE2 10.43"
instead of choosing).

## The 8.1 builds that lose a shutdown after exhaustion

On the 8.1 build the CI runners use (a setup-php compile), a child that
dies of memory exhaustion prints no envelope and writes no trace from its
shutdown handler, while the exit code still arrives: `regex:lint --json`
would report its internal error through the exit code alone. The official
8.1 build prints both, as every later PHP does. The test skips on the
losing builds with a message naming them. To close: reproduce with the
setup-php 8.1 build (or ask them how their memory manager differs), then
make the shutdown of that build print — the reserve and the
past-the-buffers write the official builds need are already in.

## Test suite: a state leak an order can expose

Running the whole suite in a container, one random order in three makes
two Laravel lint tests fail: a test that ran before them leaves the
library reading patterns under a limit it cannot meet, and the lint
report says "Failed to collect patterns" where the tests expect their
file. The class alone passes, every order on the runner CI uses has
passed so far, and seeds 424242 and 777 are clean. Find the leaking
test: pin `pcre.backtrack_limit` or the ini-setter hook and not restore
it (see `LibraryPcre::useIniSetter` and the resets in
`tests/Unit/Hir/ClassSetProviderCacheTest`), reproduce with
`--random-order-seed=1792566553`, and give it a `finally`.

## The articles section: what is left

- The next articles, in priority order: variable-length lookbehind across
  PHP 8.2-8.4, capture shapes of `$matches`, why `pcre.backtrack_limit`
  does not protect under the PCRE2 JIT, regex equivalence with proofs,
  named groups with branch reset, measured data from the corpus,
  transpiling to JavaScript with a proof, `preg_match()` versus
  `str_contains()` measured, Symfony route requirements audited.
- Re-check the 8.6 row of the published version matrix against the
  PHP-8.6 branch before GA on 19 November 2026; the article promises it.
- Publishing the four drafts: flip `published`, add each article's line
  under `## Articles` in `docs/llms.txt`, and give each `/blog/<slug>` its
  entry in the sitemap navigation-coverage check, with the blog-index
  justification.

## Test coverage: the measured gaps to 100%

Measured 2026-10-22 under pcov on the full suite (45,908 tests, 8 skips):
97.61% of statements (43,260 of 44,319), 1,059 uncovered. Every gap was
classified line by line: ~630 are reachable by tests written in this
checkout, ~150 run only in child processes or other environments pcov
cannot attribute, and the rest are guards or provably dead code.

Decisions the maintainer owns, each worth points on its own:

1. Dead code, each region proven unreachable by probe or invariant —
   delete rather than test:
   `Validator:1524,1544` and `CompiledLength:974`, which now throw where
   they returned: the statements stay, so narrowing the endpoint types
   would be what removes them.
   `Lexer:1656-1661` is NOT dead: the `namesReadWhole` guard fires for
   targets where PCRE2 reads the whole name (tested by
   `BeforePcre2Ten39Test`); it was incorrectly listed.
   Deleted: `Rewriter:1338`, `CompiledLength:1677-1688`,
   `EscapeChecks:378`, `ReferenceChecks:388`, `SearchCostProver:981`.
2. A policy call: hand-built-AST tests are the only route to ~50 guard
   statements (printer raw literals, HIR empty shapes, transpiler
   fallthrough arms). The suite already hand-builds nodes in places;
   decide whether that style is welcome at scale.

The test-writing plan, by wave (natural-input tests, existing harnesses;
numbers are uncovered statements):

1. Symfony: the two analyzers (67), the routing conflict pair (67, the
   forced rows ride the `CompiledAsRoute` double already in the tests),
   the two commands (31) — 165 statements, +0.4 pt.
2. LSP code actions (29 — the test helper pins `context.diagnostics` to
   `[]`, which is why the quickfix family never ran), Explain (25: the
   `(?i:x)` and `(*MARK:…)` families alone are 16), the three lint rules
   (37), SampleGenerator (8), Redos (18).
3. Transpilers (42), the transpile CLI render (12: one console run
   covers Warnings and Notes), the validation quartet (42, through
   simulated `pcre_version` targets and direct `CompiledLength::of`).
4. `ForkedWorkerPool` through a reflection test on `serve()` (29 — the
   16 child-side statements stay invisible to pcov by construction),
   parser core (19).
5. The long tail (~200): one dispatch meta-test for the lint rules (~25
   at once), the LSP completion contexts (`\`, `(?`), the transpiler
   flag notes and the Laravel "PARTIAL" render, CLI render branches,
   Laravel and Symfony bridge rows, automata and parser-internal edges.

Commit each wave when green; mutation-test the new rows. Ceiling: every
writable test lands ~99.0%; the deletions above take it to ~99.3%;
blessing hand-built AST tests, ~99.5%. A literal 100% is not reachable
by tests on this setup — the statements that run only in forked workers
and child processes are the floor.

## PHPRegex in phpstan-src: the fourth pull request and after

Stack on the fork `php-regex/phpstan-src` (never upstream; the
maintainer opens those): #1 seam, #2 walkers (merged), #3 target
validity, #4 capture shapes — all green on `NodeScopeResolverTest`
1879/1879. What the series still owes:

1. Nothing left of the folding: the reader splits what it reads and
   answers the rule in one call; the helper keeps the expression duties
   only (concat resolution, quoting delimiters).
2. Once `2.0.0` is tagged and the php74 twin reaches Packagist, replace
   the `2.x-dev#<hash>` pin with the exact version (the precedent is
   `hoa/compiler`, pinned to a release).
3. Done: without `u` the engine folds ASCII letters only, so `(£|€)`
   under `i` reads its constants; under `u` it folds by Unicode and
   nothing past seven bits is provable — the analysis is mode-aware.
4. The 8.6 entry reads 10.44 and php-src master carries 10.44 today, so
   the map stands corroborated; the one gesture left is re-reading
   master after the 8.6 GA (2026-11-19) in case a release-cut downgrade
   like 8.5's happens.
5. Done: the throughput subject reads the realistic corpus at 7,837
   patterns a second (first touch 4,310, AST cache off, plain
   interpreter); the number and the method sit in the series' pull
   request.
6. phpstan#11602: `isset($matches[3])` should imply its nested groups 4
   and 5 exist — needs the shape analysis to split an optional
   capture-bearing group into the case where it runs and the case where
   it is skipped; the alternation-split machinery exists, the optional-
   group split does not.
7. The php74 twin's `CaptureShape::matchShape()` render drops key names
   and optional markers outside the marks-only branch, against its own
   docblock contract — fix the render, rise the analysis version, re-
   record the digests, and let the next twin cycle carry it.
8. Known imprecision in the series, documented in the pull request: a
   lone `(?(DEFINE)...)` group renders as a union of two true arms; and
   commit hygiene (a few ungrammatical subjects, two discarded
   architectures inside the line) settles when the maintainer asks for
   the split into stacked pull requests.

## The PHPStan gold rush: what the analysis already knows and does not say

Mined 2026-10-24, four probes over the phpstan-src fork. Each item
names what is missing, never new analysis — the facts are computed.

1. phpstan#12997: `preg_replace()` keeps `non-empty-string` on the
   return while a lazy backreference in the replacement (`'$1'` against
   `(.*?)`) can expand to the empty string — the group shape the reader
   already holds, folded into `ReplaceFunctionsDynamicReturnTypeExtension`,
   drops the accessory.
2. phpstan#11438: `preg_replace_callback_array()` gives its callbacks no
   `$matches` shape — the array-of-callables first argument never reaches
   the closure extension; feeding each constant key to the matcher the
   way `preg_replace_callback()` does is the wiring.
3. Eleven `\K` wishes in the nsrt fixtures: the group slots are exact,
   the whole-match slot ignores `\K` — the kept suffix the engine writes
   is the whole a match reads after the reset.
4. Conditionals on an always-set group keep the dead arm:
   `/(a)(?(1)b|(c))/` types `'c'|''` where the engine never fills 2 —
   the participation fact exists; the conditional does not read it. Same
   family: `(?(*ACCEPT)` groups after the cut, a backreference widening
   its group, `(?(R)` with no recursion anywhere, and `(?(VERSION>=…)`
   never folding against the judged engine.
5. Tagged-union decorrelation (bug11604 and friends): a top-level
   alternation splits shapes the engine writes atomically — the
   optional-group split phpstan#11602 needs, again.
6. The package'"'"'s `TargetScope` lets the running engine stand in for
   the analysed PHP without the fork'"'"'s minor-sharing rule, never
   states the pin is honoured in both directions, and does not read
   `config.platform.lib-pcre` — the policy the fork settled, said in
   one voice.

## The third pass over the preg_ family

Mined 2026-10-24, second gold rush. The bare-false preg_split bug is
fixed; this is the rest, engine-verified:

1. preg_filter on an array subject erases its keys (array<int, string>
   for every subject) while the engine preserves them — map the subject
   like the replace extension maps its own, or route preg_filter
   through ReplaceFunctions (subject at position 2, replace at 1).
2. The replace trio (preg_replace, _callback, _callback_array) marks
   every array-subject key optional — the engine keeps them all; only
   preg_filter filters. makeAllArrayKeysOptional belongs to filter
   alone.
3. preg_grep has no return-type extension at all: the subject mapped,
   keys kept, |false.
4. preg_replace_callback with an ARRAY of literal patterns leaves its
   callback unshaped — one Type-based entry on the matcher (matchExpr
   takes an Expr; the union across the array'"'"'s constant strings is
   what matchPatternType already does) feeds the closure extension.
5. preg_match_all base return type carries null the engine never
   returns; the $matches out-type fallback string[] stands exactly
   where the shapes fail to reach.
6. Upstream issues granted by the shapes, for the pull request body:
   #11589 (J duplicate named groups), #12397 ($matches a list),
   #11291 (branch-reset tuple), #14809 (nested optionals),
   #11311 (PREG_UNMATCHED_AS_NULL trailing groups), #14302
   (variable-length lookbehind judged for the analysed engine);
   near-misses with a seam: #11479, #11744, #12126.
7. The false branches: preg_match_all overwrites $matches with array{}
   where the engine leaves the populated empty columns under
   PREG_PATTERN_ORDER; the elseif subject narrowing composes, nothing
   to fix; the quoting rule and the pattern rule keep two drifting
   hand-written lists of the preg_ functions that take patterns.

## The engine-gated test rows: the CI's red line

Engines and Coverage stay red on one shared cause: 76 test rows across
about 14 classes read the validator and the lexer against PCRE2 10.44
(the php:8.4-cli image the workflow pins) while their expectations were
verified against 10.49 — QuantifiedMultibyteLiteral, ValidatorEdgeCase,
CompiledLengthLocale, AlphaAssertionBody, PatternTooLargeLocale,
PatternPrinterRoundTrip, PatternLinterCorpus, InlineOptionLetters,
PropertyNegationSpacing, CaptureShapePhpStanType, QuoteMode,
UnicodePropertyRelease. The rows are sound on one engine and wrong on
the other; each needs the release gate named (the PcreGates trait, one
call per row) or an expectation per release. The pcre-gates spec that
waits for GO planned the same migration for the ~60 lint sites.

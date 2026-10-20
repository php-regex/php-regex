# TODO

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

## Linter: the last pass before 2.0.0

Left: the token helpers shared by the two extraction strategies, and the
argument parsing that lives in `Linter\Config` though only the CLI and the
language server read it.

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

Parser bugs, each reproduced against the engine: the library's own regexes giving up
under a low `pcre.backtrack_limit` are reported as pattern errors;
`php_version` and `ParserOptions` accept nonsense values.

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

- Symfony: validator-mapped patterns are never linted (the bundle wires a
  service FrameworkBundle does not define); `regex:security` counts a narrower `access_control` rule placed
  first on purpose (`^/admin/public` before `^/admin`) as an overlap; the
  `ide` fallback never fires; `AbstractBundle`, no container parameters,
  no autowirable internal services.
- PHPStan: a repo-only rule ships in the public extension;
  `PatternChecker` split.
- CLI: one option declaration per command for parsing and help;
  `OutputFormat` split per method in the facade; the language server's
  error stream still written through a static.
- The target settings (PHP, PCRE2, locale) enter through one class, and
  every parser derived for another target keeps the locale.

## Analysis engines: the last pass before 2.0.0

- The linter rebuilds the optimizer's Rewriter for `/x` patterns
  (`AnalysisService`), without the possessive check when verification is
  off and with a fixed "Optimized pattern." change: call
  `Optimizer::optimize()` with `factorize: false` there, and drop the
  Rewriter's `optimizeWord:` parameter.
- The Modernizer rewrites `[\t\n\x0B\f\r ]` to `\s`, which a locale widens
  (167 of 288 macOS locales add the byte 0xA0): keep the class unless the
  locale is `C`, as the optimizer now does.
- The optimizer rewrites `(?(DEFINE)a){7000}` to `(?:(?(DEFINE)a)){7000}`,
  which PCRE2 refuses: a DEFINE group is not to be wrapped.
- The printer writes a quantifier before the comments that sit between an
  atom and it; under `#`, where a `\c\` hides the delimiter in such a
  comment, PHP then reads the printed pattern differently.
- A ReDoS verdict "safe (proven)" is false where a lookaround in a loop
  holds a loop: `/^(?:(?=a+)a)+$/` takes quadratic time (about 600 ms on
  40,000 `a`, four times longer each time the subject doubles) because
  the body of the lookaround runs again at each position; the proof must
  count that work or not claim linear.
- `(*sr:)` parses as a verb, not an empty script run; the character-set
  analysis gives an unknown set for a script run; `(?<=a|a)` in a loop is
  flagged critical by the heuristic, which does not see that a lookaround
  runs on its own.
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

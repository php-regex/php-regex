<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" srcset="art/banner-dark.svg?v=6">
        <source media="(prefers-color-scheme: light)" srcset="art/banner.svg?v=6">
        <img src="art/banner.svg?v=6" alt="PHPRegex" width="100%">
    </picture>
</p>

<p align="center">
    <a href="https://php-regex.com"><img src="https://img.shields.io/badge/documentation-php--regex.com-blue" alt="Documentation Badge"></a>
    <a href="https://www.linkedin.com/in/younes--ennaji"><img src="https://img.shields.io/badge/author-@yoeunes-blue.svg" alt="Author Badge"></a>
    <a href="https://github.com/php-regex/php-regex/releases"><img src="https://img.shields.io/github/tag/php-regex/php-regex.svg" alt="GitHub Release Badge"></a>
    <a href="https://github.com/php-regex/php-regex/blob/2.x/LICENSE"><img src="https://img.shields.io/badge/license-MIT-brightgreen.svg" alt="License Badge"></a>
    <a href="https://packagist.org/packages/php-regex/php-regex"><img src="https://img.shields.io/packagist/dt/php-regex/php-regex.svg" alt="Packagist Downloads Badge"></a>
    <a href="https://github.com/php-regex/php-regex"><img src="https://img.shields.io/github/stars/php-regex/php-regex.svg" alt="GitHub Stars Badge"></a>
    <a href="https://packagist.org/packages/php-regex/php-regex"><img src="https://img.shields.io/packagist/php-v/php-regex/php-regex.svg" alt="Supported PHP Version Badge"></a>
</p>

# PHPRegex: Static Analysis, Linter & Logic Solver

PHPRegex is a PHP 8.2+ library that treats regular expressions as code.

Unlike simple wrappers around `preg_match`, PHPRegex implements a complete **compiler pipeline** (Lexer → Parser → AST) and an **Automata-based Logic Solver** (normalized form → NFA → DFA).

This architecture allows for advanced static analysis:
- **Linting:** Detect redundancy, useless flags, and common mistakes.
- **Safety:** Prove a pattern safe from catastrophic backtracking (ReDoS), or hand you the input that triggers it.
- **Logic:** Compare patterns via NFA/DFA (Intersection, Equivalence, Subset) for the regular subset it supports.

Built for robust regex tooling in PHP projects.

The documentation lives at [php-regex.com](https://php-regex.com): a
[regex tutorial](https://php-regex.com/tutorial/), a guide for every
integration, and the reference pages behind each verdict the library prints.

> ⚠️ **How to read the verdicts.** ReDoS analysis proves safety for the
> subset of PCRE it models, one match attempt at a time; outside that subset,
> structural heuristics decide — and the verdict says so. The parser follows
> PCRE closely (see the [conformance page](https://php-regex.com/reference/pcre2-conformance/))
> without covering every edge of the engine. Treat findings as leads to
> investigate, not as a guarantee.

## 2.0 is coming

The library is being split into focused `php-regex/*` packages, and the 2.0 tag
is close. Until then, install the development build and shape it with us:

```bash
composer require php-regex/regex-toolkit:2.x-dev
```

Try it, break it, and tell us what your project needs — feedback and wishes go
to [the discussions](https://github.com/php-regex/php-regex/discussions).

If you are new to regex, start with the [Regex Tutorial](https://php-regex.com/tutorial/). If you want a short overview, see the [Quick Start Guide](https://php-regex.com/quick-start/).

## Getting started

```bash
# Install the library (2.x-dev until the 2.0 tag)
composer require php-regex/regex-toolkit:2.x-dev

# Install the command-line tool, and try it
composer require --dev php-regex/regex-cli
vendor/bin/regex explain '/\d{4}-\d{2}-\d{2}/'
```

PHPRegex is a family of packages, developed together in
[php-regex/php-regex](https://github.com/php-regex/php-regex) and released
with one version number. Install the one you need:

| package | what it holds |
|---|---|
| [`php-regex/regex-toolkit`](src/Toolkit) | the `Regex` facade: every library below in one call |
| [`php-regex/regex-parser`](src/Parser) | lexer, parser, immutable AST, validator, PCRE2 release targeting, AST cache |
| [`php-regex/regex-explain`](src/Explain) | explanations, highlighting, ASCII tree, Mermaid and railroad diagrams |
| [`php-regex/regex-optimizer`](src/Optimizer) | shorter equivalent patterns, modernized syntax |
| [`php-regex/regex-generator`](src/Generator) | sample strings and test cases |
| [`php-regex/regex-automata`](src/Automata) | language equivalence, intersection and subset |
| [`php-regex/regex-redos`](src/Redos) | catastrophic backtracking (ReDoS) analysis |
| [`php-regex/regex-transpiler`](src/Transpiler) | JavaScript, HTML `pattern` and Python targets |
| [`php-regex/regex-linter`](src/Linter) | lint rules and pattern extraction from PHP sources |
| [`php-regex/regex-cli`](src/Cli) | the `regex` command |
| [`php-regex/regex-language-server`](src/LanguageServer) | diagnostics in any LSP editor |
| [`php-regex/regex-phpstan`](src/PHPStan) | the PHPStan extension |
| [`php-regex/regex-psalm`](src/Psalm) | the Psalm plugin: `$matches` typed from the pattern, invalid patterns reported |
| [`php-regex/regex-rector`](src/Rector) | Rector rules: `preg_*` calls to the string functions they prove equal to |
| [`php-regex/regex-symfony`](src/Symfony) | the Symfony bundle |
| [`php-regex/regex-laravel`](src/Laravel) | the Laravel integration |

Coming from 1.x (`yoeunes/regex-parser`)? See [UPGRADE-2.0.md](UPGRADE-2.0.md).

## What PHPRegex provides

- 🏗️ **Deep Parsing:** Parse `/pattern/flags` into a structured, typed AST.
- 🧠 **Logic Solver:** Compare two regexes using NFA/DFA transformation (intersection, equivalence, subset). Works for patterns in the [regular subset](https://php-regex.com/architecture/) it supports — every character set asked from the running PCRE2 — and refuses the rest with the reason named.
- 🛡️ **ReDoS Analysis:** Prove the backtracking cost of a pattern — linear, polynomial or exponential — with the attack input when it is vulnerable, and replay that attack on the running PCRE. Outside the modelled subset, structural heuristics decide and say so.
- 🧹 **Linter:** Detect useless flags, redundant groups, and common mistakes via the CLI.
- 📖 **Explanation:** Explain patterns in plain English.
- 🔧 **Visitor API:** A flexible API for building custom regex tooling.

## Philosophy & Accuracy

PHPRegex separates what it can guarantee from what is heuristic:

- Guaranteed: parsing and AST structure for the targeted PHP/PCRE version.
- Measured: syntax validation and error offsets follow PHP's engine; the [PCRE2 conformance page](https://php-regex.com/reference/pcre2-conformance/) publishes how closely, case by case.
- Proven: a ReDoS verdict marked `(proven)` holds for one match attempt on a model of PCRE's backtracking; `safe (proven)` means no input makes that attempt backtrack beyond a linear number of steps. The [ReDoS guide](https://php-regex.com/guides/redos/#the-guarantee) lists its limits.
- Heuristic: patterns outside that model (backreferences, conditionals, recursion, …) are judged by structural rules, marked `(heuristic)`; treat those as potential risk unless confirmed.
- Context matters: PCRE version, JIT, and backtrack/recursion limits change practical impact.

### Tested against the real engine

Round-trip correctness (`compile(parse(x))` behaves like the original) is
verified in CI by differential tests that compare PHPRegex's output against
PHP's native `preg_match()`:

- A fixture of **212 PCRE patterns** is checked for validity, match result,
  and captured groups (`OfficialPcreComplianceTest`).
- Additional behavioral tests cover named groups, lookarounds, conditionals,
  atomic groups, and other features (`BehavioralComplianceTest`,
  `AdvancedFeaturesComplianceTest`).
- PCRE2's own official test suite (10.48, pinned) is replayed at compile level
  under PHP's compile options: the compile verdict agrees on more than 4,100
  of the roughly 4,400 extractable cases, and fewer than 100 patterns that PHP
  refuses to compile are accepted. The exact counts, the skipped cases and the
  fix plan are on the [PCRE2 conformance page](https://php-regex.com/reference/pcre2-conformance/).

These tests compare against a limited set of subjects, so they catch clear
regressions but are **not** a formal proof of full PCRE equivalence. There may
be edge cases that the test suite does not yet cover.

Separately, the linter and ReDoS analyzer have been run over a corpus of
**over 1,700 unique patterns collected from around 200 real-world PHP
projects** (Symfony,
Laravel, Composer, PHPUnit, …). The lint results are in `var/log/corpus.log`.
This corpus run is **not** part of the automated CI differential test — it is
a snapshot used to validate that the lint and ReDoS rules produce sensible
output on real code.

The corpus checkouts themselves are not committed. `corpus.json`, at the root
next to `composer.json`, lists every repository with its URL, branch and the
commit it was pinned to, much like a lock file. `bin/corpus` manages the
checkouts, composer-style:

```bash
php bin/corpus install                 # rebuild corpus/ exactly at the pinned commits
php bin/corpus update                  # prune, clone what is missing, then pull everything,
                                       # and write the new commits back to corpus.json
php bin/corpus update --no-prune       # keep checkouts that are no longer listed
php bin/corpus update --add https://github.com/vendor/repo.git [--as path] [--branch main]
php bin/corpus update --write-manifest # rewrite corpus.json from what is on disk
```

Every `corpus.json` key is a `vendor/name` path — a repository added without
`--as` is checked out under the `vendor/name` derived from its URL, and a URL
that offers no vendor segment is refused rather than guessed at.

`install` never writes to `corpus.json`: delete `corpus/` at any time and one
command rebuilds it, repository by repository, at the exact commits the
manifest pins. `update` is the only command that moves the pins. A checkout
with local changes is never reset or removed unless `--force` is given, and a
repository cloned by hand is removed on the next run unless it is added with
`--add` or listed with `--write-manifest` first.

Regenerate `var/log/corpus.log` after updating the corpus, from a terminal:

```bash
php bin/regex lint corpus/ --php-version=runtime --output=var/log/corpus.log
```

`--php-version=runtime` judges the corpus for the PHP running the command and
the PCRE2 it links; without it, the command would judge for the lowest PHP this
repository's `composer.json` allows.

Piping the command instead of running it in a terminal renders the severity
badges without their padding, which reformats every severity line of the
tracked file.

## How to report a vulnerability responsibly

If you believe a pattern is exploitable:

1. Run confirmed mode: it replays the attack on your PCRE and prints the input length that makes `preg_match()` fail.
2. Include the pattern, input lengths, timings, JIT setting, and PCRE limits.
3. Verify impact in the real code path before filing a security issue.

See [SECURITY.md](SECURITY.md) for reporting channels.

## Safer rewrites (verify behavior)

These techniques reduce backtracking but can change matching behavior. Always validate with tests.

```
/(a+)+$/     -> /a+$/      (semantics often preserved, but verify captures)
/(a+)+$/     -> /a++$/     (possessive, no backtracking)
/(a|aa)+/    -> /a+/       (only if alternation is redundant)
/(a|aa)+/    -> /(?>a|aa)+/ (atomic, avoids backtracking)
```

## How it works

- `Regex::parse()` splits the literal into pattern and flags.
- The lexer produces a token stream.
- The parser builds an AST (`RegexNode`).
- Visitors walk the AST to validate, explain, analyze, or transform.

For the full architecture, see [the architecture overview](https://php-regex.com/architecture/).

## CLI quick tour

```bash
# Parse and validate a pattern
vendor/bin/regex parse '/^hello world$/'

# Get plain English explanation
vendor/bin/regex explain '/\d{4}-\d{2}-\d{2}/'

# Check for ReDoS: a proven verdict, and the attack when vulnerable
vendor/bin/regex analyze '/(a+)+$/'

# Colorize pattern for better readability
vendor/bin/regex highlight '/\d+/'

# Lint your entire codebase
vendor/bin/regex lint src/
```

![Regex Lint Output](docs/assets/regex-lint.png)

## PHP API at a glance

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Redos\RedosMode;

$regex = Regex::create([
    'runtime_pcre_validation' => true,
]);

// Parse a pattern into AST
$ast = $regex->parse('/^hello world$/i');

// Validate pattern syntax and semantics
$result = $regex->validate('/(?<=test)foo/');
if (!$result->isValid) {
    echo $result->error;
}

// Check for ReDoS (theoretical by default)
$analysis = $regex->redos('/(a+)+$/');
echo $analysis->severity->value;   // 'critical'
echo $analysis->headline();        // 'Exponential backtracking (proven)'
echo $analysis->witness->render(); // '"a" x n . "!"': the input that triggers it

// Optional: replay the attack on the running PCRE
$confirmed = $regex->redos('/(a+)+$/', mode: RedosMode::Confirmed);
echo $confirmed->isConfirmed() ? 'confirmed' : 'theoretical'; // 'confirmed'

// Get human-readable explanation
echo $regex->explain('/\d{4}-\d{2}-\d{2}/');
```

## Integrations

PHPRegex integrates with common PHP tooling:

- **Symfony bundle**: [the Symfony guide](https://php-regex.com/guides/symfony/)
- **Laravel**: [the Laravel guide](https://php-regex.com/guides/laravel/)
- **Language server**: [the language server guide](https://php-regex.com/guides/lsp/)
- **PHPStan**: enabled by extension-installer, or through
  `vendor/php-regex/regex-phpstan/extension.neon`. It reports a pattern your
  target PHP refuses while the PHP running PHPStan compiles it; lint rules and
  ReDoS analysis come with `rules.neon`. See [the PHPStan guide](https://php-regex.com/guides/phpstan/)
- **Psalm**: `vendor/bin/psalm-plugin enable php-regex/regex-psalm` types
  `$matches` from the pattern where `preg_match()` returned 1 and after
  `preg_match_all()`, and reports the patterns your target PHP refuses as
  `InvalidRegexPattern`. See [the Psalm guide](https://php-regex.com/guides/psalm/)
- **Rector**: `RegexSetList::STRING_FUNCTIONS` rewrites `preg_match('/^https:/', $url)`
  into `\str_starts_with($url, 'https:')`, `preg_replace()` into `str_replace()` and
  `preg_split()` into `explode()`, only where the automata prove them equal.
  See [the Rector guide](https://php-regex.com/guides/rector/)
- **GitHub Actions**: `vendor/bin/regex lint` in your CI pipeline

## Performance

PHPRegex measures its own time and memory with [PHPBench](https://github.com/phpbench/phpbench), one group per subsystem (`lexer`, `parser`, `validate`, `lint`, `redos`, `redos-corpus`, `automata`, `optimizer`, `capture-shape`, `formatter`).

- Run one group: `composer bench -- --group=automata`
- Run the whole suite: `composer bench` (long; `redos-corpus`, one variant per corpus pattern, is the longest group)

See the [maintainers guide](https://php-regex.com/maintainers/#benchmarks) for the inputs and how to add a case.

## Documentation

Read the docs online at <https://php-regex.com>.

Start here:
- [Docs Home](https://php-regex.com/docs/)
- [Quick Start](https://php-regex.com/quick-start/)
- [Tutorial](https://php-regex.com/tutorial/)
- [Cookbook](https://php-regex.com/cookbook/)

Guides:
- [Symfony](https://php-regex.com/guides/symfony/), [Laravel](https://php-regex.com/guides/laravel/), [CLI](https://php-regex.com/guides/cli/), [language server](https://php-regex.com/guides/lsp/)
- [PHPStan](https://php-regex.com/guides/phpstan/), [Psalm](https://php-regex.com/guides/psalm/), [Rector](https://php-regex.com/guides/rector/), [ReDoS](https://php-regex.com/guides/redos/)

Key references:
- [Architecture](https://php-regex.com/architecture/)
- [Concepts](https://php-regex.com/concepts/): [AST](https://php-regex.com/concepts/ast/), [PCRE versions](https://php-regex.com/concepts/pcre/), [ReDoS](https://php-regex.com/concepts/redos/), [visitors](https://php-regex.com/concepts/visitors/)
- [API Reference](https://php-regex.com/reference/api/)
- [Diagnostics](https://php-regex.com/reference/diagnostics/)
- [Feature Support Matrix](https://php-regex.com/reference/feature-support-matrix/)
- [PCRE2 Conformance](https://php-regex.com/reference/pcre2-conformance/)
- [FAQ & Glossary](https://php-regex.com/reference/faq-glossary/)
- [Backward Compatibility Promise](https://php-regex.com/reference/backward-compatibility/)

## Contributing

Contributions are welcome! See [`CONTRIBUTING.md`](CONTRIBUTING.md) to get started.

```bash
# Set up development environment
composer install

# Run tests
composer phpunit

# Check code style
composer phpcs

# Run static analysis
composer phpstan
```

## Sponsors

[![Sponsor](https://img.shields.io/badge/Sponsor-%E2%9D%A4-db61a2?logo=github)](https://github.com/sponsors/yoeunes)

If PHPRegex saves you time, consider [sponsoring its maintenance](https://github.com/sponsors/yoeunes).

## Support

If you run into issues or have questions, please open an issue on GitHub: <https://github.com/php-regex/php-regex/issues>.

## License

Released under the [MIT License](LICENSE).

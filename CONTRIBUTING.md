# Contributing to PHPRegex

🎉 **Thank you for your interest in contributing to PHPRegex!**

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md):

- **Be respectful** - Treat all contributors with respect and kindness
- **Be constructive** - Provide helpful, actionable feedback
- **Be collaborative** - Work together toward common goals
- **Be patient** - Remember that everyone is learning and mistakes happen

## Quick Start

You need PHP 8.4 or later for the development tools, Composer, Git, and
[Task](https://taskfile.dev), which runs the commands below. The packages
themselves run on PHP 8.1 and later.

```bash
# 1. Fork & clone
git clone https://github.com/YOUR-USERNAME/php-regex.git
cd php-regex

# 2. Install the library and the development tools
task install

# 3. Make your changes and submit a PR!
```

The root `composer.json` installs the library alone. Each development tool
(PHPUnit, PHPStan, Rector, PHP CS Fixer and the others) has its own
`composer.json` under `tools/`, so the tools never share or constrain the
library's dependencies. `task install` installs the root and each tool, then
links each tool into `vendor/bin`.

### Without Task

The tools `task install` installs are listed in `taskfile.dist.yaml`. To
install them by hand:

```bash
composer install
for tool in php-cs-fixer phpbench phplint phpstan rector infection phpunit paratest deptrac; do
    composer install -d tools/$tool
done
```

`task install` passes `--ignore-platform-req=php+` to each tool, so that a
PHP newer than a tool's locked dependencies admit still installs it. Add it
to the loop when Composer refuses your PHP.

The Composer scripts call each tool from `tools/<tool>/vendor/bin`, so they
work without the links: `composer phpunit`, `composer phpstan`,
`composer lint`, and the others in the `scripts` section of `composer.json`.

The tools are locked for PHP 8.4. To run the suite on an older PHP, resolve
PHPUnit for it first with `composer update -d tools/phpunit`: it takes the
newest major that PHP runs, down to PHPUnit 10.5 on PHP 8.1. PHPUnit 10.5
refuses two settings of `phpunit.xml.dist` that arrived in PHPUnit 11, so
PHP 8.1 runs the suite from a local `phpunit.xml` without them, as the CI
does:

```bash
composer update -d tools/phpunit --with 'rector/rector:^2.6.3'
sed -e '/displayDetailsOnAllIssues=/d' -e '/displayDetailsOnPhpunitDeprecations=/d' phpunit.xml.dist > phpunit.xml
tools/phpunit/vendor/bin/phpunit
```

## Development Workflow

### Making Changes
1. Follow the coding standards below
2. Write the test first, and watch it fail before you write the code
3. Keep commits focused on single changes
4. Update documentation as needed

### Quality Assurance

```bash
# Run all checks: Rector, PHP CS Fixer, the cache version, PHPLint,
# PHPUnit, PHPStan and Deptrac
task lint       # or: composer lint
```

Two checks run only in CI. The spelling of the documentation and the
sources is read by [typos](https://github.com/crate-ci/typos), scoped by
`_typos.toml`, which also lists the words it does not know, each with the
reason it is right; `typos` at the root of the checkout runs the same check.
The public API is compared with the latest 2.x tag by
`tools/bc-check/vendor/bin/roave-backward-compatibility-check --from=v2.0.0`,
after `composer install -d tools/bc-check`; a symbol marked `@internal` is
not compared, and `.roave-backward-compatibility-check.xml` names what the
tool reports and is not a break.

### Running the Tests

```bash
composer phpunit          # the whole suite in one process
composer test:parallel    # the same suite in parallel, one process per CPU core
```

`composer test:parallel` runs the suite through ParaTest, installed in its own
vendor under `tools/paratest`. It takes the arguments
PHPUnit does (`--filter`, a test path, `--group`) and a few of its own:
`--processes=4` sets the number of processes. Each process runs whole test
classes, so a test must leave nothing behind that another test class reads: a
file it writes goes in a temporary directory of its own, and a static it sets
is reset in `tearDown()`.

## Coding Standards

- **Strict types** required for all PHP files
- **Type declarations** for all parameters, return types, and properties
- **Immutability** - use `readonly` properties where possible; AST nodes hold
  `public readonly` properties. The packages run on PHP 8.1, which has no
  readonly classes
- **Naming conventions** - PascalCase for classes, camelCase for methods,
  except test methods (see below)
- **Style** - PHP CS Fixer and Rector apply it: run `task lint` rather than
  formatting by hand
- **Static analysis** - PHPStan at its highest level. Fix what it reports
  rather than silencing it inline; a real false positive goes in
  `phpstan.dist.neon`

## Testing Requirements

All contributions MUST include tests, and a bug fix starts with a test that
fails without the fix. The test suite's conventions:

- a test class is `final` and extends `PHPUnit\Framework\TestCase`;
- each test method is marked `#[Test]` and named `test_{feature}_{scenario}`,
  in snake case: `test_callout_number_past_255_is_refused_where_pcre_refuses_it`;
- a data provider is a static method that returns `iterable` and yields keyed
  rows, the row's name as its key and each argument by name:
  `yield 'a reversed range' => ['pattern' => '/a{2,1}/', 'valid' => false];`;
- strict assertions: `assertSame()`, not `assertEquals()`;
- what a pattern means is checked against PCRE first, in the test itself:
  the library reports what the engine does, so the engine is the reference.

```php
<?php

declare(strict_types=1);

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class QuantifierRangeTest extends TestCase
{
    #[Test]
    #[DataProvider('provideRanges')]
    public function test_quantifier_range_is_judged_as_pcre_judges_it(string $pattern, bool $valid): void
    {
        // The engine first: PCRE compiles the pattern, or refuses it.
        $this->assertSame($valid, false !== @preg_match($pattern, ''));

        $this->assertSame($valid, RegexParser::create(['cache' => null])->validate($pattern)->isValid);
    }

    /**
     * @return iterable<string, array{pattern: string, valid: bool}>
     */
    public static function provideRanges(): iterable
    {
        yield 'an ordered range' => ['pattern' => '/a{1,2}/', 'valid' => true];
        yield 'a reversed range' => ['pattern' => '/a{2,1}/', 'valid' => false];
    }
}
```

To see what PCRE does with a pattern before you write the test:

```bash
php -r 'var_dump(@preg_match("/a{2,1}/", ""), error_get_last()["message"] ?? null);'
```

[tests/README.md](tests/README.md) describes the layout of the suite.
[Extending](https://php-regex.com/extending/#adding-a-pcre-construct) walks
through a new PCRE construct, and
[Adding a Lint Rule](https://php-regex.com/adding-a-lint-rule/) through a new
lint rule, each with every place it touches and the tests that check them.

## Changing what the parser builds

The parsed AST is cached, keyed on `Regex::CACHE_VERSION`. A cached tree is
only worth restoring while the current code would build the same one — so any
change to the lexer, the parser, a node or the readers they use makes the
entries written before it wrong, and they keep answering for as long as the
cache lives.

So the version is not a number to remember to raise: it is a fingerprint of
that code, and a command writes it.

```bash
task cache-version          # or: composer cache-version
```

`task lint` runs it for you, so a normal round of work updates it and the
change shows up in `git diff` like any other. The test suite fails while the
constant and the code disagree, and `php tests/Tools/update_cache_version.php
--check` reports it without writing anything.

The fingerprint covers the code alone, comments and formatting stripped, so
rewording a docblock costs nobody their cache.

## Coverage and mutations

CI runs the suite under PCOV on one engine and fails when the line coverage
drops below the percentage in `.github/coverage-threshold`. The number is
raised by hand when a change raises the coverage; a drop is answered with a
test, not a lower number. To read a report the same way:

```bash
php -d pcov.enabled=1 tools/phpunit/vendor/bin/phpunit --coverage-clover var/coverage/clover.xml
php tests/Tools/check_coverage.php var/coverage/clover.xml .github/coverage-threshold
```

On that same coverage, CI mutates the lines a pull request or a push changes,
held to the scores `infection.json.dist` declares, and every Monday the whole
parser. Run mutation testing on your machine with `composer infection`,
pointed at what you are working on (`composer infection src/Parser/Lexer.php`,
or `--git-diff-lines --git-diff-base=origin/2.x` for your branch).

## Submitting Changes

### Commit Messages
Write the subject as a short imperative sentence that says what the change does, with no type prefix; explain the why in the body when it is not obvious:
```bash
git commit -m "Escape numeric references when compiling a backreference"
git commit -m "Support branch reset groups (?|...)"
git commit -m "Test lookarounds against the running engine"
```

### Pull Request Process
1. Sync with upstream and rebase your branch
2. Create PR using the template
3. Address review feedback
4. Maintainers will squash and merge

## Releasing (maintainers)

Every package is released together, from the monorepo:

```bash
php -d xdebug.mode=off tests/Tools/redos-verdict-gate.php   # ReDoS analysis cost on the corpus
bin/status                     # CI, the split repositories, Packagist
bin/bump 2.1.0                 # Regex::VERSION and VERSION_ID, and the CHANGELOG section dated
git commit -am "Release 2.1.0"
git push origin 2.x            # wait for CI to pass
bin/release --dry-run 2.1.0    # every check, nothing tagged
bin/release 2.1.0              # a draft release and the tag; the phar workflow publishes it
bin/bump --next 2.1.1          # 2.1.1-DEV and a new Unreleased section
```

The first command analyses every pattern of the repository corpus the way
PHPStan and the linter do, and prints the median and 99th-percentile time per
pattern and the share of each ReDoS proof. It exits with 1 when the 99th
percentile is above 6 ms (`--max-p99=<ms>` sets another limit); a release
waits until it passes. Run it on an idle machine: the times are wall-clock.

`bin/release` releases only a HEAD that origin's branch holds, read from
origin when it runs, and on which every workflow that runs on each push to
2.x passed: the tests, the engines, static analysis, the packages, coverage,
the benchmarks, the workflow checks, the spelling, the public API since the
latest tag, and the demo applications, which install the checkout into a
Symfony and a Laravel application. The benchmark table a pull request gets
in its job summary is advisory and never blocks. `bin/status` shows how
far HEAD stands from origin's branch, and reads only.

`bin/release` creates the GitHub release as a draft before it pushes the
tag, so a release GitHub refuses leaves nothing tagged; a tag cannot be moved
or deleted once pushed. Its notes are the CHANGELOG section, or the section's
opening words and a link to the CHANGELOG when the section is longer than
GitHub takes.

The tag starts the phar workflow (`.github/workflows/phar.yml`): it builds
`regex.phar` from the tag on PHP 8.2, as Box needs, then runs it on PHP 8.1,
the lowest the packages support: its version must be the tag's, `validate`
must tell a valid pattern from an invalid one by its exit code, and the
language server must answer. It then attests the phar's build provenance,
attaches it and its SHA-256 checksum to the draft, and only then publishes
the release, so the latest release never goes without its phar. If that run
fails, the release stays a draft: fix the cause, then run the workflow by
hand on the tag with "publish" set. A release already published keeps its
phar: the workflow refuses to build another for it. Run by hand without
"publish", it only builds and checks the phar.

The tag triggers the split workflow, which tags every `php-regex/regex-*`
repository; Packagist picks the versions up from there. A pre-release
(`2.1.0-BETA1`, `2.1.0-RC1`) leaves the CHANGELOG alone and is published as a
GitHub pre-release, its notes a link to the CHANGELOG and, when the tree holds
one, to the upgrade guide of its major version (`UPGRADE-2.0.md` for any 2.x).

Every push to 2.x also splits `.github/organization` to the `main` branch of
`php-regex/.github`: the organization's profile (`.github/organization/profile/README.md`) and
the community files GitHub shows in every repository that has none of its
own, the read-only ones among them. Edit them here, never in that repository:
the next split replaces what it holds. The community files are copies of the
monorepo's own (`.github/FUNDING.yml`, the issue and pull request templates,
`SECURITY.md`, `CODE_OF_CONDUCT.md`), and a test fails when one of them
drifts, or when the profile leaves out a package.

The 1.x tags (`v0.1.0` to `v1.3.0`) and the `1.x` branch stay in this
repository for good, whatever a later cleanup of tags or branches removes:
those tags are the 1.x versions of `php-regex/php-regex` on Packagist, and
the 1.x archives Packagist serves under both that name and
`yoeunes/regex-parser` point at their commits here, so the lock files
written from them download from this repository, even once
`yoeunes/regex-parser` reads another one. Deleting a tag takes that version
off Packagist, and a `composer update` that needs it fails. Only `v2.*` tags start the split workflow, so the split
repositories never receive the 1.x ones.

## 🎉 Recognition

Contributors will be:
- Mentioned in release notes

**Thank you for contributing to PHPRegex and helping make regex parsing better for everyone!** 🚀✨

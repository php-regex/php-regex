# Contributing to PHPRegex

🎉 **Thank you for your interest in contributing to PHPRegex!**

## Code of Conduct

This project follows the [Contributor Covenant Code of Conduct](CODE_OF_CONDUCT.md):

- **Be respectful** - Treat all contributors with respect and kindness
- **Be constructive** - Provide helpful, actionable feedback
- **Be collaborative** - Work together toward common goals
- **Be patient** - Remember that everyone is learning and mistakes happen

## Quick Start

```bash
# 1. Fork & clone
git clone https://github.com/YOUR-USERNAME/php-regex.git
cd php-regex

# 2. Set up environment
composer install

# 3. Install Development Tools (if you have Task installed)
task install

# 4. Make your changes and submit a PR!
```

## Development Workflow

### Making Changes
1. Follow coding standards (strict types, type declarations, immutability)
2. Write tests first (TDD approach recommended)
3. Keep commits focused on single changes
4. Update documentation as needed

### Quality Assurance

```bash
# Run all checks
task lint
```

## Coding Standards

- **Strict types** required for all PHP files
- **Type declarations** for all parameters, return types, and properties
- **Immutability** - use `readonly` classes and properties where possible
- **Naming conventions** - PascalCase for classes, camelCase for methods

## Testing Requirements

All contributions MUST include tests:

```php
<?php
declare(strict_types=1);

namespace PHPRegex\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class YourClassTest extends TestCase
{
    public function test_descriptive_name(): void
    {
        // Given
        $input = 'test input';

        // When
        $result = YourClass::process($input);

        // Then
        $this->assertSame('expected output', $result);
    }
}
```

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
pointed at what you are working on (`--filter=src/Parser/Lexer.php`, or
`--git-diff-lines --git-diff-base=origin/2.x` for your branch).

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

`bin/release` creates the GitHub release as a draft before it pushes the
tag, so a release GitHub refuses leaves nothing tagged; a tag cannot be moved
or deleted once pushed. Its notes are the CHANGELOG section, or the section's
opening words and a link to the CHANGELOG when the section is longer than
GitHub takes.

The tag starts the phar workflow (`.github/workflows/phar.yml`): it builds
`regex.phar` from the tag, attests its build provenance, attaches it and its
SHA-256 checksum to the draft, and only then publishes the release, so the
latest release never goes without its phar. If that run fails, the release
stays a draft: fix the cause, then run the workflow by hand on the tag with
"publish" set. Run by hand without "publish", it only builds the phar.

The tag triggers the split workflow, which tags every `php-regex/regex-*`
repository; Packagist picks the versions up from there. A pre-release
(`2.1.0-BETA1`, `2.1.0-RC1`) leaves the CHANGELOG alone and is published as a
GitHub pre-release.

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

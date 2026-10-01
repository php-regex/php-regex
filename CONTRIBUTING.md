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

class YourClassTest extends TestCase
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

Mutation testing is deliberately not part of CI: it belongs on your machine,
pointed at what you are working on. Run it with `composer infection`.

## Submitting Changes

### Commit Messages
Use conventional commits:
```bash
git commit -m "fix: backreference compilation now escapes numeric refs"
git commit -m "feat: add support for branch reset groups (?|...)"
git commit -m "test: add behavioral compliance tests for lookarounds"
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
bin/bump 2.1.0                 # Regex::VERSION, and the CHANGELOG section dated
git commit -am "Release 2.1.0"
git push origin 2.x            # wait for CI to pass
bin/release --dry-run 2.1.0    # every check, nothing tagged
bin/release 2.1.0              # the phar, the tag, the GitHub release
bin/bump --next 2.1.1          # 2.1.1-DEV and a new Unreleased section
```

The first command analyses every pattern of the repository corpus the way
PHPStan and the linter do, and prints the median and 99th-percentile time per
pattern and the share of each ReDoS proof. It exits with 1 when the 99th
percentile is above 5 ms (`--max-p99=<ms>` sets another limit); a release
waits until it passes. Run it on an idle machine: the times are wall-clock.

The tag triggers the split workflow, which tags every `php-regex/regex-*`
repository; Packagist picks the versions up from there. A pre-release
(`2.1.0-BETA1`, `2.1.0-RC1`) leaves the CHANGELOG alone and is published as a
GitHub pre-release.

## 🎉 Recognition

Contributors will be:
- Mentioned in release notes

**Thank you for contributing to PHPRegex and helping make regex parsing better for everyone!** 🚀✨

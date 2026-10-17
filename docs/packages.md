---
title: Choose a package
description: "Choose the PHPRegex package that matches your task: parsing, application API, CLI, static analysis, editor integration, or a framework bridge."
permalink: /packages/
---
# Choose a package

PHPRegex is developed as one family of focused packages. The focused packages are published now as `{{ site.data.product.release }}-dev`; install the surface you need and let Composer pull its dependencies.

## Install the surface you need

```bash
composer config minimum-stability dev
composer config prefer-stable true
composer require --dev php-regex/regex-cli:{{ site.data.product.release }}-dev
```

The first two commands run once in the consuming project: they allow the prerelease PHPRegex packages while still preferring stable releases everywhere else. The final command installs the terminal binary and the analyses it runs. Use the table below to pick another surface.

## Choose by package

| You need… | Install | Why this package |
|-----------|---------|------------------|
| Only the lexer, parser, AST, validator, or capture-shape analyzer | `php-regex/regex-parser` | The smallest foundation; it needs `mbstring`, while source extraction does not run here. |
| One facade for common application calls | `php-regex/regex-toolkit` | Parses, validates, explains, optimizes, generates, transpiles, and analyzes ReDoS through one configured parser: a convenience layer over those packages, which stay the contract. |
| The terminal binary | `php-regex/regex-cli` | Adds `vendor/bin/regex` for parsing, explanations, diagrams, linting, ReDoS, comparison, and transpilation. |
| PHPStan findings | `php-regex/regex-phpstan` | Reports invalid patterns and optional lint, ReDoS, and optimization findings during static analysis. |
| Psalm types and findings | `php-regex/regex-psalm` | Types `$matches` from the pattern and reports patterns the target PHP refuses. |
| Verified rewrites | `php-regex/regex-rector` | Rewrites a `preg_*` call only when the automata prove the replacement answers alike. |
| Regex assertions in your test suite | `php-regex/regex-phpunit` | Asserts what a pattern captures with exact capture semantics, that the running engine compiles it, and that it is proven linear. |
| Editor diagnostics | `php-regex/regex-language-server` | Serves diagnostics, hovers, completion, and code actions through the Language Server Protocol. |
| Laravel commands and services | `php-regex/regex-laravel` | Adds the service, facade, and Artisan commands. |
| Symfony commands and services | `php-regex/regex-symfony` | Adds the bundle, service, and console commands. |

The focused analysis libraries remain available for tools that compose them directly:

- `php-regex/regex-explain` for explanations, highlighting, and diagrams.
- `php-regex/regex-generator` for sample strings and test cases.
- `php-regex/regex-automata` for equivalence, intersection, and subset questions (`LanguageSolver`; the facade does not wrap it).
- `php-regex/regex-redos` for backtracking analysis and witnesses.
- `php-regex/regex-transpiler` for JavaScript, HTML `pattern`, and Python targets.
- `php-regex/regex-linter` for source extraction and lint rules.

## Decide by task

1. **Build a PHP application:** start with `regex-toolkit`; add `regex-cli` when you want terminal commands.
2. **Build an analysis tool:** start with `regex-parser`; add only the analysis packages your tool reports.
3. **Improve an existing project in CI:** start with `regex-cli`; add a static-analysis, testing or framework package when your workflow already uses one.
4. **Get feedback while editing:** install `regex-language-server`.

## When the monorepo is useful

`php-regex/php-regex` is the development monorepo. Install it only when you are contributing to PHPRegex, testing several integrations from one checkout, or building a tool that composes many packages and you explicitly want every component present:

```bash
composer require --dev php-regex/php-regex:{{ site.data.product.release }}-dev
```

It replaces every focused package with the same version, so do not install it merely to use the CLI, parser, or one bridge.

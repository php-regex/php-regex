---
title: Choose a package
description: "Choose the PHPRegex package that matches your task: parsing, application API, CLI, static analysis, editor integration, or a framework bridge."
permalink: /packages/
---
# Choose a package

PHPRegex is developed as one family of focused packages. During the prerelease, one monorepo install gives you every package and every integration. After the {{ site.data.product.release }} tag, install only the surface you need.

## Today: one prerelease install

```bash
composer require --dev php-regex/php-regex:{{ site.data.product.release }}-dev
```

This provides the parser, toolkit facade, CLI, linter, ReDoS analyzer, language server, static-analysis bridges, and framework integrations. It is the right starting point for trying PHPRegex and for tool authors that compose several analyses.

## After the 2.0 split

| You need… | Install | Why this package |
|-----------|---------|------------------|
| Only the lexer, parser, AST, validator, or capture-shape analyzer | `php-regex/regex-parser` | The smallest foundation; it needs `mbstring`, while source extraction does not run here. |
| One facade for common application calls | `php-regex/regex-toolkit` | Parses, validates, explains, optimizes, generates, transpiles, and analyzes ReDoS through one configured parser. |
| The terminal binary | `php-regex/regex-cli` | Adds `vendor/bin/regex` for parsing, explanations, diagrams, linting, ReDoS, comparison, and transpilation. |
| PHPStan findings | `php-regex/regex-phpstan` | Reports invalid patterns and optional lint, ReDoS, and optimization findings during static analysis. |
| Psalm types and findings | `php-regex/regex-psalm` | Types `$matches` from the pattern and reports patterns the target PHP refuses. |
| Verified rewrites | `php-regex/regex-rector` | Rewrites a `preg_*` call only when the automata prove the replacement answers alike. |
| Editor diagnostics | `php-regex/regex-language-server` | Serves diagnostics, hovers, completion, and code actions through the Language Server Protocol. |
| Laravel commands and services | `php-regex/regex-laravel` | Adds the service, facade, and Artisan commands. |
| Symfony commands and services | `php-regex/regex-symfony` | Adds the bundle, service, and console commands. |

The focused analysis libraries remain available for tools that compose them directly:

- `php-regex/regex-explain` for explanations, highlighting, and diagrams.
- `php-regex/regex-generator` for sample strings and test cases.
- `php-regex/regex-automata` for equivalence, intersection, and subset questions.
- `php-regex/regex-redos` for backtracking analysis and witnesses.
- `php-regex/regex-transpiler` for JavaScript, HTML `pattern`, and Python targets.
- `php-regex/regex-linter` for source extraction and lint rules.

## Decide by task

1. **Build a PHP application:** start with `regex-toolkit`; add `regex-cli` when you want terminal commands.
2. **Build an analysis tool:** start with `regex-parser`; add only the analysis packages your tool reports.
3. **Improve an existing project in CI:** start with `regex-cli`; add a static-analysis or framework package when your workflow already uses one.
4. **Get feedback while editing:** install `regex-language-server`.

When in doubt, install the prerelease monorepo, try the workflows, then narrow the dependency after the split is tagged.

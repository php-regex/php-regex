---
title: Guides
description: "Choose the PHPRegex integration that fits your workflow: PHPStan, Psalm, Rector, Laravel, Symfony, the language server, or the CLI."
---

# Guides

PHPRegex reports where you already work. Pick one surface below; each guide shows the exact configuration, findings, and limits for that integration.

{% include install-prerelease.html %}

During the pre-release, that one install provides every integration. The package-specific commands in the individual guides are the target layout after the split is tagged.

## Static analysis

### [PHPStan](phpstan.md)

Reports regex patterns your target PHP refuses and, on demand, lint, ReDoS, and optimization findings during static analysis.

### [Psalm](psalm.md)

Types `$matches` from the pattern and reports invalid patterns for the PHP version your project targets.

### [Rector](rector.md)

Rewrites a `preg_*` call into a cheaper string function only when the automata prove the two behave alike.

## Applications

### [Laravel](laravel.md)

Adds the `Regex` service and facade plus Artisan commands for linting, routes, explanations, comparisons, and transpilation.

### [Symfony](symfony.md)

Adds the bundle, `php_regex.regex` service, and console commands for linting, routes, security analysis, comparisons, and transpilation.

## Editors and terminal

### [Language server](lsp.md)

Diagnostics, hover explanations, completion, and quick fixes for PHP files in any editor that speaks the Language Server Protocol.

### [CLI](cli.md)

One binary for parsing, validation, explanations, diagrams, linting, ReDoS analysis, pattern comparison, and transpilation.

## Deep dives

- [ReDoS guide](redos.md): verdicts, guarantees, confirmed mode, and fixes.
- [Regex in PHP](regex-in-php.md): how PCRE behaves inside `preg_*`.

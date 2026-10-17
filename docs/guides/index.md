---
title: Guides
description: "Choose the PHPRegex integration that fits your workflow: PHPStan, Psalm, Rector, PHPUnit, Laravel, Symfony, the language server, or the CLI."
---

# Guides

PHPRegex reports where you already work. Pick one surface below; each guide installs only the package for that integration and shows its exact configuration, findings, and limits. If you want to compare the packages first, start with [Choose a package](../packages.md).

## Static analysis

<div class="guide-grid" markdown="1">

<div class="guide-entry" markdown="1">

### [PHPStan](phpstan.md)

Reports regex patterns your target PHP refuses and, on demand, lint, ReDoS, and optimization findings during static analysis.

</div>

<div class="guide-entry" markdown="1">

### [Psalm](psalm.md)

Types `$matches` from the pattern and reports invalid patterns for the PHP version your project targets.

</div>

<div class="guide-entry" markdown="1">

### [Rector](rector.md)

Rewrites a `preg_*` call into a cheaper string function only when the automata prove the two behave alike.

</div>

</div>

## Testing

<div class="guide-grid" markdown="1">

<div class="guide-entry" markdown="1">

### [PHPUnit](phpunit.md)

Four assertions for your test suite: what a pattern captures with exact capture semantics, that the running engine compiles it, and that it is proven linear.

</div>

</div>

## Applications

<div class="guide-grid" markdown="1">

<div class="guide-entry" markdown="1">

### [Laravel](laravel.md)

Adds the `Regex` service and facade plus Artisan commands for linting, routes, explanations, comparisons, and transpilation.

</div>

<div class="guide-entry" markdown="1">

### [Symfony](symfony.md)

Adds the bundle, `php_regex.regex` service, and console commands for linting, routes, security analysis, comparisons, and transpilation.

</div>

</div>

## Editors and terminal

<div class="guide-grid" markdown="1">

<div class="guide-entry" markdown="1">

### [Language server](lsp.md)

Diagnostics, hover explanations, completion, and quick fixes for PHP files in any editor that speaks the Language Server Protocol.

</div>

<div class="guide-entry" markdown="1">

### [CLI](cli.md)

One binary for parsing, validation, explanations, diagrams, linting, ReDoS analysis, pattern comparison, and transpilation.

</div>

</div>

## Deep dives

- [ReDoS guide](redos.md): verdicts, guarantees, confirmed mode, and fixes.
- [Formatting patterns](formatting.md): lay a long pattern out over lines, or compact it to one, without changing what it matches.
- [Regex in PHP](regex-in-php.md): how PCRE behaves inside `preg_*`.

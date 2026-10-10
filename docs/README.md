---
title: Documentation
description: "Pick your way into PHPRegex by task: embed it in PHPStan, Psalm or Rector, wire it into Laravel or Symfony, or learn regex from scratch."
permalink: /docs/
redirect_from:
  - /README/
  - /README.html
---

# PHPRegex Documentation

<div class="github-only">
<p>You are browsing the source folder. The served documentation lives at
<a href="https://php-regex.com/docs/"><strong>php-regex.com</strong></a>
— full-text search, a dark mode, and links that survive file renames.</p>
</div>

PHPRegex is a static analysis, linter & logic solver for PHP regular expressions. It parses every PCRE pattern into an AST and answers questions about it: validity ruled as PHP's own engine would rule it, ReDoS safety with a proof or a witness, lint findings with fixes, provably equivalent rewrites, and pattern-to-pattern logic through automata.

It is written for the authors of PHP tools — static-analysis extensions, framework bundles, libraries that carry regexes. Start with your integration below. New to regex? The [tutorial](tutorial/README.md) teaches regular expressions from scratch, and assumes nothing.

## Start by task

**I maintain a static-analysis tool or a library.** The [PHPStan](guides/phpstan.md), [Psalm](guides/psalm.md) and [Rector](guides/rector.md) guides show what each extension reports and what it proves. [Capture shapes](reference/capture-shapes.md) derive what `preg_match()` writes into `$matches` straight from the AST, and the [correctness contracts](reference/correctness-contracts.md) say, per feature, what is sound and what is heuristic.

**I work on a Laravel or Symfony application.** The [Laravel](guides/laravel.md) and [Symfony](guides/symfony.md) guides wire the `Regex` service and its commands; the [CLI guide](guides/cli.md) runs `regex lint` over the code base and in CI; the [ReDoS guide](guides/redos.md) reads the verdicts on the patterns your routes and validators accept.

**I am new to regex.** The [tutorial](tutorial/README.md) is a ten-chapter walk from the first literal to the patterns running in production PHP.

## Documentation map

### Learn

- [Quick Start](quick-start.md) - Install and a first analysis in a few runnable steps.
- [Regex 101 tutorial](tutorial/README.md) - Ten chapters, from literals to real-world PHP.
- [Regex in PHP](guides/regex-in-php.md) - How PCRE behaves inside `preg_*`.
- [ReDoS guide](guides/redos.md) - Verdicts, guarantees, confirmed mode, fixes.
- [Cookbook](cookbook.md) - Practical patterns and examples.
- [Troubleshooting](troubleshooting.md) - Common errors and how to fix them.

### Integrations

The [guides index](guides/index.md) lists every integration on one page.

- [PHPStan](guides/phpstan.md) - The patterns your target PHP refuses, plus lint and ReDoS findings in static analysis.
- [Psalm](guides/psalm.md) - `$matches` typed from the pattern, invalid patterns reported.
- [Rector](guides/rector.md) - `preg_*` calls rewritten into the string functions they provably equal.
- [Laravel](guides/laravel.md) - Service, facade, and artisan commands.
- [Symfony](guides/symfony.md) - Bundle, service, and console commands.
- [LSP](guides/lsp.md) - Diagnostics, hovers, completions and code actions in any editor.
- [CLI](guides/cli.md) - Sixteen subcommands, configuration, output formats, CI recipes.

### Concepts

- [Key concepts](concepts/README.md) - The fundamental ideas, explained for beginners.
- [What is an AST?](concepts/ast.md) - The structured representation behind every analysis.
- [Understanding Visitors](concepts/visitors.md) - How operations run over the tree.
- [ReDoS Deep Dive](concepts/redos.md) - Why patterns blow up and what is provable.
- [PCRE vs Other Engines](concepts/pcre.md) - Where PHP's engine sits among the others.

### Reference

- [Reference index](reference/README.md) - The reference material on one page.
- [Lint rules](reference/rules.md) - Every diagnostic, rule and optimization.
- [API](reference/api.md) - Entry points, return objects, exceptions.
- [Diagnostics](reference/diagnostics.md) - Error types and messages.
- [Diagnostics cheat sheet](reference/diagnostics-cheatsheet.md) - Quick error reference.
- [JSON output](reference/json-output.md) - Every key the `regex` command prints in JSON.
- [Feature support matrix](reference/feature-support-matrix.md) - PCRE construct coverage by component.
- [Correctness contracts](reference/correctness-contracts.md) - Soundness and completeness guarantees by feature.
- [Backward compatibility](reference/backward-compatibility.md) - What each release may change.
- [Capture shapes](reference/capture-shapes.md) - What `preg_match()` writes into `$matches`.
- [Pattern info](reference/pattern-info.md) - The facts PCRE2 computes on every compiled pattern.
- [Prefilters](reference/prefilters.md) - When a cheap string function can answer before `preg_match()`.
- [PCRE2 conformance](reference/pcre2-conformance.md) - validate() verdicts measured against PHP's engine.
- [Sonar](reference/sonar.md) - Where each SonarPHP regex rule maps to a PHPRegex identifier.
- [Logic solver](reference/logic-solver.md) - Pattern equivalence, intersection and subset via automata.
- [FAQ and Glossary](reference/faq-glossary.md) - Common terms and questions.
- [External References](reference/resources.md) - The sources behind the diagnostics.

### Internals

- [Architecture](architecture.md) - Internal design.
- [AST Traversal](design/ast-traversal.md) - How the tree is processed.
- [Nodes Reference](nodes/README.md) - AST node types.
- [Visitors Reference](visitors/README.md) - Built-in visitors and custom visitors.
- [Extending Guide](extending.md) - How to add features or integrations.
- [Maintainers Guide](maintainers.md) - Embedding PHPRegex in a tool as a first-class component.

## How PHPRegex works in brief

PHPRegex treats a regex literal as structured input:

- The literal is split into pattern and flags.
- The lexer emits a token stream.
- The parser builds an AST.
- Visitors walk the AST to validate, explain, analyze, or transform.

Every example output in these docs was produced by PHPRegex itself, running the same PCRE2 engine it reasons about.

## Tips for newcomers

- Always include delimiters and flags: `/pattern/flags` (for example, `/hello/i`).
- Build patterns step by step, then add constraints.
- Validate early: `vendor/bin/regex validate` catches errors quickly.
- Explain patterns with `vendor/bin/regex explain` when reviewing code.

## Getting help

- Issues and bug reports: <https://github.com/php-regex/php-regex/issues>
- Real-world examples: <https://github.com/php-regex/php-regex/tree/2.x/tests/Integration>
- Interactive playground: <https://regex101.com> (PCRE2 mode) - good to explore what a pattern matches; the verdicts, proofs and ReDoS witnesses are the CLI's job: `vendor/bin/regex analyze '/pattern/'`.

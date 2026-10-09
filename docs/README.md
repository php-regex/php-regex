---
title: Documentation
permalink: /docs/
redirect_from:
  - /README/
  - /README.html
---

# PHPRegex Documentation

This documentation covers regex fundamentals and how to use PHPRegex in PHP projects. It is written for both newcomers and experienced developers.

Start here:
- [Regex Tutorial](tutorial/README.md)
- [Quick Start](QUICK_START.md)
- [Architecture](ARCHITECTURE.md)

## Documentation map

### Learning path (beginners)

- [Regex Tutorial](tutorial/README.md) - Learn regex step by step.
- [Quick Start](QUICK_START.md) - Short, practical overview.
- [Regex in PHP](guides/regex-in-php.md) - PHP-specific behavior.

### Using PHPRegex

- [CLI Guide](guides/cli.md) - Command reference.
- [LSP Integration](guides/lsp.md) - IDE integration via Language Server Protocol.
- [PHPStan Guide](guides/phpstan.md) - Regex checks in static analysis.
- [Psalm Guide](guides/psalm.md) - `$matches` typed from the pattern, invalid patterns reported.
- [Rector Guide](guides/rector.md) - `preg_*` calls rewritten into the string functions they prove equal to.
- [Laravel Guide](guides/laravel.md) - Service, facade, and artisan commands.
- [Symfony Guide](guides/symfony.md) - Bundle, service, and console commands.
- [Cookbook](COOKBOOK.md) - Practical patterns and examples.
- [ReDoS Guide](guides/redos.md) - Security and performance guidance.
- [Troubleshooting](TROUBLESHOOTING.md) - Common errors and how to fix them.

### For developers and contributors

- [Architecture](ARCHITECTURE.md) - Internal design.
- [AST Traversal](design/AST_TRAVERSAL.md) - How the tree is processed.
- [Nodes Reference](nodes/README.md) - AST node types.
- [Visitors Reference](visitors/README.md) - Built-in visitors and custom visitors.
- [Extending Guide](EXTENDING_GUIDE.md) - How to add features or integrations.

### Reference materials

- [API Reference](reference/api.md) - PHP API documentation.
- [Diagnostics](reference/diagnostics.md) - Error types and messages.
- [Diagnostics Cheat Sheet](reference/diagnostics-cheatsheet.md) - Quick error reference.
- [JSON Output](reference/json-output.md) - Every key the `regex` command prints in JSON.
- [Feature Support Matrix](reference/feature-support-matrix.md) - PCRE construct coverage by component.
- [Correctness Contracts](reference/correctness-contracts.md) - Soundness/completeness guarantees by feature.
- [Backward Compatibility Promise](reference/backward-compatibility.md) - What each release may change.
- [FAQ and Glossary](reference/faq-glossary.md) - Common terms and questions.
- [Maintainers Guide](MAINTAINERS_GUIDE.md) - Project maintenance notes.

## How PHPRegex works in brief

PHPRegex treats a regex literal as structured input:

- The literal is split into pattern and flags.
- The lexer emits a token stream.
- The parser builds an AST.
- Visitors walk the AST to validate, explain, analyze, or transform.

Every example in these docs uses PHPRegex as the reference implementation.

## Tips for newcomers

- Always include delimiters and flags: `/pattern/flags` (for example, `/hello/i`).
- Build patterns step by step, then add constraints.
- Validate early: `vendor/bin/regex validate` catches errors quickly.
- Explain patterns with `vendor/bin/regex explain` when reviewing code.

## Getting help

- Issues and bug reports: <https://github.com/php-regex/php-regex/issues>
- Real-world examples: see `tests/Integration/`
- Interactive playground: <https://regex101.com> (PCRE2 mode)

## Key concepts

- [What is an AST?](concepts/ast.md)
- [Understanding Visitors](concepts/visitors.md)
- [ReDoS Deep Dive](concepts/redos.md)
- [PCRE vs Other Engines](concepts/pcre.md)

---

Previous: [Main README](https://github.com/php-regex/php-regex) | Next: [Quick Start](QUICK_START.md)

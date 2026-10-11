---
title: Documentation
description: "Choose your path into PHPRegex: first run, regex tutorial, integration, API reference, or the correctness contracts behind each verdict."
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

PHPRegex parses a PCRE pattern into an AST and gives structured answers about validity, meaning, backtracking risk, capture shape, and equivalence. The documentation is organized by what you are trying to do.

## Choose your path

| Goal | Start here |
|------|------------|
| Install and run one analysis | [Quick Start](/quick-start/) |
| Choose between the monorepo and focused packages | [Choose a package](/packages/) |
| Learn regular expressions from zero | [Tutorial](/tutorial/) |
| Lint a PHP codebase | [CLI guide](/guides/cli/) |
| Put findings in PHPStan, Psalm, Rector, Laravel, Symfony, or an editor | [Guides](/guides/) |
| Call the library from PHP | [API reference](/reference/api/) |
| Understand a specific warning | [Lint rules](/reference/rules/) |
| Check what is proved or heuristic | [Correctness contracts](/reference/correctness-contracts/) |
| Extend or embed the engine | [Architecture](/architecture/) |

## Read findings correctly

PHPRegex separates three kinds of answers:

- **Proven** follows from the parser, AST, automaton, or running PCRE oracle.
- **Heuristic** covers constructs outside the proof model or a budget stop.
- **Unknown** records that the analysis refuses to guess.

The [correctness contracts](/reference/correctness-contracts/) define those boundaries feature by feature. Treat a ReDoS finding as a strong lead to investigate, not a substitute for reviewing the rewrite.

## Documentation map

- [Learn](/tutorial/): tutorial, PHP regex guide, ReDoS guide, cookbook, troubleshooting.
- [Integrations](/guides/): CLI, PHPStan, Psalm, Rector, Laravel, Symfony, language server.
- [Concepts](/concepts/): AST, visitors, PCRE behavior, and ReDoS theory.
- [Reference](/reference/): diagnostics, API, output contracts, support matrices, compatibility.
- [Internals](/architecture/): architecture, traversal, AST nodes, visitors, extending, maintainer guide.

## Getting help

- Bugs and feature requests: [GitHub issues](https://github.com/php-regex/php-regex/issues).
- Design questions and usage discussion: [GitHub discussions](https://github.com/php-regex/php-regex/discussions).
- Security reports follow the [responsible disclosure policy](https://github.com/php-regex/php-regex/blob/2.x/SECURITY.md).

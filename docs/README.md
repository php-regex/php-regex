---
title: Documentation
description: "Choose your path into PHPRegex: first run, regex tutorial, integration, API reference, or the correctness contracts behind each verdict."
permalink: /docs/
redirect_from:
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

<div class="task-grid">

<a class="task-card" href="/quick-start/">
  <h3>Install and run</h3>
  <p>One package, five minutes to a first verdict on a pattern of your own.</p>
  <span>Quick start</span>
</a>

<a class="task-card" href="/tutorial/">
  <h3>Learn the toolchain</h3>
  <p>Regular expressions from zero, then the AST, visitors, and proofs on top.</p>
  <span>Tutorial</span>
</a>

<a class="task-card" href="/guides/">
  <h3>Put it in your stack</h3>
  <p>Findings where you already work: PHPStan, Rector, Laravel, Symfony, your editor.</p>
  <span>Guides</span>
</a>

<a class="task-card" href="/reference/rules/">
  <h3>Understand a warning</h3>
  <p>Every diagnostic, what it means, and what stands behind it.</p>
  <span>Lint rules</span>
</a>

</div>

Deeper in, the same paths continue:

- [Choose a package](/packages/) — the monorepo or the focused packages, per integration.
- [Call the library from PHP](/reference/api/) — the API reference.
- [Check what is proved or heuristic](/reference/correctness-contracts/) — the correctness contracts.
- [Extend or embed the engine](/architecture/) — architecture and internals.
- [Lint a codebase from the terminal](/guides/cli/) — the CLI guide.

## Read findings correctly

PHPRegex separates three kinds of answers:

<dl class="verdict-grid">
  <div class="verdict-item">
    <dt>Proven</dt>
    <dd>Follows from the parser, AST, automaton, or running PCRE oracle.</dd>
  </div>
  <div class="verdict-item">
    <dt>Heuristic</dt>
    <dd>Covers constructs outside the proof model or a budget stop.</dd>
  </div>
  <div class="verdict-item">
    <dt>Unknown</dt>
    <dd>Records that the analysis refuses to guess.</dd>
  </div>
</dl>

The [correctness contracts](/reference/correctness-contracts/) define those boundaries feature by feature. Treat a ReDoS finding as a strong lead to investigate, not a substitute for reviewing the rewrite.

## Documentation map

- [Learn](/tutorial/): tutorial, PHP regex guide, ReDoS guide, cookbook, troubleshooting.
- [Integrations](/guides/): CLI, PHPStan, Psalm, Rector, PHPUnit, Laravel, Symfony, language server.
- [Concepts](/concepts/): AST, visitors, PCRE behavior, and ReDoS theory.
- [Reference](/reference/): diagnostics, API, output contracts, support matrices, compatibility.
- [Internals](/architecture/): architecture, traversal, AST nodes, visitors, extending, adding a lint rule, maintainer guide.

## Getting help

- Bugs and feature requests: [GitHub issues](https://github.com/php-regex/php-regex/issues).
- Design questions and usage discussion: [GitHub discussions](https://github.com/php-regex/php-regex/discussions).
- Security reports follow the [responsible disclosure policy](https://github.com/php-regex/php-regex/blob/2.x/SECURITY.md).

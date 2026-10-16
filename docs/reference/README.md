---
title: Reference Index
description: "Find the PHPRegex reference page you need by task: diagnostics, compatibility, programmatic data, or background material."
permalink: /reference/
---

# Reference Index

This section freezes behavior you build against: diagnostics, output shapes, compatibility rules, and the data each API returns.

## Find a diagnostic

- [Lint rules](rules.md): every rule, severity, identifier, and fix.
- [Diagnostics](diagnostics.md): validation errors and their messages.
- [Diagnostics cheat sheet](diagnostics-cheatsheet.md): the short lookup table.
- [Sonar rule mapping](sonar.md): where SonarPHP rules map to PHPRegex identifiers.

## Check compatibility

- [Feature support matrix](feature-support-matrix.md): PCRE construct coverage by component.
- [Correctness contracts](correctness-contracts.md): what is proved, heuristic, or unknown.
- [Backward compatibility](backward-compatibility.md): what a 2.x release may change.
- [Extraction coverage](extraction-coverage.md): which calls each tool reads a pattern from.
- [PCRE2 conformance](pcre2-conformance.md): validation verdicts measured against PHP's engine.

## Use the data

- [API reference](api.md): entry points, result objects, and exceptions.
- [JSON output](json-output.md): every key the CLI and the Symfony and Laravel reports print.
- [Capture shapes](capture-shapes.md): what `preg_match()` writes into `$matches`.
- [Pattern info](pattern-info.md): capture count, lengths, limits, and target compatibility.
- [Prefilters](prefilters.md): when a string function can answer before `preg_match()`.
- [Logic solver](logic-solver.md): equivalence, intersection, subset, and witnesses.

## Learn more

- [FAQ and glossary](faq-glossary.md)
- [External references](resources.md)

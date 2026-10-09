---
permalink: /reference/
description: "The PHPRegex reference index: pick the right page by task — lint rules, diagnostics, the API surface, JSON output, capture shapes and the logic solver."
---
# Reference Index

This section contains the reference material for PHPRegex. Use it when you need specific details, error codes, or API behavior.

## Core Reference

- [Lint Rule Reference](rules.md)
- [SonarPHP Regex Rules](sonar.md)
- [API Reference](api.md)
- [Diagnostics](diagnostics.md)
- [Diagnostics Cheat Sheet](diagnostics-cheatsheet.md)
- [JSON Output](json-output.md)
- [Feature Support Matrix](feature-support-matrix.md)
- [Correctness Contracts](correctness-contracts.md)
- [Backward Compatibility Promise](backward-compatibility.md)
- [Capture Shapes](capture-shapes.md)
- [Pattern Info and Compatibility](pattern-info.md)
- [Prefilters](prefilters.md)
- [PCRE2 Conformance](pcre2-conformance.md)
- [Logic Solver](logic-solver.md)
- [FAQ and Glossary](faq-glossary.md)

## AST and Visitors

- [AST Nodes](../nodes/README.md)
- [AST Visitors](../visitors/README.md)
- [AST Traversal Design](../design/ast-traversal.md)

## External Resources

- [External resources](resources.md)

## Quick Access by Task

- Understand a lint warning: [the lint rule reference](rules.md)
- Fix a validation error: [the diagnostics cheat sheet](diagnostics-cheatsheet.md)
- Use the library in code: [the API reference](api.md)
- Read the JSON of the `regex` command: [the JSON output reference](json-output.md)
- Know what `preg_match()` writes into `$matches`: [capture shapes](capture-shapes.md)
- Read a pattern's capture count, names, lengths and limits, or the PHP and PCRE2 versions it is valid on: [pattern info and compatibility](pattern-info.md)
- Build a custom visitor: [AST nodes](../nodes/README.md) and [AST visitors](../visitors/README.md)
- Learn regex patterns: [the tutorial](../tutorial/README.md)
- Check ReDoS safety: [the ReDoS guide](../guides/redos.md)
- Compare two patterns (equivalence, intersection, subset) or get an example string: [the logic solver reference](logic-solver.md)

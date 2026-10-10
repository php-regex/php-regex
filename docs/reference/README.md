---
permalink: /reference/
description: "The PHPRegex reference index: pick the right page by task — lint rules, diagnostics, the API surface, JSON output, capture shapes and the logic solver."
---
# Reference Index

This section contains the reference material for PHPRegex. Use it when you need specific details, error codes, or API behavior.

## Core Reference

- [Lint Rule Reference](rules.md) - every diagnostic, rule and optimization
- [SonarPHP Regex Rules](sonar.md) - where each SonarPHP regex rule maps to a PHPRegex identifier
- [API Reference](api.md) - entry points, return objects, exceptions
- [Diagnostics](diagnostics.md) - error types and messages
- [Diagnostics Cheat Sheet](diagnostics-cheatsheet.md) - quick error reference
- [JSON Output](json-output.md) - every key the `regex` command prints in JSON
- [Feature Support Matrix](feature-support-matrix.md) - PCRE construct coverage by component
- [Correctness Contracts](correctness-contracts.md) - soundness and completeness guarantees by feature
- [Backward Compatibility Promise](backward-compatibility.md) - what each release may change
- [Capture Shapes](capture-shapes.md) - what `preg_match()` writes into `$matches`
- [Pattern Info and Compatibility](pattern-info.md) - the facts PCRE2 computes on every compiled pattern
- [Prefilters](prefilters.md) - when a cheap string function can answer before `preg_match()`
- [PCRE2 Conformance](pcre2-conformance.md) - validate() verdicts measured against PHP's engine
- [Logic Solver](logic-solver.md) - pattern equivalence, intersection and subset via automata
- [FAQ and Glossary](faq-glossary.md) - common terms and questions

## AST and Visitors

- [AST Nodes](../nodes/README.md) - every node type and its fields
- [AST Visitors](../visitors/README.md) - built-in visitors and custom visitors
- [AST Traversal Design](../design/ast-traversal.md) - how the tree is processed

## External Resources

- [Regex resources on the web](resources.md) - engines, tools and papers behind the diagnostics

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

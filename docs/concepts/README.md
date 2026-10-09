---
permalink: /concepts/
description: "The concepts section at a glance: AST and visitor foundations for newcomers, plus PCRE2 targeting and ReDoS internals for tool authors."
---
# Key Concepts

This section explains the fundamental concepts behind PHPRegex and regular expressions. Its pages sit at two altitudes: start with the foundations if the ideas are new to you, and read the deep dives when you build tooling on top of the library.

## Foundations

Start here if ASTs or the visitor pattern are new to you. These two pages assume nothing and explain the data model every PHPRegex feature builds on:

- **[What is an AST?](ast.md)** - The tree a pattern becomes, one node at a time
- **[Understanding Visitors](visitors.md)** - How one tree supports many analyses

## Deep dives

These pages are written for tool authors — the people behind PHPStan extensions, linters and code generators. They assume the foundations and go into engine internals:

- **[PCRE vs Other Engines](pcre.md)** - Syntax by PCRE2 release, target versioning, engine comparison
- **[ReDoS Deep Dive](redos.md)** - Ambiguity, witnesses and the proof model

## When to read these

- **New to regex?** Start with the main [Tutorial](../tutorial/README.md) first
- **Confused about a term?** Check the [FAQ & Glossary](../reference/faq-glossary.md)
- **Need deeper understanding?** Read the relevant concept guide
- **Building tools?** Study the [Architecture](../architecture.md) and [Extending Guide](../extending.md)

## Related resources

- [FAQ & Glossary](../reference/faq-glossary.md) - Quick definitions
- [Architecture](../architecture.md) - Technical deep dive
- [Tutorial](../tutorial/README.md) - Hands-on learning

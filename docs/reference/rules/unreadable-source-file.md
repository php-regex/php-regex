---
description: "regex lint could not read a PHP file it was asked to scan, so its patterns were not linted: raise memory_limit, or exclude the file. An error."
head_title: "A PHP file the regex lint could not read"
parent: /reference/rules/
rule_category: "Sources"
rule_order: 1
rule_ids:
  - regex.lint.source.unreadable
---
# Unreadable Source File

**Identifier:** `regex.lint.source.unreadable` (error)

**When it triggers:** `regex lint` could not read a PHP file it was asked to scan: the file
cannot be opened, or holding it and its tokens would not fit in `memory_limit`. Its
patterns are not linted, so the file is reported instead of being left out silently, at
error severity: the run fails.

**Fix:** Do what the message names: make the file readable, raise `memory_limit`, or
exclude the file (`--exclude`, or `exclude` in `regex.json`) when it holds no pattern you
write, as a large generated file.

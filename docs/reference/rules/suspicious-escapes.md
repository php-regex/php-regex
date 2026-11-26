---
description: "An escape that names a value PCRE cannot read: a \\x{...} code point past U+10FFFF, an octal escape past \\377 in byte mode, or an unknown \\N{name}."
head_title: "Suspicious escape sequences in a PHP regex"
parent: /reference/rules/
rule_category: "Escapes"
rule_order: 1
rule_ids:
  - regex.lint.escape.suspicious
---
# Suspicious Escapes

**Identifier:** `regex.lint.escape.suspicious`

**When it triggers:** An escape names a value PCRE cannot read: a `\x{...}` code point past U+10FFFF, an octal escape past `\377` in byte mode (`\777` without `/u`), or a `\N{name}` no character answers to (the name lookup needs `intl`). `\d` and the other shorthands are never suspicious, and `\8` is not an escape question at all: PCRE reads it as a backreference, and validation refuses it as `regex.backref.missing_group`.

The same defects are refused at validation first — `regex.unicode.out_of_range`, `regex.octal.out_of_range`, `regex.escape.unsupported` — so `regex lint` reports the pattern as invalid rather than printing a warning. The lint id is what the linter reports on a parsed tree.

**Example** (real `regex lint` output):
```
      → /\x{110000}/
    FAIL Invalid Unicode codepoint "\x{110000}" (out of range).

      → /\777/
    FAIL Invalid legacy octal codepoint "\777" (out of range).
```

**Fix:** Write a value in range: `\x{10FFFF}` under `/u`, `\777` under `/u`, or `\377` in byte mode.

---

**Try it.** [Open `/\x{110000}/` in the playground](/#pattern=%2F%5Cx%7B110000%7D%2F&subject=): the Diagnostics tab shows what PHP prints and the code PHPRegex gives it.

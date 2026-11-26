---
description: "A control character, or a character that hides or moves text, written raw in a pattern: why the linter reports it, and the escape to write instead."
head_title: "Invisible character written raw in a PHP regex"
parent: /reference/rules/
rule_category: "Literals"
rule_order: 2
rule_ids:
  - regex.lint.literal.invisible
---
# Invisible Character Written Raw

**Identifier:** `regex.lint.literal.invisible`

**When it triggers:** The pattern reads, as text, a character that does not show where the
pattern is read, written raw: a C0 control (a tab, a line feed and a carriage return included),
DEL, or one of the characters that hide or move text, which the console writes as escapes: the
C1 controls, the format characters (a zero-width space, a zero-width joiner, a soft hyphen, a
byte order mark, a bidirectional control such as U+202E) and the line and paragraph separators.
Each is reported once, where it stands, with the escape to write: `\t`, `\n` and `\r`, `\xHH`
for the other controls, and `\x{HEX}` for the others under `/u` or `(*UTF)`, their UTF-8 bytes
`\xHH` otherwise. Inside `\Q...\E`, where an escape is text, the tip closes the quote around it
(`\E\t\Q`). A character already written as an escape (`\t`, `\x09`, `\cI`, `\x{200B}`,
`\N{U+200B}`) is not reported, nor is white space `x` skips, a comment or a verb's name; a
no-break space shows as a space and is not reported either.

The lint command, PHPStan and the language server read the PHP literal the pattern was written
with: PHP turns a double-quoted `"\t"` into a raw tab before the pattern reaches PCRE, but the
source spells it, so it is not reported. A pattern whose literal is not at hand (one held in a
variable, a route requirement, a validator constraint) is not reported. Run on its own, the
linter reads the pattern string, where any such character is raw.

**Message:** `The pattern holds a raw control character U+0009, which does not show where the pattern is read.` (or `format character`, `line separator`, `paragraph separator`)

**Example:**
```php
// Not reported: the source spells the tab, PHP writes it
preg_match("/a\tb/", $subject);

// PREFERRED wherever the character would be written raw
preg_match('/a\tb/', $subject);
preg_match('/a\x{200B}b/u', $subject);
```

**Fix:** Write the escape the tip gives.

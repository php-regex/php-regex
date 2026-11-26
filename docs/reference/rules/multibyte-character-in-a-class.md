---
description: "Without the u modifier a character class matches each byte of a multibyte character on its own, so [é] is not the letter: add /u. An error of the lint."
head_title: "Multibyte character in a class without /u"
parent: /reference/rules/
rule_category: "Bytes Without /u"
rule_order: 1
rule_ids:
  - regex.lint.unicode.multibyteInClassWithoutU
---
# Multibyte Character in a Class

**Identifier:** `regex.lint.unicode.multibyteInClassWithoutU`

**When it triggers:** A character class holds a character of two bytes or more, and the
pattern has neither `/u` nor `(*UTF)`. The class holds each byte on its own, and a range
starts at the last byte of its start and ends at the first byte of its end: `[à-é]` is
`\xC3`, the range `\xA0-\xC3`, then `\xA9`. The other class rules read those bytes too,
but a byte two different characters share is reported here alone: `[äö]` holds `\xC3`
twice, and the fix is `/u`, not removing it. So is a byte of a character that a range to
it holds: `[a-ä]` is the range `a-\xC3`, then the `\xA4` it holds. A character written
twice, `[ää]`, even where others hold each of its bytes first, `[ö¤ää]`, or a byte
written beside a character that starts with it, `[aä\xC3]`, is a duplicate
`regex.lint.charclass.redundant` reports, as are two ranges sharing an ASCII character,
which no split makes: `[a-äb-ö]` holds `b` to `z` twice, with `/u` or without.

**Example:**
```php
// ERROR: [é] is the class of the bytes \xC3 and \xA9
preg_match('/[é]/', 'à');   // 1: "à" starts with \xC3 too

// PREFERRED: read code points
preg_match('/[é]/u', 'à');  // 0
```

**Fix:** Add `/u`. If bytes are meant, write them as `\x` escapes so the class says so.

---

**Try it.** [Open `/[é]/` in the playground](/#pattern=%2F%5B%C3%A9%5D%2F&subject=%C3%A0): the finding is in the Diagnostics tab.

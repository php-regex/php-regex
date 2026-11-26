---
description: "The i modifier is useless in a PHP pattern that holds no character with another case: what the rule keeps under /u, another locale or (*UCP)."
head_title: "When the PHP regex i flag does nothing"
parent: /reference/rules/
rule_category: "Flags"
rule_order: 3
rule_ids:
  - regex.lint.flag.useless.i
---
# Useless Flag 'i' (Caseless)

**Identifier:** `regex.lint.flag.useless.i`

**When it triggers:** The pattern sets the `i` (case-insensitive) modifier but contains no case-sensitive characters. Without `u`, a byte above ASCII has a case only under another locale's tables, the target's `locale`: under `fr_FR.ISO8859-1`, `"/\xE9/i"` matches `\xC9`, so its `i` is kept. Under `u`, a character has a case where the target's PCRE2 gives it another, one character for one: `/\x{149}/iu`, whose `ŉ` has a capital of two characters that PCRE2 does not fold, is reported. Every character of a range is read, not its ends alone: `/[×-÷]/iu` holds `Ø` to `ö` and matches `ø` only with `i`, so its `i` is kept, while `/[\x{4E00}-\x{9FFF}]/iu` holds no character with a case. Under `(*UCP)` without `u`, from PCRE2 10.35, a byte above ASCII has the case of the character of the same value, whatever the locale: `/(*UCP)\xE9/i` matches `\xC9`, so its `i` is kept, while `/(*UCP)\xB5/i` matches no other byte, the other cases of `µ` being past them. Without `u`, a class that holds the other case of every byte it holds matches the same bytes with `i`, which adds that case: `/[aA]/i`, `/[A-z]/i` and `/(*UCP)[\xC0-\xFE]/i` are reported, `/[a]/i` is not. Under `u`, a class is read against the characters the target's PCRE2 folds each of its own with, its caseless set included: `/[aA]/iu` and `/[kK\x{212A}]/iu` are reported, `/[kK]/iu` and `/[a-zA-Z]/iu` are not, `i` adding the Kelvin sign and the long s; under another locale's tables or `(*TURKISH_CASING)` such a class keeps the `i`. Where caseless matching is restricted, by `(?r)`, the `r` modifier or `(*CASELESS_RESTRICT)`, an ASCII letter folds with no character past ASCII from PCRE2 10.43: `/(?r)[kK]/iu` and `/(?r)[a-zA-Z]/iu` are reported, `/(?r)[k]/iu` and `/(?r)(?-r)[kK]/iu` are not. Under `(*UCP)` with another locale's tables, a byte above ASCII takes the case of the character of the same value, and an ASCII letter is read only where the tables give it the other case Unicode does: `/(*UCP)[\xC9\xE9]/i` is reported under `fr_FR.ISO8859-1`. A byte the tables leave unsettled, whose case the releases read otherwise, keeps the `i` whatever the class holds. One the interpreter reads otherwise matched alone keeps it in a class of that byte only: a class of two bytes or more is compiled from the pairs of the tables, with `i` and without, and `/[Ii\xDD\xFD]/i` and `/(*UCP)[iI]/i` are reported under macOS's `tr_TR.ISO8859-9`, `/[I]/i` is not. A backreference whose group captures nothing with a case, as `/(1)\1/i`, `/(\d)\1/i` or `/([\p{Nd}])\1/i`, matches that text alone. A lookaround in the group reads characters that are no part of what it captures, and a call matches what the group it runs matches: `/(\d(?=\w))\1/i` and `/(\d)((?1))\2/i` are reported, `/(\w)((?1))\2/i` is not, nor is a group that calls the whole pattern. `\p{Lu}`, `\p{Ll}` and `\p{Lt}` match every cased letter under `i` from PCRE2 10.45 and as written before it, where `/\p{Lu}/i` is reported; `\p{Lc}`, `\p{L&}`, `\p{Upper}` and `\p{Lower}` match as written on every release. `[:upper:]` and `[:lower:]` match the letters under `i` without `u`, and `/[aA[:upper:]]/i` keeps its `i`; under `u` or `(*UCP)` they read as `\p{Lu}` and `\p{Ll}`, which `i` leaves as written in a class on every release: `/[aA[:upper:]]/iu` and `/(*UCP)[[:lower:]]/i` are reported, unless the pattern sets an ASCII option such as `(?aP)`, which gives the POSIX classes back to the tables. A POSIX class standing on its own in an extended class is read the same way: `/(?[ [:upper:] ])/i` and `/(?[ [:alpha:] - [:lower:] ])/i` keep their `i`. After an inline option that turns `i` off, or sets it again, a character, a class or a backreference reads its case alike without the pattern's own `i`: `/(?-i)a/i` and `/(?i)a/i` are reported. An option set in an alternative holds for the alternatives after it, up to the end of the group around them: `/(?-i)a|b/i` is reported, `/(?:(?-i)a|b)c/i` is not.

**Message:** `Flag 'i' is useless: the pattern contains no case-sensitive characters.`

**Example:**
```php
// Warning: No letters to justify case-insensitive matching
preg_match('/^\d{4}-\d{2}-\d{2}$/i', $date);

// Preferred: Remove unnecessary flag
preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
```

**Fix:** Drop the flag when matching digits/symbols.

---

**Try it.** [Open `/^\d{4}-\d{2}-\d{2}$/i` in the playground](/#pattern=%2F%5E%5Cd%7B4%7D-%5Cd%7B2%7D-%5Cd%7B2%7D%24%2Fi&subject=2026-11-19): the finding is in the Diagnostics tab.

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

**When it triggers:** The pattern sets the `i` (case-insensitive) modifier but contains no case-sensitive characters. Without `u`, a byte above ASCII has a case only under another locale's tables, the target's `locale`: under `fr_FR.ISO8859-1`, `"/\xE9/i"` matches `\xC9`, so its `i` is kept. Under `u`, a character has a case where the target's PCRE2 gives it another, and every character of a range is read, not its ends alone: `/[×-÷]/iu` holds `Ø` to `ö` and matches `ø` only with `i`, so its `i` is kept, while `/[\x{4E00}-\x{9FFF}]/iu` holds no character with a case. Under `(*UCP)` without `u`, from PCRE2 10.35, a byte above ASCII has the case of the character of the same value, whatever the locale: `/(*UCP)\xE9/i` matches `\xC9`, so its `i` is kept, while `/(*UCP)\xB5/i` matches no other byte, the other cases of `µ` being past them. Without `u`, a class that holds the other case of every byte it holds matches the same bytes with `i`, which adds that case: `/[aA]/i`, `/[A-z]/i` and `/(*UCP)[\xC0-\xFE]/i` are reported, `/[a]/i` is not. Under `u`, a class is read against the characters the target's PCRE2 folds each of its own with, its caseless set included: `/[aA]/iu` and `/[kK\x{212A}]/iu` are reported, `/[kK]/iu` and `/[a-zA-Z]/iu` are not, `i` adding the Kelvin sign and the long s; under another locale's tables or `(*TURKISH_CASING)` such a class keeps the `i`. Under `(*UCP)` with another locale's tables, a byte above ASCII takes the case of the character of the same value, and an ASCII letter is read only where the tables give it the other case Unicode does: `/(*UCP)[\xC9\xE9]/i` is reported under `fr_FR.ISO8859-1`. A byte the tables leave unsettled, whose case the engine's paths or releases read otherwise, keeps the `i` whatever the class holds: `/[Ii\xDD\xFD]/i` under macOS's `tr_TR.ISO8859-9`. A backreference whose group captures nothing with a case, as `/(1)\1/i` or `/(\d)\1/i`, matches that text alone. `\p{Lu}`, `\p{Ll}` and `\p{Lt}` match every cased letter under `i` from PCRE2 10.45 and as written before it, where `/\p{Lu}/i` is reported; `\p{Lc}`, `\p{L&}`, `\p{Upper}` and `\p{Lower}` match as written on every release. After an inline option that turns `i` off, or sets it again, a character, a class or a backreference reads its case alike without the pattern's own `i`: `/(?-i)a/i` and `/(?i)a/i` are reported. An option set in an alternative holds for the alternatives after it, up to the end of the group around them: `/(?-i)a|b/i` is reported, `/(?:(?-i)a|b)c/i` is not.

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

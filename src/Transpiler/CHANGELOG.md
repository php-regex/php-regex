CHANGELOG
=========

2.0
---

 * First release as its own package, split from `yoeunes/regex-parser`;
   see the [main changelog](https://github.com/php-regex/php-regex/blob/2.x/CHANGELOG.md).
 * Without `u`, a byte above 0x7F on its own (`\xE9`, `[\x80-\xFF]`, invalid
   UTF-8) is refused for JavaScript and Python, which read characters; a
   multibyte character written whole stays that character.
 * The `html-pattern` target (alias `html`): the value of an HTML `pattern`
   attribute, matched whole under the `v` flag; an unanchored side is padded
   so it accepts what `preg_match()` finds, and classes escape what `v`
   reserves. `/i` and `(?i)` are spelled out, each letter written with every
   case PCRE takes for it (`[aA]`, `[kK\u212A]` under `u`).
 * A script property is written as JavaScript reads it: `\p{Han}`, which
   PCRE2 reads as the script's extensions, is `\p{Script_Extensions=Han}`,
   `\p{sc:Han}` is `\p{Script=Han}`, a name written loosely takes its
   Unicode spelling. A Bidi_Class is refused.

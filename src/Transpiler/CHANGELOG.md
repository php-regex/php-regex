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
 * The JavaScript and `html-pattern` targets drop `/S`, which PHP has
   ignored since 7.3, with a note.
 * The JavaScript and `html-pattern` targets carry `/U` and `(?U)` by
   swapping greedy and lazy in the quantifiers they govern.
 * Each target writes what its engine reads otherwise as PCRE reads it:
   `$` before a final newline, `^` and `$` under `/m`, the dot, and, under
   `u`, `\d`, `\w`, `\s` and `\b` (JavaScript), `\Z` and `\z` (Python),
   `re.ASCII` without `u` (Python). What it cannot write so is refused: an
   atomic group in Python, which was emulated with an extra group, `\w`
   under `u` in Python, and in JavaScript a backreference to a group that
   may not have matched or a group a repetition may leave unset.
 * The Python target carries `(?i:…)`, which it dropped, `(?i)`, `/U` and
   `/x`, which lost an escaped space.

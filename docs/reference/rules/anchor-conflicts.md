---
description: "A ^ after characters were consumed, a $ before characters that cannot end a line, a word boundary between two word characters: how PCRE reads each, and the fix."
head_title: "PHP regex anchors that can never match"
parent: /reference/rules/
rule_category: "Anchors"
rule_order: 1
rule_ids:
  - regex.lint.anchor.impossible.start
  - regex.lint.anchor.impossible.end
  - regex.lint.anchor.impossible.boundary
---
# Anchor Conflicts

**Identifier:** `regex.lint.anchor.impossible.start`, `regex.lint.anchor.impossible.end`, `regex.lint.anchor.impossible.boundary`

**When it triggers:**
- `^` appears after consuming tokens (without the `m` flag, including an inline `(?m)` scope) — cannot match start. A reference or a call that may read a group that matched nothing reads no character, and an `(*ACCEPT)` before the anchor may end the match first: `/()\1^a/` and `/a(*ACCEPT)^b/` match and are not reported
- `$` appears before consuming tokens that cannot continue a line end — asserts end too early. `$` and `\Z` still match before the subject's final newline (and a multiline `$` before any newline), so a tail such as `$\n` is fine; under the `D` modifier `$` is strict like `\z` (unless `m` is set, which disables `D`), and `\z` always is. The rule reports a tail only when every way through it is known to fail: an assertion, a lookaround or a verb that may hold is read through, a reference or a call may match what its group matches, and a set the rule cannot read (`\X`, a property that may hold the newline) may match the newline. `/a$\b\n/`, `/a$(?=\n)\n/` and `/a$\X/u` match `"a\n"` and are not reported; `/a$\n\b/`, `/(a)a$\1/` and `/a$\pL/u` never match and are reported. A byte above ASCII before the anchor, written as it is or read by a class such as `[^\x00-\x7F]`, is a word character or not as the target's tables say: PCRE2's own, under the `C` locale, hold none above ASCII, another locale's tables tell which bytes are, and under `/u` or `(*UCP)` it may be one or not. So under `C`, without `/u` or `(*UCP)`, `\B` after such a byte is not reported and `\b` after it is: in PHP, `"/\xE9\$\\b\\n/"`, the byte `0xE9` written as it is, never matches under `C` and is reported. Under `fr_FR.ISO8859-1`, where `0xE9` is a letter, it matches `"\xE9\n"` and is not reported, while `"/\xE9\$\\B\\n/"` never matches there and is, and so is `/\W$\b\n/`, `\W` taking no letter of those tables. Under `/u` or `(*UCP)` a byte above ASCII is reported after neither. The character before the anchor is read under the flags in effect where it stands: under `i` the class `[^a-z\x00-\x40\x5B-\x60\x7B-\x7E]` leaves out the capitals with the small letters, and once `(?-i)` lifts `i` it takes them, so `'/(?-i)[^a-z\x00-\x40\x5B-\x60\x7B-\x7E]$\b\n/i'` matches `"A\n"` and is not reported. Under `(*CRLF)` and `(*ANYCRLF)`, `\Z` and `$` without `m` stand before the newline that ends the subject, `\r\n` under the first, `\r\n`, `\r` or `\n` under the second, and a tail that reads it and asks for more is reported: `/(*CRLF)a\Z\r\n^/m` and `/(*CRLF)a$\r\nb/` never match, while `/(*ANYCRLF)a\Z\r^/m` matches `"a\r\n"`, `^` holding between the `\r` and the `\n`
- `^` under `m` holds at the start of the subject, or after a newline more follows. Once a character was read before it, it is reported where it can hold neither: after a character that ends no line under the newline convention (`/a$^/m` and `/a^/m`; under `(*CRLF)` a line ends at `\r\n` alone, so `/(*CRLF)a$\r^\n/m` is reported, while under `(*ANYCRLF)` a `\r` ends one and `/(*ANYCRLF)a$\r^\n/m` matches `"a\r\n"`); beside `\z` with only zero-width items between (`/a\z^/m`, `/\n^\z/m`); and in a lookahead, after characters of a fixed count that the items after the lookahead read as many of before `\z` (`/a(?=\n^)\n\z/m`). A character before it that may end a line, an item that may read nothing (`/a\n?^/m`), `\Z`, which may hold before the final newline (`/\n\Z^/m` matches `"\n\n"`), and an `(*ACCEPT)` that may end the match first (`/\n^(*ACCEPT)\z/m`) keep it silent
- `\b` sits between two word characters or two non-word characters, or `\B` between a word and a non-word character (`regex.lint.anchor.impossible.boundary`): `/a\bb/` and `/a\B!/` never match. Both neighbours must be items that read exactly one character (a letter, an escape, a class, a dot, `\d`...); an optional one may be absent, so `/a?\bb/` is not reported. What a word character is follows the pattern's mode: under `/u` PHP turns UCP on, so `é` is one and `/é\bx/u` is reported, while in byte mode the last byte of `é` is not, and `/é\bx/` matches `éx`. An ASCII character is a word character or not under every option, and is decided as such; any other atom is put to the automata, which the rule asks at most eight questions about one pattern, staying silent for the rest of it past them. The rule stays silent in a pattern that sets an ASCII option (`(?a)`, `(?aD)`, …) or `(?xx)`, or sets `r` beside a `(?^)`: the flags it reads do not say which of them is in force.

**Visual Explanation:**
```
Valid:
  /^abc/  -> anchor before content
  /abc$/  -> anchor after content
Invalid:
  /a^bc/  -> ^ after consuming 'a'
  /$abc/  -> $ before consuming anything
  /a\bb/  -> no boundary between two letters
```

**Fix:** Move anchors to the correct position.

---

**Try it.** [Open `/a^bc/` in the playground](/#pattern=%2Fa%5Ebc%2F&subject=a%5Ebc): the finding is in the Diagnostics tab.

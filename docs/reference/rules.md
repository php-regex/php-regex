---
description: "Every PHPRegex lint rule — when each fires, its message, its severity and its fix — from flags and anchors to ReDoS and Unicode byte traps."
---
# PHPRegex Rule Reference

This reference documents every lint rule that PHPRegex produces. It serves as the authoritative guide for understanding what PHPRegex checks and how to fix issues. Validation error codes live in [Diagnostics: Error Codes](diagnostics.md#error-codes), and the optimizer's rewrite suggestions in [the API reference](api.md).

<div class="page-filter">
  <label class="visually-hidden" for="rules-filter-input">Filter the rule reference</label>
  <input type="search" id="rules-filter-input" data-unit="rules" placeholder="Filter by rule, message or identifier — e.g. useless flag, redos, backreference" autocomplete="off" spellcheck="false">
  <span class="page-count"></span>
</div>
<p class="page-empty" role="status" hidden>No rule matches that filter — try a shorter fragment, or press Escape to see all of them again.</p>

## Table of Contents
{: .filter-toc }

| Section                                      | Description                       |
|----------------------------------------------|-----------------------------------|
| [Validation Layers](#validation-layers)      | How PHPRegex analyzes patterns    |
| [Flags](#flags)                              | Flag-related diagnostics          |
| [Anchors](#anchors)                          | Anchor positioning issues         |
| [Quantifiers](#quantifiers)                  | Quantifier-related patterns       |
| [Groups](#groups)                            | Group-related diagnostics         |
| [Lookarounds](#lookarounds)                  | Lookahead contradictions          |
| [Alternation](#alternation)                  | Alternation patterns              |
| [Backreferences](#backreferences)            | Reference diagnostics             |
| [Character Classes](#character-classes)      | Character class issues            |
| [Escapes](#escapes)                          | Escape sequence problems          |
| [Literals](#literals)                        | Literal text that reads badly     |
| [Bytes Without /u](#bytes-without-u)        | Multibyte text read as bytes      |
| [Inline Flags](#inline-flags)                | Inline flag diagnostics           |
| [Pattern Complexity](#pattern-complexity)    | When a pattern is too complex     |
| [Sources](#sources)                          | What the lint could not read      |
| [ReDoS Security](#security-redos)            | Catastrophic backtracking         |
| [Advanced Syntax](#advanced-syntax)          | Pointers to the tutorial          |
| [Compatibility](#compatibility--limitations) | PHP and PCRE2 support             |
| [Diagnostics Catalog](#diagnostics-catalog)  | Error code reference              |
| [Quick Reference Table](#quick-reference-table) | One line per rule              |

---

## Validation Layers

PHPRegex validates patterns through four layers — parse, semantic, runtime (optional), lint — each catching different types of issues. The diagram, one example per layer and the category each refused pattern carries live in [Diagnostics: Validation Layers](diagnostics.md#validation-layers). The layers below this one are the last of the four: the lint rules this page documents.

### PCRE2 Compatibility Contract

PHPRegex targets **PHP's PCRE2 engine** (`preg_*`). Key behaviors that may surprise users:

| Behavior           | Description               | Example                         |
|--------------------|---------------------------|---------------------------------|
| Forward references | Backreference before capture compiles, but the group is unset and the match will fail until it has captured | `/\1(a)/` compiles, but `\1` fails |
| Branch reset       | `(?\|...)` changes capture numbering | `\2` can be invalid in branches |
| `\g{0}`            | Invalid in PHP            | Use `\g<0>` or `(?R)`           |
| Lookbehind         | Must have bounded length  | `(?<=a+)` is invalid            |

---

## Flags

### Useless Flag 's' (DOTALL)

**Identifier:** `regex.lint.flag.useless.s`

**When it triggers:** The pattern sets the `s` (DotAll) modifier but contains no unescaped dot outside a character class. The `s` flag only affects `.`: an escaped `\.` and a `.` inside `[...]` are literal dots, which it leaves alone.

**Message:** `Flag 's' is useless: the pattern contains no unescaped dot outside a character class.`

**Visual Explanation:**
```
/^\d+\.\d+$/s
  -> no unescaped dot outside a class
  -> s has no effect
```

**Example:**
```php
// Warning: DotAll does nothing because there is no dot
preg_match('/^user_id:\d+$/s', $input);

// Preferred: Remove unnecessary flag
preg_match('/^user_id:\d+$/', $input);
```

**Fix:** Drop the flag or introduce a dot if intended.

**Read more:**
- [PHP: Pattern Modifiers (`s` / DOTALL)](https://www.php.net/manual/en/reference.pcre.pattern.modifiers.php)

---

### Useless Flag 'm' (Multiline)

**Identifier:** `regex.lint.flag.useless.m`

**When it triggers:** The pattern sets the `m` (multiline) modifier but contains no start/end anchors (`^` or `$`). `\A`, `\z` and `\Z` are not read by `m`, so they do not count.

**Message:** `Flag 'm' is useless: the pattern contains no ^ or $ anchor.`

**Visual Explanation:**
```
/search_term/m
  -> no ^ or $ anchors
  -> m has no effect
```

**Example:**
```php
// Warning: Multiline mode is unused because there are no anchors
preg_match('/search_term/m', $text);

// Preferred: Remove unnecessary flag
preg_match('/search_term/', $text);
```

**Fix:** Remove the flag or add anchors for per-line matching.

**Read more:**
- [PHP: Pattern Modifiers (`m` / MULTILINE)](https://www.php.net/manual/en/reference.pcre.pattern.modifiers.php)

---

### Useless Flag 'i' (Caseless)

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

### Useless Flag 'D' (Dollar End Only)

**Identifier:** `regex.lint.flag.useless.D`

**When it triggers:** The pattern sets the `D` modifier, which only stops a `$` from matching before a final newline, but no `$` is read without `m`: there is no `$` at all (`\Z` and a `$` in a class are not read by `D`), or every `$` is under `m`, set as a modifier or inline, which overrides `D`.

**Message:** `Flag 'D' is useless: the pattern contains no $ anchor.` or `Flag 'D' is useless: every $ anchor is read under m, which overrides D.`

**Example:**
```php
// Warning: no $ for D to read
preg_match('/^[a-z]+\z/D', $input);

// Warning: m overrides D on every $
preg_match('/^[a-z]+$/mD', $input);

// Preferred: drop the flag
preg_match('/^[a-z]+\z/', $input);
```

**Fix:** Drop the flag, or write `\z` and drop it.

---

### Useless Flag 'x' (Extended)

**Identifier:** `regex.lint.flag.useless.x`

**When it triggers:** The pattern sets the `x` modifier, which only drops whitespace and `#` comments, but holds no whitespace and no `#` comment. A `#` in a class, escaped, or in a `(?#...)` group opens no comment. Whitespace is looked for in the pattern as written: a space in a class or after a backslash, which `x` keeps, silences the rule too, and so does a no-break space, which `x` drops under a UTF-8 `LC_CTYPE` locale. A pattern holding both a `#` and a NUL byte is left alone: under `(*NUL)` a NUL ends a comment.

**Message:** `Flag 'x' is useless: the pattern contains no whitespace and no # comment.`

**Example:**
```php
// Warning: nothing for x to drop
preg_match('/^\d{4}-\d{2}$/x', $input);

// Preferred
preg_match('/^\d{4}-\d{2}$/', $input);
```

**Fix:** Drop the flag, or lay the pattern out with spaces and comments.

---

## Anchors

### Anchor Conflicts

**Identifier:** `regex.lint.anchor.impossible.start`, `regex.lint.anchor.impossible.end`, `regex.lint.anchor.impossible.boundary`

**When it triggers:**
- `^` appears after consuming tokens (without the `m` flag, including an inline `(?m)` scope) — cannot match start. A reference or a call that may read a group that matched nothing reads no character, and an `(*ACCEPT)` before the anchor may end the match first: `/()\1^a/` and `/a(*ACCEPT)^b/` match and are not reported
- `$` appears before consuming tokens that cannot continue a line end — asserts end too early. `$` and `\Z` still match before the subject's final newline (and a multiline `$` before any newline), so a tail such as `$\n` is fine; under the `D` modifier `$` is strict like `\z` (unless `m` is set, which disables `D`), and `\z` always is. The rule reports a tail only when every way through it is known to fail: an assertion, a lookaround or a verb that may hold is read through, a reference or a call may match what its group matches, and a set the rule cannot read (`\X`, a property that may hold the newline) may match the newline. `/a$\b\n/`, `/a$(?=\n)\n/` and `/a$\X/u` match `"a\n"` and are not reported; `/a$\n\b/`, `/(a)a$\1/` and `/a$\pL/u` never match and are reported. A byte above ASCII before the anchor, written as it is or read by a class such as `[^\x00-\x7F]`, is a word character or not as the target's tables say: PCRE2's own, under the `C` locale, hold none above ASCII, another locale's tables tell which bytes are, and under `/u` or `(*UCP)` it may be one or not. So under `C`, without `/u` or `(*UCP)`, `\B` after such a byte is not reported and `\b` after it is: in PHP, `"/\xE9\$\\b\\n/"`, the byte `0xE9` written as it is, never matches under `C` and is reported. Under `fr_FR.ISO8859-1`, where `0xE9` is a letter, it matches `"\xE9\n"` and is not reported, while `"/\xE9\$\\B\\n/"` never matches there and is, and so is `/\W$\b\n/`, `\W` taking no letter of those tables. Under `/u` or `(*UCP)` a byte above ASCII is reported after neither. The character before the anchor is read under the flags in effect where it stands: under `i` the class `[^a-z\x00-\x40\x5B-\x60\x7B-\x7E]` leaves out the capitals with the small letters, and once `(?-i)` lifts `i` it takes them, so `'/(?-i)[^a-z\x00-\x40\x5B-\x60\x7B-\x7E]$\b\n/i'` matches `"A\n"` and is not reported
- `$` appears before consuming tokens that cannot continue a line end — asserts end too early. `$` and `\Z` still match before the subject's final newline (and a multiline `$` before any newline), so a tail such as `$\n` is fine; under the `D` modifier `$` is strict like `\z` (unless `m` is set, which disables `D`), and `\z` always is. The rule reports a tail only when every way through it is known to fail: an assertion, a lookaround or a verb that may hold is read through, a reference or a call may match what its group matches, and a set the rule cannot read (`\X`, a property that may hold the newline) may match the newline. `/a$\b\n/`, `/a$(?=\n)\n/` and `/a$\X/u` match `"a\n"` and are not reported; `/a$\n\b/`, `/(a)a$\1/` and `/a$\pL/u` never match and are reported. A byte above ASCII before the anchor, written as it is or read by a class such as `[^\x00-\x7F]`, is a word character or not as the target's tables say: PCRE2's own, under the `C` locale, hold none above ASCII, and `/u`, `(*UCP)` or another locale's tables may. So `\B` after such a byte is not reported, and `\b` after it is reported under `C` alone, without `/u` or `(*UCP)`: in PHP, `"/\xE9\$\\b\\n/"`, the byte `0xE9` written as it is, never matches under `C` and is reported, and matches `"\xE9\n"` under `fr_FR.ISO8859-1`, where it is not. The character before the anchor is read under the flags in effect where it stands: under `i` the class `[^a-z\x00-\x40\x5B-\x60\x7B-\x7E]` leaves out the capitals with the small letters, and once `(?-i)` lifts `i` it takes them, so `'/(?-i)[^a-z\x00-\x40\x5B-\x60\x7B-\x7E]$\b\n/i'` matches `"A\n"` and is not reported. Under `(*CRLF)` and `(*ANYCRLF)`, `\Z` and `$` without `m` stand before the newline that ends the subject, `\r\n` under the first, `\r\n`, `\r` or `\n` under the second, and a tail that reads it and asks for more is reported: `/(*CRLF)a\Z\r\n^/m` and `/(*CRLF)a$\r\nb/` never match, while `/(*ANYCRLF)a\Z\r^/m` matches `"a\r\n"`, `^` holding between the `\r` and the `\n`
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

### Anchor Precedence in Alternation

**Identifier:** `regex.lint.anchor.alternationPrecedence`

**When it triggers:** An anchor binds tighter than `|`: `/^a|b|c$/` is `^a`, or `b` anywhere,
or `c$`. The rule speaks of intent and never says the pattern is wrong. It fires when the
first alternative of the pattern starts with `^`, `\A` or `\G` (past the option settings,
verbs and comments before it), or the last one ends with `$`, `\z` or `\Z`, and some
alternative has an anchor on neither side. The anchor may sit inside a group that changes
nothing about where the alternative matches (`(?:b$)`, `(b$)`, `(?>b$)`, a one-alternative
`(?|b$)`) or be all a positive lookaround asserts (`b(?=$)`). An alternation whose every
alternative is anchored on one side is left alone, as the trim idiom `/^\s+|\s+$/` is, and so
is an alternative that is the anchor alone (`/a|$/`: "or the end"). As in SonarPHP, an anchor anywhere
but the start of the first alternative and the end of the last shows the anchors are placed
alternative by alternative, and the rule stays silent (`/^ +| +$|,/`, `/^a|b|^c/`), as it does
under the `A` modifier, which anchors every alternative at the start. An alternative of verbs
and comments only, as `(*FAIL)`, is not counted, and an empty one (`/^a|/`) is
`regex.lint.alternation.empty`'s. A verb that ends the match
attempt on backtracking, accepts at once or fails (`(*COMMIT)`, `(*PRUNE)`, `(*SKIP)`,
`(*ACCEPT)`, `(*FAIL)`), in an alternative that reads something, leaves the rule silent: it can
keep the engine from the other alternatives (`/(*COMMIT)^a|b/` and `/^(*COMMIT)a|b/` never
match `b`), and a failing verb would carry into the grouped form, which then matches nothing.
A `(*SKIP:n)` that no `(*MARK:n)` names is ignored by the engine, and by the rule. The tip
keeps the options before the anchor (`(?i)^(?:a|b)`), keeps text
quoted with `\Q...\E` quoted (`^(?:a|\Qb)\E)` for `/^a|\Qb)\E/`), and leaves out the comments
ending each alternative, so that it compiles under `/x`. A group holding the anchor alone
leaves with it (`^(?:a|b)` for `/(?:^)a|b/`), but a capturing group stays, keeping its number,
and a group setting `m` moves as written (`(?m:^)(?:a|b)`).

**Example:**
```php
// WARNING: "b" matches anywhere
preg_match('/^a|b|c$/', 'xbx');      // 1

// PREFERRED, if the anchors are meant for every alternative
preg_match('/^(?:a|b|c)$/', 'xbx');  // 0
```

**Fix:** Group the alternatives, as the tip shows, when the anchors are meant for all of them.

---

### Dollar Before a Trailing Newline

**Identifier:** `regex.lint.anchor.dollarNewline` (info, off by default)

**When it triggers:** Without the `D` modifier and without `m`, `$` matches at the end of the
subject and also just before a newline that ends it: `/^\d+$/` accepts `"123\n"`. The rule
reports a `$` that ends a pattern anchored at its start, the shape of a whole-value check. The
alternative holding the `$` starts with `^` (read without `m`) or `\A`, or the `A` modifier
anchors it; each anchored alternative is checked on its own, so `/^a$|^b$/` is reported twice,
and `/^a|b$/` not at all. Nothing but comments may follow the `$`, and no subroutine call may run
it from elsewhere. The rule speaks only when the trailing newline makes a difference: what
precedes the `$` cannot end with a newline (`/^a\s*$/`, `/^[^,]+$/` and `/^.+$/s` take the
newline themselves and are left alone), and no lookahead in the pattern may read the newline the
`$` leaves. Under `(*CRLF)` the newline is `\r\n`, under `(*CR)` a `\r`; under `(*ANY)`, whose
newlines lie above ASCII, the rule stays silent. `\Z` is PCRE's explicit spelling of "the end, or
before a final newline" and is not reported; neither is a `$` under `m`, set as a modifier or
inline, which asks for line ends.

The rule is off by default: most anchored patterns end with `$`, and most of them read values
that never carry a trailing newline. It is recommended for code that validates untrusted input,
where `"admin\n"` passing `/^[a-z]+$/` can reach a header, a log line or a file name. Turn it on
with `"anchor.dollarNewline": true` under `checks.lint.rules` in `regex.json`. PHPStan and the
language server have no switch for one rule: they follow the defaults and do not report it.

**Message:** `"$" also matches before a newline that ends the subject, so a value with a trailing newline passes.`

**Example:**
```php
// INFO: the trailing newline passes the check
preg_match('/^\d+$/', "123\n");   // 1

// PREFERRED
preg_match('/^\d+\z/', "123\n");  // 0
preg_match('/^\d+$/D', "123\n");  // 0
```

**Fix:** Write `\z` to match at the very end only, or add the `D` modifier.

---

## Quantifiers

### Nested Quantifiers (ReDoS Risk)

**Identifier:** `regex.lint.quantifier.nested`

**When it triggers:** A variable quantifier wraps another variable quantifier, creating catastrophic backtracking potential. A count fixed above zero hides neither the loop after it nor the one inside it: `/^(?:\d{4}|[a-z]+)+$/` and `/^(?:(?:a+){2})+$/` are reported. Nor does a first loop that may match nothing hide the loop behind it: in `/^(?:b?c+)+$/` the runs of `c` of two iterations meet where `b?` matches nothing, and the pattern is reported. Every loop of the iteration is read against what follows it, the iteration read as a ring whose last item comes before its first: in `/^\w+(?:\s+\w+\s*)*$/` the `\s*` that ends one iteration meets the `\s+` that starts the next, and in `/^(?:\s*;\s*\w+\s*\w+)*$/` the two runs of letters meet where `\s*` matches nothing; both are reported.

The message names the outer loop and quotes it as written, on one line without the layout and the comments, thirty columns at most, so two findings on one pattern read apart: `in capturing group 1 "(a+)"` for `/(a+)+b/`, `in named group "(?<run>a+)"` for `/(?<run>a+)+b/`, and for a loop that captures nothing its offset in bytes into the pattern, `in "(?:a+)" at offset 1` for `/x(?:a+)+b/`.

It is not reported when the nesting cannot blow up:

- **Nothing at all follows the outer loop**: `/(a+)+/` and `/x(\d+\.?)+/` end the pattern, so the first way the engine finds is the match. This holds when the outer minimum is 0 or 1, and when no subroutine call runs the loop again. Anything after the loop, an anchor or an assertion included, keeps the issue: `/(a+)+$/` and `/(?:a+)+(?=b)/` are reported.
- **A short run before the end**: an outer bound of at most 2 around a greedy run of one literal character, followed only by `$`, `\z` or `\Z`, without `/m`, as `/(a+){1,2}$/`. Any other shape keeps the issue: a bound of 3 or more (`/(a+){0,3}$/`), a class (`/([ab]+){1,2}$/`), a lazy run (`/(a+?){1,2}$/`), `/m`, or a lookahead after the loop.
- **A separator splits each iteration**: the inner loop is followed right away by an item it cannot match, and every other item of the iteration matches one way only. In the unrolled loop `/"[^"\\]*(?:\\.[^"\\]*)*"/`, each iteration ends with `[^"\\]*`, and the `\\` that opens the next iteration is what `[^"\\]` refuses, so there is one way to split the input. The separator may stand behind items that may match nothing and take none of the loop's characters: the `,` behind `\s*` keeps the runs of `/^(?:\s*\d+\s*,)*\s*\d+\s*$/` apart. Under `/i` the two sides are compared case-folded, so `/(?:a+,)+$/i` is still exempt, but a loop unrolled this way is never exempt: `/"[^"\\]*(?:\\.[^"\\]*)*"/i` is reported. Without `/u`, under another locale's tables than PCRE2's own, the two sides are compared every byte as those tables give them, the other case of an ASCII letter and the word characters above ASCII included, unless the pattern reads a character caselessly where the engine's paths read some case otherwise (macOS's `tr_TR.ISO8859-9` reads `i`, `I` and `\xDD` so): under glibc's `tr_TR.ISO-8859-9`, which pairs `i` with `\xDD` and `I` with `\xFD`, `/(?:i+I)+$/i` is exempt and `/(?:i+\xDD)+$/i` is reported, and so is `"/(?:\\w+\xDD)+\$/"`, with the byte written as it is, `\w` taking `0xDD` there. Under `/u` an ASCII letter written alone, `i`, `\x69`, `[i]` or `[^i]`, takes the cases those tables give it, where a class of several characters takes Unicode's: under glibc's `tr_TR.UTF-8`, which gives `i` and `I` no other case, `/(?:i+I)+$/iu` is exempt, and `/(?:[ij]+I)+$/iu` and `/(?:[^i]+I)+$/iu`, whose classes take `I` there, are reported.
- **The inner loop is in a lookaround**: a lookaround holds without being tried again once it has matched, so a loop inside it splits no run of the loop around: `/^(?:(?=\w+)\w)+$/` and `/^(?:(?<=\s{1,3})\w)+$/` are not reported. A non-atomic lookahead, `(*napla:...)`, is backtracked into: `/^(?:(*napla:a+)a)+$/` is reported.
- **A small counted pattern**: every repeat of the pattern is bounded, and the engine can read the whole pattern in few ways, so one attempt costs a number of steps the pattern alone sets and no subject can grow. The ways are counted from the pattern: items in a row multiply theirs, branches add theirs (or count as the widest where no two start alike), and a repeat whose next character tells it to stop or go on counts one way a run, while one that may stop anywhere counts once for each count it may stop at. Items that end the pattern and cannot fail, such as optional groups with nothing after them, are tried once, and their ways add to those of what comes before them. The whole pattern is counted because what comes before a loop decides how often it is entered and what comes after it how often it is tried again: `/^(a?){0,10}(a?){0,10}$/` and `/^\w*(a?){0,10}$/` are reported, the first running past PHP's default `pcre.backtrack_limit` on seven `a` then `!`. Where every attempt starts at one place (the pattern opens with `^` without `m`, `\A` or `\G` in every branch, or has the `A` modifier), 2,048 ways are allowed: `/^(a{0,4}){0,4}$/` (781 ways, 438 steps at most on PCRE2 10.49) and `/^(a?){0,10}$/` (2,047 ways, 2,559 steps) are not reported, nor are the syllable patterns of a shaping library that nest `?`, `{0,2}`, `{0,3}` and `{0,4}` (73 steps at most), while `/^(a?){0,12}$/` (8,191 ways, 10,239 steps), `/^(a{0,6}){0,6}$/` (56,024 steps) and `/^(a{0,16}){0,16}$/`, which runs past the limit on seven `a` then `!`, are. A search that may start anywhere pays the attempt at every offset, and the limit counts each start apart, so only 7 ways are allowed there: `/(a?){0,2}(?:b|c)/` (14 steps a start, about 20 ms on 100,000 `a` without the JIT) is not reported, `/(a{0,2}){0,2}(?:b|c)/` (13 ways, about 30 ms) and `/(a?){0,10}(?:b|c)/` (about 10 s) are. The steps are the smallest `pcre.backtrack_limit` under which `preg_match()` finishes with `pcre.jit=0`, on the costliest subject a search finds: of 426 random patterns the rule leaves out this way, none whose attempts start at one place takes more than 5,117, and none that a search may start anywhere in more than 26 a start. An unbounded repeat, a reference, a subroutine call or a conditional anywhere in the pattern keeps the issue.
- **The loop ends an atomic group**, or the body of a possessive loop, with nothing after it there that can fail: `/(?>(?:a+)+)$/` and `/(?:(?:a+)+)++$/` never retry the loop. A subroutine call that runs a group between the loop and the atomic group runs the loop without it, so `/^(?:(?>x((?:a+)+))|y(?1)$)/` is reported.

With the ReDoS analysis on (`--redos`, or `checks.redos.enabled` in `regex.json`), a pattern the analysis proves linear drops the issue: the proof outranks the heuristic. That holds for a pattern with inline options too, an option setting such as `(?s)` or a scoped group such as `(?i-r:…)`, which the proof reads as PCRE does. The issue is also left out for a pattern listed in the ignored patterns or found trivially safe.

**Visual Explanation:**
```
Pattern: /(a+)+b/
Input: "aaaaa!"
Inner (a+) can match 1..n and the outer + repeats 1..n
Result: many backtracking paths
```

**Example:**
```php
// VULNERABLE: Nested quantifiers can cause ReDoS
preg_match('/(a+)+b/', $input);

// SAFER: Use atomic groups
preg_match('/(?>a+)+b/', $input);

// SAFER: Use possessive quantifier
preg_match('/(a++)+b/', $input);
```

**Fix:** Refactor to be deterministic, or use atomic groups/possessive quantifiers — but verify the rewrite still matches everything you need: when the inner part is ambiguous (`(?:ab|a)+b`), an atomic inner group removes the backtracking between iterations and can change the language.

**Read more:**
- [OWASP: ReDoS](https://community.owasp.org/attacks/Regular_expression_Denial_of_Service_-_ReDoS)

---

### Dot-Star in Quantifier

**Identifier:** `regex.lint.dotstar.nested`

**When it triggers:** An unbounded quantifier wraps a dot-star, which can cause extreme backtracking.

Without `/s` (or an inline `(?s)`), a dot cannot cross a newline, so `/(?:.*\n)+x/` is not reported: each iteration ends at the next newline and the input splits one way. With `/s` or `(?s)`, the same pattern is reported. Unlike nested quantifiers, a loop at the very end of the pattern is still reported, as `/(?:.*)+/` in the example below. With the ReDoS analysis on, a pattern the analysis proves linear drops the issue, inline options such as `(?s)` or `(?s:…)` included (see [Nested Quantifiers](#nested-quantifiers-redos-risk)).

**Example:**
```php
// RISKY: .* in repetition can backtrack heavily
preg_match('/(?:.*)+/', $input);

// SAFER: Make the dot-star atomic or possessive
preg_match('/(?>.*)+/', $input);
preg_match('/.*+/', $input);  // If no outer repetition is needed

// BETTER: Use negated character class
preg_match('/[^"]*/', $input);  // For double-quoted strings
```

**Fix:** Make it atomic/possessive or replace `.*` with a specific class — and verify the rewrite still matches everything you need.

---

### Useless Quantifier

**Identifier:** `regex.lint.quantifier.useless`

**When it triggers:** A quantifier that matches exactly once (e.g., `{1}` or `{1,1}`). A possessive one, `{1}+`, is an atomic group that never gives back, and is not reported: `/(a|ab){1}+ab/` does not match `"ababx"`, which `/(a|ab)ab/` does. Nor is a count that keeps an escape apart from the digit after it: `/(a)\1{1}0/` matches `"aa0"`, `/(a)\10/` does not, and `/\x4{1}1/` is not `/\x41/`. The tip names the count as written, a lazy `{1}?` with its `?`: `/a{1}?b/` is `/ab/`, not `/a?b/`.

**Example:**
```php
// WARNING: {1} does not change the match
preg_match('/a{1}/', $input);

// PREFERRED: Remove the quantifier
preg_match('/a/', $input);
```

**Fix:** Remove the `{1}` quantifier.

---

### Zero Quantifier

**Identifier:** `regex.lint.quantifier.zero`

**When it triggers:** A quantifier with a maximum of zero (e.g., `{0}` or `{0,0}`), which makes the element disappear. A repeat whose removal changes the match is left alone, as the optimizer leaves it: one holding a group, which still takes its number and may be called (`(?<d>\d){0}(?&d)+`, the way to define a subpattern), a backreference or a conditional, and one holding an assertion, which still tells PCRE2 where a match may start (`/(?:x|(?=a)){0}\w/` never matches `"\tb"`, where `/\w/` does).

**Example:**
```php
// WARNING: {0} always matches zero occurrences
preg_match('/ab{0}c/', $input);

// PREFERRED: Remove the quantified element
preg_match('/ac/', $input);
```

**Fix:** Remove the quantified element or replace it with an empty pattern.

---

### Optimal Quantifier Concatenation

**Identifier:** `regex.lint.quantifier.concatenation`

**When it triggers:** Two adjacent quantified tokens can be simplified because one character set is a subset of the other.
The rule speaks only when it knows the subset holds: a token that may take a character above
ASCII fits only a dot, a negated class of ASCII members or, without `u`, `\D`, `\W` and `\S`
(`/^\h{1,3}[\t ]+\z/` stays silent, `\h` takes `"\xA0"`), and under `i` a negated class must
hold both cases of its letters (`/^A?[^a]*\z/i` stays silent). Without `u`, under another
locale's tables than PCRE2's own, both tokens are read every byte as those tables give them,
unless the pattern reads a character caselessly where the engine's paths read some case
otherwise: under glibc's `tr_TR.ISO-8859-9`, which pairs `i` with `\xDD` and `I` with
`\xFD`, `/i+I+$/i` stays silent, its two tokens taking no character in common, and
`/i+\xDD+$/i` is reported; under `fr_FR.ISO8859-1`, so is `"/\xC9+\xE9+$/i"`, with the
bytes written as they are. Under `u` an ASCII letter written alone, `i`, `\x69`, `[i]` or
`[^i]`, takes the cases those tables give it, where a class of several characters takes
Unicode's: under glibc's `tr_TR.UTF-8`, which gives `i` and `I` no other case, `/i+I+$/iu`
stays silent and `/i+[ij]+$/iu` is reported. Nor does it speak of a token
PCRE2 makes possessive before an atomic group, or a possessive repeat of a group, that may
match nothing: dropped or fixed to a count, it gives back what PCRE2 took from the token
before it, and `/[ab]{2,}a?(?:b){0,2}+a/` finds nothing in `aaa` where
`/[ab]{2,}(?:b){0,2}+a/` finds it.

**Example:**
```php
// WARNING: \d is a subset of \w
preg_match('/\d+\w+/', $input);

// PREFERRED: Keep the superset quantifier unbounded
preg_match('/\d\w+/', $input);

// WARNING: \d* before \w* can be dropped — \w covers it
preg_match('/\d*\w*/', $input);

// PREFERRED: Drop the whole quantified term
preg_match('/\w*/', $input);
```

An atom guarded by a negative lookahead, as in `(?:.(?!x))*`, does not match every character of its class: `.(?!x)` refuses a character followed by `x`. Such a pair is reported only when the rewrite keeps every guard true, so `/^(?:.(?!x))*.{1,3}$/` is not.

**Fix:** Tighten the smaller quantifier to its minimum, or — when it can already match zero times — drop the whole quantified term.

---

### Lazy Quantifier at the End

**Identifier:** `regex.lint.quantifier.lazyEnd`

**When it triggers:** A lazy quantifier, or a greedy one under `/U`, has nothing after it
in the pattern. The match ends as soon as it may, so the quantifier matches its minimum.
The same holds when only items that may match nothing follow it, and none of them holds an
anchor, a lookaround or another test that can fail: in `/a+?b*/` on `aab`, `a+?` takes one
`a`, `b*` matches nothing after it, and the match is `a`. A `$`, a `\b` or a lookahead after
the suffix lets the quantifier take more, and the rule stays silent.
Under `/U` the message says where the laziness comes from:
`Quantifier "+" is lazy under the U flag and ends the pattern, so it always matches its minimum.`

A quantifier inside a group that a subroutine call runs again (`(?1)`, `(?&name)`) is not
reported: where the call stands, something may follow it. In `/(?1)x(a+?)/`, the call takes
`aaa` from `aaaxa`. A recursive pattern (`(?R)`, `(?0)`) is not checked by this rule at all.

**Example:**
```php
// WARNING: +? stops after one digit
preg_match('/id-\d+?/', 'id-123', $m);  // $m[0] is "id-1"

// WARNING: under /U, + is lazy: each space is replaced on its own
preg_replace('/\s+/U', ' ', "a   b");    // "a   b"

// PREFERRED
preg_match('/id-\d+/', 'id-123', $m);   // "id-123"
preg_match('/id-\d+?$/', 'id-123', $m); // "id-123": something follows
```

**Fix:** Make the quantifier greedy, write its minimum, or anchor what must follow it.

---

### Repeat That Can Match Empty

**Identifier:** `regex.lint.quantifier.emptyRepeat`

**When it triggers:** An unbounded quantifier (`*`, `+`, `{n,}`) repeats an item that can
match the empty string: `(a*)*`, `(?:a|b?)+`, `(?:a{0,2})+`. PCRE ends the loop on an empty
iteration, which matches nothing more. When every way the item matches the empty string goes
through a capturing group, that last, empty iteration sets the capture to the empty string,
and the message says so; in `(?:(a)|b?)*` the empty way goes around the group, and `$m[1]`
keeps its `a`, and a capture inside a lookaround keeps what the lookaround read:
`(?:x|(?=(a)))*` on `xa` captures `a`. An item that fails rather than match the empty string is sound:
`(?:a|(*FAIL))*` and `(?:a|(?!))*` are not reported. Where another rule already reports the
same repeat (an empty alternative, a quantified lookaround, a nested quantifier), this one
stays silent, as long as the configuration turns that other rule on: `(?:a*)*b` with
`quantifier.nested` off is reported by this rule.

**Example:**
```php
// WARNING: the capture ends empty
preg_match('/(a*)*/', 'aaa', $m);  // $m is ["aaa", ""]

// PREFERRED
preg_match('/(a*)/', 'aaa', $m);   // $m is ["aaa", "aaa"]
```

**Fix:** Make the repeated item read at least one character, or drop the outer quantifier.
The tip gives a rewrite only where one matches the same text: `a*` for `(?:a*)+`, `(?:a?)*`
or `(?:a{0,2})+`. Elsewhere, as for `(?:a|b?)+` (which is `(?:a|b)*`) or `(?:a{0})+` (which
reads nothing), the issue comes without a tip.

---

### Impossible Possessive Quantifier

**Identifier:** `regex.lint.quantifier.possessiveImpossible`

**When it triggers:** A possessive repeat (`a*+`), or a greedy one inside an atomic group
(`(?>a*)`), takes every character the atom right after it could read, and never gives one
back: `/a*+a/`, `/\d++5/` and `/a*+A/i` never match through there. Decided on atoms that
read exactly one character, under the flags in force at each, the automata comparing their
sets: `/a*+A/` matches `A`, `/\w++é/` matches `aé` in byte mode, `/\w++é/u` never does. A
bounded repeat stops at its bound (`/^a{0,3}+a$/` matches `aaaa`) and an atom that may match
nothing (`/a*+a?/`) can step aside: neither is reported. Past the eighth repeat it asks the
automata about in a pattern, the rule stays silent for the rest of it. The rule stays silent in a pattern that sets an ASCII option (`(?a)`, `(?aD)`, …) or `(?xx)`, or sets `r` beside a `(?^)`: the flags it reads do not say which of them is in force.

**Example:**
```php
// WARNING: the repeat takes every digit, the "5" included
preg_match('/\d++5/', '12345');  // 0

// PREFERRED
preg_match('/\d+5/', '12345');   // 1
```

**Fix:** Make the repeat greedy, or exclude from its set what must follow it.

---

### Lazy Quantifier Before a Delimiter

**Identifier:** `regex.lint.quantifier.lazyToClass` (off by default)

**When it triggers:** A lazy dot, `.*?` or `.+?`, is followed by one closing character and
nothing that can fail comes after it: `/".*?"/` backtracks one character at a time where
`/"[^"\n]*"/` reads the run at once, and the two write the same `$matches` on every subject.
The tip spells the class: `[^"\n]*` without `s`, `[^"]*` under `s`. The automata prove the
two patterns equivalent before the rule speaks; it stays silent when something follows the
closing character (in `/".*?"x/` on `"a"b"x` the lazy dot crosses the middle quote, the class
cannot), and under a newline convention other than `\n` (`(*CRLF)`, `(*ANYCRLF)`, `(*CR)`...),
where the dot stops at another character, and past the eighth lazy dot it asks the automata
about in a pattern. A perf rule: turn it on with
`"quantifier.lazyToClass": true` under `checks.lint.rules`.

**Example:**
```php
// INFO: backtracks at each character
preg_match('/<.*?>/', '<a><b>', $m);       // "<a>"

// PREFERRED: same match, read at once
preg_match('/<[^>\n]*>/', '<a><b>', $m);  // "<a>"
```

**Fix:** Write the negated class the tip gives.

---

### Useless Lazy Quantifier

**Identifier:** `regex.lint.quantifier.uselessLazy` (off by default)

**When it triggers:** A lazy quantifier writes the same `$matches` as its greedy form on every
subject: `/(a+?)b/` and `/(a+)b/` stop at the same `b`, since the run cannot take one, and a
fixed count such as `a{3}?` has nothing to choose. The automata prove the two patterns
equivalent before the rule speaks, from any offset (a pattern with a start anchor is asked
again with the anchor failing). It stays silent where the lazy marker matters
(`/(a+?)a/` on `aaa` captures `a`, the greedy form `aa`), under `U`, where `+?` is the greedy
one, where nothing that can fail follows a variable count (`quantifier.lazyEnd` reports that),
in a pattern that may match the empty string (after an empty match, `preg_match_all()`,
`preg_replace()` and `preg_split()` try again for a non-empty one, where the two forms part:
`/(\d+?,?)??/` finds `1` and `2` in `12`, the greedy form `12`) or that sets a
`(*LIMIT_MATCH=…)`, `(*LIMIT_DEPTH=…)` or `(*LIMIT_HEAP=…)`, which the lazy form, backtracking
more, may reach first, and past the eighth question it asks the automata about in a pattern. A style rule: turn it
on with `"quantifier.uselessLazy": true` under `checks.lint.rules`.

**Example:**
```php
// INFO: lazy for nothing
preg_match('/(\d+?)\D/', 'ab12x', $m);  // "12x", "12"

// PREFERRED: the same $matches
preg_match('/(\d+)\D/', 'ab12x', $m);   // "12x", "12"
```

**Fix:** Drop the `?`.

---

### Repeat at the Edge of a Lookaround

**Identifier:** `regex.lint.lookaround.edgeQuantifier` (off by default)

**When it triggers:** A lookahead only checks that its body can match, so the last item of
the body repeated past its minimum changes nothing: `(?=a{2,6})` asserts what `(?=a{2})`
asserts, `(?=ab*)` what `(?=a)` asserts. The same holds for the first item of a lookbehind,
`(?<=a{2,6})`. The automata prove that the two bodies hold at the same positions before the
rule speaks. It stays silent on a body holding a capture (`(?=(a{2,6}))` keeps the whole run
in `$1`), a reference, a call, a verb, a callout or `\K`, on a lookbehind whose body tests a
position (`\z`, `$`, `\b`, a lookahead...: a lookbehind of variable length reads the end of the
subject at its own position, one of fixed length the real end, and `(?<=b{1,2}\z)a` matches
`ba` where `(?<=b\z)a` does not), in a non-atomic lookahead
(`(*napla:...)`), on a possessive repeat, which the automata do not read, and past the eighth
question it asks them about in a pattern. A perf rule: turn it on with
`"lookaround.edgeQuantifier": true` under `checks.lint.rules`.

**Example:**
```php
// INFO: the x past the first is never checked
preg_match('/\d+(?=px{1,3})/', '12pxx', $m);  // "12"

// PREFERRED
preg_match('/\d+(?=px)/', '12pxx', $m);       // "12"
```

**Fix:** Write the minimum count the tip gives.

---

### Quantified Assertion

**Identifier:** `regex.lint.quantifier.assertion`

**When it triggers:** A lookaround carries a quantifier. With a minimum of zero PCRE tries
the rest of the pattern with and without the assertion, so it constrains nothing; with a
minimum of one or more, PCRE checks it once. A lookaround that captures is left alone:
`(?=(\w+))?` still sets its group when it holds.

**Example:**
```php
// WARNING: the lookahead may be skipped
preg_match('/(?=\d)?\w/', 'a');  // 1

// PREFERRED
preg_match('/(?=\d)\w/', 'a');   // 0
```

**Fix:** Remove the quantifier, or the assertion.

---

## Groups

### Redundant Non-Capturing Group

**Identifier:** `regex.lint.group.redundant`

**When it triggers:** A non-capturing group wraps a single atomic token without changing precedence.
An empty group is `regex.lint.group.empty`'s, and a group that keeps an escape apart from
the digit after it is not redundant: `(a)\1(?:0)` matches `aa0`, `(a)\10` does not, and
`(?:\N){U+41}` reads `{U+41}` as text where `\N{U+41}` is the code point under `/u`, and does
not compile without it. Neither is a group that keeps braces from reading as a quantifier, `a{(?:2)}` (it matches `a{2}`,
`a{2}` matches `aa`), nor a group a quantifier repeats around anything but one character:
without the group, `(?:\Qab\E)+` repeats the `b` alone, `(?:é)+` without `/u` the last byte
of the letter, and `(?:^)+` does not compile. `(?:a)+` is reported: `a+` is the same. A group
whose brackets decide which repeats PCRE2 makes possessive stays too: PCRE2 makes a character
repeated before an atomic group, or a possessive repeat of a group, that may match nothing
possessive, as if the group could not be skipped, so `/a+(?:b)?+a/` finds nothing in `aa`
where `/a+b?+a/` finds it, and `/(?:a)+(?:b)?+a/` finds `aa` where `/a+(?:b)?+a/` does not.
The engine reads those brackets on its way from the repeated character to the group it may
skip, no further than the first character that must be there: `(?:b)` stays in
`/a+c?(?:b)?+a/`, and is reported in `/a+x(?:b)?+a/`, as `(?:\\)` is in
`/(?:\\)?define [a-z]++ (?:,x)*+ /`. A group repeated more than once is read again for each
round, `(?:b)` in `/(?:(?>(?:b)?)a+){2}/` staying for the `a+` of the round before, and a
pattern that holds a subroutine call is read whole: there a group stays wherever a repeat
stands before such a group. A group inside a group a call runs that repeats something some
number of times, or anywhere in a pattern that calls itself whole, stays wherever it stands:
PCRE2 makes such a repeat possessive by the brackets around it, and its JIT then misses
matches, so `/(a+b)c(?1)d/` finds nothing in `abcabcabd` where `/((?:a)+b)c(?1)d/` and
`/(a+(?:b))c(?1)d/` find `abcabd`, and `/x(?R)a|a*/` finds the empty string in `xaa` where
`/x(?R)a|(?:a)*/` finds `xaa`. `(?:a)` is still reported in `/(?:a)+b(?1)(c)/`, outside the
group the call runs.

**Example:**
```php
// WARNING: the group wraps one token, `a`
preg_match('/x(?:a)y/', $input);

// PREFERRED: Remove the group
preg_match('/xay/', $input);
```

**Fix:** Remove the unnecessary group.

---

### Empty Group

**Identifier:** `regex.lint.group.empty`

**When it triggers:** A non-capturing or atomic group is empty, `(?:)` or `(?>)`: it matches
the empty string and changes nothing. An empty capturing group `()` is a placeholder that
numbers a group and is left alone, as are empty lookarounds, which have rules of their own.
So is a `(?:)` that keeps an escape apart from the digit after it, the form the library's own
printer writes: `(a)\1(?:)0` is not `(a)\10`, and `\01(?:)2` is not the newline `\012`; one
that keeps braces from a count, `a{(?:)2}`, `a{1,(?:)2}` or `a{(?:),2}` (`{,2}` is a
quantifier since PCRE2 10.43); and one a quantifier repeats: `a(?:)?` matches `a` alone,
`a?` the empty string too. An unbounded repeat of an empty group, `(?>)+`, is
`regex.lint.quantifier.emptyRepeat`'s. So is an empty group PCRE2 enters to choose which
repeats it makes possessive: `/a+(?>(?:)|b)a/` finds `aa`, where `/a+(?>|b)a/` finds nothing,
and one inside a group a call runs that repeats something, or in a pattern that calls itself
whole: with the JIT, `/(a+(?:)c)x(?1)d/` finds `acxacd` in `acxacxacd`, `/(a+c)x(?1)d/` nothing.

**Example:**
```php
// WARNING: the group does nothing
preg_match('/a(?:)b/', 'ab');  // 1

// PREFERRED
preg_match('/ab/', 'ab');      // 1
```

**Fix:** Remove the group.

---

### Capture That Never Captures Text

**Identifier:** `regex.lint.group.alwaysEmptyCapture`

**When it triggers:** A capturing group is empty or unset wherever the pattern matches: in
`/a+(a*)/` the greedy `a+` takes every `a`, so `$1` is always `""`; in `/^\w+(\d*)$/` `\w+`
takes the digits. The automata prove it: the pattern with the group's body removed writes the
same `$matches` on every subject, from offset 0 and, for a pattern with a start anchor, again
with the anchor failing, as it does where `preg_match_all()` goes on (`/^.|.(c)/s` fills `$1`
there). It stays silent on a group with nothing in it (`()` is a marker), on a group in a branch
reset (`(?|...)`), whose number another alternative may fill, in a pattern that may match the empty string (`preg_match_all()` tries again after an
empty match, where another path may fill the group), where `quantifier.lazyEnd` already says
why the group stays empty (`/^L_(.*?)/`), in a repeat of zero copies, which defines a group a call
may reach (`/(b){0}/`), on a group
a reference or a call reads, which the automata do not follow, and past the eighth question it
asks them about in a pattern.

**Example:**
```php
// WARNING: $1 is always ""
preg_match('/^(\w+)(\d*)$/', 'abc123', $m);  // ["abc123", "abc123", ""]

// PREFERRED
preg_match('/^([a-z]+)(\d*)$/', 'abc123', $m);  // ["abc123", "abc", "123"]
```

**Fix:** Remove the group, or narrow what precedes it so the group can read text.

---

### Quantified Capturing Group

**Identifier:** `regex.lint.group.quantifiedCapture`

**When it triggers:** A repeatable quantifier (`*`, `+`, `{n,}`…) sits on a capturing group: each iteration overwrites the capture, and only the last one survives. An unnamed group is reported at info severity, a named one at warning. The message names the group by its number, as PCRE counts it, or its name, and quotes it as written, on one line without the layout and the comments, thirty columns at most, so two findings on one pattern read apart: `capturing group 2 "(b)"` for `/(a)(b)+/`.

**Example:**
```php
// INFO: Quantified capturing group 1 "(a)" with "+":
//      only the last iteration's capture is retained.
preg_match('/(a)+/', 'aaa', $m);  // $m is ["aaa", "a"]

// PREFERRED: the repetition does not need to capture
preg_match('/(?:a)+/', 'aaa', $m);
```

**Fix:** Use a non-capturing group `(?:...)` for the repetition and capture the whole match, or restructure the pattern.

---

## Lookarounds

### Impossible Lookaround

**Identifier:** `regex.lint.lookaround.impossible`

**When it triggers:** A lookahead contradicts what the pattern reads after it: `(?=a)b` asks
for an `a` where the pattern reads a `b`, `(?!a)a` forbids the `a` it reads. The automata
decide it: what follows the lookahead runs through the enclosing groups and quantifiers
(`/(?:x(?=a))+b/` is reported: another `x` or the `b` comes next, never an `a`), read for a
few items. A contradiction in one alternative is reported though the pattern still matches
through another (`/(?:x(?=a)|y)b/`). The rule stays silent where it cannot read: a
backreference, a lookbehind or `\K` after the lookahead, a group a subroutine call runs from
elsewhere, a pattern past the automata's work cap or past the eighth lookahead it asks them
about, and in UTF mode a word boundary, `\w` or a
Unicode property near the lookahead. Without `/u`, a byte read caselessly has the case the target's PCRE2 gives it: under `(*UCP)` the case of the character of the same value from 10.35, the tables' before, so `/(*UCP)(?!\xC9)\xE9/i` is reported for PHP 8.0 and later and not for PHP 7.4 with PCRE2 10.34, which matches it on `\xE9`. Under another locale's tables the rule stays silent where the engine's paths read a byte apart: under macOS's `tr_TR.ISO8859-9` the JIT matches `"/(?=\xFD)i/i"` on `I` and the interpreter does not, and the interpreter alone matches `/(?![\xFDI])I/` on `I`, as PCRE2 reads a class of a byte and its other case as the byte, caseless. A repeated lookahead is
`regex.lint.quantifier.assertion`'s, and an empty negative lookahead `(?!)` fails on purpose. The rule stays silent in a pattern that sets an ASCII option (`(?a)`, `(?aD)`, …) or `(?xx)`, or sets `r` beside a `(?^)`: the flags it reads do not say which of them is in force.

**Example:**
```php
// WARNING: a digit is required where a letter is read
preg_match('/(?=\d)[a-z]+/', 'a1');  // 0

// WARNING: the "a" is forbidden, then read
preg_match('/(?!a)a/', 'aa');         // 0
```

**Fix:** Fix the lookahead or what follows it, or remove the alternative that cannot match.

---

### Lookaround Inside a Lookaround

**Identifier:** `regex.lint.lookaround.redundant` (off by default)

**When it triggers:** A positive lookahead ends a lookahead, positive or negative: nothing in
the outer lookahead reads past it, so its body written in its place asserts the same,
`(?=a(?=b))` what `(?=ab)` asserts and `(?!a(?=b))` what `(?!ab)` asserts. The same holds for
a positive lookbehind that starts a lookbehind: `(?<=(?<=a)b)` is `(?<=ab)`. Each alternative
of the outer body is read on its own (`(?=x|a(?=b))` is `(?=x|ab)`), and comments may follow the
inner lookahead. The tip keeps a group around a body that holds alternatives or sets an option,
`(?=a(?:b|c))` for `(?=a(?=b|c))`, so that neither reaches what surrounds it. The rule stays
silent on an inner negative lookaround (`(?=a(?!b))` also holds at the end of the subject, where
`(?=a[^b])` does not), on a lookahead that does not end its parent or a lookbehind that does not
start it, on a lookaround of the other direction, a quantified one, a non-atomic one on either
side (`(*napla:...)`), a body holding a verb, a callout or `\K`, and on an inner lookbehind whose
body holds alternatives, which nested in the outer body would need PCRE2 10.43 when their
lengths differ. A style rule: turn it on with `"lookaround.redundant": true` under
`checks.lint.rules`.

**Message:** `"(?=b)" ends a lookahead, which can hold its body: "(?=ab)" asserts the same.`

**Example:**
```php
// STYLE: the inner lookahead adds nothing
preg_match('/\w+(?=,(?= ))/', 'a, b', $m);  // "a"

// PREFERRED
preg_match('/\w+(?=, )/', 'a, b', $m);      // "a"
```

**Fix:** Write the tip's rewrite.

---

## Alternation

### Duplicate Alternation Branches

**Identifier:** `regex.lint.alternation.duplicateDisjunction`

**When it triggers:** The same alternative appears more than once.

**Example:**
```php
// WARNING: Duplicate branch
preg_match('/(a|a)/', $input);

// PREFERRED: Use a single literal
preg_match('/a/', $input);
```

**Fix:** Remove duplicates or use a character class.

---

### Empty Alternatives

**Identifier:** `regex.lint.alternation.empty`

**When it triggers:** An alternation contains an empty branch (e.g., trailing `|` or `||`).

**Example:**
```php
// WARNING: Empty alternative
preg_match('/a|/', $input);

// PREFERRED: Use a quantifier
preg_match('/a?/', $input);
```

**Fix:** Replace the empty alternative with a quantifier or an explicit empty group if intentional.

---

### Overlapping Alternation Branches

**Identifier:** `regex.lint.alternation.overlap`

**When it triggers:** One literal alternative is a prefix of another. Under `i` the texts are compared in one case: every letter under `/u`, and without it the letters the tables of the target's locale pair, the ASCII ones under `C`. In PHP, `"/(?:\xC9x|\xE9x)+/i"`, with raw bytes, is reported under `fr_FR.ISO8859-1`, where the Latin-1 tables pair `\xC9` with `\xE9`, and not under `C`. A byte is paired as the target's PCRE2 release reads the tables, and one they pair one way alone keeps its own case. An escape that names one character, `\x41`, `\x{E9}`, `\101`, `\o{101}`, `\N{U+E9}` or `\cA`, is literal text, as it is to the engine: `/(?:\x41x|ax)+/i` is reported as `/(?:Ax|ax)+/i` is, with the same message, `Alternation branches "Ax" and "ax" overlap.`, and `'/(?:\xC9x|\xE9x)+/i'`, written with escapes, under `fr_FR.ISO8859-1` as the raw bytes are. Each character is read in the case the options in force where it stands give it: an option setting at the start of a branch, `(?-i)`, or a group around it, `(?-i:…)`, reads the branch in its own case, so `/(?:(?-i)Ax|(?-i)ax)+/i` is not reported, and the setting holds in the branches after its own, as PCRE reads it. Two characters meet when they are the same or, where either is read caselessly, one letter in two cases: `/(?:(?i:A)x|ax)+/` is reported, `/(?:(?i:A)x|aX)+/` is not. Under `/u` an ASCII letter keeps its own case where the tables of the target's locale give it no other, as PCRE2 compares a letter written alone: under glibc's `tr_TR.UTF-8`, which gives `i` and `I` none, `/(?:i|I)+$/iu` is not reported; `k` and `s`, which Unicode pairs with the Kelvin sign and the long s, keep both cases.

**Visual Explanation:**
```
Pattern: /(a|aa)+b/
Input: "aaaaab"
The engine can split the a's as: a+a+a+a+a or aa+a+a+a, ...
Result: overlapping paths trigger heavy backtracking
```

**Example:**
```php
// VULNERABLE: Overlapping branches in repetition
preg_match('/(a|aa)+b/', $input);

// SAFER: Use atomic groups
preg_match('/(?>a|aa)+b/', $input);

// SIMPLER: Just use a+
preg_match('/a+b/', $input);  // Often equivalent
```

**Fix:** Use atomic groups or simplify the pattern.

---

### Dot or Newline Alternation

**Identifier:** `regex.lint.alternation.dotNewline`

**When it triggers:** An alternation reads `(.|\n)`, the anti-pattern for "any character including newlines". The dot already matches every character but a newline, so the branch adds nothing but a slower, two-way choice.

**Example** (real `regex lint` output):
```
      → /(.|\n)/
    WARN Alternation (.|\n) is an anti-pattern for matching any character including newlines.
         ↳ Use the "s" (PCRE_DOTALL) flag to make "." match newlines, or use [\s\S] instead of (.|\n).
```

**Fix:** Use the `s` flag so the dot crosses newlines, or write `[\s\S]`.

---

### Overlapping Character Sets

**Identifier:** `regex.lint.overlap.charset`

**When it triggers:** Alternation branches have overlapping character sets, and the alternation is repeated by an unbounded quantifier: a character both branches accept can be taken by either, on every iteration. An alternation matched once, such as `/[a-c]|[b-d]/`, is not reported, nor is one inside a lookbehind (`(?<!http:|https:)`): PCRE runs a lookaround atomically, so the loop around it never comes back to try the other branch. A loop at the very end of the pattern is still reported. Two branches that start with literal text which tells them apart, `associative-array|array` or `callable-(?:string|object)|class-string`, are not reported either: the input one branch takes, the other never does, whatever character they share at their start. The text is read through an atomic group and a script run, so `/(?:(?>ab)|ac)+/` and `/(?:(*sr:öa)|öb)+x/u` are not reported. Two texts one of which starts the other are told apart by what follows the shorter one, where both are read in their own case: in `/(?:a\d|aa)+/` the second `a` is no digit and in `/(?:a\d|a[a-z])+/` no character follows both texts, so neither is reported, while `/(?:a\w|ab)+/` is. Read caselessly, or where the bytes of what follows are not known, as those of `\d` under `/u`, the texts alone decide: `/(?:a\d|aa)+/i` and `/(?:a\d|aa)+/u` are reported. Two literal branches written alike are one branch twice, and are reported whatever their first characters tell: `/(?:é|é|)+/u`, where the empty branch keeps the engine from being asked and the first bytes say nothing past ASCII. Past ASCII the branches are compared by the characters the target's PCRE reads, not by their bytes: `/(?:ö|è)+/u` is not reported, though both start with the byte `\xC3`. Without `/u` a byte past ASCII, written as it is or as an escape (`\xE9`, `\x{E9}`, `\351` or `\o{351}`), is read as the tables of the target's locale class it, and read caselessly it meets its other case as they give it, whether the engine judges the branches or, one of them matching empty, their bytes decide: beside `\W` it overlaps under `C`, where `\W` takes every byte past ASCII, and beside `\w` or `[[:alpha:]]` under `fr_FR.ISO8859-1`, where `\xE9` is a letter; in PHP, `"/(?:\xC9|[\xC9\xCA]|)+/i"`, with raw bytes, is reported under both, and `'/(?:\xC9|[\xE9\xEA]|)+/i'`, written with escapes, under `fr_FR.ISO8859-1` and not under `C`, where `\xC9` has no other case. An ASCII letter read caselessly meets its other case as those tables give it too, where the engine's paths read every case alike: under glibc's `tr_TR.ISO-8859-9`, which pairs `i` with `\xDD` and `I` with `\xFD`, `/(?:i|\xDD)+$/i` is reported and `/(?:i|I)+$/i` is not, its branches taking no character in common there. Under `/u` an ASCII letter written alone, `i`, `\x69`, `[i]` or `[^i]`, has the case those tables give it too, as PCRE2 reads it, where a class of several characters reads Unicode's: under glibc's `tr_TR.UTF-8`, which gives `i` and `I` no other case, `/(?:[i]|I)+$/iu` is not reported, and `/(?:[ij]|I)+$/iu` and `/(?:[^i]|I)+$/iu` are. The texts branches start with, escapes included, are read character by character in the case in force where each stands, as the tables give it, so `"/(?:\xC9x\\d|\xE9x\\w)+/i"` is reported under `fr_FR.ISO8859-1`, and `/(?:a(?-i)B\d|A(?-i)b\w)+/i` is not, its second letters telling the branches apart; two branches of literal text alone that fold alike, `"/(?:\xC9|\xE9)+/i"` or `'/(?:\xC9|\xE9|)+/i'` there, are reported as overlapping branches instead. Under `(*UCP)` a byte read caselessly has the case the target's PCRE2 gives it, the character of the same value's from 10.35 and the tables' before: `/(*UCP)(?:\xC9|\xE9)+$/i` is reported for PHP 8.0 and later, not for PHP 7.4 with PCRE2 10.34, where `\xC9` matches itself alone. Where the engine's paths read a byte apart under another locale's tables, the branches meet where either path starts both with the same byte: under `el_GR.ISO8859-7` the JIT matches `"/\xF2/i"` on `\xD3` and the interpreter does not, so `"/(?:\xF2|\xD3)+/i"` is reported. A branch is read past a first item that may read nothing (`/(?:ö?è|ê)+x/u`) and past a lookaround, which looks at characters without taking them: `/^(?:(?!x)a|a)+$/` is reported, `/(?:[^$]+|(?<=\\)\$)+/`, `/^(?:>(?:(?!!)|!))+$/` and `/^(?:(?=ö)ö|è)+$/u` are not. A lookahead narrows the characters the branch starts with: a positive one to those its body starts with, a negative one to all but those its body refuses whatever follows them (`a` for `(?!a)`, `(?!ab?)` or `(?!a+)`, none for `(?!ab)`, `(?!a{2})` or `(?!a$)`). So `/\A(?:(?=b)[ab]|a)+\z/` and `/\A(?:(?!a)[ab]|a)+\z/` are not reported, `/\A(?:(?=a)[ab]|a)+\z/` and `/(?:(?!ab)a|a)+/` are. Lookaheads that differ by the way taken let through what any way does: `/\A(?:(?:(?=a)|(?=b))[ab]|a)+\z/` is reported. A lookahead the rule cannot read, or lookaheads in more than sixteen ways, leave the branch unjudged. A script run starts with what its body starts with: `/(?:(*sr:ö)|è)+/u` is not reported. Where the target's PCRE cannot be asked about a branch, as for a class holding every delimiter, the first bytes are compared, under `/u` the ASCII ones alone: a lead byte two characters share is no overlap, and a character past ASCII is known by the byte it starts with, written as it is, as an escape or in a range: `/(?:[a-z]|\x{E9}|[a-c]|)+/u` and `/(?:[a-z]|[é-ÿ]|[a-c]|)+/u` are reported as `/(?:[a-z]|é|[a-c]|)+/u` is. A branch is read through every branch of a branch reset group `(?|…)`, and under the very options of `a` that stand where it does, `(?aW)` restricting `\w` to ASCII and `(?aD)` leaving it as it is; under a pattern's own `/D` or `/S`, which those options spell the same, the branch is left unjudged. A class read under `(?xx)` drops its spaces and tabs, which `(?x)` alone, `(?-x)`, `(?^)` and PHP's `x` modifier, however many times it is written, leave in: `/(?xx)(?:[a b]|\x20|\xE9)+/` is not reported, `/(?:[a b]|\x20|\xE9)+/xx` is. The caseless restriction `(*CASELESS_RESTRICT)` sets holds until `(?-r)` lifts it: under `/iu` the Kelvin sign then matches `k` and `K`, which `[^k]` refuses, so `/(*CASELESS_RESTRICT)(?-r:\x{212A}|[^k])+/iu` is not reported. With the ReDoS analysis on, a pattern the analysis proves linear drops the issue, inline options such as `(?s)` or `(?s:…)` included; like the two rules above, the issue is also left out for a pattern listed in the ignored patterns or found trivially safe.

**Example:**
```php
// WARNING: Overlapping character classes inside a repetition
preg_match('/(?:[a-c]|[b-d])+$/', $input);

// SAFER: Use an atomic group to avoid backtracking
preg_match('/(?>[a-c]|[b-d])+$/', $input);

// IF EQUIVALENT: Merge ranges
preg_match('/[a-d]+$/', $input);
```

---

## Backreferences

### Useless Backreferences

**Identifier:** `regex.lint.backref.useless`

**When it triggers:** A backreference is used before its capturing group can be set or when the group is guaranteed to be empty.

A backreference inside a group that a subroutine call runs again is not reported: the call may run it after the group has captured, as in `/^(?:(a)|(\1))(?2)$/`. A recursive pattern (`(?R)`, `(?0)`) is not checked by this rule.

Nor is a backreference that a loop around it and its group runs after an earlier iteration has captured, where an iteration may skip the reference through another branch or an optional item: `/^(a|b\1)+$/`, the PCRE2 manual's example, matches `aba`, and `/^(?:x(a)|\1)+$/` matches `xaa`. Where every way through the loop passes the reference first, as in `/^(a\1)+$/`, no iteration ever sets the group, and the reference is reported.

**Example:**
```php
// WARNING: Backreference appears before the group closes
preg_match('/\1(a)/', $input);

// WARNING: Capturing group is always empty
preg_match('/(\b)a\1/', $input);

// PREFERRED: Move the backreference after the group
preg_match('/(a)\1/', $input);
```

**Fix:** Move the backreference after the capturing group or remove it if it adds no constraint.

---

### Undefined Backreference

**Identifier:** `regex.lint.backref.undefined`

**When it triggers:** A numbered or named backreference points to a group the pattern does not have: `\2` with a single group, `\k<name>` with no group of that name. A `\NN` of two digits or more that names no group and starts with an octal digit is an octal escape instead (`\11` is a tab), and is not reported. Validation refuses the same defect first (`regex.backref.missing_group`, `regex.backref.missing_named_group`), so `regex lint` reports the pattern as invalid; the lint id is what the linter reports on a parsed tree.

**Example:**
```php
// FAIL (validation): Backreference to non-existent group: \2.
preg_match('/(a)\2/', $input);

// PREFERRED: refer to a group that exists
preg_match('/(a)\1/', $input);
```

**Fix:** Number or name the group the reference points to, or drop the reference.

---

## Character Classes

### Redundant Character Class Elements

**Identifier:** `regex.lint.charclass.redundant`

**When it triggers:** A character class contains redundant elements or overlapping ranges.

**Example:**
```php
// WARNING: Redundant elements detected in character class.
// ↳ Redundant elements: range 'a'-'z' (overlaps 'a'-'z')
preg_match('/[a-zA-Za-z]/', $input);

// PREFERRED: Remove duplicates
preg_match('/[a-zA-Z]/', $input);

// WARNING: Redundant elements detected in character class.
// ↳ Redundant elements: range 'c'-'d' (covered by range 'a'-'f')
preg_match('/[a-fc-d]/', $input);

// PREFERRED: Use clean ranges
preg_match('/[a-f]/', $input);

// WARNING: Redundant elements detected in character class.
// ↳ Redundant elements: ranges 'a'-'m' and 'k'-'z' overlap: merge them into 'a'-'z'
preg_match('/[a-mk-z]/', $input);

// PREFERRED: One range
preg_match('/[a-z]/', $input);
```

The hint (`↳`) names what to change: a repeated range is named with the one it repeats, a range another one covers is named with the range that covers it, and two ranges that only partly overlap, which are both needed as written, come with the range to merge them into.

**Fix:** Remove duplicates or merge ranges.

---

### Duplicate Character Class Elements

**Identifier:** `regex.lint.charclass.duplicateChars`

**When it triggers:** A character class contains an element that is fully covered by another element (e.g., `\d` plus `0-9`).

**Example:**
```php
// WARNING: \d already includes 0-9
preg_match('/[\d0-9]/', $input);

// WARNING: \w already includes A-Z
preg_match('/[\wA-Z]/', $input);

// PREFERRED: Remove the redundant element
preg_match('/[\d]/', $input);
preg_match('/[\w]/', $input);
```

**Fix:** Remove the redundant character class element.

---

### Useless Character Range

**Identifier:** `regex.lint.range.useless`

**When it triggers:** A range spans one character, as `[a-a]`: the author meant a character or a wider range. A range of two consecutive characters, `[a-b]` or `[0-1]`, is correct as written and the list `[ab]` saves one character only, so it is not reported: a generated Unicode table holds them by the thousand.

**Example:**
```php
// WARNING: Range only spans one character
preg_match('/[a-a]/', $input);

// PREFERRED: Write the character alone
preg_match('/[a]/', $input);

// Left alone: two consecutive characters are correct as written
preg_match('/[a-b]/', $input);
```

**Fix:** Write the character alone, or widen the range to what was meant.

---

### Suspicious ASCII Ranges

**Identifier:** `regex.lint.charclass.suspiciousRange`

**When it triggers:** A character range spans the ASCII gap between `Z` and `a` (e.g. `[A-z]`), which includes `[ \ ] ^ _ `.

**Example:**
```php
// WARNING: Includes non-letters between Z and a
preg_match('/[A-z]/', $input);

// PREFERRED: Use two ranges
preg_match('/[A-Za-z]/', $input);
```

**Fix:** Split into `[A-Z]` and `[a-z]`, or combine as `[A-Za-z]`.

---

### Alternation-like Character Classes

**Identifier:** `regex.lint.charclass.suspiciousPipe`

**When it triggers:** A character class contains `|` alongside many letters, suggesting an alternation typo.

**Example:**
```php
// WARNING: | is literal inside []
preg_match('/[error|failure]/', $input);

// PREFERRED: Use alternation
preg_match('/(error|failure)/', $input);
```

**Fix:** Replace the class with an alternation group when you intend multi-character words.

---

### Literal Metacharacter in a Character Class

**Identifier:** `regex.lint.charclass.literalMetachar`

**When it triggers:** A small character class holds a shorthand (`\w`, `\d`, `\s` or their negations) next to an unescaped `*`, `+` or `?`. Inside `[...]` these are literal characters, so `[\w*]` matches a word character or a `*`: the quantifier was likely meant outside the class. It is not reported when the metacharacter is written with a backslash, as `\*` in `[\w\*]`, which says the literal is meant, in a negated class such as `[^\s+]`, or in a class that lists three elements or more besides it, such as `[\w+.-]`, which builds a set on purpose.

**Example:**
```php
// WARNING: "*" is a literal character inside a character class, not a quantifier
preg_match('/^[\w*]$/', '*');    // 1: the class matches a "*"

// PREFERRED: Quantify the shorthand
preg_match('/^\w*$/', '*');      // 0

// PREFERRED: Escape the literal with a backslash when it is meant
preg_match('/^[\w\*]$/', '*');   // 1, not reported
```

**Fix:** Move the quantifier out of the class, or escape the character with a backslash when the literal is meant.

---

### Backreference Written Inside a Character Class

**Identifier:** `regex.lint.charclass.backrefAsOctal`

**When it triggers:** A class holds `\1`-`\9` while a group of that number exists. Inside a class the escape is octal, not a backreference: `[\1]` is the byte `\x01`. Without a group of that number the escape is plain octal and nothing is reported.

**Example** (real `regex lint` output):
```
      → /(a)[\1]/
    WARN Suspicious \1 inside character class: this is octal (\x01), not a backreference to group 1.
         ↳ Backreferences do not work inside character classes. If you intended to match the same text as group 1, move
           the check outside the class.
```

**Fix:** Match the same text as the group outside the class (`(a)\1`), or write the byte you mean (`[\x01]`).

---

### Single-Character Class

**Identifier:** `regex.lint.charclass.single` (off by default)

**When it triggers:** A class holds one character, `[a]`, which reads the same without the
class. A style rule: turn it on with `"charclass.single": true` under `checks.lint.rules`.
A class holding one metacharacter (`[.]`, `[*]`, and under `x`, `[#]` or any white space `x`
skips: a space, tab, line feed, vertical tab, form feed, carriage return or next line, and
under `/u` U+200E, U+200F, U+2028 and U+2029) is the clearer escape and is left alone, as are a negated class `[^a]`, the delimiter `[\/]`, an escape a
class reads otherwise (`[\b]` is a backspace, `[\1]` an octal escape where `\1` is a
reference), a multibyte character without `/u`, whose bytes `[é]` reads one at a time, and a
character that would join the text around the class: `\1[0]` is not `\10`, `a{[2]}` not
`a{2}`. The message quotes the class as written, `[\Qa\E]`. `[aa]` is
`regex.lint.charclass.redundant`'s.

**Example:**
```php
// INFO: the class adds nothing
preg_match('/x[a]y/', 'xay');  // 1

// PREFERRED
preg_match('/xay/', 'xay');    // 1
```

**Fix:** Write the character alone.

---

## Escapes

### Suspicious Escapes

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

## Literals

### Multiple Spaces

**Identifier:** `regex.lint.literal.multipleSpaces` (off by default)

**When it triggers:** Two or more literal spaces in a row are hard to count; ` {n}` says how
many. A style rule: turn it on with `"literal.multipleSpaces": true` under
`checks.lint.rules`. Only spaces written bare are counted: under `x` or `xx`, or after
`(?x)`, they are not literal, and quoted (`\Q  \E`), escaped or class spaces are not bare. A
quantifier on the last space enters the count: the tip for `/a  +/` is ` {2,}`.

**Example:**
```php
// INFO: how many spaces?
preg_match('/a   b/', 'a   b');   // 1

// PREFERRED
preg_match('/a {3}b/', 'a   b');  // 1
```

**Fix:** Write the space once with its count.

---

### Invisible Character Written Raw

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

---

## Bytes Without /u

Without `/u`, PCRE reads the pattern and the subject as bytes. A character written in
UTF-8 that takes several bytes is several items to it, and three places get it wrong: a
multibyte character in a class, a quantifier after one, and a Unicode property such as
`\p{L}` (`regex.lint.unicode.propertyWithoutU`), which then covers only the first 256
code points.

The three rules report at error severity: the pattern compiles, but does not do what it
says, so `regex lint` exits with 1. Turn one off under `checks.lint.rules` in `regex.json`
(`"unicode.multibyteInClassWithoutU": false`) when bytes are really meant. Two further
rules watch byte mode: the braced hex escape PCRE2 refuses outright, and — off by
default — the ASCII-only shorthands.

### Multibyte Character in a Class

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

### Quantifier After a Multibyte Character

**Identifier:** `regex.lint.unicode.quantifiedMultibyteWithoutU`

**When it triggers:** A quantifier follows a character of two bytes or more, and the
pattern has neither `/u` nor `(*UTF)`. The quantifier repeats the last byte only.

**Example:**
```php
// ERROR: + repeats \xA9, the last byte of "é"
preg_match('/^é+$/', 'éé');      // 0
preg_match('/^é+$/', "é\xA9");   // 1

// PREFERRED
preg_match('/^é+$/u', 'éé');     // 1
preg_match('/^(?:é)+$/', 'éé');  // 1, still byte mode
```

**Fix:** Add `/u`, or group the character so the quantifier takes all of it.

---

### Unicode Property Without /u

**Identifier:** `regex.lint.unicode.propertyWithoutU`

**When it triggers:** A Unicode property (`\p{…}` or `\P{…}`) is used, and the pattern has
neither `/u` nor `(*UTF)`. The property then reads one byte at a time, as a code point
below 256, so it covers only the first 256 code points.

**Example:**
```php
// ERROR: "é" is the bytes \xC3 \xA9, and \xA9 is no letter
preg_match('/^\p{L}+$/', 'é');   // 0

// PREFERRED
preg_match('/^\p{L}+$/u', 'é');  // 1
```

**Fix:** Add `/u`.

---

### Braced Hex Escape Without /u

**Identifier:** `regex.lint.unicode.bracedHexWithoutU`

**When it triggers:** A braced escape `\x{...}` names a code point above U+FF, and the pattern has neither `/u` nor `(*UTF)`. Unlike the three rules above, the pattern does not even compile in byte mode — PCRE2 refuses it — so validation reports it first, and the lint id names the same defect on a parsed tree, at error severity.

**Example:**
```php
// FAIL (validation): Invalid code point "\x{100}":
//      without the "u" flag, a character is at most \xFF.
preg_match('/\x{100}/', $input);

// PREFERRED
preg_match('/\x{100}/u', $input);
```

**Fix:** Add `/u`.

---

### Unicode Shorthand Without /u

**Identifier:** `regex.lint.unicode.shorthandWithoutU` (off by default)

**When it triggers:** A `\w`, `\d`, `\s` or one of their negations runs without `/u`: each matches ASCII only, so `é` is no `\w` match. A style rule: turn it on with `"unicode.shorthandWithoutU": true` under `checks.lint.rules`, or `--enable-rule=unicode.shorthandWithoutU` on the command line.

**Example** (real `regex lint` output):
```
      → /\w+/
    INFO Shorthand "\w" matches only ASCII without /u flag.
         ↳ Add /u flag for Unicode support, or use \p{L} for letters.
```

```php
preg_match('/^\w+$/', 'é');    // 0: é is no ASCII word character
preg_match('/^\w+$/u', 'é');   // 1
```

**Fix:** Add `/u`, or write the property you mean (`\p{L}`).

---

## Inline Flags

### Inline Flag Redundant

**Identifier:** `regex.lint.flag.redundant`

**When it triggers:** An inline flag sets/unsets a modifier already in the desired state. `(?xx)` is an option of its own, under which a class drops its spaces and tabs: it is redundant where `(?xx)` holds already, not under the `x` modifier, which is `x` however many times it is written, and `(?x)` after `(?xx)` takes it off, so `/(?xx)(?x)[a b]/` and `/(?xx)[a b]/x` are not reported.

**Example:**
```php
// WARNING: Redundant inline flag
preg_match('/(?i)foo/i', $input);  // Global i already set

// PREFERRED: Remove redundancy
preg_match('/foo/i', $input);
```

---

### Inline Flag Override

**Identifier:** `regex.lint.flag.override`

**When it triggers:** An inline flag explicitly unsets a global modifier.

**Example:**
```php
// WARNING: Unset global flag
preg_match('/(?-i:foo)/i', $input);

// CONSIDER: Scope the flag instead
preg_match('/(?i:foo)bar/', $input);
```

---

## Pattern Complexity

### Complexity Threshold

**Identifier:** `regex.lint.complexity`

**When it triggers:** The analysis' complexity score for the pattern — the same number `Regex::validate()` returns as `complexityScore` — reaches the warning threshold, 50 by default.

**Message:** `Pattern is complex (score: 74).` — here for `/((a|b)+c?){3}(x|y)*[0-9]{2,4}(p|q|r)+\s*z{5,}/`:

```php
use PHPRegex\Toolkit\Regex;

$result = Regex::create()->validate('/((a|b)+c?){3}(x|y)*[0-9]{2,4}(p|q|r)+\s*z{5,}/');
echo $result->complexityScore;  // 74
```

It is off by default. `regex lint` reports it once `regex.json` turns it on, with the threshold you choose:

```json
{
  "checks": {
    "complexity": {
      "enabled": true,
      "threshold": 50
    }
  }
}
```
{: data-file="regex.json" }

The PHPStan rule reports it under the same identifier once its `complexity` check is on; `rules.neon` leaves it off:

```neon
parameters:
    phpRegex:
        checks:
            complexity:
                enabled: true
                threshold: 50
```
{: data-file="phpstan.neon" }

The Symfony and Laravel lint commands leave it out.

**Fix:** Split the pattern, or factor its repeated parts out.

---

## Sources

### Unreadable Source File

**Identifier:** `regex.lint.source.unreadable` (error)

**When it triggers:** `regex lint` could not read a PHP file it was asked to scan: the file
cannot be opened, or holding it and its tokens would not fit in `memory_limit`. Its
patterns are not linted, so the file is reported instead of being left out silently, at
error severity: the run fails.

**Fix:** Do what the message names: make the file readable, raise `memory_limit`, or
exclude the file (`--exclude`, or `exclude` in `regex.json`) when it holds no pattern you
write, as a large generated file.

---

### Pattern Built at Run Time

**Identifier:** `regex.lint.source.dynamic` (info)

**When it triggers:** Under `regex lint --report-dynamic`, or `"reportDynamic": true` in
`regex.json`, on a pattern argument of which the file proves nothing: a parameter, the result
of a method, a property the lint does not read, or a text that does not start with a literal,
whose delimiter is then unknown. No rule ran on it: the issue says so, at the argument,
instead of leaving the call out silently. Without the option the run only counts these
arguments, as the `dynamic` figure of its `Not verifiable` line.

**Message:** `The pattern is built at run time: it is not verified.`

**Example:**
```php
public function matches(string $pattern, string $text): bool
{
    return 1 === preg_match($pattern, $text);  // reported at $pattern
}
```

It is an info and never fails a run. It is no lint rule: `checks.lint.rules`, `--enable-rule`
and `--disable-rule` do not name it, and `--report-dynamic` turns it on with the two below.
In the JSON report its result has no `pattern`; the argument is under `expression`, as the
file writes it.

**Fix:** None is needed when the pattern is meant to come from elsewhere: validate it where
it enters the program, with `Regex::create()->validate($pattern)` for a pattern a user or a
configuration gives. A marker on the line silences the issue,
`// @regex-ignore regex.lint.source.dynamic`. When the text is in the file after all,
[the CLI guide](../guides/cli.md#patterns-held-in-a-constant-a-variable-or-a-property) lists
what the lint follows and what it does not.

---

### Pattern Around a Part Built at Run Time

**Identifier:** `regex.lint.source.dynamicPart` (info)

**When it triggers:** Under `regex lint --report-dynamic`, on a pattern argument that starts
with a literal and holds a part the file does not prove, other than a `preg_quote()` call. The
part decides whether the pattern compiles and what it matches (`.*`, `a|`, an unclosed group),
so no rule ran on the rest either. Without the option the run counts these arguments as the
`around a dynamic part` figure of its `Not verifiable` line.

**Message:** `The pattern holds a part built at run time: it is not verified.`

**Example:**
```php
public function startsWith(string $prefix, string $text): bool
{
    return 1 === preg_match('/^' . $prefix . '\d+$/', $text);  // reported at '/^'
}
```

**Fix:** When the part is a text to match as it is, pass it through `preg_quote()` with the
delimiter of the pattern. When it is a fragment of a pattern on purpose, nothing is to fix:
validate the whole pattern where it is built, or silence the line with
`// @regex-ignore regex.lint.source.dynamicPart`.

---

### Pattern Around a Quoted Text

**Identifier:** `regex.lint.source.quotedPart` (info)

**When it triggers:** Under `regex lint --report-dynamic`, on a pattern argument that starts
with a literal and whose every part the file does not prove is a `preg_quote()` call: written
in the pattern, assigned to a variable first, or given to `sprintf()`. The text is known at run
time only, so the pattern is not linted. Without the option the run counts these arguments as
the `around preg_quote()` figure of its `Not verifiable` line.

**Message:** `The pattern holds a preg_quote() text: it is not verified.`

**Example:**
```php
public function hasWord(string $word, string $text): bool
{
    return 1 === preg_match('/\b' . preg_quote($word, '/') . '\b/i', $text);  // reported at '/\b'
}
```

**Fix:** Nothing is wrong with the call. Silence the line with
`// @regex-ignore regex.lint.source.quotedPart` when the list should stay short.

---

## Security (ReDoS)

### Catastrophic Backtracking

**Identifier:** `regex.lint.redos`; `regex.redos` in PHPStan, whatever the severity; the message names it

**When it triggers:** The ReDoS analyzer proves how one match attempt grows with the input, by following PCRE's backtracking order, and hands back the input that triggers it. Patterns outside that model (backreferences, conditionals, recursion, an analysis over budget) are judged by heuristics, and the report says which of the two decided. The message prefix names the class: `Exponential backtracking (ReDoS)`, `Polynomial backtracking (ReDoS)` or `Potential backtracking (ReDoS)`.

**Risk Levels:**

| Level      | Proven class                     | Action Required      |
|------------|----------------------------------|----------------------|
| `critical` | exponential                      | Refactor immediately |
| `high`     | polynomial, degree 3 or more     | Consider refactoring |
| `medium`   | polynomial, degree 2 (quadratic) | Monitor and plan fix |
| `low`      | heuristic finding only           | Accept with logging  |

See the [ReDoS guide](../guides/redos.md) for the guarantee and its limits.

**Example:**
```php
// VULNERABLE: Exponential backtracking
preg_match('/(a+)+$/', $input);  // CRITICAL

// SAFER: Atomic group
preg_match('/(?>a+)+$/', $input);  // SAFE

// SAFER: Possessive quantifier
preg_match('/(a++)+$/', $input);  // SAFE
```

**Fix:** Make the ambiguous part atomic or possessive, or refactor.

**Read more:**
- [OWASP: Regular Expression Denial of Service](https://community.owasp.org/attacks/Regular_expression_Denial_of_Service_-_ReDoS)

---

### Quadratic Search

**Identifier:** `regex.lint.redos.search`; `regex.redos.search` in PHPStan, with the message `Quadratic search (ReDoS): <pattern>`

**When it triggers:** One match attempt is proven linear, but the search is not anchored: `preg_match()` may start an attempt at each later position of the subject, and on a run of characters each attempt reads to the end of the run before it fails. On the run repeated n times then a breaking character, PCRE2's interpreter takes a number of steps quadratic in n; `preg_match_all()`, `preg_replace()` and `preg_split()` retry the same way. An anchored alternative does not protect the others: the trim regex `/^\s+|\s+$/` is quadratic on `"!" . " " x n . "!"`. Nor does a match after the run, which the search tries last: `/\s*$/` is quadratic on `" " x n . "!"` and `/(?:\w+\.)?x/` on `"0" x n . "x"`, though `preg_match()` returns 1. `pcre.backtrack_limit` does not stop it: the limit counts each attempt apart, and trips only when one attempt exceeds it. The JIT may avoid it for some patterns, not for all (see the [ReDoS guide](../guides/redos.md#the-cost-of-an-unanchored-search)).

It also triggers on a pattern whose `preg_match()` search stops at an empty match inside a run, when the call is one that looks for every match. Those calls are `preg_match_all()`, `preg_replace()`, `preg_filter()`, `preg_replace_callback()`, `preg_replace_callback_array()` and `preg_split()`, the `Safe\`, composer/pcre, nette/utils, spatie/regex and Laravel `Str` forms of these, and a `RegexIterator` in the `ALL_MATCHES`, `SPLIT` or `REPLACE` mode, when no literal limit bounds the call ([the list](../guides/redos.md#the-calls-that-run-the-loop)). After an empty match PHP tries the same offset again for a non-empty one, and on the run each such retry reads to its end: `/(?:a+\d)?/` on `"a" x n`. The message then starts with `Quadratic search after an empty match` and names the function. The JIT does not avoid it, as PHP runs that retry in PCRE2's interpreter whatever `pcre.jit` (see the [ReDoS guide](../guides/redos.md#the-search-of-the-functions-that-look-for-every-match)). A pattern whose `preg_match()` search stops at a short match inside the run, found after a path that reads to the end of the run failed, triggers it the same way at those calls: `/a*b|a/` on `"a" x n`, where each call of the loop starts where the last match ended. The message then starts with `Quadratic search over short matches`, and the JIT may avoid it for some patterns. At a `preg_match()` call the same patterns are linear, and nothing is reported.

It runs under the ReDoS check, with no switch of its own. Its severity is that of a proven quadratic attempt, `medium`, so the default `high` threshold hides it: `--redos-threshold=medium` shows it. It is a warning in every mode, and `--disable-rule=regex.lint.redos.search` or `"redos.search": false` in the `checks.lint.rules` of `regex.json` turns it off.

**Example:**
```php
// Quadratic search: on " " x n . "!" each attempt reads the rest of the run
preg_match('/\s+$/', $input);

// Linear, and the same answer: the characters \s matches without /u
rtrim($input, " \t\n\v\f\r") !== $input;
```

The two agree on the characters `\s` matches without `/u` in the default C locale. PHP builds PCRE2's character tables from `LC_CTYPE` when a script sets another locale, which may change what `\s` matches; under `/u`, `\s` also matches Unicode spaces such as U+00A0 and U+2028, which this `rtrim()` keeps.

**Fix:** Anchor the pattern when every match starts at a known place (`^`, `\A`, `\G` or the `A` modifier), or bound the length of the run, or do the work without a regex (`rtrim()` for trailing whitespace). After an empty match, make every match read at least one character, or bound the run; after a short match, bound the run. Anchoring helps there only with `^` or `\A`, since each search of the loop starts where the last match ended.

---

## Advanced Syntax

The fixes above lean on three constructs this reference does not teach from scratch:
possessive quantifiers (`a++`), atomic groups (`(?>a+)`) and lookarounds
(`(?=…)`, `(?<=…)`). The tutorial covers each in depth —
[Quantifiers and Greediness](../tutorial/04-quantifiers.md) and
[Performance and ReDoS](../tutorial/08-performance-redos.md) for the first two,
[Lookarounds and Assertions](../tutorial/06-lookarounds.md) for the third, and
[Backreferences, Subroutines, and Recursion](../tutorial/07-backreferences-recursion.md)
for the recursive `(?R)` calls some fixes keep working.

---

## Compatibility & Limitations

### Supported PHP Versions

- **PHP 8.1** and above
- Uses readonly properties and enum features
- Needs the `mbstring` extension. `intl` is optional: it only lets a lint message name the character a `\N{name}` escape spells, an escape PCRE refuses anyway; every verdict and every other lint result is the same without it
- Reads digits, letters and spaces in ASCII, as PCRE does: the locale of the process changes no verdict

### Target Engine

- **PCRE2** via PHP's `preg_*` functions, judged for PHP 7.3 and later, the
  PHP versions on PCRE2; PHP 7.2 and earlier ship PCRE1, which the lint
  never judges: a `composer.json` range that also allows 7.3 or later is
  judged from there, with a note, and any other target below 7.3 is refused

### Known Differences from PCRE1

| Feature           | PCRE2        | Note                  |
|-------------------|--------------|-----------------------|
| `\p{...}` Unicode | Supported    |                       |
| Branch reset `(?\|...)` | Supported    | |

---

### Meaning That Changes Across PHP Versions

**Identifier:** `regex.lint.compat.meaningChanges`

**When it triggers:** `regex lint` judges a project over the PHP range of its `composer.json`.
A pattern every version of the range accepts may still be parsed into another meaning by a
later one: `/a{,3}/` is the text `a{,3}` under PCRE2 before 10.43 (PHP 8.2 and 8.3) and `a`
zero to three times from 10.43 (PHP 8.4); `/a{ 2 }/` likewise. The warning names the first
version that reads the pattern otherwise, under `target` in the JSON report.
`"compat.meaningChanges": false` in `checks.lint.rules` turns it off.

The same warning names a change the release table knows that keeps the parse and moves what
the engine matches, with the witness each real engine gives. It is reported, never rewritten:

| from | the same tree, matched otherwise | witness |
|---|---|---|
| PCRE2 10.34 (PHP 7.4.6) | a possessive `{1}+` on a group makes it atomic; before, it was ignored | `/(a\|ab){1}+c/` finds `abc` in `"abc"` on 10.33, nothing from 10.34 |
| PCRE2 10.35 (PHP 8.0) | a group that holds a reference to itself may be backtracked into; before, it was atomic | `/^(a\|ab\|\1x)+b$/` finds `abb` in `"abb"` from 10.35 only |
| PCRE2 10.35 (PHP 8.0) | a repeated assertion runs as many times as its count asks; before, once | `/^(?=(\1?a)){2}/` captures `aa` in `"aa"` from 10.35, `a` before |
| PCRE2 10.35 (PHP 8.0) | `(*UCP)` without UTF folds the case of characters past ASCII | `/(*UCP)\xC9/i` matches `"\xE9"` from 10.35 only |
| PCRE2 10.40 (PHP 8.2) | a bare script name, `\p{Katakana}`, means its script extensions | U+30FB matches `/\p{Katakana}/u` from PHP 8.2 only |

The message names the first version of the range that matches the pattern the new way, and the
hint the spelling that keeps one reading on every PHP where one exists: `{1}` or `(?>...)` for
the possessive repeat, an atomic group around the group that refers to itself, one pass of the
assertion, both cases spelled in a class. A bare script name has none: `\p{sc:Katakana}` keeps
the script alone from PHP 8.2 on, and PHP 8.1 refuses it.

**Message:** `From PHP 8.4 (PCRE2 10.44) the pattern parses differently: the same text means something else there.`

**Fix:** Write the part that differs in a form every version reads alike: `a{0,3}` for the
quantifier, `a\{,3}` for the text.

---

## Diagnostics Catalog

Every code an exception or a failed validation carries is listed, with its meaning, in
[Diagnostics: Error Codes](diagnostics.md#error-codes). The lint rule ids below
are a separate vocabulary: they name advice, not a refused pattern.

---

## Quick Reference Table

Every lint rule reports at warning severity unless the table says otherwise. The rules
SonarPHP users know are mapped to these ids in [SonarPHP Regex Rules](sonar.md). A rule of
error severity fails `regex lint` (exit code 1); a warning is printed and leaves the code
at 0; an info, `style` included, is printed under an `INFO` badge and leaves the code at 0.

| Category    | Rule ID                                                                                   | Severity | Quick Fix                         |
|-------------|-------------------------------------------------------------------------------------------|----------|-----------------------------------|
| Flags       | `regex.lint.flag.useless.s`, `.m`, `.i`, `.D`, `.x`                                       | warning  | Remove the unused flag            |
| Anchors     | `regex.lint.anchor.impossible.start`, `.end`, `.boundary`                                 | warning  | Move the anchor                   |
| Anchors     | `regex.lint.anchor.alternationPrecedence`                                                 | warning  | Group the alternatives            |
| Anchors     | `regex.lint.anchor.dollarNewline` (off by default)                                        | info     | Write `\z` or add `D`             |
| Quantifiers | `regex.lint.quantifier.nested`, `regex.lint.dotstar.nested`                               | warning  | Use atomic groups                 |
| Quantifiers | `regex.lint.quantifier.useless`, `.zero`, `.concatenation`, `.lazyEnd`, `.assertion`      | warning  | Simplify the quantifier           |
| Quantifiers | `regex.lint.quantifier.emptyRepeat`, `.possessiveImpossible`                              | warning  | Fix the repeated item             |
| Quantifiers | `regex.lint.quantifier.lazyToClass` (off by default)                                      | perf     | Use a negated class               |
| Quantifiers | `regex.lint.quantifier.uselessLazy` (off by default)                                      | style    | Drop the `?`                      |
| Quantifiers | `regex.lint.lookaround.edgeQuantifier` (off by default)                                   | perf     | Keep the minimum count            |
| Groups      | `regex.lint.group.redundant`, `.empty`                                                    | warning  | Remove the group                  |
| Groups      | `regex.lint.group.alwaysEmptyCapture`                                                     | warning  | Narrow what precedes the group    |
| Lookarounds | `regex.lint.lookaround.impossible`                                                        | warning  | Fix the lookahead                 |
| Lookarounds | `regex.lint.lookaround.redundant` (off by default)                                        | style    | Write the inner body inline       |
| Groups      | `regex.lint.group.quantifiedCapture`                                                      | info for an unnamed group, warning for a named one | Repeat a non-capturing group |
| Alternation | `regex.lint.alternation.duplicateDisjunction`, `.empty`, `.overlap`, `.dotNewline`        | warning  | Simplify or use atomic            |
| Alternation | `regex.lint.overlap.charset`                                                              | warning  | Use atomic or merge the sets      |
| Backrefs    | `regex.lint.backref.useless`, `.undefined`                                                | warning  | Move or remove the backreference  |
| Character   | `regex.lint.charclass.redundant`, `.duplicateChars`, `.suspiciousRange`, `.suspiciousPipe`, `.literalMetachar`, `.backrefAsOctal` | warning | Clean up the class |
| Character   | `regex.lint.charclass.single` (off by default)                                            | style    | Write the character alone         |
| Ranges      | `regex.lint.range.useless`                                                                | warning  | Write the character alone         |
| Escapes     | `regex.lint.escape.suspicious`                                                            | warning  | Fix the escape sequence           |
| Literals    | `regex.lint.literal.multipleSpaces` (off by default)                                      | style    | Count the spaces                  |
| Literals    | `regex.lint.literal.invisible`                                                            | warning  | Write the escape                  |
| Unicode     | `regex.lint.unicode.multibyteInClassWithoutU`, `.quantifiedMultibyteWithoutU`, `.propertyWithoutU`, `.bracedHexWithoutU` | error | Add the `/u` flag |
| Unicode     | `regex.lint.unicode.shorthandWithoutU` (off by default)                                   | style    | Add the `/u` flag                 |
| Inline      | `regex.lint.flag.redundant`, `.override`                                                  | warning  | Remove or scope the inline flag   |
| Complexity  | `regex.lint.complexity`                                                                   | warning  | Split the pattern                 |
| ReDoS       | `regex.lint.redos` (`regex.redos` in PHPStan)                                             | warning; error when `--redos-mode=confirmed` reproduces a verdict at `high` or above or proves one it cannot replay | Use possessive quantifiers |
| Targets     | `regex.lint.compat.meaningChanges`                                                        | warning  | Write it alike for every PHP      |
| ReDoS       | `regex.lint.redos.search` (`regex.redos.search` in PHPStan)                               | warning in every mode; ReDoS severity `medium`, shown from `--redos-threshold=medium` | Anchor the pattern or bound the run |
| Sources     | `regex.lint.source.unreadable`                                                       | error    | A source file could not be read, so its patterns were not linted — fix what the message names: raise `memory_limit`, or exclude the file |
| Sources     | `regex.lint.source.dynamic` (under `--report-dynamic`)                               | info     | The pattern comes from elsewhere: validate it where it enters the program |
| Sources     | `regex.lint.source.dynamicPart` (under `--report-dynamic`)                           | info     | Quote a text with `preg_quote()`, or validate the pattern where it is built |
| Sources     | `regex.lint.source.quotedPart` (under `--report-dynamic`)                            | info     | Nothing to fix: the pattern holds a text known at run time only |

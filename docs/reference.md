# RegexParser Rule Reference

This comprehensive reference documents every diagnostic, lint rule, and optimization that RegexParser produces. It serves as the authoritative guide for understanding what RegexParser checks and how to fix issues.

## Table of Contents

| Section                                      | Description                       |
|----------------------------------------------|-----------------------------------|
| [Validation Layers](#validation-layers)      | How RegexParser analyzes patterns |
| [Flags](#flags)                              | Flag-related diagnostics          |
| [Anchors](#anchors)                          | Anchor positioning issues         |
| [Quantifiers](#quantifiers)                  | Quantifier-related patterns       |
| [Groups](#groups)                            | Group-related diagnostics         |
| [Alternation](#alternation)                  | Alternation patterns              |
| [Character Classes](#character-classes)      | Character class issues            |
| [Escapes](#escapes)                          | Escape sequence problems          |
| [Inline Flags](#inline-flags)                | Inline flag diagnostics           |
| [ReDoS Security](#security-redos)            | Catastrophic backtracking         |
| [Advanced Syntax](#advanced-syntax)          | Optimizations and assertions      |
| [Compatibility](#compatibility--limitations) | PHP and PCRE2 support             |
| [Diagnostics Catalog](#diagnostics-catalog)  | Error code reference     |

---

## Validation Layers

RegexParser validates patterns through four layers, each catching different types of issues:

```
Pattern literal
  -> Parse (Lexer + Parser): syntax errors, malformed patterns
  -> Semantic validation: PCRE rules, group and reference checks
  -> Runtime validation (optional): preg_match compilation
  -> Linting & analysis: ReDoS, performance, best practices
```

Examples by layer:

- Parse: unbalanced brackets, invalid escapes, missing delimiters
- Semantic: unbounded lookbehinds, invalid backreferences, duplicate group names
- Runtime: engine-specific compilation errors
- Linting & analysis: ReDoS hotspots, risky quantifiers, optimization hints

### PCRE2 Compatibility Contract

RegexParser targets **PHP's PCRE2 engine** (`preg_*`). Key behaviors that may surprise users:

| Behavior           | Description               | Example                         |
|--------------------|---------------------------|---------------------------------|
| Forward references | Backreference before capture compiles, but the group is unset and the match will fail until it has captured | `/\1(a)/` compiles, but `\1` fails |
| Branch reset       | `(?|...)` changes capture numbering | `\2` can be invalid in branches |
| `\g{0}`            | Invalid in PHP            | Use `\g<0>` or `(?R)`           |
| Lookbehind         | Must have bounded length  | `(?<=a+)` is invalid            |

---

## Flags

### Useless Flag 's' (DOTALL)

**Identifier:** `regex.lint.flag.useless.s`

**When it triggers:** The pattern sets the `s` (DotAll) modifier but contains no dot tokens (`.`). The `s` flag only affects `.` behavior.

**Visual Explanation:**
```
/^\d+$/s
  -> no dot in pattern
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

**When it triggers:** The pattern sets the `m` (multiline) modifier but contains no start/end anchors (`^` or `$`).

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

**When it triggers:** The pattern sets the `i` (case-insensitive) modifier but contains no case-sensitive characters.

**Example:**
```php
// Warning: No letters to justify case-insensitive matching
preg_match('/^\d{4}-\d{2}-\d{2}$/i', $date);

// Preferred: Remove unnecessary flag
preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
```

**Fix:** Drop the flag when matching digits/symbols.

---

## Anchors

### Anchor Conflicts

**Identifier:** `regex.lint.anchor.impossible.start`, `regex.lint.anchor.impossible.end`

**When it triggers:**
- `^` appears after consuming tokens (without `m` flag) — cannot match start
- `$` appears before consuming tokens — asserts end too early

**Visual Explanation:**
```
Valid:
  /^abc/  -> anchor before content
  /abc$/  -> anchor after content
Invalid:
  /a^bc/  -> ^ after consuming 'a'
  /$abc/  -> $ before consuming anything
```

**Fix:** Move anchors to the correct position.

---

## Quantifiers

### Nested Quantifiers (ReDoS Risk)

**Identifier:** `regex.lint.quantifier.nested`

**When it triggers:** A variable quantifier wraps another variable quantifier, creating catastrophic backtracking potential.

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

**Fix:** Refactor to be deterministic, or use atomic groups/possessive quantifiers.

**Read more:**
- [OWASP: ReDoS](https://owasp.org/www-community/attacks/Regular_expression_Denial_of_Service_-_ReDoS)

---

### Dot-Star in Quantifier

**Identifier:** `regex.lint.dotstar.nested`

**When it triggers:** An unbounded quantifier wraps a dot-star, which can cause extreme backtracking.

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

**Fix:** Make it atomic/possessive or replace `.*` with a specific class.

---

### Useless Quantifier

**Identifier:** `regex.lint.quantifier.useless`

**When it triggers:** A quantifier that matches exactly once (e.g., `{1}` or `{1,1}`).

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

**When it triggers:** A quantifier with a maximum of zero (e.g., `{0}` or `{0,0}`), which makes the element disappear.

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

**Example:**
```php
// WARNING: \d is a subset of \w
preg_match('/\d+\w+/', $input);

// PREFERRED: Keep the superset quantifier unbounded
preg_match('/\d\w+/', $input);
```

**Fix:** Tighten the smaller quantifier to its minimum or remove redundant repetition.

---

## Groups

### Redundant Non-Capturing Group

**Identifier:** `regex.lint.group.redundant`

**When it triggers:** A non-capturing group wraps a single atomic token without changing precedence.

**Example:**
```php
// WARNING: Unnecessary group
preg_match('/(?:foo)/', $input);

// PREFERRED: Remove the group
preg_match('/foo/', $input);
```

**Fix:** Remove the unnecessary group.

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

**When it triggers:** One literal alternative is a prefix of another.

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

### Overlapping Character Sets

**Identifier:** `regex.lint.overlap.charset`

**When it triggers:** Alternation branches have overlapping character sets.

**Example:**
```php
// WARNING: Overlapping character classes
preg_match('/[a-c]|[b-d]/', $input);

// SAFER: Use an atomic group to avoid backtracking
preg_match('/(?>[a-c]|[b-d])/', $input);

// IF EQUIVALENT: Merge ranges
preg_match('/[a-d]/', $input);
```

---

## Backreferences

### Useless Backreferences

**Identifier:** `regex.lint.backref.useless`

**When it triggers:** A backreference is used before its capturing group can be set or when the group is guaranteed to be empty.

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

## Character Classes

### Redundant Character Class Elements

**Identifier:** `regex.lint.charclass.redundant`

**When it triggers:** A character class contains redundant elements or overlapping ranges.

**Example:**
```php
// WARNING: Duplicate letters
preg_match('/[a-zA-Za-z]/', $input);

// PREFERRED: Remove duplicates
preg_match('/[a-zA-Z]/', $input);

// WARNING: Overlapping ranges
preg_match('/[a-fc-d]/', $input);  // 'c' and 'd' already in a-f

// PREFERRED: Use clean ranges
preg_match('/[a-f]/', $input);
```

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

**When it triggers:** A range spans only one or two characters (e.g., `[a-a]` or `[a-b]`) and can be written explicitly.

**Example:**
```php
// WARNING: Range only spans one character
preg_match('/[a-a]/', $input);

// WARNING: Range only spans two characters
preg_match('/[a-b]/', $input);

// PREFERRED: List characters explicitly
preg_match('/[a]/', $input);
preg_match('/[ab]/', $input);
```

**Fix:** Replace the range with literal characters inside the class.

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

## Escapes

### Suspicious Escapes

**Identifier:** `regex.lint.escape.suspicious`

**When it triggers:** Escapes that are likely typos or out-of-range values.

**Example:**
```php
// WARNING: Out of range Unicode
preg_match('/\x{110000}/', $input);  // Max is 0x10FFFF

// FIX: Use valid code point
preg_match('/\x{10FFFF}/', $input);

// WARNING: Suspicious escape
preg_match('/\d/', $input);  // Valid: digit

preg_match('/\8/', $input);  // Ambiguous: not a valid escape
```

**Fix:** Correct the codepoint or use valid escapes.

---

## Inline Flags

### Inline Flag Redundant

**Identifier:** `regex.lint.flag.redundant`

**When it triggers:** An inline flag sets/unsets a modifier already in the desired state.

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

## Security (ReDoS)

### Catastrophic Backtracking

**Identifiers:** `regex.redos.critical`, `regex.redos.high`, `regex.redos.medium`, `regex.redos.low`

**When it triggers:** The ReDoS analyzer detects nested quantifiers or overlapping alternatives that can explode backtracking time.

**Risk Levels:**

| Level      | Severity                | Action Required      |
|------------|-------------------------|----------------------|
| `critical` | Easily exploitable      | Refactor immediately |
| `high`     | Requires specific input | Consider refactoring |
| `medium`   | Requires crafted input  | Monitor and plan fix |
| `low`      | Minimal risk            | Accept with logging  |

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
- [OWASP: Regular Expression Denial of Service](https://owasp.org/www-community/attacks/Regular_expression_Denial_of_Service_-_ReDoS)

---

## Advanced Syntax

### Possessive Quantifiers

**What they are:** Quantifiers with trailing `+` (`*+`, `++`, `?+`, `{m,n}+`) that consume text without backtracking.

**Visual Comparison:**
```
Greedy: /".*"/
  -> matches as much as possible
  -> backtracks on failure

Possessive: /".*+"/
  -> matches as much as possible
  -> never backtracks
```

**Example:**
```php
// Greedy: may backtrack heavily
preg_match('/".*"/', $input);

// Possessive: consumes once and fails fast
preg_match('/".*+"/', $input);
```

---

### Atomic Groups

**What they are:** Groups of the form `(?>...)` that disallow backtracking into their contents once matched.

**Example:**
```php
// Risky: catastrophic backtracking on repeated 'a'
preg_match('/(a+)+!/', $input);

// Atomic: once inside the group matches, it cannot backtrack
preg_match('/(?>a+)+!/', $input);
```

---

### Assertions

**What they are:** Zero-width lookarounds like `(?=...)` / `(?!...)` / `(?<=...)` / `(?<!...)`.

**Example:**
```php
// Lookahead: require a trailing digit without consuming it
preg_match('/^[A-Z]{2}(?=\d$)/', $input);

// Lookbehind: ensure the match is preceded by "ID-"
preg_match('/(?<=ID-)\d+/', $input);
```

---

## Real-World Patterns (from Fixtures)

These examples are copied from `tests/Fixtures/pcre_patterns.php` to show the kinds of patterns RegexParser parses in tests.

### HTML Hex Entities

```
#(&\#x*)([0-9A-F]+);*#iu
```

Matches hex entities like `&#x1F4A9;`, capturing the prefix and hex digits. Case-insensitive (`i`) and Unicode-aware (`u`).

### Nested [indent] Tags (Recursive)

```
#\[indent]((?:[^[]|\[(?!/?indent])|(?R))+)\[/indent]#
```

Recursively matches nested `[indent]...[/indent]` blocks using `(?R)` to re-enter the whole pattern.

---

## Compatibility & Limitations

### Supported PHP Versions

- **PHP 8.2** and above
- Uses readonly classes and enum features

### Target Engine

- **PCRE2** via PHP's `preg_*` functions

### Known Differences from PCRE1

| Feature           | PCRE2        | Note                  |
|-------------------|--------------|-----------------------|
| `\p{...}` Unicode | Supported    |                       |
| `\g{0}`           | Invalid      | Use `\g<0>` or `(?R)` |
| Branch reset `(?|...)` | Supported    | |

---

## Diagnostics Catalog

| Error Code                          | Message Template                                    | Meaning                               | Fix Example              |
|-------------------------------------|-----------------------------------------------------|---------------------------------------|--------------------------|
| `regex.backref.missing_group`       | Backreference to non-existent group: "{ref}"        | Backreference points to missing group | Change `\2` to `\1`      |
| `regex.backref.missing_named_group` | Backreference to non-existent named group: "{name}" | Named backreference undefined         | Add `(?<name>...)`       |
| `regex.backref.zero`                | Backreference \0 is not valid                       | `\0` invalid in PCRE                  | Use `\g<0>`              |
| `regex.group.duplicate_name`        | Duplicate group name "{name}"                       | Named groups must be unique           | Use different names      |
| `regex.quantifier.invalid_range`    | Invalid quantifier range "{quant}": min > max       | `{3,2}` is invalid                    | Swap to `{2,3}`          |
| `regex.syntax.delimiter`            | Invalid delimiter "{delim}"                         | Delimiter not allowed                 | Use `/pattern/`          |
| `regex.escape.unrecognized`         | Unrecognized escape sequence "\{letter}"            | The letter after `\` means nothing to PCRE | Change `\i` to `i`  |
| `regex.escape.unsupported`          | PCRE does not support the escape "\{escape}"        | `\F`, `\L`, `\l`, `\U`, `\u` and `\N{name}` are refused | Change `\u0041` to `\x{41}` |
| `regex.escape.digits_missing`       | Digits missing in {escape}                          | `\x{}`, `\o{}` or `\N{U+}` holds no digit | Change `\o{}` to `\o{101}` |
| `regex.octal.missing_brace`         | Missing opening brace after \o                      | `\o` takes its digits in braces       | Change `\o101` to `\o{101}` |
| `regex.octal.invalid_digit`         | Invalid character in \o{}, or closing brace missing | A non-octal digit, or no `}`          | Change `\o{19}` to `\o{17}` |
| `regex.unicode.invalid_digit`       | Invalid character in {escape}, or closing brace missing | A non-hex digit in `\x{}` or `\N{U+}`, or no `}` | Change `\x{zz}` to `\x{2a}` |
| `regex.unicode.surrogate`           | Code point "{escape}" is a surrogate, which is not allowed in Unicode mode | `\x{d800}` to `\x{dfff}` under `u` or `(*UTF)` | Use a code point outside that range |
| `regex.charclass.invalid_escape`    | Escape sequence \{letter} is invalid in a character class | `\A`, `\B`, `\C`, `\G`, `\K`, `\N`, `\R`, `\X`, `\Z`, `\z` inside `[...]` | Move `\B` out of the class |
| `regex.posix.outside_class`         | POSIX named classes are supported only within a class | `[:alpha:]` written as a class of its own | Change `[:alpha:]` to `[[:alpha:]]` |
| `regex.posix.collating_element`     | POSIX collating elements are not supported          | `[.ch.]` and `[=ch=]` are refused     | Change `[[.ch.]]` to `(?:ch)` |
| `regex.semantic`                    | Various semantic errors                             | Pattern violates PCRE rules           | Add bounds to lookbehind |

---

## Quick Reference Table

| Category    | Rule ID                     | Severity      | Quick Fix                        |
|-------------|-----------------------------|---------------|----------------------------------|
| Flags       | `regex.lint.flag.useless.*` | warning       | Remove unused flag               |
| Anchors     | `regex.lint.anchor.*`       | error         | Move anchors to correct position |
| Quantifiers | `regex.lint.quantifier.*`   | warning/error | Use atomic groups                |
| Groups      | `regex.lint.group.*`        | info          | Remove redundant groups          |
| Alternation | `regex.lint.alternation.*`  | warning       | Simplify or use atomic           |
| Backrefs    | `regex.lint.backref.*`      | warning       | Move or remove backreferences    |
| Character   | `regex.lint.charclass.*`    | warning       | Remove duplicates                |
| Ranges      | `regex.lint.range.*`        | warning       | Replace with literals            |
| Escapes     | `regex.lint.escape.*`       | warning       | Fix escape sequences             |
| ReDoS       | `regex.redos.*`             | error/warning | Use possessive quantifiers       |

---

Previous: [Quick Start](QUICK_START.md) | Next: [ReDoS Guide](REDOS_GUIDE.md)

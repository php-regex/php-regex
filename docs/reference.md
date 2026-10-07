# PHPRegex Rule Reference

This comprehensive reference documents every diagnostic, lint rule, and optimization that PHPRegex produces. It serves as the authoritative guide for understanding what PHPRegex checks and how to fix issues.

## Table of Contents

| Section                                      | Description                       |
|----------------------------------------------|-----------------------------------|
| [Validation Layers](#validation-layers)      | How PHPRegex analyzes patterns |
| [Flags](#flags)                              | Flag-related diagnostics          |
| [Anchors](#anchors)                          | Anchor positioning issues         |
| [Quantifiers](#quantifiers)                  | Quantifier-related patterns       |
| [Groups](#groups)                            | Group-related diagnostics         |
| [Lookarounds](#lookarounds)                  | Lookahead contradictions          |
| [Alternation](#alternation)                  | Alternation patterns              |
| [Character Classes](#character-classes)      | Character class issues            |
| [Escapes](#escapes)                          | Escape sequence problems          |
| [Literals](#literals)                        | Literal text that reads badly     |
| [Bytes Without /u](#bytes-without-u)        | Multibyte text read as bytes      |
| [Inline Flags](#inline-flags)                | Inline flag diagnostics           |
| [ReDoS Security](#security-redos)            | Catastrophic backtracking         |
| [Advanced Syntax](#advanced-syntax)          | Optimizations and assertions      |
| [Compatibility](#compatibility--limitations) | PHP and PCRE2 support             |
| [Diagnostics Catalog](#diagnostics-catalog)  | Error code reference     |

---

## Validation Layers

PHPRegex validates patterns through four layers, each catching different types of issues:

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

**Identifier:** `regex.lint.anchor.impossible.start`, `regex.lint.anchor.impossible.end`, `regex.lint.anchor.impossible.boundary`

**When it triggers:**
- `^` appears after consuming tokens (without the `m` flag, including an inline `(?m)` scope) — cannot match start
- `$` appears before consuming tokens that cannot continue a line end — asserts end too early. `$` and `\Z` still match before the subject's final newline (and a multiline `$` before any newline), so a tail such as `$\n` is fine; under the `D` modifier `$` is strict like `\z` (unless `m` is set, which disables `D`), and `\z` always is
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
is an alternative that is the anchor alone (`/a|$/`: "or the end"). An alternative of verbs
and comments only, as `(*FAIL)`, is not counted, and an empty one (`/^a|/`) is
`regex.lint.alternation.empty`'s. A verb that ends the match
attempt on backtracking, accepts at once or fails (`(*COMMIT)`, `(*PRUNE)`, `(*SKIP)`,
`(*ACCEPT)`, `(*FAIL)`), in an alternative that reads something, leaves the rule silent: it can
keep the engine from the other alternatives (`/(*COMMIT)^a|b/` and `/^(*COMMIT)a|b/` never
match `b`), and a failing verb would carry into the grouped form, which then matches nothing. The tip keeps the options before the anchor (`(?i)^(?:a|b)`), keeps text
quoted with `\Q...\E` quoted (`^(?:a|\Qb)\E)` for `/^a|\Qb)\E/`), and leaves out the comments
ending each alternative, so that it compiles under `/x`.

**Example:**
```php
// WARNING: "b" matches anywhere
preg_match('/^a|b|c$/', 'xbx');      // 1

// PREFERRED, if the anchors are meant for every alternative
preg_match('/^(?:a|b|c)$/', 'xbx');  // 0
```

**Fix:** Group the alternatives, as the tip shows, when the anchors are meant for all of them.

---

## Quantifiers

### Nested Quantifiers (ReDoS Risk)

**Identifier:** `regex.lint.quantifier.nested`

**When it triggers:** A variable quantifier wraps another variable quantifier, creating catastrophic backtracking potential.

It is not reported when the nesting cannot blow up:

- **Nothing at all follows the outer loop**: `/(a+)+/` and `/x(\d+\.?)+/` end the pattern, so the first way the engine finds is the match. This holds when the outer minimum is 0 or 1, and when no subroutine call runs the loop again. Anything after the loop, an anchor or an assertion included, keeps the issue: `/(a+)+$/` and `/(?:a+)+(?=b)/` are reported.
- **A short run before the end**: an outer bound of at most 2 around a greedy run of one literal character, followed only by `$`, `\z` or `\Z`, without `/m`, as `/(a+){1,2}$/`. Any other shape keeps the issue: a bound of 3 or more (`/(a+){0,3}$/`), a class (`/([ab]+){1,2}$/`), a lazy run (`/(a+?){1,2}$/`), `/m`, or a lookahead after the loop.
- **A separator splits each iteration**: the inner loop is followed right away by an item it cannot match, and every other item of the iteration matches one way only. In the unrolled loop `/"[^"\\]*(?:\\.[^"\\]*)*"/`, each iteration ends with `[^"\\]*`, and the `\\` that opens the next iteration is what `[^"\\]` refuses, so there is one way to split the input. Under `/i` the two sides are compared case-folded, so `/(?:a+,)+$/i` is still exempt, but a loop unrolled this way is never exempt: `/"[^"\\]*(?:\\.[^"\\]*)*"/i` is reported.

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
- [OWASP: ReDoS](https://owasp.org/www-community/attacks/Regular_expression_Denial_of_Service_-_ReDoS)

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
stays silent, even when the configuration turns that other rule off: `(?:a*)*b` with
`quantifier.nested` off is reported by neither.

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
of the letter, and `(?:^)+` does not compile. `(?:a)+` is reported: `a+` is the same.

**Example:**
```php
// WARNING: Unnecessary group
preg_match('/(?:foo)/', $input);

// PREFERRED: Remove the group
preg_match('/foo/', $input);
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
`regex.lint.quantifier.emptyRepeat`'s.

**Example:**
```php
// WARNING: the group does nothing
preg_match('/a(?:)b/', 'ab');  // 1

// PREFERRED
preg_match('/ab/', 'ab');      // 1
```

**Fix:** Remove the group.

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
Unicode property near the lookahead. A repeated lookahead is
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

**When it triggers:** Alternation branches have overlapping character sets, and the alternation is repeated by an unbounded quantifier: a character both branches accept can be taken by either, on every iteration. An alternation matched once, such as `/[a-c]|[b-d]/`, is not reported, nor is one inside a lookbehind (`(?<!http:|https:)`): PCRE runs a lookaround atomically, so the loop around it never comes back to try the other branch. A loop at the very end of the pattern is still reported. With the ReDoS analysis on, a pattern the analysis proves linear drops the issue, inline options such as `(?s)` or `(?s:…)` included; like the two rules above, the issue is also left out for a pattern listed in the ignored patterns or found trivially safe.

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

## Bytes Without /u

Without `/u`, PCRE reads the pattern and the subject as bytes. A character written in
UTF-8 that takes several bytes is several items to it, and three places get it wrong: a
multibyte character in a class, a quantifier after one, and a Unicode property such as
`\p{L}` (`regex.lint.unicode.propertyWithoutU`), which then covers only the first 256
code points.

The three rules report at error severity: the pattern compiles, but does not do what it
says, so `regex lint` exits with 1. Turn one off under `checks.lint.rules` in `regex.json`
(`"unicode.multibyteInClassWithoutU": false`) when bytes are really meant.

### Multibyte Character in a Class

**Identifier:** `regex.lint.unicode.multibyteInClassWithoutU`

**When it triggers:** A character class holds a character of two bytes or more, and the
pattern has neither `/u` nor `(*UTF)`. The class holds each byte on its own.

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

**Identifier:** `regex.redos` in PHPStan, whatever the severity; the message names it

**When it triggers:** The ReDoS analyzer proves how one match attempt grows with the input, by following PCRE's backtracking order, and hands back the input that triggers it. Patterns outside that model (backreferences, conditionals, recursion, an analysis over budget) are judged by heuristics, and the report says which of the two decided. The message prefix names the class: `Exponential backtracking (ReDoS)`, `Polynomial backtracking (ReDoS)` or `Potential backtracking (ReDoS)`.

**Risk Levels:**

| Level      | Proven class                     | Action Required      |
|------------|----------------------------------|----------------------|
| `critical` | exponential                      | Refactor immediately |
| `high`     | polynomial, degree 3 or more     | Consider refactoring |
| `medium`   | polynomial, degree 2 (quadratic) | Monitor and plan fix |
| `low`      | heuristic finding only           | Accept with logging  |

See the [ReDoS guide](REDOS_GUIDE.md) for the guarantee and its limits.

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

### Quadratic Search

**Identifier:** `regex.lint.redos.search`; `regex.redos.search` in PHPStan, with the message `Quadratic search (ReDoS): <pattern>`

**When it triggers:** One match attempt is proven linear, but the search is not anchored: `preg_match()` starts an attempt at each position of the subject, and on a run of characters each attempt reads to the end of the run before it fails. On the run repeated n times then a breaking character, PCRE2's interpreter takes a number of steps quadratic in n; `preg_match_all()`, `preg_replace()` and `preg_split()` retry the same way. An anchored alternative does not protect the others: the trim regex `/^\s+|\s+$/` is quadratic on `"!" . " " x n . "!"`. `pcre.backtrack_limit` does not stop it: the limit counts each attempt apart, and trips only when one attempt exceeds it. The JIT may avoid it for some patterns, not for all (see the [ReDoS guide](REDOS_GUIDE.md#the-cost-of-an-unanchored-search)).

It runs under the ReDoS check, with no switch of its own. Its severity is that of a proven quadratic attempt, `medium`, so the default `high` threshold hides it: `--redos-threshold=medium` shows it. It is a warning in every mode, and `--disable-rule=regex.lint.redos.search` or `"redos.search": false` in the `checks.lint.rules` of `regex.json` turns it off.

**Example:**
```php
// Quadratic search: on " " x n . "!" each attempt reads the rest of the run
preg_match('/\s+$/', $input);

// Linear, and the same answer: the characters \s matches without /u
rtrim($input, " \t\n\v\f\r") !== $input;
```

The two agree on the characters `\s` matches without `/u` in the default C locale. PHP builds PCRE2's character tables from `LC_CTYPE` when a script sets another locale, which may change what `\s` matches; under `/u`, `\s` also matches Unicode spaces such as U+00A0 and U+2028, which this `rtrim()` keeps.

**Fix:** Anchor the pattern when every match starts at a known place (`^`, `\A`, `\G` or the `A` modifier), or bound the length of the run, or do the work without a regex (`rtrim()` for trailing whitespace).

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

These examples are copied from `tests/Fixtures/pcre_patterns.php` to show the kinds of patterns PHPRegex parses in tests.

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
- Needs the `mbstring` extension. `intl` is optional: it only lets a lint message name the character a `\N{name}` escape spells, an escape PCRE refuses anyway; every verdict and every other lint result is the same without it
- Reads digits, letters and spaces in ASCII, as PCRE does: the locale of the process changes no verdict

### Target Engine

- **PCRE2** via PHP's `preg_*` functions

### Known Differences from PCRE1

| Feature           | PCRE2        | Note                  |
|-------------------|--------------|-----------------------|
| `\p{...}` Unicode | Supported    |                       |
| `\g{0}`           | Invalid      | Use `\g<0>` or `(?R)` |
| Branch reset `(?\|...)` | Supported    | |

---

## Diagnostics Catalog

Every code an exception or a failed validation carries is listed, with its meaning, in
[Diagnostics: Error Codes](reference/diagnostics.md#error-codes). The lint rule ids below
are a separate vocabulary: they name advice, not a refused pattern.

---

## Quick Reference Table

Every lint rule reports at warning severity unless the table says otherwise. The rules
SonarPHP users know are mapped to these ids in [SonarPHP Regex Rules](reference/sonar.md). A rule of
error severity fails `regex lint` (exit code 1); a warning is printed and leaves the code
at 0; an info, `style` included, is printed under an `INFO` badge and leaves the code at 0.

| Category    | Rule ID                                                                                   | Severity | Quick Fix                         |
|-------------|-------------------------------------------------------------------------------------------|----------|-----------------------------------|
| Flags       | `regex.lint.flag.useless.s`, `.m`, `.i`                                                   | warning  | Remove the unused flag            |
| Anchors     | `regex.lint.anchor.impossible.start`, `.end`, `.boundary`                                 | warning  | Move the anchor                   |
| Anchors     | `regex.lint.anchor.alternationPrecedence`                                                 | warning  | Group the alternatives            |
| Quantifiers | `regex.lint.quantifier.nested`, `regex.lint.dotstar.nested`                               | warning  | Use atomic groups                 |
| Quantifiers | `regex.lint.quantifier.useless`, `.zero`, `.concatenation`, `.lazyEnd`, `.assertion`      | warning  | Simplify the quantifier           |
| Quantifiers | `regex.lint.quantifier.emptyRepeat`, `.possessiveImpossible`                              | warning  | Fix the repeated item             |
| Quantifiers | `regex.lint.quantifier.lazyToClass` (off by default)                                      | perf     | Use a negated class               |
| Groups      | `regex.lint.group.redundant`, `.empty`                                                    | warning  | Remove the group                  |
| Lookarounds | `regex.lint.lookaround.impossible`                                                        | warning  | Fix the lookahead                 |
| Groups      | `regex.lint.group.quantifiedCapture`                                                      | info for an unnamed group, warning for a named one | Repeat a non-capturing group |
| Alternation | `regex.lint.alternation.duplicateDisjunction`, `.empty`, `.overlap`, `.dotNewline`        | warning  | Simplify or use atomic            |
| Alternation | `regex.lint.overlap.charset`                                                              | warning  | Use atomic or merge the sets      |
| Backrefs    | `regex.lint.backref.useless`, `.undefined`                                                | warning  | Move or remove the backreference  |
| Character   | `regex.lint.charclass.redundant`, `.duplicateChars`, `.suspiciousRange`, `.suspiciousPipe`, `.literalMetachar`, `.backrefAsOctal` | warning | Clean up the class |
| Character   | `regex.lint.charclass.single` (off by default)                                            | style    | Write the character alone         |
| Ranges      | `regex.lint.range.useless`                                                                | warning  | Replace with literals             |
| Escapes     | `regex.lint.escape.suspicious`                                                            | warning  | Fix the escape sequence           |
| Literals    | `regex.lint.literal.multipleSpaces` (off by default)                                      | style    | Count the spaces                  |
| Unicode     | `regex.lint.unicode.multibyteInClassWithoutU`, `.quantifiedMultibyteWithoutU`, `.propertyWithoutU`, `.bracedHexWithoutU` | error | Add the `/u` flag |
| Unicode     | `regex.lint.unicode.shorthandWithoutU` (off by default)                                   | style    | Add the `/u` flag                 |
| Inline      | `regex.lint.flag.redundant`, `.override`                                                  | warning  | Remove or scope the inline flag   |
| Complexity  | `regex.lint.complexity`                                                                   | warning  | Split the pattern                 |
| ReDoS       | `regex.lint.redos` (`regex.redos` in PHPStan)                                             | warning; error when `--redos-mode=confirmed` reproduces a verdict at `high` or above or proves one it cannot replay | Use possessive quantifiers |
| ReDoS       | `regex.lint.redos.search` (`regex.redos.search` in PHPStan)                               | warning in every mode; ReDoS severity `medium`, shown from `--redos-threshold=medium` | Anchor the pattern or bound the run |
| Sources     | `regex.lint.source.unreadable`: a source file an extractor could not read, so the patterns it holds were not linted (Laravel `regex:lint`, for the `regex:` validation rules it reads) | error | Fix what the message names |

---

Previous: [Quick Start](QUICK_START.md) | Next: [ReDoS Guide](REDOS_GUIDE.md)

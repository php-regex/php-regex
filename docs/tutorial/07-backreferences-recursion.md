---
layout: tutorial
description: "Reuse matched text with backreferences, replay group patterns with subroutines, recurse into nested structures, and write (?(group)yes|no) conditionals."
---

# Chapter 7: Backreferences, Subroutines, and Recursion

> **Goal:** Match repeated patterns and create self-referential expressions.

---

## What are Backreferences?

**Backreferences** let you match the same text that was previously captured.

Example:
- Text: `"hello hello"`
- Pattern: `/(\w+) \1/`
- Group 1 captures `"hello"`.
- `\1` matches the same text, so the full match is `"hello hello"`.

### Real-World Analogy

| Scenario        | Backreference        | What It Matches                |
|-----------------|----------------------|--------------------------------|
| Repeated words  | `/\b(\w+)\s+\1\b/`   | "hello hello", "test test"     |
| Matching quotes | `/(['"])(.*?)\1/`    | "quoted text" or 'quoted text' |
| HTML tags       | `/<(\w+)>.*?<\/\1>/` | `<div>...</div>`, `<p>...</p>` |

---

## Using Backreferences

### Numbered Backreferences `\1`, `\2`, ...

```php
// Match repeated words
preg_match('/\b(\w+)\s+\1\b/', 'hello hello world', $matches);
echo $matches[0];  // "hello hello"
echo $matches[1];  // "hello" (the captured word)

// Match quoted text
preg_match('/(["\'])(.*?)\1/', '"hello"', $matches);
echo $matches[0];  // '"hello"'
echo $matches[1];  // '"' (quote character)
echo $matches[2];  // "hello" (content)
```

### Named Backreferences `\k<name>` or `\k'name'`

```php
// Match repeated word with named group
preg_match('/\b(?<word>\w+)\s+\k<word>\b/', 'test test', $matches);
echo $matches[0];      // "test test"
echo $matches['word']; // "test"

// Match quoted text with named groups
preg_match('/(?<quote>["\'])(?<content>.*?)\k<quote>/', '"hello"', $matches);
echo $matches['quote'];   // '"'
echo $matches['content']; // "hello"
```

---

## Subroutines: Reuse Group Patterns

Subroutines let you **reuse a group's pattern** without capturing:

### Numbered Subroutine `(?1)`, `(?2)`, ...

```php
// Match balanced parentheses
$pattern = '/(\((?:[^()]|(?1))*\))/';
preg_match($pattern, '(a(b)c)', $matches);
echo $matches[0];  // "(a(b)c)"
```

### Named Subroutine `(?&name)`

```php
// Match balanced brackets with named subroutine
$pattern = '/(?(DEFINE)(?<brackets>\[(?:[^\[\]]|(?&brackets))*\]))(?&brackets)/';

preg_match($pattern, '[a[b]c]', $matches);
echo $matches[0];  // "[a[b]c]"
```

The `(?(DEFINE)...)` block parks groups on a shelf: nothing inside it matches on its own — only what comes after the block is matched against the subject. `(?&brackets)` then replays the shelved recipe wherever it is called.

---

## Recursion: Pattern Calls Itself

### Recursive Pattern for Balanced Structures

```php
// Match balanced parentheses
$pattern = '/\((?:[^()]|(?R))*\)/';

preg_match($pattern, '(simple)', $matches);
echo $matches[0];  // "(simple)"

preg_match($pattern, '(nested (deep))', $matches);
echo $matches[0];  // "(nested (deep))"
```

### How recursion works

Pattern: `/\((?:[^()]|(?R))*\)/`

Text: `"(a(b)c)"`

- The outer pattern matches an opening `(` and a closing `)`.
- Inside, it alternates between non-parentheses and a recursive call `(?R)`.
- The recursive call handles nested parentheses such as `"(b)"`.

---

## Conditionals: If-Then Patterns

### Basic Conditional `(?(group)yes|no)`

```php
// If group 1 matched, require 'b', else require 'c'
$pattern = '/^(a)?(?(1)b|c)/';

preg_match($pattern, 'ab', $matches);  // Match: yes (group 1=a, so 'b')
preg_match($pattern, 'c', $matches);   // Match: yes (no group 1, so 'c')
preg_match($pattern, 'ac', $matches);  // Match: no (group 1=a, expected 'b')
```

The condition must reference a group that exists in the pattern — `(?(1)yes|no)` alone does not compile, because there is no group 1.

### Named Conditionals

```php
// If named group matched
$pattern = '/(?<has_a>a)?(?(has_a)b|c)/';

preg_match($pattern, 'ab', $matches);  // Match: yes
preg_match($pattern, 'c', $matches);   // Match: yes
```

---

## Practical Examples

### 1. Find Repeated Words

```php
// Match doubled words like "the the"
$pattern = '/\b(?<word>\w+)\s+\k<word>\b/i';

preg_match_all($pattern, 'the the quick brown fox ran run', $matches);
// $matches[0] = ["the the"]
```

### 2. Match HTML Tags

```php
// Opening tag, then a subroutine that replays the tag-name pattern
$pattern = '/<(?<tag>\w+)[^>]*>(?:[^<]|(?<nested><(?&tag)[^>]*>)|(?<closing><\/(?&tag)>))*/';

preg_match($pattern, '<div><span>text</bogus></div>', $matches);
echo $matches[0];  // "<div><span>text</bogus></div>"
```

Careful with that result: `(?&tag)` replays the **pattern** `\w+`, not the name the group happened to capture — so `</bogus>` happily closes `<span>`. To force the closing tag to repeat the opening one, use a backreference:

```php
$pattern = '/<(?<tag>\w+)[^>]*>[^<]*<\/\k<tag>>/';

preg_match($pattern, '<div>text</div>', $matches);   // Match: "<div>text</div>"
preg_match($pattern, '<div>text</span>', $matches);  // Match: no (close must repeat the open)
```

This simpler pattern does not handle nesting — for nested markup, reach for a real HTML parser.

### 3. Validate Paired Delimiters

```php
// Match text between matching quotes (single or double)
$pattern = '/(?<q>["\'])(?:(?!\k<q>).)*\k<q>/';

preg_match($pattern, '"hello"', $matches);          // Match: '"hello"'
preg_match($pattern, "'world'", $matches);          // Match: "'world'"
preg_match($pattern, '"say "hi" now"', $matches);   // Match: '"say "' — stops at the inner quote
```

The engine is `(?!\k<q>).`: consume one character that is not the closing quote. Note what is **not** written: a backreference inside a character class. `[^\1]` looks like "any character except the group-1 match", but inside a class `\1` is the octal escape `\x01` — the class would mean "any character except chr(1)". A backreference only works where a match can be tested: outside classes.

---

## Good Patterns vs Bad Patterns

### Good: Clear and Safe

```php
// Simple repeated word
'/\b(\w+)\s+\1\b/'

// Balanced parentheses with recursion
'/\((?:[^()]|(?R))*\)/'

// Conditional based on an existing capture
'/^(a)?(?(1)b|c)/'
```

### Bad: Complex or Dangerous

```
// Deep recursion without limits (ReDoS risk)
/\((?:[^()]|(?R))*\)/  // On deeply nested input!

// Overly complex conditionals
'/(?(1)(?(2)(?(3)yes|no)|maybe)|no)/'

// Recursion without a base case — never matches, see Common Errors below
```

---

## Exercises

### Exercise 1: Match Repeated Words

Write a pattern to find "hello hello", "test test", etc.:

```php
$pattern = '/\b(?<word>\w+)\s+\k<word>\b/';
preg_match($pattern, 'test test', $matches);
echo $matches[0];  // "test test"
```

### Exercise 2: Match Balanced Brackets

Write a pattern that matches `[a[b]c]` using a named subroutine:

```php
$pattern = '/(?(DEFINE)(?<brackets>\[(?:[^\[\]]|(?&brackets))*\]))(?&brackets)/';
preg_match($pattern, '[a[b]c]', $matches);
echo $matches[0];  // "[a[b]c]"
```

### Exercise 3: Conditional on a Named Group

Write a pattern that matches "ab" when the group catches an "a", or "c" otherwise:

```php
$pattern = '/(?<has_a>a)?(?(has_a)b|c)/';
preg_match($pattern, 'ab', $m);  // Match: yes
preg_match($pattern, 'c', $m);   // Match: yes
```

---

## Key Takeaways

1. **Backreferences** `\1`, `\k<name>` reuse captured text
2. **Subroutines** `(?1)`, `(?&name)` reuse group patterns
3. **Recursion** `(?R)` makes pattern call itself
4. **Conditionals** `(?(group)yes|no)` add logic
5. These features are **flexible but complex**
6. Use **carefully** to avoid performance issues

---

## Common Errors

### Error: Backreference vs Subroutine

```php
// Backreference: matches same text as group 1
'/(a)\1/'   // Example match: "aa"

// Subroutine: uses group 1's PATTERN
'/(a)(?1)/' // Example match: "aa" (group 1 pattern is "a")
```

### Error: Missing Base Case in Recursion

```php
// No branch can finish without recursing: every path calls (?R) again,
// and the pattern dies with "JIT stack limit exhausted" instead of matching
'/(?:a|(?R))*/'  // Matches nothing on any input — an error, not a match

// Rule: a recursive pattern needs a branch that matches WITHOUT recursing
'/\((?:[^()]|(?R))*\)/'  // [^()] is the base case
```

A lazy quantifier does not add a base case: `/(?:a|(?R))+?/` fails exactly the same way.

### Error: Backreference Outside Group

```php
// No group to reference
preg_match('/\1/', '1');  // Error: no such group

// Define group first
preg_match('/(\d)\1/', '11');  // Match: yes ("11")
```

---

## Recap

You now understand:
- Backreferences (numbered and named)
- Subroutines
- Recursion
- Conditionals
- When to use each feature

**Next:** [Chapter 8: Performance and ReDoS](08-performance-redos.md)

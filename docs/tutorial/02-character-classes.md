---
layout: tutorial
description: "Match sets of characters with [abc], ranges, negation, shorthand classes like \d and \w, and when to use them."
---

# Chapter 2: Character Classes & Escapes

> **Goal:** Match *one* character from a specific set, or *anything except* a set.

---

## 1) What is a Character Class?

A **character class** lets you say: *"match any one character from this group"*.

Think of it like a multiple-choice question — only one answer is picked.

```text
Pattern: /[aeiou]/
Text:    "hello"
Matches: "e", "o"  (two separate matches)
```

The square brackets `[...]` define the set. The pattern matches **exactly one character** that is inside that set.

---

## 2) Matching From a Set

### List specific characters

Put characters between `[` and `]`:

```php
preg_match('/[aeiou]/', 'hello', $matches); // matches 'e'
echo $matches[0], PHP_EOL;
preg_match('/[bcd]/', 'cat', $matches);     // matches 'c'
echo $matches[0], PHP_EOL;
```
{: data-run="" }

### Use ranges

To avoid listing every letter or digit, use `-` to define a range:

```php
preg_match('/[0-9]/', 'Age: 42', $matches); // matches '4'
echo $matches[0], PHP_EOL;
preg_match('/[a-z]/', 'Hello', $matches);   // matches 'e' (lowercase only)
echo $matches[0], PHP_EOL;
preg_match('/[A-Z]/', 'Hello', $matches);   // matches 'H'
echo $matches[0], PHP_EOL;
```
{: data-run="" }

Ranges work based on character codes. For ASCII letters and digits this is fine. Avoid tricks like `[A-z]` — we'll see why later.

---

## 3) Negated Character Classes

Prefix with `^` inside the brackets to match **anything except** what's in the set:

```php
preg_match('/[^0-9]/', 'ABC123', $matches); // matches 'A'
echo $matches[0], PHP_EOL;
preg_match('/[^aeiou]/', 'apple', $matches); // matches 'p', 'p', 'l'
echo $matches[0], PHP_EOL;
```
{: data-run="" }

Here, `[^0-9]` means: *"any single character that is not a digit"*.

---

## 4) Escaping Inside Character Classes

Rules change a little inside `[...]`. Most characters lose their special meaning there.

- A `.` inside a class is a **literal dot**. No need to escape it.
- `]` is special — escape it with `\` if you want to match a literal `]`.
- `\` is still the escape character.
- `-` creates a range by default. Put it at the **start** or **end** of the class to match it literally.
- `^` only negates when it's the **first** character. Elsewhere it's literal.

```php
preg_match('/[.]/', 'a.b', $matches);      // matches '.'
echo $matches[0], PHP_EOL;
preg_match('/[abc-]/', 'a-b', $matches);   // matches 'a' or '-' (dash at end)
echo $matches[0], PHP_EOL;
preg_match('/[-abc]/', '-abc', $matches); // matches '-' (dash at start)
echo $matches[0], PHP_EOL;
preg_match('/[\]]/', 'a]b', $matches);     // matches ']'
echo $matches[0], PHP_EOL;
```
{: data-run="" }

Compare with outside a class: `/\./` matches a literal dot too, but inside `[.]` it's already literal.

---

## 5) Shorthand Character Classes

Typing `[0-9]`, `[a-zA-Z0-9_]` etc. gets repetitive. PCRE provides shorthands:

| Shorthand | Equivalent | Meaning |
|-----------|-------------|---------|
| `\d` | `[0-9]` | Any digit |
| `\D` | `[^0-9]` | Any non-digit |
| `\w` | `[a-zA-Z0-9_]` | Any "word" character (letters, digits, underscore) |
| `\W` | `[^a-zA-Z0-9_]` | Any non-word character |
| `\s` | `[ \t\r\n\f\v]` | Any whitespace (space, tab, newline, etc.) |
| `\S` | `[^ \t\r\n\f\v]` | Any non-whitespace |
| `.` | *(special)* | Any single character **except** newline (by default) |

```php
preg_match('/\d/', 'Order 123', $matches);     // matches '1'
echo $matches[0], PHP_EOL;
preg_match('/\w+/', 'hello_world', $matches);  // matches 'hello_world'
echo $matches[0], PHP_EOL;
preg_match('/\s/', "hello world", $matches);   // matches space
echo $matches[0], PHP_EOL;
preg_match('/./', 'abc', $matches);            // matches 'a'
echo $matches[0], PHP_EOL;
```
{: data-run="" }

Note: `.` is not a "character class" in the `[...]` sense, but it's related — it matches broadly. We'll keep using it as we go.

---

## 6) Unicode Character Properties (a bit more)

If you need to match letters from other languages, digits from other scripts, or emoji, you can use Unicode properties with the `u` [flag](01-basics.md#5-case-and-flags).

```php
preg_match('/^\p{L}+$/u', 'café', $matches); // matches (letters)
echo $matches[0], PHP_EOL;
preg_match('/^\p{N}+$/u', '١٢٣', $matches);   // matches (Arabic-Indic digits)
echo $matches[0], PHP_EOL;
preg_match('/\p{Emoji}/u', 'Hi 👋', $matches); // matches emoji
echo $matches[0], PHP_EOL;
```
{: data-run="" }

Common ones:

| Property | Meaning |
|----------|---------|
| `\p{L}` | Any letter (from any language) |
| `\p{N}` | Any number |
| `\p{P}` | Punctuation |
| `\p{S}` | Symbols |
| `\p{Z}` | Separators (spaces) |
| `\p{C}` | Other/control characters |

**Important:** For `\p{...}` and `\P{...}` you almost always need the `u` flag.

If you don't need Unicode right now, it's perfectly fine to skip this section and come back to it later.

---

## 7) Putting It Together

Character classes shine when combined with other concepts you'll learn soon:

- **[Anchors](03-anchors-boundaries.md)** (`^`, `$`) to match the whole string: `/^[0-9]+$/` means "only digits"
- **[Quantifiers](04-quantifiers.md)** (`+`, `*`, `{n,m}`) to say "one or more", "zero or more", etc.

For example, to check if something is all digits: `/^[0-9]+$/` — but don't worry, we'll explain `^`, `$` and `+` properly in the next chapters.

---

## 8) Exercises

Only use what you've learned in this chapter and [chapter 1](01-basics.md).

### Exercise 1: Vowels
Write a pattern that matches any vowel (`a`, `e`, `i`, `o`, `u`) in lowercase.

<details markdown="1">
<summary>Solution</summary>

```php
'/[aeiou]/'
```

</details>

### Exercise 2: Digits with shorthand
Write a pattern using `\d` that matches a single digit.

<details markdown="1">
<summary>Solution</summary>

```php
'/\d/'
```

</details>

### Exercise 3: Not a letter
Write a pattern that matches any character that is **not** a letter (A-Z, a-z).

<details markdown="1">
<summary>Solution</summary>

```php
'/[^A-Za-z]/'
```

</details>

### Exercise 4: Literal dot in a class
Write a pattern using a character class to match a literal dot.

<details markdown="1">
<summary>Solution</summary>

```php
'/[.]/'
```

</details>

### Exercise 5: Word characters
Write a pattern that matches one or more word characters (`\w+`). Test it mentally on `"hello_123"`.

<details markdown="1">
<summary>Solution</summary>

```php
'/\w+/'
```

</details>

---

## Key Takeaways

- **Character classes** `[...]` match **one** character from a set.
- **Ranges** like `[0-9]`, `[a-z]` cover consecutive characters.
- **Negation** `[^...]` matches one character **not** in the set.
- **Shorthands** `\d`, `\D`, `\w`, `\W`, `\s`, `\S` cover common cases.
- Inside `[...]`, `.` is literal. Watch out for `]`, `\`, `-` and `^`.
- For Unicode properties use `\p{...}` with the `u` flag.
- `[A-z]` is a common trap — be explicit with `[A-Za-z]` if you only want letters.

---

## Common Pitfalls

- **`[A-z]` is wrong for "all letters"** — it includes characters between `Z` and `a` in ASCII (`[`, `\`, `]`, `^`, `_`, `` ` ``).
- **Forgetting `u` for `\p{}`** — without `u`, Unicode properties often won't behave as expected.
- **Confusing `.` and `[.]`** — outside classes `.` is "any character", inside `[.]` it's a literal dot.

---

## Optional: Tools to Inspect Patterns

If you'd like to visualize or explain a character class, you can use these optional tools. Not required to learn the concepts.

```bash
vendor/bin/regex explain '/[aeiou]/'
vendor/bin/regex diagram '/\d+/'
```

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
echo $regex->explain('/[^0-9]/');
```

---

**Next:** [Chapter 3: Anchors & Boundaries](03-anchors-boundaries.md)

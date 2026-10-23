---
layout: tutorial
description: "Control where a match can happen with anchors (^, $, \A, \z) and word boundaries (\b)."
---

# Chapter 3: Anchors & Boundaries

> **Goal:** Match *positions* in a string, not characters.

---

## 1) Positions, not characters

Most of what we've seen so far matches **characters**. 

**Anchors** are different. They don't consume any text, they just say: *"the match is only allowed at this position"*.

Think of them like bookmarks: start of string, end of string, or the edge between a word and something else.

---

## 2) Start and End: `^` and `$`

### `^` — start of the string

`^` matches the very beginning of the subject.

```php
preg_match('/^cat/', 'cat food'); // matches
preg_match('/^cat/', 'The cat');  // no match (not at start)
```

### `$` — end of the string

`$` matches the very end of the subject.

```php
preg_match('/cat$/', 'The cat');  // matches
preg_match('/cat$/', 'cat food'); // no match (not at end)
```

### Matching the entire string

Combine `^` and `$` to require an **exact match** of the whole string.

```php
preg_match('/^cat$/', 'cat');      // matches
preg_match('/^cat$/', 'cat food'); // no match
preg_match('/^cat$/', 'The cat');  // no match
```

This is extremely useful for validation. If you want "this input must be exactly X", anchors are your friend.

---

## 3) A Quick Word About Multiline

With the `m` ([multiline](01-basics.md#5-case-and-flags)) flag, `^` and `$` can also match at the start/end of **each line** instead of only the whole string.

```php
$text = "cat\nbird";

preg_match('/^cat/m', $text);  // matches (start of first line)
preg_match('/^bird/m', $text); // matches (start of second line)
```

For now, just know this exists. We'll not rely on `m` in this chapter's exercises.

---

## 4) Absolute Anchors: `\A` and `\z`

Sometimes you want to be strict and **ignore** the multiline behavior entirely.

- `\A` — matches only the **absolute start** of the string
- `\z` — matches only the **absolute end** of the string

```php
$text = "cat\nbird";

preg_match('/\Acat/m', $text);  // matches (absolute start)
preg_match('/\Abird/m', $text); // no match
preg_match('/bird\z/m', $text); // matches (absolute end)
```

### The trailing newline trap

One subtle point: without `m`, `$` can match **just before a final newline**. `\z` never does this — it only matches at the very end with nothing after it.

```php
preg_match('/^cat$/', "cat\n"); // matches (before the final newline)
preg_match('/^cat\z/', "cat\n"); // no match
```

If you truly mean "the entire input must be exactly this", `\A...\z` is the clearest and safest choice.

---

## 5) Word Boundaries `\b`

A **word boundary** `\b` matches a position between a [word character](02-character-classes.md#5-shorthand-character-classes) (`\w`: letters, digits, underscore) and a **non-word character** (`\W`), or between a word character and the start/end of the string.

Crucially, `\b` matches **a position, not a space**. It won't add extra characters to your match.

### Matching whole words

```php
preg_match('/\bcat\b/', 'the cat sat'); // matches "cat"
preg_match('/\bcat\b/', 'category');    // no match (cat is part of the word)
preg_match('/\bcat\b/', 'wildcat');     // no match
```

### Seeing it differently

| Pattern | Text | Match | Explanation |
| --- | --- | --- | --- |
| `/\bcat/` | `"category"` | yes | Boundary before `cat`, but no boundary after it |
| `/cat\b/` | `"wildcat"` | yes | Boundary after `cat`, but no boundary before it |
| `/\bcat\b/` | `"the cat"` | yes | Boundaries on both sides |

You can also use `\B` for the **opposite**: a position where there is **no** word boundary (i.e. between two word characters, or two non-word characters).

```php
preg_match('/\Bcat\B/', 'concatenate'); // matches "cat" in the middle
preg_match('/\Bcat\B/', 'the cat');     // no match
```

You won't need `\B` often as a beginner, but it's good to know it exists.

---

## 6) Putting It Together

Anchors and boundaries become really practical when you mix them with [character classes](02-character-classes.md) and [quantifiers](04-quantifiers.md).

A few examples to get the idea:

```php
// Only digits
preg_match('/^\d+$/', '12345'); // matches

// Starts with "http://"
preg_match('/^https?:\/\//', 'https://example.com'); // matches

// Ends with ".txt"
preg_match('/\.txt$/', 'readme.txt'); // matches

// Whole word only
preg_match('/\berror\b/', 'error: failed'); // matches
preg_match('/\berror\b/', 'errorCode');     // no match
```

Don't worry about `+` yet — we'll cover [quantifiers](04-quantifiers.md) in the next chapter.

---

## 7) Exercises

Only use what you've learned in this chapter and the previous ones.

### Exercise 1: Start of string
Write a pattern that matches strings that **start with** `hello`.

<details markdown="1">
<summary>Solution</summary>

```php
'/^hello/'
```

</details>

### Exercise 2: End of string
Write a pattern that matches strings that **end with** `.txt`.

<details markdown="1">
<summary>Solution</summary>

```php
'/\.txt$/'
```

</details>

### Exercise 3: Exact match
Write a pattern that matches **only** the exact string `PHP`.

<details markdown="1">
<summary>Solution</summary>

```php
'/^PHP$/'
```

</details>

### Exercise 4: Whole word
Write a pattern that matches the word `test` only when it's a **separate word**.

<details markdown="1">
<summary>Solution</summary>

```php
'/\btest\b/'
```

</details>

### Exercise 5: Strict from start to end
Write a pattern using `\A` and `\z` that matches **exactly** `cat`.

<details markdown="1">
<summary>Solution</summary>

```php
'/\Acat\z/'
```

</details>

---

## Key Takeaways

- **Anchors** (`^`, `$`) match **positions**, not characters.
- `^` = start of string, `$` = end of string.
- Use `^...$` to match the **entire** string.
- `\A` and `\z` are **always** absolute (ignore multiline).
- `$` can match before a final newline. `\z` does not.
- **Word boundaries** `\b` match the edge between `\w` and `\W`.
- `\B` matches where there is **no** word boundary.

---

## Common Pitfalls

- **Forgetting anchors in validation** — `/\d+/` will match digits *inside* a longer string. `/^\d+$/` requires the whole string to be digits.
- **Mixing up `\b` and spaces** — `\b` doesn't match a space, it matches the *position* between space and word.
- **Using `$` when you mean "end, no newline"** — in strict validation, consider `\z` over `$`.

---

## Optional: Tools to Inspect Patterns

If you'd like to see how anchors and boundaries are parsed, these optional tools can help:

```bash
vendor/bin/regex explain '/^\d+$/'
vendor/bin/regex diagram '/\bcat\b/'
```

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
echo $regex->explain('/^hello$/');
```

---

**Next:** [Chapter 4: Quantifiers & Greediness](04-quantifiers.md)

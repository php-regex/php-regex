---
layout: tutorial
description: "Learn what a regex pattern is, match literal text, understand delimiters and escaping — with no prior regex knowledge required."
---

# Chapter 1: Regex Basics

> **Goal:** Understand what a pattern is, match plain text, and learn just enough syntax to write your first safe patterns.

---

## 1) What is a Pattern?

A **regular expression** (regex) is a way to describe text. A **pattern** is that description.

Think of it like a wanted poster: instead of saying "find the exact word cat", you write a short description that the engine uses to look for matches in some text.

```
Text:    "The cat sat on the mat"
Pattern: cat
Match:   "cat"
```

For now, think in the simplest terms: **a pattern matches characters in order**.

---

## 2) Matching Literal Text

The easiest patterns are **literals**. A literal character matches exactly that character.

```text
Pattern: /cat/
Matches: "cat", "a cat is here"
Does not match: "cut", "bat", "CAT" (by default)
```

By default, matching is **case-sensitive**. `cat` is not the same as `CAT`.

### Trying it in PHP

If you want to test quickly, you can use PHP's built-in engine:

```php
$text = 'The cat sat on the mat';

if (preg_match('/cat/', $text)) {
    echo "Found it!";
}
```
{: data-run="" }

You don't need any extra library to follow this chapter. What matters is understanding the **pattern**, not the function.

---

## 3) Delimiters: Wrapping the Pattern

In PHP and PCRE, a regex pattern is almost always **wrapped by delimiters**. The delimiters tell PHP: "everything between these two characters is the pattern, everything after is a [modifier/flag](#5-case-and-flags)".

The most common delimiter is `/`:

```php
/pattern/
```

### Why delimiters exist

Imagine your pattern needs to contain a `/` itself, like a URL. If you also used `/` as delimiter, it would be ambiguous. To avoid that, you can pick a different delimiter.

```php
// Using / as delimiter would conflict with the slashes inside
#https://example.com#  // Using # instead
~https://example.com~  // Using ~ instead
```

Both are perfectly valid.

### Minimal rules

- Delimiters must be **non-alphanumeric**, **not a backslash** `\`, and **not whitespace**.
- The opening and closing delimiter are usually the same character.
- You can also use paired delimiters like `()`, `[]`, `{}`, `<>` where the closing one matches its pair.

For now, you only need to remember one thing: **the `/ ... /` you see around patterns are delimiters**. We'll go deeper only if needed later.

---

## 4) Escaping Special Characters

Some characters have a **special meaning** in regex. If you want to match them **literally**, you need to escape them with a backslash `\`.

### Start small: the dot `.`

The dot `.` is special. By itself, it means: *"match any single character except a newline"*.

```text
Pattern: /c.t/
Matches: "cat", "cot", "c t"
Does not match: "ct", "coat"
```

Now, if you actually want to match a **literal dot** (like in `example.com`), you must escape it:

```text
Pattern: /example\.com/
Matches: "example.com"
Does not match: "exampleXcom"
```

Notice the difference: `.` vs `\.`. This is the most important first escape to learn.

### Other characters you might see

Later you'll run into characters like `^`, `$`, `*`, `+`, `?`, `(`, `)`, `[`, `]`, `{`, `}`, `|`, `\`. 

- **Outside of [character classes](02-character-classes.md)**, many of them are special and need `\` to be matched literally.
- **Inside [character classes](02-character-classes.md)**, the rules change slightly. We'll cover that at the right time.

For now, **focus on `.` vs `\.`**. Don't try to memorize the entire list yet.

---

## 5) Case and Flags

Sometimes you want to ignore case. You can add a **flag** after the closing delimiter.

The most common one to know right now is `i` (case-insensitive):

```php
print_r(preg_match('/cat/i', 'CAT')); // 1 — matches
print_r(preg_match('/cat/i', 'Cat')); // 1 — matches
```
{: data-run="" }

Other flags exist (`m`, `s`, `u`, `x`...), but we only introduce them when a chapter actually needs them. For now, `i` is more than enough.

---

## 6) A Gentle Note on What's Next

As you progress, you'll see terms like:

- **[Anchors](03-anchors-boundaries.md)** (`^`, `$`) — match *positions*, not characters
- **[Character classes](02-character-classes.md)** (`[abc]`, `\d`, `\w`) — match *one* character from a set
- **[Quantifiers](04-quantifiers.md)** (`*`, `+`, `?`, `{n,m}`) — say *how many* times
- **[Groups and alternation](05-groups-alternation.md)** (`(...)`, `|`) — group or choose between options

**You don't need any of these to understand this chapter.** Whenever we touch them later, we'll explain them from scratch and link back here if useful.

Also, notice the email example you might have seen elsewhere: a realistic email pattern combines anchors, character classes, a literal dot and quantifiers all at once. That's great for later, but it's **too much** for a first day. We'll build up to it step by step.

---

## 7) Exercises

Do only what you've just learned. Don't reach for anything from future chapters.

### Exercise 1: Literal matching
Write a pattern that matches the word `hello` exactly as lowercase.

<details markdown="1">
<summary>Solution</summary>

```php
'/hello/'
```

</details>

### Exercise 2: Case-insensitive
Write a pattern that matches `hello`, `HELLO` or `Hello`.

<details markdown="1">
<summary>Solution</summary>

```php
'/hello/i'
```

</details>

### Exercise 3: Literal dot
Write a pattern that matches `file.txt` literally (not "file" followed by any character followed by "txt").

<details markdown="1">
<summary>Solution</summary>

```php
'/file\.txt/'
```

</details>

### Exercise 4: Different delimiter
Take the pattern `/https://example.com/` and rewrite it using `#` as the delimiter instead. Don't forget to escape the `.`

<details markdown="1">
<summary>Solution</summary>

```php
'#https://example\.com#'
```

</details>

---

## Key Takeaways

- A **pattern** describes text to look for.
- **Literals** match themselves. By default it's **case-sensitive**.
- Patterns in PHP/PCRE are wrapped by **delimiters** (`/`, `#`, `~`, ...).
- Some characters are **special**. Use `\` to match them **literally** (start with `.` -> `\.`).
- **Flags** like `i` change behaviour. Add them after the closing delimiter.
- Learn one thing at a time. Concepts like anchors, classes and quantifiers will come in their own chapters with links between them.

---

## Optional: Tools to Inspect Patterns

If you ever want a second opinion on "what does this pattern actually mean?", there are tools that can explain it to you. These are **completely optional**. You can skip this section and come back to it later.

### PHPRegex CLI

```bash
vendor/bin/regex explain '/cat/i'
vendor/bin/regex diagram '/cat/i'
vendor/bin/regex validate '/cat/i'
```

### PHPRegex API

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();

// Validate
$result = $regex->validate('/cat/i');

// Explain
echo $regex->explain('/cat/i');
```

**You do not need these to learn regex.** They're here to help debug or visualize once you already understand the pattern you're looking at.

---

**Next:** [Chapter 2: Character Classes & Escapes](02-character-classes.md)

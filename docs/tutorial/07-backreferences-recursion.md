---
layout: tutorial
description: "Reuse captured text with backreferences, and see how subroutines and recursion work for more advanced patterns."
---

# Chapter 7: Backreferences, Subroutines & Recursion

> **Goal:** Match repeated text and handle simple nested structures.

---

## 1) Backreferences

A **backreference** lets you match **exactly the same text** that was captured by a previous [group](05-groups-alternation.md).

### Numbered backreferences `\1`, `\2`, ...

```php
// Match repeated words like "hello hello"
preg_match('/\b(\w+)\s+\1\b/', 'hello hello world', $matches);
echo $matches[0]; // "hello hello"
```

Group `1` captured `hello`, `\1` requires the same string again.

### Named backreferences `\k<name>`

Easier to read when you have multiple groups:

```php
preg_match('/\b(?<word>\w+)\s+\k<word>\b/', 'test test', $matches);
echo $matches[0]; // "test test"
```

### Matching quotes

```php
preg_match('/(["\'])(.*?)\1/', '"hello"', $matches);
echo $matches[0]; // '"hello"'
```

Group `1` captures either `"` or `'`, `\1` forces the same one at the end. Note we use `(.*?)` (lazy) to avoid consuming too much.

---

## 2) Subroutines `(?1)`, `(?&name)`

A **subroutine** reuses the **pattern** of another group, without treating it as a new capture in the same way. Think of it as "insert this group's recipe here".

### Numbered subroutine

```php
// Reuse group 1's pattern
preg_match('/(a)(?1)/', 'aa', $matches);
echo $matches[0]; // "aa"
```

Group 1 matches `a`, `(?1)` runs the same pattern again (`a`).

### Named subroutine

```php
preg_match('/(?<char>a)(?&char)/', 'aa', $matches);
echo $matches[0]; // "aa"
```

---

## 3) Recursion `(?R)`

`(?R)` calls the **entire pattern** again from the start. This is useful for matching balanced structures like parentheses or brackets.

```php
// Match balanced parentheses (one level or nested)
$pattern = '/\((?:[^()]|(?R))*\)/';

preg_match($pattern, '(hello)', $matches);
echo $matches[0]; // "(hello)"

preg_match($pattern, '(a(b)c)', $matches);
echo $matches[0]; // "(a(b)c)"
```

### How it works

- `\(` matches opening paren
- `(?:[^()]|(?R))*` matches either non-paren characters, **or** a nested balanced pair via `(?R)`
- `\)` matches closing paren
- `[^()]` acts as a **base case** so recursion can stop

**Important:** A recursive pattern must have some way to match **without** recursing. If every alternative recurses, it won't work.

---

## 4) Conditionals `(?(...)yes|no)`

Conditionals let you do "if this group matched, do X, else do Y".

### Based on a numbered group

```php
// If group 1 matched 'a', expect 'b', else expect 'c'
$pattern = '/^(a)?(?(1)b|c)/';

preg_match($pattern, 'ab', $matches); // matches
preg_match($pattern, 'c',  $matches); // matches
preg_match($pattern, 'ac', $matches); // no match
```

### Based on a named group

```php
$pattern = '/^(?<has>a)?(?(has)b|c)/';

preg_match($pattern, 'ab', $matches); // matches
preg_match($pattern, 'c',  $matches); // matches
```

---

## 5) Practical Tips

- **Backreferences** = "match the same text again"
- **Subroutines** = "run this group's pattern again"
- **Recursion** = "run the whole pattern again"
- For most real tasks, you'll use backreferences far more than recursion
- Matching arbitrary nested HTML with regex is tricky — a proper parser is usually the better choice

---

## 6) Exercises

Use chapters [1](01-basics.md)–[6](06-lookarounds.md).

### Exercise 1: Repeated word
Write a pattern to match doubled words like `the the` using a named group and `\k<name>`.

<details markdown="1">
<summary>Solution</summary>

```php
'/\b(?<word>\w+)\s+\k<word>\b/'
```

</details>

### Exercise 2: Matching quotes
Write a pattern that matches text inside matching single or double quotes.

<details markdown="1">
<summary>Solution</summary>

```php
'/(["\'])(.*?)\1/'
```

</details>

### Exercise 3: Balanced parentheses
Write a pattern that matches `(hello)` and `(a(b)c)` using `(?R)`.

<details markdown="1">
<summary>Solution</summary>

```php
'/\((?:[^()]|(?R))*\)/'
```

</details>

### Exercise 4: Conditional
Write a pattern that matches `ab` if `a` is present, else matches `c`. Use a named group.

<details markdown="1">
<summary>Solution</summary>

```php
'/(?<a>a)?(?(a)b|c)/'
```

</details>

---

## Key Takeaways

- **Backreferences** `\1`, `\k<name>` repeat captured text
- **Subroutines** `(?1)`, `(?&name)` repeat a group's pattern
- **Recursion** `(?R)` repeats the whole pattern (great for balanced pairs)
- **Conditionals** `(?(group)yes|no)` add simple logic
- Always include a **base case** when using recursion

---

## Common Pitfalls

- **Confusing backreference and subroutine** — `\1` = same text, `(?1)` = same pattern
- **Recursion without base case** — will fail or behave badly
- **Trying to fully parse HTML/XML** — regex can do small balanced bits, but parsers are safer
- **Backreference to non-existent group** — will cause errors

---

## Optional: Tools to Inspect Patterns

```bash
vendor/bin/regex explain '/\b(\w+)\s+\1\b/'
vendor/bin/regex diagram '/(["\'])(.*?)\1/'
```

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
echo $regex->explain('/(?<word>\w+)\s+\k<word>\b/');
```

---

**Next:** [Chapter 8: Performance & ReDoS](08-performance-redos.md)

---
layout: tutorial
description: "Group patterns with (...), (?:...) and named groups, use alternation, and see why option order — not grouping — decides which alternative wins."
---

# Chapter 5: Groups and Alternation

> **Goal:** Group patterns together and match one of several alternatives.

---

## What are Groups?

**Groups** let you treat multiple characters as a single unit. Think of them like **putting things in parentheses** in math:

```
Without groups:  a+b  means "a followed by one or more b's"

With groups:     (ab)+  means "ab" as a unit, repeated one or more times
                 "ab", "abab", "ababab", etc.
```

### Real-World Analogy

| Scenario             | Without Groups          | With Groups          |
|----------------------|-------------------------|----------------------|
| Phone with area code | `5551234567`            | `(555) 123-4567`     |
| HTTP URL             | `http: / / example.com` | `http://example.com` |
| Repeated phrase      | "hello hello hello"     | `(hello ){2}hello`   |

Note the last row: `(hello ){3}` requires the trailing space after **every** repetition, so it matches `"hello hello hello "` — with a final space. To match the phrase without one, the last repetition must drop it: `(hello ){2}hello`.

---

## Types of Groups

### 1. Capturing Groups (...)

Stores the matched text for later use:

```php
// Capture the first word
preg_match('/^(\w+)/', 'hello world', $matches);
echo $matches[1];  // Output: "hello"

// Capture both parts
preg_match('/^(\w+) (\w+)$/', 'hello world', $matches);
echo $matches[1];  // "hello"
echo $matches[2];  // "world"
```

### 2. Non-Capturing Groups `(?:...)`

Groups without capturing (faster, no storage):

```php
// Non-capturing: groups but doesn't store
preg_match('/(?:hello) (world)/', 'hello world', $matches);
echo $matches[0];  // "hello world" (full match)
echo $matches[1];  // "world" (only second group captured)
// No $matches[2] because first group was non-capturing
```

### 3. Named Groups `(?<name>...)`

Give groups a name for easier access:

```php
preg_match('/(?<greeting>hello) (?<name>world)/', 'hello world', $matches);
echo $matches['greeting'];  // "hello"
echo $matches['name'];      // "world"
```

---

## Alternation: The Pipe `|`

Match **one of several** alternatives:

```php
// Match cat OR dog OR bird
preg_match('/(cat|dog|bird)/', 'I have a dog', $matches);
echo $matches[0];  // "dog"
echo $matches[1];  // "dog" (captured group)
```

### Alternation behavior

Alternation tries options from left to right and returns the first match. For `/(cat|dog|bird)/` against `"The dog is here"`, the match is `"dog"`.

### Grouping with Alternation

Use parentheses to control scope:

```php
// Without grouping: foo OR barbaz
preg_match('/foo|barbaz/', 'foobaz', $m);
echo $m[0];  // "foo" (first alternative wins!)

// With grouping: foobaz OR barbaz
preg_match('/(foo|bar)baz/', 'foobaz', $m);
echo $m[0];  // "foobaz"
echo $m[1];  // "foo"
```

---

## Group Examples

### Capturing Groups

```php
// Extract date parts
preg_match('/(\d{4})-(\d{2})-(\d{2})/', '2024-01-15', $m);
echo $m[0];  // "2024-01-15" (full match)
echo $m[1];  // "2024" (year)
echo $m[2];  // "01" (month)
echo $m[3];  // "15" (day)
```

### Named Groups

```php
// Extract with names
preg_match('/(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})/', '2024-01-15', $m);
echo $m['year'];   // "2024"
echo $m['month'];  // "01"
echo $m['day'];    // "15"
```

### Non-Capturing Groups

```php
// Group without capturing
preg_match('/(?:get|post|put|delete) \/api/i', 'POST /api', $m);
echo $m[0];  // "POST /api" (full match)
// No captured group for HTTP method!
```

---

## Complex Group Examples

### HTTP Method Validator

```php
// Match exactly GET, POST, PUT, or DELETE
preg_match('/^(get|post|put|delete)$/i', 'POST', $matches);  // Match: yes
preg_match('/^(get|post|put|delete)$/i', 'PATCH', $matches);  // Match: no
```

### Email with Named Groups

```php
$pattern = '/^(?<user>[^@]+)@(?<domain>[^@]+)$/';
preg_match($pattern, 'alice@example.com', $m);

echo $m['user'];    // "alice"
echo $m['domain'];  // "example.com"
```

### Nested Groups

Numbering follows the **opening** parenthesis, left to right:

```php
// Outer group contains inner groups
preg_match('/((?<outer>hello) (?<inner>world))/', 'hello world', $m);
echo $m[0];         // "hello world" (full match)
echo $m[1];         // "hello world" (group 1: the outer anonymous group)
echo $m['outer'];   // "hello" (group 2)
echo $m['inner'];   // "world" (group 3)
```

---

## Good Patterns vs Bad Patterns

### Good: Clear and Efficient

```php
// Non-capturing when you don't need the data
'/(?:get|post|put|delete) \/api/'

// Named groups for clarity
'/(?<email>[^@]+)@(?<domain>[^@]+)/'

// Proper grouping with alternation
'/^(?:html|css|js)$/'
```

### Bad: Confusing or Inefficient

```php
// Too many unnamed groups
'/(\w+) (@) (\w+) (\.) (\w+)/'

// Missing grouping on alternation
'/html|css|js\/api/'  // Example match: "html", "css", or "js/api"

// Proper grouping
'/(?:html|css|js)\/api/'
```

---

## Exercises

### Exercise 1: Match HTTP Methods

Write a pattern that matches GET, POST, PUT, DELETE (case-insensitive):

```php
$pattern = '/^(?:GET|POST|PUT|DELETE)$/i';
// or: '/^(get|post|put|delete)$/i'
```

### Exercise 2: Extract Date Parts

Write a pattern to extract year, month, day from "2024-01-15":

```php
$pattern = '/(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})/';
preg_match($pattern, '2024-01-15', $m);
echo $m['year'];   // "2024"
echo $m['month'];  // "01"
echo $m['day'];    // "15"
```

### Exercise 3: Test Alternation Order

Alternation tries options from left to right; the first alternative that matches wins. Grouping does **not** change that order — it only bounds the scope.

```php
// "foo" is tried first and matches, so "foobar" is never tried
preg_match('/foo|foobar/', 'foobar', $m);
echo "Left first: " . $m[0] . "\n";  // "foo"

// Grouping changes nothing here — the order still decides
preg_match('/(?:foo|foobar)/', 'foobar', $m);
echo "Grouped: " . $m[0] . "\n";     // "foo"

// Put the longer alternative first to get the longer match
preg_match('/(?:foobar|foo)/', 'foobar', $m);
echo "Long first: " . $m[0] . "\n";  // "foobar"
```

---

## Key Takeaways

1. **Groups** `(...)` treat multiple characters as one unit
2. **Capturing groups** `(...)` store matched text
3. **Non-capturing groups** `(?:...)` don't store (faster)
4. **Named groups** `(?<name>...)` use names instead of numbers
5. **Alternation** `|` matches one of several alternatives
6. **Group alternation** with `()` to control scope; **order** decides which option wins

---

## Common Errors

### Error: Forgetting Grouping with Alternation

```php
// Wrong: "html" matches on its own, without the "/api" part
preg_match('/html|css\/api/', 'html/api', $m);
echo $m[0];  // "html" (left alternative wins, "/api" never checked)

// Correct: Group the alternatives
preg_match('/(?:html|css)\/api/', 'css/api', $m);
echo $m[0];  // "css/api"
```

### Error: Too Many Capturing Groups

```php
// Slow: Many groups when you don't need them
'/(\w+) (@) (\w+) (\.) (\w+)/'

// Better: Non-capturing groups for structure
'/\w+ @ \w+ \. \w+/'

// Best: Only capture what you need
'/\w+ (?<user>\w+) @ \w+ \. (?<tld>\w+)/'
```

---

## Recap

You now understand:
- Capturing vs non-capturing groups
- Named groups
- Alternation and precedence
- Common pitfalls and fixes

**Next:** [Chapter 6: Lookarounds and Assertions](06-lookarounds.md)

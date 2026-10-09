# Chapter 4: Quantifiers and Greediness

> **Goal:** Control how many times a pattern should match using quantifiers like `*`, `+`, `?`, and `{n,m}`.

---

## What are Quantifiers?

**Quantifiers** specify **how many times** the previous element should match. Think of them like **quantity indicators** in English:

| English           | Regex   | Meaning         |
|-------------------|---------|-----------------|
| "zero or more"    | `*`     | 0 to unlimited  |
| "one or more"     | `+`     | 1 to unlimited  |
| "optional"        | `?`     | 0 or 1          |
| "exactly n"       | `{n}`   | Exactly n times |
| "at least n"      | `{n,}`  | n or more       |
| "between n and m" | `{n,m}` | n to m times    |

### Real-World Analogy

```
You need to order pizza for a group:

Pattern: /pizza+/
         "pizz" + "a" one or more times

Options:
  pizza    Match: yes (1 a)
  pizzza   Match: yes (3 a's)
  pizz     Match: no (need at least 1 a)

Pattern: /chips{0,2}/
         "chip" + "s" 0 to 2 times

Options:
  chip     Match: yes (0 s)
  chips    Match: yes (1 s)
  chipss   Match: yes (2 s)
  chipsss  Match: no (too many s)
```

---

## Basic Quantifiers

### * - Zero or More

Matches 0 or more occurrences:

```php
// "a" followed by zero or more "b"s
preg_match('/ab*/', 'a');      // Match: yes (0 b's)
preg_match('/ab*/', 'ab');     // Match: yes (1 b)
preg_match('/ab*/', 'abbb');   // Match: yes (3 b's)
```

### + - One or More

Matches 1 or more occurrences:

```php
// "a" followed by one or more "b"s
preg_match('/ab+/', 'a');      // Match: no (need at least 1 b)
preg_match('/ab+/', 'ab');     // Match: yes (1 b)
preg_match('/ab+/', 'abbb');   // Match: yes (3 b's)
```

### ? - Zero or One (Optional)

Matches 0 or 1 occurrence (makes something optional):

```php
// "a" followed by optional "b"
preg_match('/ab?/', 'a');      // Match: yes (0 b)
preg_match('/ab?/', 'ab');     // Match: yes (1 b)
preg_match('/ab?/', 'abb');    // Match: yes ("ab", only 1 b)
```

### Examples with "abbb"

- `/ab*/` matches `"abbb"` (a followed by zero or more b).
- `/ab+/` matches `"abbb"` (a followed by one or more b).
- `/ab?/` matches `"ab"` (a followed by an optional b).

---

## Exact Quantifiers with Braces

Use `{}` for precise control:

| Pattern | Meaning          | Example Matches                  |
|---------|------------------|----------------------------------|
| `{n}`   | Exactly n times  | `a{3}` -> "aaa"                   |
| `{n,}`  | At least n times | `a{2,}` -> "aa", "aaa", "aaaa"... |
| `{n,m}` | Between n and m  | `a{2,4}` -> "aa", "aaa", "aaaa"   |

### Examples

```php
// Exactly 3 digits
preg_match('/\d{3}/', '123');      // Match: yes
preg_match('/\d{3}/', '12');       // Match: no (only 2)
preg_match('/\d{3}/', '1234');     // Match: yes ("123", first 3)

// At least 2 digits
preg_match('/\d{2,}/', '123');     // Match: yes
preg_match('/\d{2,}/', '1');       // Match: no (only 1)

// Between 2 and 4 digits
preg_match('/\d{2,4}/', '123');    // Match: yes
preg_match('/\d{2,4}/', '12345');  // Match: yes ("1234", first 4)
```

---

## Greediness: The Default Behavior

By default, quantifiers are **greedy** - they match as many characters as possible:

```php
$text = "12345";

preg_match('/\d+/', $text, $matches);
echo $matches[0];  // Output: "12345" (ALL digits!)
```

### How Greedy Matching Works

Text: `"<p>hello</p>"`

- Pattern: `/<.+>/`
- `<` is literal.
- `.` matches any character.
- `+` repeats one or more times.
- Greedy matching consumes the longest possible span, so the match is `"<p>hello</p>"`.

---

## Lazy (Non-Greedy) Matching

Add `?` after a quantifier to make it **lazy** - match as few characters as possible:

```php
$text = "<p>hello</p>";

// Greedy: matches as much as possible
preg_match('/<.+>/', $text, $matches);
echo $matches[0];  // Output: "<p>hello</p>"

// Lazy: matches as little as possible
preg_match('/<.+?>/', $text, $matches);
echo $matches[0];  // Output: "<p>" (first tag only)
```

### Greedy vs Lazy Comparison

| Pattern   | Text       | Match      | Why                                    |
|-----------|------------|------------|----------------------------------------|
| `/.+/`    | "abc"      | "abc"      | Greedy: all characters                 |
| `/.+?/`   | "abc"      | "a"        | Lazy: one character is enough          |
| `/<.+>/`  | "<p>x</p>" | "<p>x</p>" | Greedy: everything                     |
| `/<.+?>/` | "<p>x</p>" | "<p>"      | Lazy: stops at first `>`               |

### When to Use Lazy

```php
// Extract content between tags (lazy)
preg_match('/<p>(.+?)<\/p>/', '<p>hello</p><p>world</p>', $matches);
echo $matches[1];  // Output: "hello" (first paragraph only)

// Get all paragraphs (need preg_match_all)
preg_match_all('/<p>(.+?)<\/p>/', '<p>hello</p><p>world</p>', $matches);
print_r($matches[1]);  // Output: Array ( [0] => "hello", [1] => "world" )
```

---

## Possessive Quantifiers (Performance)

Add `++`, `*+`, `?+` to prevent backtracking (great for ReDoS prevention):

```php
// Regular quantifier (can backtrack)
preg_match('/a+b/', 'aaab');  // Match: yes (but may cause ReDoS)

// Possessive (never backtracks - faster, safer)
preg_match('/a++b/', 'aaab');  // Match: yes (no backtracking)
```

### When to Use Possessive

| Scenario            | Pattern    | Benefit         |
|---------------------|------------|-----------------|
| Match possessive    | `/a++b/`   | No backtracking |
| Character class     | `/[ab]++/` | Faster matching |
| Optional possessive | `/a?+b/`   | Prevent ReDoS   |

---

## Good Patterns vs Bad Patterns

### Good: Specific and Safe

```php
// Match 1-3 digits
'/\d{1,3}/'

// Match word with optional plural
'/\w+s?/'

// Match HTML tags (lazy)
'/<[^>]+>/'

// Match with possessive (performance)
'/[a-z]++/'
```

### Bad: Too Vague or Dangerous

```php
// Too greedy - matches too much
'/.*/

// Nested quantifiers - ReDoS risk!
'/(a+)+$/

// Missing bounds on user input
preg_match('/x{0,}/', $userInput);  // Could be huge!

// Better: Set reasonable limits
preg_match('/x{1,100}/', $userInput);  // Reasonable limit
```

---

## Exercises

### Exercise 1: Identify Matches

For each pattern, what does it match?

1. `/a*/` on "aaa"
2. `/a+/` on "aaa"
3. `/a?/` on "aaa"
4. `/a{2,3}/` on "aaaa"

```php
// Answers:
// 1. "aaa" (zero or more a's = all a's)
// 2. "aaa" (one or more a's = all a's)
// 3. "a" (zero or one a = first a only)
// 4. "aaa" (2-3 a's = first 3 a's)
```

### Exercise 2: Write Patterns

Write patterns that match:

1. One or more digits
2. 10-digit phone number
3. Optional file extension
4. HTML tag content (lazy)

```php
// Solution 1
$pattern1 = '/\d+/';

// Solution 2
$pattern2 = '/\d{10}/';

// Solution 3
$pattern3 = '/\.[a-z]+/i';  // With . prefix for extension

// Solution 4
$pattern4 = '/>(.+?)</';
```

### Exercise 3: Test Greedy vs Lazy

```php
$text = "<div>hello</div>";

preg_match('/<.+>/', $text, $m);
echo "Greedy: " . $m[0] . "\n";

preg_match('/<.+?>/', $text, $m);
echo "Lazy: " . $m[0] . "\n";
```

---

## Key Takeaways

1. **Quantifiers** control repetition: `*`, `+`, `?`, `{n,m}`
2. **`*`** = 0 or more (zero or more)
3. **`+`** = 1 or more (at least one)
4. **`?`** = 0 or 1 (optional)
5. **`{n,m}`** = exact range
6. **Greedy** = matches maximum (default)
7. **Lazy** = matches minimum (add `?`)
8. **Possessive** = no backtracking (add `++`, `*+`, `?+`)

---

## Common Errors

### Error: Forgetting Quantifier Defaults to Greedy

```php
$text = "123 456 789";

// Greedy by default: each \d+ takes a whole run of digits
preg_match('/\d+\s+\d+/', $text, $m);
echo $m[0];  // "123 456" (two full runs - the match ends once the pattern is satisfied)

// Lazy: each quantifier stops as soon as it can
preg_match('/\d+?\s+\d+?/', $text, $m);
echo $m[0];  // "123 4" (the trailing \d+? stops after one digit)
```

### Error: Nested Quantifiers (ReDoS Risk)

```php
// Dangerous: Can cause exponential backtracking
'/(a+)+$/'  // DO NOT USE!

// Safe: Use possessive quantifiers
'/(a++)+$/'   // Better
'/a+$/'       // Even better - simplify!
```

### Error: Unbounded User Input

```php
// Dangerous: No limit
preg_match('/a{1,}/', $userInput);

// Safe: Set reasonable limit
preg_match('/a{1,100}/', $userInput);
```

---

## Recap

You now understand:
- All quantifier types (`*`, `+`, `?`, `{n,m}`)
- Greedy vs lazy matching
- Possessive quantifiers for performance
- Common pitfalls and fixes

**Next:** [Chapter 5: Groups and Alternation](05-groups-alternation.md)

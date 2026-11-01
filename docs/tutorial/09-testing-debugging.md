---
layout: tutorial
description: "Learn a simple way to test and debug regex patterns using plain PHP first."
---

# Chapter 9: Testing & Debugging

> **Goal:** Build a practical workflow to test, understand, and fix patterns.

---

## 1) Start Simple with PHP

You don't need any extra tools to test patterns. PHP's built-in functions are more than enough for learning.

```php
$pattern = '/\d{3}/';
$text = 'Order 123 and 456';

if (preg_match($pattern, $text, $matches)) {
    echo "Found: " . $matches[0]; // "123"
}
```
{: data-run="" }

To find **all** matches:

```php
preg_match_all('/\d+/', 'a1 b22 c333', $matches);
print_r($matches[0]); // ["1", "22", "333"]
```
{: data-run="" }

---

## 2) A Practical Testing Workflow

When a pattern doesn't behave the way you expect, go step by step:

### Step 1: Write a small test

Keep it minimal. Test on the exact string you're trying to match.

```php
$pattern = '/^hello$/';
var_dump(preg_match($pattern, 'hello')); // int(1)
var_dump(preg_match($pattern, 'hello!')); // int(0)
```
{: data-run="" }

### Step 2: Check anchors and scope

If it's matching too much or too little, check [anchors](03-anchors-boundaries.md).

```php
// Matches anywhere
preg_match('/cat/', 'category'); // matches "cat"
print_r(preg_match('/cat/', 'category')); // 1

// Match whole word only
preg_match('/\bcat\b/', 'category'); // no match
print_r(preg_match('/\bcat\b/', 'category')); // 0
```
{: data-run="" }

### Step 3: Check quantifiers

If it's greedy when you want lazy, or vice versa, check [quantifiers](04-quantifiers.md).

```php
$text = '<b>hi</b>';

// Too much
preg_match('/<.+>/', $text, $m);  // <b>hi</b>
print_r($m[0]);

// Just right
preg_match('/<.+?>/', $text, $m); // <b>
print_r($m[0]);
```
{: data-run="" }

### Step 4: Check groups and alternation

If `|` is behaving oddly, it's usually a grouping issue ([Chapter 5](05-groups-alternation.md)).

```php
// Wrong scope
preg_match('/jpg|png\.gif/', 'a.png.gif', $m); // matches "png.gif" but for wrong reason
print_r($m[0]);
// Better
preg_match('/(?:jpg|png)\.gif/', 'a.png.gif', $m);
print_r($m[0]);
```
{: data-run="" }

---

## 3) Common Debugging Tricks

### Use `var_dump` on matches

```php
preg_match('/(\w+)@(\w+)\.(\w+)/', 'user@example.com', $matches);
var_dump($matches);
// Shows full match + each captured group
```
{: data-run="" }

### Test with `preg_match_all` to see all matches

```php
preg_match_all('/\b\w{4}\b/', 'cat dog bird fish', $matches);
print_r($matches[0]); // ["bird", "fish"]
```
{: data-run="" }

### Isolate pieces

If a long pattern fails, break it down. Test each part separately, then combine.

```php
// Test parts
preg_match('/^\w+$/', 'user');      // yes
print_r(preg_match('/^\w+$/', 'user')); // 1
preg_match('/^@/', '@example');     // yes
print_r(preg_match('/^@/', '@example')); // 1
preg_match('/^\w+\.\w+$/', 'com');  // no — this part expects a dot
print_r(preg_match('/^\w+\.\w+$/', 'com')); // 0
```
{: data-run="" }

### Watch for escaping

Remember [Chapter 1](01-basics.md): in PHP strings, backslashes need care. In single quotes less so, but better to be aware.

```php
// In PHP string
$pattern = '/\d+/'; // \d is fine in single quotes
print_r(preg_match($pattern, 'Order 123')); // 1
```

### Check case sensitivity

```php
preg_match('/hello/', 'HELLO');   // no match
print_r(preg_match('/hello/', 'HELLO')); // 0
preg_match('/hello/i', 'HELLO'); // match
print_r(preg_match('/hello/i', 'HELLO')); // 1
```
{: data-run="" }

---

## 4) Testing Checklist

Before using a pattern anywhere important, ask yourself:

- [ ] **Does it match what I want?** Test positive cases
- [ ] **Does it reject what I don't want?** Test negative cases
- [ ] **Does it match the whole thing or just part?** Consider [anchors](03-anchors-boundaries.md)
- [ ] **Is it too greedy?** Try [lazy](04-quantifiers.md#5-lazy-non-greedy-matching) if needed
- [ ] **Is it safe?** Check for [nested quantifiers](08-performance-redos.md)
- [ ] **Does it handle edge cases?** Empty string, single char, special chars
- [ ] **Is it readable?** If it's too complex, can you simplify it?

---

## 5) Exercises

### Exercise 1: Debug a broken pattern
This pattern is supposed to match dates like `2025-10-23`, but something's wrong. Fix it.

```php
$pattern = '/\d{4}\d{2}\d{2}/'; // broken
print_r(preg_match($pattern, '2025-10-23')); // 0 — it never matches a real date
```
{: data-run="" }

<details markdown="1">
<summary>Solution</summary>

Missing the dashes:

```php
$pattern = '/\d{4}-\d{2}-\d{2}/';
print_r(preg_match($pattern, '2025-10-23')); // 1
```
{: data-run="" }

</details>

### Exercise 2: Greedy vs lazy
Extract just `title` from `<title>My Page</title>`. Use a lazy match.

<details markdown="1">
<summary>Solution</summary>

```php
preg_match('/<title>(.*?)<\/title>/', '<title>My Page</title>', $matches);
echo $matches[1]; // "My Page"
```
{: data-run="" }

</details>

### Exercise 3: Whole word only
Fix this so it matches `cat` only as a whole word, not in `category`.

```php
$pattern = '/cat/';
print_r(preg_match($pattern, 'category')); // 1 — matches too much
```
{: data-run="" }

<details markdown="1">
<summary>Solution</summary>

```php
$pattern = '/\bcat\b/';
print_r(preg_match($pattern, 'category')); // 0
```
{: data-run="" }

</details>

### Exercise 4: Build a test
Write a small test script that checks if an email-like string is valid using anchors and a simple pattern.

<details markdown="1">
<summary>Solution</summary>

```php
$pattern = '/^[\w.-]+@[\w.-]+\.[a-z]{2,}$/i';

$tests = ['a@b.com', 'bad-email', 'user.name@test.org'];
foreach ($tests as $t) {
    echo $t . ': ' . (preg_match($pattern, $t) ? 'OK' : 'NO') . PHP_EOL;
}
```
{: data-run="" }

</details>

---

## Key Takeaways

- **Test small** — Start with a tiny example and grow
- **Use `preg_match`/`preg_match_all`** with `$matches` to inspect results
- **Break it down** — Isolate complex patterns into parts
- **Think about scope** — Anchors, word boundaries, and grouping fix most issues
- **Test negative cases** — Making sure it *doesn't* match is just as important

---

## Optional: Extra Tools

If you'd like to explain or visualize patterns while debugging, there are optional tools available. You can skip these entirely and still follow everything above.

```bash
vendor/bin/regex explain '/\d{4}-\d{2}-\d{2}/'
vendor/bin/regex diagram '/(\w+)\s+\1/'
```

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
echo $regex->explain('/^[a-z]+$/i');
```

---

**Next:** [Chapter 10: Real-World Patterns in PHP](10-real-world-php.md)

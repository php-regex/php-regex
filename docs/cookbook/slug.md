---
description: "A PHP regex for slugs: lower-case letters and digits in segments separated by single hyphens, proven safe from ReDoS, with its matches, rejects and a PHP example."
head_title: "PHP regex for slugs: letters, digits, hyphens"
parent: /cookbook/
pattern: "/^[a-z0-9]+(?:-[a-z0-9]+)*$/"
---
# PHP regex for slugs

This pattern accepts a slug made of lower-case letters and digits, in one or more segments separated by single hyphens: `my-post`, `hello-world-123`, `a`. It refuses a hyphen at the start or at the end, two hyphens in a row, capitals, an underscore, a space and any other character.

```
/^[a-z0-9]+(?:-[a-z0-9]+)*$/
```

**Try it.** [Open `/^[a-z0-9]+(?:-[a-z0-9]+)*$/` in the playground](/#pattern=%2F%5E%5Ba-z0-9%5D%2B%28%3F%3A-%5Ba-z0-9%5D%2B%29*%24%2F&subject=my-post): the Diagnostics tab shows what the linter says of it.

## What it matches

```
✓ my-post
✓ hello-world-123
✓ a
✓ 0  (digits alone make a segment)
```

## What it rejects

```
✗ -my-post  (starts with hyphen)
✗ my-post-  (ends with hyphen)
✗ my--post  (consecutive hyphens)
✗ my_post  (underscore not allowed)
✗ My-Post  (capitals)
```

## Why it is safe

- The pattern requires at least one alphanumeric character.
- It allows additional segments separated by hyphens.
- Each segment is bounded by hyphens or end of string.

The only repeat is closed by a hyphen, which the segment before it cannot hold: the engine reads a slug one way, and the analyzer's verdict is `safe (proven)`.

## How to validate a slug in PHP

```php
$pattern = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

var_dump(preg_match($pattern, 'my-post')); // int(1)
var_dump(preg_match($pattern, 'my--post')); // int(0)
```

---

Back to the [cookbook](../cookbook.md) for the other fourteen recipes, and for how to build a pattern of your own.

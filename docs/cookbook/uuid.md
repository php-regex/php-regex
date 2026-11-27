---
description: "A PHP regex for UUIDs of versions 1 to 5, proven safe from ReDoS: what it accepts, what it refuses, why it is linear, and how to validate a UUID in PHP."
head_title: "PHP regex for UUIDs: versions 1 to 5"
parent: /cookbook/
pattern: "/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i"
---
# PHP regex for UUIDs

This pattern accepts a UUID in its canonical text form: 32 hexadecimal digits in five groups of 8, 4, 4, 4 and 12 digits, separated by hyphens — 36 characters in all. It asks for a version digit from 1 to 5 and a variant digit that is `8`, `9`, `a` or `b`. Capitals pass under the `i` flag.

It refuses everything else: braces, a hyphen gone, any length but 36 characters, and the other versions. [RFC 9562](https://www.rfc-editor.org/rfc/rfc9562) also defines versions 6, 7 and 8; the pattern does not know them.

```
/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i
```

**Try it.** [Open `/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i` in the playground](/#pattern=%2F%5E%5B0-9a-f%5D%7B8%7D-%5B0-9a-f%5D%7B4%7D-%5B1-5%5D%5B0-9a-f%5D%7B3%7D-%5B89ab%5D%5B0-9a-f%5D%7B3%7D-%5B0-9a-f%5D%7B12%7D%24%2Fi&subject=550e8400-e29b-41d4-a716-446655440000): the Diagnostics tab shows what the linter says of it.

## What it matches

```
✓ 550e8400-e29b-41d4-a716-446655440000  (version 4)
✓ 6ba7b810-9dad-11d1-80b4-00c04fd430c8  (version 1)
✓ 6ba7b811-9dad-11d1-80b4-00c04fd430c8  (version 1)
✓ 550E8400-E29B-41D4-A716-446655440000  (capitals pass under the i flag)
```

## What it rejects

```
✗ 550e8400-e29b-41d4-a716-44665544000  (eleven digits in the last group: twelve are needed)
✗ 550e8400-e29b-41d4-a716-446655440000g  (a character after the last group)
✗ 550e8400-e29b-41d4-a716-4466554400  (ten digits in the last group: twelve are needed)
✗ 018f47dc-4c7e-7d30-9c3e-8a3f0d2b6e5c  (version 7: the version digit must be 1 to 5)
✗ 550e8400-e29b-41d4-c716-446655440000  (variant digit c: 8, 9, a or b are expected)
✗ {550e8400-e29b-41d4-a716-446655440000}  (braces)
```

## Why it is safe

- Five fixed-length hex groups with exact bounds.
- The version and the variant positions are constrained to one digit each.
- No quantifier is open, none nests, and no two ways of reading the same text exist: the analyzer's verdict is `safe (proven)`.

## How to validate a UUID in PHP

```php
$pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

var_dump(preg_match($pattern, '550e8400-e29b-41d4-a716-446655440000')); // int(1)
var_dump(preg_match($pattern, '550e8400-e29b-41d4-a716-4466554400')); // int(0)
```

---

Back to the [cookbook](../cookbook.md) for the other fourteen recipes, and for how to build a pattern of your own.

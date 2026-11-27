---
description: "A PHP regex for hex colors, #rgb and #rrggbb, proven safe from ReDoS: what it matches, what it rejects, and how to validate a color in PHP."
head_title: "PHP regex for hex colors: #rgb and #rrggbb"
parent: /cookbook/
pattern: "/^#(?:[0-9a-fA-F]{3}){1,2}$/"
---
# PHP regex for hex colors

This pattern accepts a color written `#rgb` or `#rrggbb`: a hash, then three or six hexadecimal digits, capitals included. It refuses any other count of digits — four, five, or eight, the form with an alpha channel — a letter outside `a` to `f`, and a value without its hash.

```
/^#(?:[0-9a-fA-F]{3}){1,2}$/
```

**Try it.** [Open `/^#(?:[0-9a-fA-F]{3}){1,2}$/` in the playground](/#pattern=%2F%5E%23%28%3F%3A%5B0-9a-fA-F%5D%7B3%7D%29%7B1%2C2%7D%24%2F&subject=%23fff): the Diagnostics tab shows what the linter says of it.

## What it matches

```
✓ #fff
✓ #FFF
✓ #ffffff
✓ #FFFFFF
✓ #abc
✓ #a1b2c3
```

## What it rejects

```
✗ #fffff  (5 digits: 3 or 6)
✗ #gggggg  (invalid hex)
✗ fff  (missing #)
✗ #ffff  (4 digits: 3 or 6)
✗ #ffffffff  (8 digits: an alpha channel is not supported)
```

## Why it is safe

A literal, then a class repeated exactly three or six times. Both counts are fixed, so the engine never goes back on what it read, and the analyzer's verdict is `safe (proven)`.

## How to validate a hex color in PHP

```php
$pattern = '/^#(?:[0-9a-fA-F]{3}){1,2}$/';

var_dump(preg_match($pattern, '#1a2B3c')); // int(1)
var_dump(preg_match($pattern, '#ffff')); // int(0)
```

---

Back to the [cookbook](../cookbook.md) for the other fourteen recipes, and for how to build a pattern of your own.

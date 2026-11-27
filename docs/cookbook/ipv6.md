---
description: "A PHP regex for the full form of IPv6 addresses, eight groups of hex digits, proven safe from ReDoS: its matches, its rejects, and filter_var() for the rest."
head_title: "PHP regex for IPv6 addresses: eight hex groups"
parent: /cookbook/
pattern: "/^(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}$/i"
---
# PHP regex for IPv6 addresses

This pattern accepts an IPv6 address in its full form: eight groups of one to four hexadecimal digits, separated by colons and nothing else, as in `2001:0db8:0000:0000:0000:0000:0000:0001`. Capitals pass under the `i` flag. It refuses the compressed form (`2001:db8::1`), a group longer than four digits, and seven or nine groups.

```
/^(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}$/i
```

**Try it.** [Open `/^(?:[0-9a-f]{1,4}:){7}[0-9a-f]{1,4}$/i` in the playground](/#pattern=%2F%5E%28%3F%3A%5B0-9a-f%5D%7B1%2C4%7D%3A%29%7B7%7D%5B0-9a-f%5D%7B1%2C4%7D%24%2Fi&subject=2001%3A0db8%3A0000%3A0000%3A0000%3A0000%3A0000%3A0001): the Diagnostics tab shows what the linter says of it.

## What it matches

```
✓ 2001:0db8:0000:0000:0000:0000:0000:0001
✓ fe80:0000:0000:0000:0000:0000:0000:0001
✓ 0000:0000:0000:0000:0000:0000:0000:0001
✓ 1:2:3:4:5:6:7:8  (a group may hold fewer than four digits)
```

## What it rejects

```
✗ 2001:db8::1  (compressed form)
✗ 12345::1  (a group of five digits)
✗ 1:2:3:4:5:6:7:8:9  (nine groups)
✗ 1:2:3:4:5:6:7:8:  (an empty group)
```

## Why it is safe

Seven groups of one to four hexadecimal digits, each closed by a colon, then the eighth: every repeat is bounded, and a colon belongs to one group only, so the engine reads an address one way. The analyzer's verdict is `safe (proven)`.

## The compressed form needs PHP's own validator

The pattern above only accepts the full eight-group form. Compressed addresses such as `2001:db8::1`, `::1` or `fe80::1` are valid IPv6 but need the full RFC 4291 grammar; for validation prefer PHP's own validator, which accepts both forms:

```php
$ip = '2001:db8::1';

$valid = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
var_dump($valid); // bool(true)

$ip = '2001:db8:::1';

$valid = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
var_dump($valid); // bool(false)
```

---

Back to the [cookbook](../cookbook.md) for the other fourteen recipes, and for how to build a pattern of your own. [IPv4 addresses](ipv4.md) have their own page.

---
description: "A PHP regex for IPv4 addresses, four octets from 0 to 255 without a leading zero, proven safe from ReDoS: matches, rejects and a PHP example."
head_title: "PHP regex for IPv4: four octets, 0 to 255"
parent: /cookbook/
pattern: "/^(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])(?:\\.(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])){3}$/"
---
# PHP regex for IPv4 addresses

This pattern accepts an IPv4 address written as four octets from `0` to `255`, separated by dots and nothing else. Each octet is written without a leading zero: `10.0.0.1` and `192.168.1.1` pass, `192.168.001.1` is refused. It refuses an octet above 255, three or five octets, and any letter.

```
/^(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])(?:\.(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])){3}$/
```

**Try it.** [Open `/^(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])(?:\.(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])){3}$/` in the playground](/#pattern=%2F%5E%28%3F%3A25%5B0-5%5D%7C2%5B0-4%5D%5B0-9%5D%7C1%5B0-9%5D%5B0-9%5D%7C%5B1-9%5D%5B0-9%5D%7C%5B0-9%5D%29%28%3F%3A%5C.%28%3F%3A25%5B0-5%5D%7C2%5B0-4%5D%5B0-9%5D%7C1%5B0-9%5D%5B0-9%5D%7C%5B1-9%5D%5B0-9%5D%7C%5B0-9%5D%29%29%7B3%7D%24%2F&subject=192.168.1.1): the ReDoS tab proves the pattern linear, and the Diagnostics tab shows what the linter says of it.

## What it matches

```
✓ 192.168.1.1
✓ 10.0.0.1
✓ 255.255.255.255
✓ 0.0.0.0
```

## What it rejects

```
✗ 256.1.1.1  (octet above 255)
✗ 1.2.3  (too few octets)
✗ 1.2.3.4.5  (too many octets)
✗ 01.2.3.4  (leading zero)
✗ 192.168.001.1  (leading zero)
```

## Why it is safe

Each octet is one of five alternatives — `25` followed by `0` to `5`, `2` followed by `24` or less, `1` followed by two digits, a digit from `1` to `9` followed by one more, a single digit — and each alternative is deterministic: it takes a text the others cannot, so an octet reads one way only. That is what lets the analyzer prove the whole pattern linear, and its verdict is `safe (proven)`.

## How to validate an IPv4 address in PHP

```php
$pattern = '/^(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])(?:\.(?:25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]|[0-9])){3}$/';

var_dump(preg_match($pattern, '192.168.1.1')); // int(1)
var_dump(preg_match($pattern, '256.1.1.1')); // int(0)
```

PHP ships a validator of its own, which refuses a leading zero as the pattern does:

```php
var_dump(filter_var('192.168.1.1', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)); // string(11) "192.168.1.1"
var_dump(filter_var('192.168.001.1', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)); // bool(false)
var_dump(filter_var('256.1.1.1', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)); // bool(false)
```

---

Back to the [cookbook](../cookbook.md) for the other fourteen recipes, and for how to build a pattern of your own. [IPv6 addresses](ipv6.md) have their own page.

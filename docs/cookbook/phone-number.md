---
description: "PHP regexes for phone numbers in E.164 and North American formats, digits only, proven safe from ReDoS: what each matches, what each rejects, a PHP example."
head_title: "PHP regex for phone numbers: E.164 and US"
parent: /cookbook/
pattern: "/^\\+[1-9]\\d{6,14}$/"
---
# PHP regex for phone numbers

Two patterns, both for digits without any separator. The E.164 pattern accepts the international form: a plus sign, then 7 to 15 digits, the first of which is not `0`. The North American pattern accepts ten digits, or eleven with a leading `1` — a plus sign may stand before it — with the area code and the exchange both starting from `2` to `9`. Both refuse separators.

The first pattern is E.164:

```
/^\+[1-9]\d{6,14}$/
```

**Try it.** [Open `/^\+[1-9]\d{6,14}$/` in the playground](/#pattern=%2F%5E%5C%2B%5B1-9%5D%5Cd%7B6%2C14%7D%24%2F&subject=%2B14155552671): the Diagnostics tab shows what the linter says of it.

## What it matches

```
✓ +14155552671
✓ +442071838750
```

## What it rejects

```
✗ +04155552671  (country code can't start with 0)
✗ 415-555-2671  (separators not allowed)
✗ 4155552671  (no leading plus sign)
✗ +123456  (six digits: seven at least)
```

## Why it is safe

A class or two, then one bounded count: `\d{6,14}` for E.164, fixed counts for North America. Nothing nests and nothing is open, and the analyzer's verdict is `safe (proven)` for the two patterns.

## How to validate a phone number in PHP

```php
$e164 = '/^\+[1-9]\d{6,14}$/';
$northAmerica = '/^\+?1?[2-9]\d{2}[2-9]\d{6}$/';

var_dump(preg_match($e164, '+14155552671')); // int(1)
var_dump(preg_match($e164, '415-555-2671')); // int(0)
var_dump(preg_match($northAmerica, '4155552671')); // int(1)
var_dump(preg_match($northAmerica, '415-555-2671')); // int(0)
```

## The North American number may carry its country code

For the US and the rest of North America: ten digits, or eleven with the country code `1` before them.

```
/^\+?1?[2-9]\d{2}[2-9]\d{6}$/
```

```
✓ 4155552671
✓ 14155552671
✓ +14155552671
```

```
✗ 415555267  (too short)
✗ 415-555-2671  (separators not allowed)
✗ 11555552671  (the area code can't start with 1)
✗ 4151552671  (the exchange can't start with 1)
```

## A pattern cannot know every national format

Phone number validation is complex due to varying international formats. The patterns above are digits-only: strip separators before matching, or write a separator-aware pattern. Consider using a dedicated library like `libphonenumber-for-php` for production applications.

---

Back to the [cookbook](../cookbook.md) for the other fourteen recipes, and for how to build a pattern of your own.

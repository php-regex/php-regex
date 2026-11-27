---
description: "A PHP regex for ISO 8601 date-times: a date, a T, a time and an optional zone offset, proven safe from ReDoS, with its matches, rejects and a PHP example."
head_title: "PHP regex for date-times: the ISO 8601 shape"
parent: /cookbook/
pattern: "/^\\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01]?[0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?(?:Z|[+-](?:[01]?[0-9]|2[0-3]):[0-5][0-9])?$/u"
---
# PHP regex for date-times

This pattern accepts a date, a `T`, a time and, optionally, a zone: `2024-12-25T10:30:00`, `2024-12-25T10:30:00Z` or `2024-12-25T10:30:00+05:00`. The date is the ISO 8601 date of [the date page](date.md), the hour may be written with one digit, the seconds are optional, and the zone is `Z` or an offset written `+05:00` or `-05:00`.

It refuses a space instead of the `T`, an hour above `23` or a minute above `59`, a lowercase `t` or `z`, an offset written without its colon (`+0530`), and fractional seconds. Like the date it starts from, it checks the shape and not the calendar: `2024-02-30T10:30` passes. An offset up to `+23:59` passes too: the pattern checks the shape of the zone, not whether a zone uses it.

```
/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01]?[0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?(?:Z|[+-](?:[01]?[0-9]|2[0-3]):[0-5][0-9])?$/u
```

**Try it.** [Open `/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01]?[0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?(?:Z|[+-](?:[01]?[0-9]|2[0-3]):[0-5][0-9])?$/u` in the playground](/#pattern=%2F%5E%5Cd%7B4%7D-%28%3F%3A0%5B1-9%5D%7C1%5B0-2%5D%29-%28%3F%3A0%5B1-9%5D%7C%5B12%5D%5B0-9%5D%7C3%5B01%5D%29T%28%3F%3A%5B01%5D%3F%5B0-9%5D%7C2%5B0-3%5D%29%3A%5B0-5%5D%5B0-9%5D%28%3F%3A%3A%5B0-5%5D%5B0-9%5D%29%3F%28%3F%3AZ%7C%5B%2B-%5D%28%3F%3A%5B01%5D%3F%5B0-9%5D%7C2%5B0-3%5D%29%3A%5B0-5%5D%5B0-9%5D%29%3F%24%2Fu&subject=2024-12-25T10%3A30%3A00Z): the Diagnostics tab shows what the linter says of it.

## What it matches

```
✓ 2024-12-25T10:30:00Z
✓ 2024-12-25T10:30:00+05:00
✓ 2024-12-25T10:30:00
✓ 2024-12-25T10:30  (seconds are optional)
✓ 2024-12-25T10:30:00-05:00
```

## What it rejects

```
✗ 2024-12-25 10:30:00  (space instead of T)
✗ 2024-12-25T25:30:00  (invalid hour)
✗ 2024-12-25T10:30:60  (invalid second)
✗ 2024-12-25T10:30:00+0530  (offset without its colon)
✗ 2024-12-25T10:30:00.123Z  (fractional seconds)
```

## Why it is safe

Every repeat of the date and of the time is bounded. The two optional parts that follow the minutes — seconds, then the zone — each start with a character the part before cannot take: a colon for the seconds, `Z`, `+` or `-` for the zone, against digits before. No two ways of reading the same text exist, and the analyzer's verdict is `safe (proven)`.

## How to validate a date-time in PHP

The pattern carries the `u` flag, so PCRE reads the subject as UTF-8: on a subject that is not valid UTF-8, `preg_match()` returns `false` rather than `0`.

```php
$pattern = '/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])T(?:[01]?[0-9]|2[0-3]):[0-5][0-9](?::[0-5][0-9])?(?:Z|[+-](?:[01]?[0-9]|2[0-3]):[0-5][0-9])?$/u';

var_dump(preg_match($pattern, '2024-12-25T10:30:00+05:00')); // int(1)
var_dump(preg_match($pattern, '2024-12-25 10:30:00')); // int(0)
var_dump(preg_match($pattern, "2024-12-25T10:30:00\xFF")); // bool(false)
```

---

Back to the [cookbook](../cookbook.md) for the other fourteen recipes, and for how to build a pattern of your own. A date alone is a [date](date.md); a time alone is a [time](time.md).

---
description: "PHP regexes for times, proven safe from ReDoS: 24-hour with or without seconds, and 12-hour with AM or PM, with matches, rejects and a PHP example."
head_title: "PHP regex for time: 24-hour, seconds, AM/PM"
parent: /cookbook/
pattern: "/^(?:[01]?[0-9]|2[0-3]):[0-5][0-9]$/"
---
# PHP regex for times

Three patterns: a 24-hour time with minutes, the same with seconds, and a 12-hour time followed by `AM` or `PM`. In all three the minutes (and the seconds) are two digits, `00` to `59`. In the 24-hour patterns the hour is written `9` or `09`, up to `23`; on the 12-hour clock it runs from `1` to `12`.

The first pattern is the 24-hour time, `HH:MM`:

```
/^(?:[01]?[0-9]|2[0-3]):[0-5][0-9]$/
```

**Try it.** [Open `/^(?:[01]?[0-9]|2[0-3]):[0-5][0-9]$/` in the playground](/#pattern=%2F%5E%28%3F%3A%5B01%5D%3F%5B0-9%5D%7C2%5B0-3%5D%29%3A%5B0-5%5D%5B0-9%5D%24%2F&subject=09%3A30): the Diagnostics tab shows what the linter says of it.

## What it matches

```
✓ 00:00
✓ 9:05
✓ 17:30
✓ 23:59
```

## What it rejects

```
✗ 24:00  (hour 24)
✗ 9:60  (minute 60)
✗ 9:5  (the minutes need two digits)
✗ 10:05:30  (holds seconds: the next pattern is for that)
```

## Why it is safe

Each pattern of this page is a fixed alternation for the hour and a class of exactly two digits for the minutes and the seconds. Nothing is open but the `\s*` that may separate a 12-hour time from its `AM` or `PM`, and the letter after it is no whitespace: the engine reads a time one way. The analyzer's verdict is `safe (proven)` for the three.

## How to validate a time in PHP

```php
$pattern = '/^(?:[01]?[0-9]|2[0-3]):[0-5][0-9]$/';

var_dump(preg_match($pattern, '09:30')); // int(1)
var_dump(preg_match($pattern, '9:30')); // int(1)
var_dump(preg_match($pattern, '24:00')); // int(0)
```

## The same time with seconds

`HH:MM:SS`:

```
/^(?:[01]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/
```

```
✓ 00:00:00
✓ 9:05:00
✓ 23:59:59
```

```
✗ 10:05  (no seconds)
✗ 10:05:60  (second 60)
✗ 24:00:00  (hour 24)
```

## A 12-hour time is followed by AM or PM

The hour runs from `1` to `12`, the marker may be written in any case (`am`, `PM`, `Pm`), and the space before it is optional:

```
/^(?:0?[1-9]|1[0-2]):[0-5][0-9]\s*[AaPp][Mm]$/
```

```
✓ 1:05 PM
✓ 12:00am
✓ 11:30 Am
```

```
✗ 0:00 AM  (a 12-hour clock has no hour 0)
✗ 13:05 PM  (hour above 12)
✗ 1:05 P.M.  (dots in the marker)
✗ 1:05  (no AM or PM)
```

---

Back to the [cookbook](../cookbook.md) for the other fourteen recipes, and for how to build a pattern of your own. A time behind a date is a [date-time](datetime.md).

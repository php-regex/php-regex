---
description: "PHP regexes for dates in ISO 8601, US and European formats, proven safe from ReDoS: what each matches, what each rejects, and how checkdate() completes them."
head_title: "PHP regex for dates: ISO 8601, US and European"
parent: /cookbook/
pattern: "/^\\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])$/"
---
# PHP regex for dates

Three patterns, one for each way a date is written. Each takes a four-digit year, a month from `01` to `12` and a day from `01` to `31`, in the order its format puts them and with its own separators. They check the shape of a date, not the calendar: `2023-02-29` passes although 2023 is not a leap year.

The first pattern is the ISO 8601 format, `YYYY-MM-DD`:

```
/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])$/
```

**Try it.** [Open `/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])$/` in the playground](/#pattern=%2F%5E%5Cd%7B4%7D-%28%3F%3A0%5B1-9%5D%7C1%5B0-2%5D%29-%28%3F%3A0%5B1-9%5D%7C%5B12%5D%5B0-9%5D%7C3%5B01%5D%29%24%2F&subject=2024-12-25): the Diagnostics tab shows what the linter says of it.

## What it matches

```
✓ 2024-12-25
✓ 1999-01-01
✓ 2050-12-31
```

## What it rejects

```
✗ 2024-13-01  (invalid month)
✗ 2024-12-32  (invalid day)
✗ 2024/12/25  (wrong separator)
✗ 24-12-25  (2-digit year)
```

## Why it is safe

The three patterns of this page are built the same way, and the analyzer's verdict is `safe (proven)` for each.

- Year, month, and day are bounded with explicit ranges.
- Separators are literal and consistent.
- No nested quantifiers or ambiguous overlaps.

## The US format puts the month before the day

`MM/DD/YYYY`, with slashes:

```
/^(?:0[1-9]|1[0-2])\/(?:0[1-9]|[12][0-9]|3[01])\/\d{4}$/
```

```
✓ 12/25/2024
✓ 01/01/1999
```

```
✗ 25/12/2024  (month 25)
✗ 13/01/2024  (month 13)
✗ 12-25-2024  (wrong separator)
✗ 1/5/2024  (month and day need two digits)
```

## The European format puts the day before the month

`DD.MM.YYYY`, with dots:

```
/^(?:0[1-9]|[12][0-9]|3[01])\.(?:0[1-9]|1[0-2])\.\d{4}$/
```

```
✓ 25.12.2024
✓ 01.01.1999
```

```
✗ 32.01.2024  (day 32)
✗ 01.13.2024  (month 13)
✗ 25-12-2024  (wrong separator)
✗ 12/25/2024  (slashes instead of dots)
```

## How to check the calendar in PHP

These patterns validate format, not calendar validity. `2023-02-29` passes although 2023 is not a leap year, and `1900-02-29` passes although century years must be divisible by 400 to be leap years. Use PHP's `checkdate()` for true calendar validation. The pattern accepts the date below; `checkdate()` answers `false`, because 2023 is not a leap year.

```php
$date = '2023-02-29';

if (preg_match('/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])$/', $date)) {
    [$year, $month, $day] = explode('-', $date);
    var_dump(checkdate((int) $month, (int) $day, (int) $year)); // bool(false)
}

var_dump(checkdate(2, 29, 2024)); // bool(true)
var_dump(checkdate(2, 29, 1900)); // bool(false)
```

---

Back to the [cookbook](../cookbook.md) for the other fourteen recipes, and for how to build a pattern of your own. A date followed by a time is a [date-time](datetime.md).

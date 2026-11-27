---
description: "PHP regexes for credit card numbers, proven safe from ReDoS: 13 to 19 digits, and Visa, Mastercard, Amex and Discover by their first digits."
head_title: "PHP regex for credit card numbers, by card type"
parent: /cookbook/
pattern: "/^[0-9]{13,19}$/"
---
# PHP regex for credit card numbers

The generic pattern accepts 13 to 19 digits and nothing else — no space, no hyphen: strip them before matching. It refuses twelve digits or fewer, twenty or more, and any character that is not a digit.

```
/^[0-9]{13,19}$/
```

**Try it.** [Open `/^[0-9]{13,19}$/` in the playground](/#pattern=%2F%5E%5B0-9%5D%7B13%2C19%7D%24%2F&subject=4111111111111111): the Diagnostics tab shows what the linter says of it.

## What it matches

```
✓ 4111111111111111  (Visa, 16 digits)
✓ 5555555555554444  (Mastercard, 16 digits)
✓ 378282246310005  (Amex, 15 digits)
✓ 4111111111111111111  (19 digits, the most the pattern takes)
```

## What it rejects

```
✗ 411111111111  (12 digits: 13 at least)
✗ 41111111111111111111  (20 digits: 19 at most)
✗ 4111 1111 1111 1111  (spaces are not digits)
✗ 4111-1111-1111-1111  (hyphens are not digits)
```

## Why it is safe

One character class under one bounded count: nothing is open, nothing nests. The analyzer's verdict is `safe (proven)` for the five patterns of this page.

## How to tell the card by its first digits

Four patterns recognize a card by the digits it starts with and by its length, and by nothing else: a Luhn checksum is arithmetic a pattern cannot carry.

**Visa** starts with `4` and has 13 or 16 digits.

```
/^4[0-9]{12}(?:[0-9]{3})?$/
```

```
✓ 4111111111111111
✓ 4111111111111  (13 digits)
```

```
✗ 411111111111111  (15 digits: 13 or 16 are expected)
✗ 5111111111111111  (does not start with 4)
```

**Mastercard** starts with `51` to `55` and has 16 digits.

```
/^5[1-5][0-9]{14}$/
```

```
✓ 5555555555554444
✓ 5105105105105100
```

```
✗ 511111111111111  (15 digits: 16 are needed)
✗ 5011111111111111  (starts with 50: 51 to 55 are expected)
```

**American Express (Amex)** starts with `34` or `37` and has 15 digits.

```
/^3[47][0-9]{13}$/
```

```
✓ 378282246310005
✓ 371449635398431
```

```
✗ 3782822463100050  (16 digits: 15 are needed)
✗ 30569309025904  (does not start with 34 or 37)
```

**Discover** starts with `6011` or `65` and has 16 digits.

```
/^6(?:011|5[0-9]{2})[0-9]{12}$/
```

```
✓ 6011111111111117
✓ 6011000990139424
```

```
✗ 601111111111111  (15 digits: 16 are needed)
✗ 6111111111111111  (does not start with 6011 or 65)
```

## A pattern cannot check the sum of the digits

These patterns verify format and length. Always run the Luhn algorithm for actual validation: `4111111111111112` passes the Visa pattern and fails the Luhn check.

```php
function luhnCheck(string $number): bool
{
    $sum = 0;
    $length = strlen($number);
    $parity = $length % 2;

    for ($i = $length - 1; $i >= 0; $i--) {
        $digit = (int) $number[$i];
        if ($i % 2 === $parity) {
            $digit *= 2;
            if ($digit > 9) {
                $digit -= 9;
            }
        }
        $sum += $digit;
    }

    return $sum % 10 === 0;
}

var_dump(luhnCheck('4111111111111111')); // bool(true)
var_dump(luhnCheck('4111111111111112')); // bool(false)
```

---

Back to the [cookbook](../cookbook.md) for the other fourteen recipes, and for how to build a pattern of your own.

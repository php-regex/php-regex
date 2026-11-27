---
description: "PHP regexes for password strength: eight characters with a lower-case letter, a capital and a digit, or twelve with a symbol too, proven safe from ReDoS."
head_title: "PHP regex for password strength: two patterns"
parent: /cookbook/
pattern: "/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}$/"
---
# PHP regex for password strength

The basic pattern accepts a password of at least eight characters that holds a lower-case letter, a capital and a digit, in any order and anywhere: any other character may stand beside them, and longer is fine. It refuses a password that misses one of the three kinds, and one shorter than eight characters. The dot matches any character but a line break.

```
/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}$/
```

**Try it.** [Open `/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}$/` in the playground](/#pattern=%2F%5E%28%3F%3D.*%5Ba-z%5D%29%28%3F%3D.*%5BA-Z%5D%29%28%3F%3D.*%5B0-9%5D%29.%7B8%2C%7D%24%2F&subject=Password1): the Diagnostics tab shows what the linter says of it.

## What it matches

```
✓ Password1
✓ MyPass123
✓ A1aaaaaa  (eight characters, the minimum)
```

## What it rejects

```
✗ password  (no uppercase, no number)
✗ PASSWORD  (no lowercase, no number)
✗ Passw0d  (seven characters: eight at least)
✗ Password  (no digit)
```

## Why it is safe

- `(?=.*[a-z])` requires at least one lowercase letter.
- `(?=.*[A-Z])` requires at least one uppercase letter.
- `(?=.*[0-9])` requires at least one digit.
- `(?=.*[!@#$%...])` requires at least one special character (strong pattern, below).
- `.{8,}` enforces minimum length.
- Lookaheads do not consume characters, but their bodies still backtrack: keep them free of nested quantifiers. The two patterns of this page are `safe (proven)`.

## How to check a password in PHP

```php
$basic = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}$/';
$strong = '/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[!@#$%^&*()_+\-=\[\]{};\'"\\\\|,.<>\/?]).{12,}$/';

var_dump(preg_match($basic, 'Password1')); // int(1)
var_dump(preg_match($basic, 'Password')); // int(0)
var_dump(preg_match($strong, 'Password1')); // int(0)
var_dump(preg_match($strong, 'P@ssw0rd!#123')); // int(1)
```

## A stronger pattern asks for a special character

Twelve characters at least, and at least one special character besides the three kinds:

```
/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9])(?=.*[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]).{12,}$/
```

```
✓ P@ssw0rd!#123
✓ MyStr0ng!P@ss
✓ A1aaaaaaaaa!  (twelve characters, the minimum)
```

```
✗ password  (no uppercase, no number)
✗ PASSWORD  (no lowercase, no number)
✗ Passw0d  (seven characters: twelve at least)
✗ Password  (no digit)
✗ P@ssw0rd!#1  (eleven characters: twelve at least)
✗ A1aaaaaaaaaa  (no special character)
```

---

Back to the [cookbook](../cookbook.md) for the other fourteen recipes, and for how to build a pattern of your own.

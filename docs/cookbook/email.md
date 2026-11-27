---
description: "A PHP regex for email addresses, proven safe from ReDoS: what it accepts, what it refuses, why it is linear, and how to validate an address with preg_match()."
head_title: "PHP regex for email addresses: a proven pattern"
parent: /cookbook/
pattern: "/^[a-z0-9]([a-z0-9._%+-]*[a-z0-9])?@[a-z0-9]([a-z0-9-]*[a-z0-9])?(\\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i"
---
# PHP regex for email addresses

This pattern accepts an address made of a local part, an `@`, and a domain with at least two labels separated by dots: `user@example.com`, `user.name+tag@example.co.uk`. It refuses an empty local part or domain, a part that starts or ends with a dot, two dots in a row in the domain, and any space.

It is a simplified form of the address RFC 5322 defines. A quoted local part (`"john doe"@example.com`), a domain written as a bare host name (`user@localhost`) or an IP literal in brackets (`user@[127.0.0.1]`) does not pass. The `i` flag lets capitals through everywhere.

```
/^[a-z0-9]([a-z0-9._%+-]*[a-z0-9])?@[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i
```

**Try it.** [Open `/^[a-z0-9]([a-z0-9._%+-]*[a-z0-9])?@[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i` in the playground](/#pattern=%2F%5E%5Ba-z0-9%5D%28%5Ba-z0-9._%25%2B-%5D*%5Ba-z0-9%5D%29%3F%40%5Ba-z0-9%5D%28%5Ba-z0-9-%5D*%5Ba-z0-9%5D%29%3F%28%5C.%5Ba-z0-9%5D%28%5Ba-z0-9-%5D*%5Ba-z0-9%5D%29%3F%29%2B%24%2Fi&subject=user%40example.com): the tree and the verdict sit on the same screen, and the Diagnostics tab shows what the linter says of it.

## What it matches

```
✓ user@example.com
✓ user.name@example.com
✓ user+tag@example.com
✓ a@b.co
✓ User@Example.COM  (capitals pass under the i flag)
✓ user..name@example.com  (two dots in a row pass in the local part)
```

## What it rejects

```
✗ @example.com  (missing local part)
✗ user@  (missing domain)
✗ user@.com  (empty domain part)
✗ user@example..com  (consecutive dots)
✗ user@exam ple.com  (space in domain)
✗ user@localhost  (no dot in the domain)
```

## Why it is safe

- The local part starts with a letter or a digit and allows dots, underscores, percent signs, plus signs and hyphens in the middle — so Gmail-style `user+tag` addresses pass.
- The domain starts with a letter or a digit and allows hyphenated parts.
- At least one dot-separated label follows the first label of the domain: the TLD.
- The repeats nest, and the linter says so: `regex.lint.quantifier.nested` on the third group. The nesting is harmless here: every label of the domain starts after a dot and ends on a letter or a digit, so the engine reads a label one way and never re-reads a long input in many ways. The analyzer's verdict is `safe (proven)`.

## How to validate an email in PHP

`preg_match()` is all the check needs. The example also asks PHPRegex for its two answers on the pattern: whether it is valid, and whether a match on it can blow up in time. It uses the `Regex` facade of the `php-regex/regex-toolkit` package, whose install command the [cookbook](../cookbook.md) gives.

```php
use PHPRegex\Toolkit\Regex;

$email = 'user@example.com';
$pattern = '/^[a-z0-9]([a-z0-9._%+-]*[a-z0-9])?@[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/i';

$regex = Regex::create();
$result = $regex->validate($pattern);
echo $result->isValid ? 'Valid pattern' : 'Invalid pattern';  // Valid pattern

$isMatch = preg_match($pattern, $email) === 1;
echo $isMatch ? 'Matches' : 'Does not match';                  // Matches

// ReDoS check
$analysis = Regex::create()->redos($pattern);
echo $analysis->headline();                                    // safe (proven)
```

---

Back to the [cookbook](../cookbook.md) for the other fourteen recipes, and for how to build a pattern of your own.

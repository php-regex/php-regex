---
description: "A PHP regex for usernames of 3 to 32 characters that start with a letter, proven safe from ReDoS, with the patterns of Linux, GitHub and X for comparison."
head_title: "PHP regex for usernames: 3 to 32 characters"
parent: /cookbook/
pattern: "/^[a-zA-Z][a-zA-Z0-9_-]{2,31}$/"
---
# PHP regex for usernames

This pattern accepts a username of 3 to 32 characters that starts with a letter and goes on with letters, digits, underscores and hyphens: `user123`, `John_Doe`, `admin`. It refuses a first character that is not a letter, fewer than three characters, a 33rd character, and any other character — a dollar sign, a dot, a space, a letter outside ASCII.

```
/^[a-zA-Z][a-zA-Z0-9_-]{2,31}$/
```

**Try it.** [Open `/^[a-zA-Z][a-zA-Z0-9_-]{2,31}$/` in the playground](/#pattern=%2F%5E%5Ba-zA-Z%5D%5Ba-zA-Z0-9_-%5D%7B2%2C31%7D%24%2F&subject=user123): the Diagnostics tab shows what the linter says of it.

## What it matches

```
✓ user123
✓ John_Doe
✓ admin
✓ user-name
```

## What it rejects

```
✗ 123user  (must start with letter)
✗ a  (too short: three characters at least)
✗ ab  (too short: three characters at least)
✗ user$name  (invalid character)
✗ user.name  (a dot is not allowed)
```

## Why it is safe

One bounded repeat, `{2,31}`, over a character class: nothing is open and nothing nests. The analyzer's verdict is `safe (proven)`.

## How to validate a username in PHP

```php
$pattern = '/^[a-zA-Z][a-zA-Z0-9_-]{2,31}$/';

var_dump(preg_match($pattern, 'user123')); // int(1)
var_dump(preg_match($pattern, '123user')); // int(0)
```

## How other systems spell their usernames

The pattern above is one choice. Other systems have rules of their own. Three follow, each with a pattern written after its rule; each pattern is `safe (proven)`.

**Linux.** The [`useradd` manual](https://www.man7.org/linux/man-pages/man8/useradd.8.html) allows lower and upper case letters, digits, underscores and dashes in a user name, with no dash first and an optional dollar sign last. The pattern leaves out the dollar sign and asks for a letter or an underscore first. The `i` flag lets capitals through.

```
/^[a-z_][a-z0-9_-]*$/i
```

```
✓ ada
✓ _daemon
✓ Ada-01
```

```
✗ -ada  (starts with a hyphen)
✗ ada!  (invalid character)
✗ ada.lovelace  (a dot is not allowed)
```

The pattern sets no length limit, and the manual gives one (256 characters): check the length your system allows beside it.

**GitHub.** [Its documentation](https://docs.github.com/enterprise-cloud@latest/admin/managing-iam/iam-configuration-reference/username-considerations-for-external-authentication) gives the rules of a username: at most 39 characters, letters, digits and dashes only, no dash first or last, no two dashes in a row. The pattern holds all of it but the dash last:

```
/^[a-zA-Z0-9](?:[a-zA-Z0-9]|-(?!-)){0,38}$/
```

```
✓ ada
✓ ada-lovelace
✓ Ada01
✓ ada-  (a trailing hyphen passes, although GitHub refuses it)
```

```
✗ -ada  (starts with a hyphen)
✗ a--b  (two hyphens in a row)
✗ a_b  (an underscore is not allowed)
```

**X (formerly Twitter).** [Its help center](https://help.x.com/en/managing-your-account/x-username-rules) accepts at most 15 characters, letters, digits and underscores:

```
/^[a-zA-Z0-9_]{1,15}$/
```

```
✓ ada
✓ ada_lovelace
✓ Ada01
```

```
✗ -ada  (a hyphen is not allowed)
✗ ada!  (invalid character)
✗ abcdefghijklmnop  (16 characters: 15 at most)
```

---

Back to the [cookbook](../cookbook.md) for the other fourteen recipes, and for how to build a pattern of your own.

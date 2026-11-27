---
description: "With the u modifier the subject must be valid UTF-8. When it is not, preg_match() returns false, with no warning. Check the encoding, or match in byte mode."
head_title: "\"Malformed UTF-8 characters\" in PHP"
parent: /reference/diagnostics/
engine_family: "The match"
engine_order: 2
engine:
  message: "Malformed UTF-8 characters, possibly incorrectly encoded"
  from: "preg_last_error_msg"
  pattern: "/./u"
  subject_hex: "636166C3"
---
# Malformed UTF-8 characters, possibly incorrectly encoded

The pattern carries the `u` modifier, and the subject is not valid UTF-8: PHP stopped before any match.

```php
var_dump(preg_match('/./u', "touch\xC3"));
// bool(false)
echo preg_last_error_msg();
// Malformed UTF-8 characters, possibly incorrectly encoded
```

The subject stops in the middle of a character. `\xC3` is the first of the two bytes of `é`, and the second is missing. Under `u` the whole subject has to be UTF-8, so the call returns `false`. No warning is printed, and the same subject matches under `/./`.

## Why PHP prints it

Under `u` the engine reads the subject as characters, not bytes. PHP checks all of it before the match, and one byte out of place gives up on the call.

**A character was cut in two.** `substr()` counts bytes, and six bytes of `touché` stop inside the `é`:

```php
var_dump(preg_match('/./u', substr("touch\xC3\xA9", 0, 6)));
// bool(false)
echo preg_last_error_msg();
// Malformed UTF-8 characters, possibly incorrectly encoded
```

**The text is in another encoding.** Latin-1 writes `é` as one byte, `\xE9`, which is not UTF-8:

```php
var_dump(preg_match('/./u', "touch\xE9"));
// bool(false)
echo preg_last_error_msg();
// Malformed UTF-8 characters, possibly incorrectly encoded
```

**The bad byte is far from the match.** The subject is checked as a whole, so a match that ends before the bad byte fails as well:

```php
var_dump(preg_match('/^a/u', "a\xC3"));
// bool(false)
echo preg_last_error_msg();
// Malformed UTF-8 characters, possibly incorrectly encoded
```

A lone `\x80`, a `\xFF`, an overlong sequence or an encoded surrogate gives the same message.

## How to fix it

The subject has to be UTF-8 when the pattern carries `u`. Make it so, or refuse it before the call, or drop the `u`.

```php
// Cut at a character boundary: mb_strcut() never splits one
var_dump(mb_strcut("touch\xC3\xA9", 0, 6));
// string(5) "touch"

// Or convert the text from the encoding it is in
var_dump(mb_convert_encoding("touch\xE9", 'UTF-8', 'Windows-1252') === "touch\xC3\xA9");
// bool(true)

// Or know it before the call: false when the subject is not UTF-8
var_dump(mb_check_encoding("touch\xC3", 'UTF-8'));
// bool(false)
```

`mb_scrub()` replaces what is broken: `mb_scrub("touch\xC3")` gives `"touch?"`.

Without `u`, the engine matches bytes, and the call works on the same subject:

```php
// In byte mode the dot takes one byte
var_dump(preg_match('/./', "touch\xC3"));
// int(1)
```

The trade-off is that the pattern stops counting characters: `/\w+/` matches `touch` in `touché`, where `/\w+/u` matches the whole word.

## Where it hides

- **An excerpt shortened by bytes.** The code that cuts a text for a list or a description gives `"touch\xC3"` for `substr('touché', 0, 6)`. `mb_strcut()` and `mb_substr()` cut between characters.
- **A file read by size.** `fread($handle, 6)` can stop inside a character. A line read with `fgets()` stops at its end.
- **Binary data beside text.** A raw hash, an image or a packed value reaches a pattern that carries `u`: `"\x80"` and `"\xFF"` give the message.
- **A result that fails in silence.** `preg_replace()` returns `null` and `preg_match_all()` returns `false`. Code that reads either as an empty result sees no error.

## What PHPRegex reports

PHPRegex reports nothing: `/./u` is a valid pattern with no lint finding. The cause is in the subject, which the library does not see.

[Open `/./u` in the playground](/#pattern=%2F.%2Fu&subject=touch%C3%A9): the pattern opens, with its tree.

## Related

- [JIT stack limit exhausted](jit-stack-limit-exhausted.md)—another `false` that a valid pattern returns.
- [Backtrack limit exhausted](backtrack-limit-exhausted.md)—the third, when the pattern backtracks past its limit.
- [UTF-8 error in pattern](utf-8-error-in-pattern.md)—when the pattern itself holds a broken character.
- [Regex in PHP](../../guides/regex-in-php.md)—the modifiers and the error codes of a match.

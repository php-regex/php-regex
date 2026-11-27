---
description: "With the u modifier the pattern must be valid UTF-8 too. A raw or badly encoded byte past ASCII is refused at compile time: check the encoding, or drop u."
head_title: "\"UTF-8 error\" in a PHP regex with the u flag"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 19
engine:
  message: "Compilation failed: UTF-8 error: illegal byte (0xfe or 0xff) at offset 1"
  pattern_hex: "2F61FF622F75"
codes:
  - regex.encoding.invalid_utf8
---
# UTF-8 error in the pattern

With the `u` modifier, the pattern itself must be valid UTF-8, and this one holds a byte that is not.

```php
preg_match("/a\xFFb/u", $value);
// preg_match(): Compilation failed: UTF-8 error: illegal byte (0xfe or 0xff) at offset 1
```

The byte `0xFF` stands right after the opening delimiter, at offset 1. No UTF-8 text holds it. The compilation starts with a check of the pattern as UTF-8, and stops at the first byte that breaks the encoding: nothing is wrong with the regex itself.

## Why PHP prints it

The `u` modifier makes the pattern and the subject UTF-8 for PCRE2. Each kind of broken bytes gives the message, with its own detail after `UTF-8 error:`.

**Text in another encoding.** `touché` in Windows-1252 is the seven bytes `touch\xE9`:

```php
preg_match("/touch\xE9/u", $text);
// preg_match(): Compilation failed: UTF-8 error: 2 bytes missing at end at offset 5
```

A byte on its own, such as the curly quote `\x92` of the same encoding, gives `isolated byte with 0x80 bit set`.

**A character cut in the middle.** A function that counts bytes, as `substr()` does, can leave half of one:

```php
preg_match("/\xC3/u", $text);
// preg_match(): Compilation failed: UTF-8 error: 1 byte missing at end at offset 0
```

**A sequence that encodes nothing.** The overlong form `\xC0\x80` gives `overlong 2-byte sequence`, and an encoded surrogate gives `code points 0xd800-0xdfff are not defined`.

The offset is that of the byte, counted from the opening delimiter.

## How to fix it

```php
// Check the encoding before the pattern reaches the engine
if (!mb_check_encoding($pattern, 'UTF-8')) {
    // Not UTF-8: convert it from the encoding it came in
    $pattern = mb_convert_encoding($pattern, 'UTF-8', 'Windows-1252');
}

// Or work on bytes: without u, the pattern compiles
preg_match("/a\xFFb/", $value);
```

`mb_convert_encoding()` needs the encoding of the text: Windows-1252 above, for a `touché` that arrived as `touch\xE9`. Without `u`, the shorthand classes and the properties stop at ASCII: `/\w/` does not match `é`, and `/\w/u` does.

## Where it hides

- **A file saved in another encoding.** A pattern typed in a source file saved as Windows-1252 holds `\xE9` where `é` was typed.
- **A pattern cut or built by bytes.** `substr('é', 0, 1)` is `\xC3`: the first byte of the character, alone.
- **A byte in a class, or under the `x` modifier.** `"/[\xFF]/u"` and `"/a \xFF b/xu"` give the message, at the offset of the byte.
- **Nothing without `u`.** `"/a\xFFb/"` compiles, and matches the byte.

## What PHPRegex reports

- `regex.encoding.invalid_utf8` for the pattern above: "Input string is not valid UTF-8." The library gives the offset of the byte, 1 here, and no hint. It reports the byte only with `u`: without the modifier, the pattern is valid to the library, as it is to PHP.

No link opens the playground on this pattern: a link carries text, and `\xFF` typed there is the escape, which is valid under `u`.

## Related

- [Malformed UTF-8 characters, possibly incorrectly encoded](malformed-utf-8-characters.md): the same demand on the subject, and what the match returns then.
- [Unicode Property Without /u](../rules/unicode-property-without-u.md): what a property covers without the flag.
- [Unicode Shorthand Without /u](../rules/unicode-shorthand-without-u.md): what `\w`, `\d` and `\s` cover without the flag.

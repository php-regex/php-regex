---
description: "A backslash is followed by a letter PCRE2 knows no escape for: \\y, \\q, a misspelt one. What may follow a backslash, and the escapes that look right and are not."
head_title: "\"unrecognized character follows \\\" in PHP regex"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 12
engine:
  message: "Compilation failed: unrecognized character follows \\ at offset 2"
  pattern: "/\\y/"
codes:
  - regex.escape.unrecognized
---
# Unrecognized character follows \

A backslash is followed by a letter PCRE2 has no escape for, and the pattern is refused.

```php
preg_match('/\y/', $value);
// preg_match(): Compilation failed: unrecognized character follows \ at offset 2
```

In a pattern, a backslash starts an escape. PCRE2 knows `\d`, `\w` and `\b`. It does not know `\y`, and it refuses the pattern instead of reading a literal `y`. The offset, counted from the opening delimiter, is the letter after the backslash.

## Why PHP prints it

Three kinds of characters may follow a backslash. A character that is neither a letter nor a digit stands for itself: `\.`, `\-`, `\/` and every other punctuation mark of ASCII, in a class and outside one. A digit makes a backreference or an octal code, as `\1` and `\101`. A letter is an escape PCRE2 gives a meaning: the classes `\d`, `\h`, `\s`, `\v`, `\w` and their negations in uppercase, the anchors `\A`, `\b`, `\B`, `\Z`, `\z`, the control characters `\a`, `\e`, `\f`, `\n`, `\r`, `\t`, the code points `\x…` and `\o{…}`, and `\p{…}`, `\k<name>` and `\Q…\E`, which take what follows them. Eleven letters print this message instead: `i`, `j`, `m`, `q`, `y`, `I`, `J`, `M`, `O`, `T` and `Y`. Five print another one: `\F`, `\L`, `\l`, `\U` and `\u`.

**A typo in an escape.** The key next to the one meant:

```php
preg_match('/\w\q/', $value);
// preg_match(): Compilation failed: unrecognized character follows \ at offset 4
```

**Text that holds a backslash.** A path matched as it is written: PCRE2 takes the backslash for the start of an escape.

```php
preg_match('/^C:\inetpub/', $path);
// preg_match(): Compilation failed: unrecognized character follows \ at offset 5
```

**Inside a class.** The same letters are refused there:

```php
preg_match('/[\y]/', $value);
// preg_match(): Compilation failed: unrecognized character follows \ at offset 3
```

## How to fix it

```php
// The escape that was meant
preg_match('/\w+/', $value);

// A literal backslash: it is escaped too
preg_match('/^C:\\\\inetpub/', $path);

// Or let a function escape the text
preg_match('/^' . preg_quote($path, '/') . '$/', $path);
```

In a single-quoted string, `\\\\` is two backslashes in the pattern, and `\\` matches one. `preg_quote()` escapes the backslash with the other metacharacters, and the delimiter given as its second argument.

## Where it hides

- **A path.** `C:\inetpub` is refused at `\i`. A directory that starts with a letter PCRE2 knows gives no message and another meaning: in `/^C:\temp/`, `\t` is a tab.
- **A typesetting command.** `/\item\s+(\w+)/` is refused at the `\i`.
- **A backslash that looks doubled in the source.** `'/\\y/'` is the pattern `/\y/`: a single-quoted string turns `\\` into one backslash. For a literal backslash followed by `y`, the source is `'/\\\\y/'`.

## What PHPRegex reports

- `regex.escape.unrecognized` for `/\y/`: "Unrecognized escape sequence "\y"." The library names the escape and gives its offset, 2 here. It adds no hint.

[Open `/\y/` in the playground](/#pattern=%2F%5Cy%2F&subject=y): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [PCRE2 does not support \F, \L, \l, \N{name}, \U, or \u](pcre2-does-not-support-unicode-escapes.md): the message of the five letters PCRE2 refuses by name.
- [Unknown property after \P or \p](unknown-unicode-property.md): what may follow `\p` and `\P`.
- [Reference to non-existent subpattern](reference-to-non-existent-subpattern.md): the message of `\1` when no group stands behind it.
- [Suspicious Escapes](../rules/suspicious-escapes.md): the linter also reports an escape that names a value PCRE cannot read.

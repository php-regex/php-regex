---
description: "A Unicode property \\p{...} names one PCRE2 does not know, or the sequence is malformed. The properties it knows, and the fix."
head_title: "\"unknown property after \\P or \\p\" in PHP regex"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 18
quick_fix: "Name a property PCRE2 knows, under the u modifier"
quick_fix_order: 10
engine:
  message: "Compilation failed: unknown property after \\P or \\p at offset 7"
  pattern: "/\\p{Foo}/u"
codes:
  - regex.unicode.property_invalid
  - regex.unicode.property_malformed
---
# Unknown property after \P or \p

The pattern names a Unicode property PCRE2 does not know.

```php
preg_match('/\p{Foo}/u', $value);
// preg_match(): Compilation failed: unknown property after \P or \p at offset 7
```

`\p{…}` matches a character by a Unicode property, as `\p{L}` matches a letter. The name between the braces has to be one of the properties PCRE2 knows. `Foo` is none, and the compilation stops at the `}`: offset 7, counted from the opening delimiter.

## Why PHP prints it

PCRE2 looks the name up among its general categories, scripts and other properties. A name that is not there prints the message.

**A name PCRE2 does not know.** The general categories go by their short form: `\p{Letter}` and `\p{Number}` are refused, `\p{L}` and `\p{N}` compile.

```php
preg_match('/\p{Letter}/u', $value);
// preg_match(): Compilation failed: unknown property after \P or \p at offset 10
```

**An empty name, or one letter that is not a category.** `/\a\pb/u` prints the message at the `b`:

```php
preg_match('/\p{}/u', $value);
// preg_match(): Compilation failed: unknown property after \P or \p at offset 4
```

**A brace or a letter missing.** The message is then `malformed \P or \p sequence`:

```php
preg_match('/\p{L/u', $value);
// preg_match(): Compilation failed: malformed \P or \p sequence at offset 4
```

The offset is the end of what PCRE2 read: the `}` of a failed name, or the brace or the `p` of a malformed sequence. Names are not case-sensitive: `\p{l}` matches as `\p{L}` does.

## How to fix it

```php
// A general category, by its short form
preg_match('/^\p{L}+$/u', 'café'); // matches "café"

// An uppercase letter
preg_match('/^\p{Lu}+$/u', 'ABC'); // matches "ABC"

// A script, and a class built on properties
preg_match('/^\p{Greek}+$/u', 'αβ'); // matches "αβ"
preg_match('/^[\p{L}\p{N}_]+$/u', 'aé_9'); // matches "aé_9"
```

The `u` modifier stays on the pattern. Without it, `/\p{L}/` compiles, and the property covers the first 256 code points only: `^\p{L}$` matches `é` with `u`, and not without.

## Where it hides

- **A name with a prefix.** `\p{IsGreek}` and `\p{InGreek}` are refused. The script goes bare, `\p{Greek}`, or as `\p{Script=Greek}`.
- **The `u` inside the delimiters.** `/\p{L}u/` compiles, and ends with a literal `u`: no modifier is set, and the property stops at the first 256 code points.
- **A misspelt name.** `\p{Letters}` and `\p{Digits}` are refused, where `\p{L}` and `\p{N}` compile.
- **A `\p` that should be a literal `p`.** `/\p/u` is malformed: without a property letter or a brace, the backslash has to go.

## What PHPRegex reports

- `regex.unicode.property_invalid` for `/\p{Foo}/u`: "Invalid or unsupported Unicode property: \p{Foo}." The hint follows: "Use a property PCRE2 knows, such as \p{L}, \p{Lu}, \p{N} or a script such as \p{Greek}." For `/\p{Letter}/u`, the message ends with "Did you mean \p{L}?"
- `regex.unicode.property_malformed` for `/\p{L/u`: "Malformed \p sequence: a property letter or a braced name must follow it." The hint follows: "Name a property, as in "\pL" or "\p{Lu}", or drop the backslash for a literal "p"."

The library gives the offset in both.

[Open `/\p{Foo}/u` in the playground](/#pattern=%2F%5Cp%7BFoo%7D%2Fu&subject=a): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Unicode Property Without /u](../rules/unicode-property-without-u.md): the lint finding for a property that compiles without the flag.
- [UTF-8 error in the pattern](utf-8-error-in-pattern.md): what the `u` modifier demands from the pattern itself.
- [Character code point value in \x{} or \o{} is too large](character-code-point-value-is-too-large.md): a character by its code point instead of its property.
- [Multibyte Character in a Class](../rules/multibyte-character-in-a-class.md): a class and the `u` modifier.

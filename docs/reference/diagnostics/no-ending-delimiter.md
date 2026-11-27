---
description: "PHP found no second delimiter: one is missing, or a backslash right before it escapes it. With a paired delimiter the message says no ending matching delimiter."
head_title: "\"No ending delimiter\" found in a PHP regex"
parent: /reference/diagnostics/
engine_family: "The checks of PHP"
engine_order: 3
quick_fix: "Close the pattern, and leave the closing delimiter unescaped"
quick_fix_order: 3
engine:
  message: "No ending delimiter '/' found"
  pattern: "/^[a-z]+$"
  also:
    - "No ending matching delimiter '}' found"
codes:
  - regex.delimiter.unclosed
---
# No ending delimiter found

PHP found the delimiter that opens the pattern, and no second one to close it.

```php
preg_match('/^[a-z]+$', $value);
// preg_match(): No ending delimiter '/' found
```

The pattern opens with `/` and never closes. PHP looks for the next `/` that no backslash escapes, meets the end of the string instead, and stops. The call returns `false` and nothing is compiled.

## Why PHP prints it

PHP reads the pattern up to its second delimiter. That delimiter has to be there, and a backslash right before it turns it into an escaped character, which does not close the pattern.

**The closing delimiter is missing.** The slashes inside the expression are escaped, and the one that should close it is forgotten:

```php
preg_match('/^\/users\/\d+', $value);
// preg_match(): No ending delimiter '/' found
```

**A backslash escapes the last delimiter.** To PHP the last `/` is an escaped slash, and no other one follows:

```php
preg_match('/abc\/', $value);
// preg_match(): No ending delimiter '/' found
```

Two backslashes do not escape it: they make a literal backslash, and the delimiter after them closes the pattern.

**A bracket pair closes with its other half.** `(`, `[`, `{` and `<` are closed by `)`, `]`, `}` and `>`. The message then names the one it expected:

```php
preg_match('{abc\}', $value);
// preg_match(): No ending matching delimiter '}' found
```

## How to fix it

Close the pattern with a delimiter that no backslash escapes:

```php
// The closing delimiter added
preg_match('/^[a-z]+$/', 'abc');
// 1

// The escaped slash stays inside, and a free slash closes the pattern
preg_match('/abc\//', 'x abc/ y');
// 1

// The escaped brace stays inside, and a free brace closes the pattern
preg_match('{abc\}}', 'abc}');
// 1
```

Another delimiter removes the escapes: `#^/users/\d+#` and `#abc/#` match the same text with no backslash.

## Where it hides

- **A path or a route.** The slashes inside are all escaped, which is when the last one is most easily forgotten.
- **A literal backslash at the end.** In PHP source, `'/foo\\/'` is the string `/foo\/`, where the slash is escaped. `'/foo\\\\/'` matches `foo\`.
- **A bracket pair closed with its own character.** `{abc{` does not close: PHP expects a `}`.
- **An ending added by a condition.** `'/' . $body . ($strict ? '/i' : '')` has no closing delimiter when `$strict` is false.
- **A value copied without its last character.** `/foo/` comes back as `/foo`.

## What PHPRegex reports

- `regex.delimiter.unclosed` for `/^[a-z]+$`: "No closing delimiter "/" found. You opened with "/"; expected closing "/". Tip: escape "/" inside the pattern (\/) or use a different delimiter, e.g. #^[a-z]+$#." The message names the delimiter PHP found first, and its tip offers the same pattern under another delimiter. For `{abc\}` it expects the `}`: "No closing delimiter "}" found. You opened with "{"; expected closing "}". Tip: escape "}" inside the pattern (\}) or use a different delimiter, e.g. #abc\}#."

[Open `/^[a-z]+$` in the playground](/#pattern=%2F%5E%5Ba-z%5D%2B%24&subject=abc): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Unknown modifier](unknown-modifier.md)—a delimiter inside the expression closes the pattern early.
- [Delimiter must not be alphanumeric, backslash, or NUL byte](delimiter-must-not-be-alphanumeric.md)—the first character cannot open a pattern.
- [Empty regular expression](empty-regular-expression.md)—the pattern holds nothing at all.
- [Regex in PHP](../../guides/regex-in-php.md)—delimiters and modifiers from the start.

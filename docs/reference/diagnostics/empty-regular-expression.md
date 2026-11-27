---
description: "preg_match() refuses an empty string, or whitespace only: a variable held nothing. The empty pattern // matches everywhere: guard the variable before the call."
head_title: "PHP \"Empty regular expression\" warning"
parent: /reference/diagnostics/
engine_family: "The checks of PHP"
engine_order: 4
engine:
  message: "Empty regular expression"
  pattern: ""
codes:
  - regex.pattern.empty
---
# Empty regular expression

PHP received a pattern that holds nothing, and refused it.

```php
preg_match('', $value);
// preg_match(): Empty regular expression
```

The string passed as the pattern is empty. PHP skips the whitespace at its start, meets the end of the string, and stops: the call returns `false` and nothing is compiled. Nothing in the string points at a cause: it is in the variable.

## Why PHP prints it

PHP reads a pattern from its first character that is not whitespace. When there is none, there is no pattern to compile.

**A variable holds nothing.** It is declared and never filled, or the setting that should feed it is missing:

```php
$pattern = '';
preg_match($pattern, $value);
// preg_match(): Empty regular expression
```

**The variable holds whitespace only.** A space, a tab or a newline counts as nothing there:

```php
preg_match('   ', $value);
// preg_match(): Empty regular expression
```

## How to fix it

The call needs a pattern. Give it one before PHP reads the variable.

```php
// A pattern that holds nothing never reaches the engine
if ('' === trim($pattern)) {
    throw new InvalidArgumentException('The pattern is empty.');
}
```

```php
// Or a default takes its place before the call: this one accepts every subject
$pattern = trim($pattern) ?: '/.*/';
var_dump(preg_match($pattern, 'anything'));
// int(1)
```

Wrapping the empty variable in delimiters is not a fix. `//` compiles, and it matches at every position:

```php
// The delimiters around an empty expression: a pattern that matches everywhere
$pattern = '';
var_dump(preg_replace('/' . $pattern . '/', '-', 'ab'));
// string(5) "-a-b-"
```

## Where it hides

- **An option left blank.** A setting, a form field or a column that was never filled comes back as `''`.
- **A missing key.** `$settings['pattern']` is `null` when the key is absent: PHP deprecates the `null`, then prints the same warning.
- **A branch that never ran.** The variable starts as `$pattern = ''` and is filled in a branch the request does not take.
- **A guard that compares with `''`.** `'   '` is not `''`: the call goes through, and PHP prints the same warning. A guard with `trim()` stops it.

## What PHPRegex reports

- `regex.pattern.empty`: "Regex is too short. It must include delimiters, e.g. "/abc/"." It gives no position, since nothing in the string points at the cause.

[Open an empty pattern in the playground](/#pattern=&subject=abc): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Delimiter must not be alphanumeric, backslash, or NUL byte](delimiter-must-not-be-alphanumeric.md)—the first character cannot open a pattern.
- [No ending delimiter found](no-ending-delimiter.md)—the first delimiter has no second.
- [Unknown modifier](unknown-modifier.md)—what follows the closing delimiter is not a modifier.
- [Regex in PHP](../../guides/regex-in-php.md)—delimiters and modifiers from the start.

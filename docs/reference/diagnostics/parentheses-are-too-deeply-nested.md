---
description: "PCRE2 nests groups 250 deep at most, a limit a pattern built in a loop meets. Split the pattern, or build it without nesting a group per iteration."
head_title: "\"parentheses are too deeply nested\" in PHP"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 15
engine:
  message: "Compilation failed: parentheses are too deeply nested at offset 251"
  pattern: "/((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((a))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))/"
codes:
  - regex.group.nested_too_deep
---
# Parentheses are too deeply nested

The pattern opens groups one inside another more times than PCRE2 follows.

```php
preg_match('/(((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((((a)))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))))/', $value);
// preg_match(): Compilation failed: parentheses are too deeply nested at offset 251
```

This pattern opens 251 groups before the first one closes. PCRE2 follows 250: the 251st is refused, and the offset, counted from the opening delimiter, is its position.

## Why PHP prints it

A group inside a group costs one level, and PCRE2 stops at 250 levels.

**Every kind of group counts.** A non-capturing group, an atomic group and a lookaround take a level as a capturing group does:

```php
$pattern = '/' . str_repeat('(?:', 251) . 'a' . str_repeat(')', 251) . '/';
preg_match($pattern, $value);
// preg_match(): Compilation failed: parentheses are too deeply nested at offset 753
```

251 `(?>` and 251 `(?=` are refused at the same offset.

**A pattern built in a loop.** A loop that folds the alternatives one around the other adds a level at each iteration:

```php
$words = array_map(static fn (int $i): string => 'word' . $i, range(1, 260));
$alternation = preg_quote($words[0], '/');
foreach (array_slice($words, 1) as $word) {
    // One level more around the alternatives already there
    $alternation = '(?:' . preg_quote($word, '/') . '|' . $alternation . ')';
}
$pattern = '/\b' . $alternation . '\b/';
preg_match($pattern, $text);
// preg_match(): Compilation failed: parentheses are too deeply nested at offset 2666
```

## How to fix it

Flatten the pattern: a group that wraps one item adds a level and nothing else.

```php
// Before: five groups, and the code reads the second and the fourth
preg_match('/(((\d{4}))-((\d{2})))/', $date, $matches);

// After: the groups that wrapped one item are gone
preg_match('/(\d{4})-(\d{2})/', $date, $matches); // on "2026-11", groups 1 and 2 hold "2026" and "11"

// A list of alternatives: siblings, not one around the other
$alternation = '(?:' . implode('|', array_map(static fn (string $word): string => preg_quote($word, '/'), $words)) . ')';
preg_match('/^' . $alternation . '$/', 'word7', $match); // matches "word7"
```

The rewritten groups keep their meaning, but a dropped capturing group moves the numbers of those after it: the day moves from `$matches[4]` to `$matches[2]`. A pattern that has to stay deep can be cut in two, each half matched by its own call.

## Where it hides

- **A loop that wraps.** The fold above, or an optional chain: `'(?:' . $part . $pattern . ')?'` at each iteration. 260 parts nest 260 levels.
- **The other kinds of group.** `(?:`, `(?>` and `(?=` count like `(`: a non-capturing group is not a way around the limit.
- **Groups side by side.** They cost nothing here: `str_repeat('(a)', 2000)` compiles. Only the depth counts.
- **Classes.** A bracket is not a parenthesis: 4000 `[` in a row, closed by 4000 `]`, compile.

## What PHPRegex reports

- `regex.group.nested_too_deep` for the pattern above: "Parentheses are nested too deeply: PCRE allows at most 250 levels." The library gives the offset, 251 here, and a hint: "Flatten the pattern: drop groups that only wrap one item."

[Open the 251 nested groups in the playground](/#pattern=%2F%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28%28a%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%29%2F&subject=a): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Missing closing parenthesis](missing-closing-parenthesis.md): the message of a group left open.
- [Regular expression is too large](regular-expression-is-too-large.md): the other limit a generated pattern meets.
- [Redundant Non-Capturing Group](../rules/redundant-non-capturing-group.md): the linter also reports a group around one atom.
- [Groups & Alternation](../../tutorial/05-groups-alternation.md): groups, and the non-capturing form.

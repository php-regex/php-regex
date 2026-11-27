---
description: "A recursive pattern recursed with nothing to stop it, as (?R) with no exit: preg_match() returns false at once. Give the recursion a way out."
head_title: "\"JIT stack limit exhausted\" in PHP preg_match"
parent: /reference/diagnostics/
engine_family: "The match"
engine_order: 3
engine:
  message: "JIT stack limit exhausted"
  from: "preg_last_error_msg"
  pattern: "/(?R)/"
  subject: "a"
---
# JIT stack limit exhausted

The pattern calls itself with nothing to stop it, and the engine ran out of stack at once.

```php
var_dump(preg_match('/(?R)/', 'a'));
// bool(false)
echo preg_last_error_msg();
// JIT stack limit exhausted
```

`(?R)` means the whole pattern, again. The whole pattern is `(?R)`, so each level calls the next before matching a character. The engine goes down without end, and gives up. The call returns `false` at once, and no warning is printed.

## Why PHP prints it

Each call of the pattern inside the match takes a frame on a stack. The stack is finite: a recursion that never comes back fills it, and so does one that goes deep enough.

**The recursion is tried first.** In `/(?R)|a/` the first branch calls the pattern, and `a` is never reached. A quantifier changes nothing: `(?R)?` and `(?R)+` go down before they skip or stop:

```php
var_dump(preg_match('/(?R)|a/', 'a'));
// bool(false)
echo preg_last_error_msg();
// JIT stack limit exhausted
```

**A group calls itself.** `(?1)` and `(?&name)` run a group again, and `/((?1)|a)/` ends the same way. `/(a(?1)?)/` matches `a`, since a character comes before the call:

```php
var_dump(preg_match('/((?1)|a)/', 'a'));
// bool(false)
echo preg_last_error_msg();
// JIT stack limit exhausted
```

**The subject is nested too deep.** The pattern of the nested parentheses is right, and ten thousand levels are too many for it:

```php
var_dump(preg_match('/\((?:[^()]++|(?R))*+\)/', str_repeat('(', 10000) . str_repeat(')', 10000)));
// bool(false)
echo preg_last_error_msg();
// JIT stack limit exhausted
```

The message names the JIT because PHP compiles its patterns with it by default, under `pcre.jit`.

## How to fix it

Put a character in front of the call, in the same branch. Each level then consumes one before it goes down, and the level that finds nothing stops the recursion:

```php
// A character is matched before the call, and the call can be skipped
var_dump(preg_match('/a(?R)?/', 'aaa'));
// int(1)

// On a subject with no "a", nothing matches and no error comes
var_dump(preg_match('/a(?R)?/', 'b'));
// int(0)

// Nested parentheses: the call sits behind a "(" that fails when none is left
var_dump(preg_match('/\((?:[^()]++|(?R))*+\)/', 'f(a(b)c)'));
// int(1)
```

When the subject is what goes deep, the pattern can ask for the interpreter: `(*NO_JIT)` at its start takes the JIT out of that match.

```php
// The same pattern, run by the interpreter: ten thousand levels match
var_dump(preg_match('/(*NO_JIT)\((?:[^()]++|(?R))*+\)/', str_repeat('(', 10000) . str_repeat(')', 10000)));
// int(1)
```

It runs slower, and the interpreter has its own ceiling, `pcre.recursion_limit`: at fifty thousand levels the call returns `false` again, with `Recursion limit exhausted`.

## Where it hides

- **A branch that recurses on its own.** `/(?R)|a/` gives the message on every subject. So does `/a|(?R)/` on any subject without an `a`: the branch that fails hands over to the call.
- **A recursive pattern adapted.** `/\((?:[^()]|(?R))*\)/` matches nested parentheses. `/(?:[^()]|(?R))*/` gives the message, since nothing stands before the call in its branch.
- **The JIT switched off.** The same bug then returns `false` all the same, and says `Internal error`.
- **A check that reads the result as a boolean.** `!preg_match('/(?R)/', 'a')` is `true`: the error passes for no match.

## What PHPRegex reports

PHPRegex reports nothing: `/(?R)/` is a valid pattern, the linter has no finding for it, and how deep a recursion goes is decided by the subject, which the library does not see.

[Open `/(?R)/` in the playground](/#pattern=%2F%28%3FR%29%2F&subject=a): the pattern opens, with its tree.

## Related

- [Backtrack limit exhausted](backtrack-limit-exhausted.md)—the `false` of a pattern that tries too many ways.
- [Malformed UTF-8 characters, possibly incorrectly encoded](malformed-utf-8-characters.md)—the `false` of a subject that is not UTF-8.
- [Chapter 7: Backreferences, Subroutines & Recursion](../../tutorial/07-backreferences-recursion.md)—`(?R)`, `(?1)` and `(?&name)` on nested parentheses.
- [Regex in PHP](../../guides/regex-in-php.md)—the modifiers and the error codes of a match.

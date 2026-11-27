---
description: "Every PHP regex modifier, shown with and without it: i, m, s, x, u, U, D, A, J, n, the inline options, and why there is no g flag."
head_title: "PHP regex modifiers and flags: i, m, s, x, u, U"
---
# PHP regex modifiers
A modifier is a letter written after the closing delimiter of a pattern, and it changes how the whole pattern reads the subject: `/cat/i` ignores case, `/a.b/s` lets the dot match a line break. The PHP manual calls them pattern modifiers and JavaScript calls them flags: two names for the letters that follow the pattern.

In every example, the comment at the end of a line gives what the line prints.

## Every modifier at a glance

- `i`: case-insensitive.
- `m`: `^` and `$` match at the start and the end of each line.
- `s`: the dot matches line breaks.
- `x`: the spaces and the `#` comments of the pattern are ignored.
- `u`: the subject is read as UTF-8.
- `U`: quantifiers are lazy by default.
- `D`: `$` matches at the very end only.
- `A`: the pattern matches at the start of the subject only.
- `J`: two groups may share a name.
- `n`: parentheses do not capture, from PHP 8.2.
- `e`: removed. `preg_replace_callback()` does its work.
- `r`: under `i`, an ASCII letter stops matching the two characters outside ASCII that fold into it, from PHP 8.4.
- `S` and `X`: accepted, and do nothing.

## Where modifiers stand, and how they combine

Modifiers follow the closing delimiter, and nothing else may: the first letter PHP does not know there stops the pattern with a warning, [Unknown modifier](../reference/diagnostics/unknown-modifier.md).

```php
var_dump(preg_match('/^a.b$/is', "A\nB")); // int(1)
var_dump(preg_match('/^a.b$/si', "A\nB")); // int(1)
var_dump(preg_match('/a/ii', 'A')); // int(1)
```

- `is` and `si` are the same pattern: the order is free.
- A modifier written twice does the work of one.
- They are case-sensitive: `i` ignores case, `I` is unknown.
- A space, a carriage return or a line break between the delimiter and the modifiers is skipped. A tab is not: `/a/` followed by a tab and `i` warns `Unknown modifier` with the tab in the message.

## `i` ignores case

Letters match their other case, in the pattern and in the subject. A class of letters follows: `[a-f]` with `i` matches `F`.

```php
echo preg_replace('/cat/', 'dog', 'Cat, cat, CAT'); // Cat, dog, CAT
echo preg_replace('/cat/i', 'dog', 'Cat, cat, CAT'); // dog, dog, dog
```

Under `u`, every character with another case follows: `/é/iu` matches `É`. Without `u`, `é` is two bytes, and `/é/i` does not match `É`.

## `m` matches at the start and the end of each line

Without `m`, `^` matches at the start of the subject, and `$` at its end or just before a line break that ends it. With `m`, both match at every line end too: `^` after a line break, `$` before one.

```php
echo preg_replace('/^\w+$/', 'X', "one\ntwo"); // one\ntwo
echo preg_replace('/^\w+$/m', 'X', "one\ntwo"); // X\nX
```

`m` moves `^` and `$` and nothing else, so a pattern with neither anchor gains nothing from it.

The line break `m` reads is the LF. `^` matches after `\n` and after `\r\n`, and not after a lone `\r`. For every line break, write `(*ANY)` at the start of the pattern:

```php
var_dump(preg_match('/^b/m', "a\rb")); // int(0)
var_dump(preg_match('/(*ANY)^b/m', "a\rb")); // int(1)
```

## `s` lets the dot match line breaks

```php
echo preg_replace('/<p>.*<\/p>/', '', "<p>a\nb</p>tail"); // <p>a\nb</p>tail
echo preg_replace('/<p>.*<\/p>/s', '', "<p>a\nb</p>tail"); // tail
```

`s` changes the dot alone. A class matches a line break with or without it, as `[\s\S]` and `[^"]` do. `\.` and a dot inside a class, `[.]`, are the character itself, with or without `s`.

## `x` ignores the spaces and the comments of the pattern

Under `x`, PHP drops the spaces, the tabs and the line breaks of the pattern, and takes everything from `#` to the end of the line as a comment. A long pattern can then be laid out and explained:

```php
$body = '\d{4}    # the year
-            # the dash
\d{2}    # the month';
echo preg_match('/'.$body.'/', '2026-11'); // 0
echo preg_match('/'.$body.'/x', '2026-11'); // 1
```

Three places keep what they hold:

- Inside a class, a space is a character: `a[ ]b` matches `a b` and not `ab`.
- A backslash keeps a character literal: `\ ` is a space and `\#` a hash.
- A `#` inside a class is the character.

```php
var_dump(preg_match('/a[ ]b/x', 'a b')); // int(1)
var_dump(preg_match('/\#/x', '#')); // int(1)
var_dump(preg_match('/[#a]/x', '#')); // int(1)
```

## `u` reads the subject as UTF-8

Without `u`, the engine counts bytes. With `u`, it counts characters, and the shorthands follow Unicode:

```php
var_dump(preg_match('/^\w+$/', 'café')); // int(0)
var_dump(preg_match('/^\w+$/u', 'café')); // int(1)
var_dump(preg_match_all('/./', 'café')); // int(5)
var_dump(preg_match_all('/./u', 'café')); // int(4)
```

What `u` changes:

- One character is one character: `.` matches `é`, and `.{4}` counts four of them. `café` is five bytes and four characters.
- `\w`, `\d`, `\s` and their negations match Unicode characters: `\d` matches `٣`, `\s` matches a no-break space.
- `\x{…}` names a character by its code point, and `\p{…}` by a Unicode property. Under `u`, a hex escape is a code point, where it is a byte without: `\xE9` is `é` under `u`, and `\xC3\xA9`, the two bytes of `é`, is then two characters, `Ã` and `©`.

```php
var_dump(preg_match('/^\xE9$/', 'é')); // int(0)
var_dump(preg_match('/^\xE9$/u', 'é')); // int(1)
var_dump(preg_match('/^\xC3\xA9$/u', 'é')); // int(0)
var_dump(preg_match('/^\x{E9}$/u', 'é')); // int(1)
```

The price of `u` is that the subject has to be valid UTF-8. A subject that is not makes the call fail, and no warning says so:

```php
var_dump(preg_replace('/./u', 'x', "touch\xC3")); // NULL
echo preg_last_error_msg(); // Malformed UTF-8 characters, possibly incorrectly encoded
```

[Malformed UTF-8 characters](../reference/diagnostics/malformed-utf-8-characters.md) shows where such a subject comes from and how to keep it out.

## `U` makes quantifiers lazy

A quantifier is greedy by default: it takes as much as it can. `U` swaps the two: `*`, `+`, `?` and `{n,m}` take the least, and a lazy quantifier such as `.*?` takes the most.

```php
echo preg_replace('/".*"/', 'X', '"a" and "b"'); // X
echo preg_replace('/".*"/U', 'X', '"a" and "b"'); // X and X
echo preg_replace('/".*?"/U', 'X', '"a" and "b"'); // X
```

## `D` matches at the very end only

Without `D` and without `m`, `$` matches at the end of the subject and also just before a line break that ends it. `D` removes the second position: `/^\d+$/D` is `/^\d+\z/`.

```php
var_dump(preg_match('/^\d+$/', "123\n")); // int(1)
var_dump(preg_match('/^\d+$/D', "123\n")); // int(0)
var_dump(preg_match('/^\d+\z/', "123\n")); // int(0)
```

It matters to a pattern that validates a value: `"123\n"` passes `/^\d+$/`. Under `m`, `$` matches before every line break and `D` changes nothing:

```php
var_dump(preg_match('/^\d+$/mD', "123\n")); // int(1)
```

## `A` anchors the pattern at the start

```php
var_dump(preg_match('/cat/', 'a cat')); // int(1)
var_dump(preg_match('/cat/A', 'a cat')); // int(0)
var_dump(preg_match('/\Acat/', 'a cat')); // int(0)
```

With `A`, the pattern matches at the start of the subject only, as `\A` stood in front of it. `A` differs from `^` under `m`: `^` moves to the start of every line, and `A` stays.

```php
var_dump(preg_match('/^cat/m', "dog\ncat")); // int(1)
var_dump(preg_match('/^cat/mA', "dog\ncat")); // int(0)
```

## `J` lets two groups share a name

Two named groups with the same name do not compile without `J`:

```php
// preg_match(): Compilation failed: two named subpatterns have the same name (PCRE2_DUPNAMES not set) at offset 17
var_dump(preg_match('/(?<id>\d+)-(?<id>\d+)/', '12-34')); // bool(false)
```

With `J`, alternatives can each capture the same thing under one name. In `$matches`, the name holds the last group of that name which took part in the match:

```php
preg_match('/(?<id>\d+)-(?<id>\d+)/J', '12-34', $matches);
echo $matches['id']; // 34
preg_match('/(?<year>\d{4})|(?<year>\d{2})/J', 'in 26', $matches);
echo $matches['year']; // 26
```

## `n` stops parentheses from capturing

From PHP 8.2, `n` makes `(…)` behave as `(?:…)`: no group is stored.

```php
preg_match('/(a)(b)/', 'ab', $matches);
echo json_encode($matches); // ["ab","a","b"]
preg_match('/(a)(b)/n', 'ab', $matches);
echo json_encode($matches); // ["ab"]
```

A named group still captures, and it keeps a number: `(?<first>a)` below is group 1. A backreference by number then has nothing to refer to: `/(a)\1/n` does not compile, with `reference to non-existent subpattern`.

```php
preg_match('/(?<first>a)(b)/n', 'ab', $matches);
echo json_encode($matches); // {"0":"ab","first":"a","1":"a"}
```

Before PHP 8.2 the letter is unknown: PHP 8.1 answers `Unknown modifier 'n'`. That is read in the list of modifiers of `ext/pcre/php_pcre.c`, which has `n` at the `php-8.2.0` tag of php-src and not at `php-8.1.0`, and run on PHP 8.1.34 and 8.2.36.

## `e` is removed: preg_replace_callback() replaced it

In PHP 5, `/e` made `preg_replace()` run the replacement as PHP code. PHP 7.0 removed it: the call returns `null` and warns. The warning is `Unknown modifier 'e'` on PHP 8.4, and `The /e modifier is no longer supported, use preg_replace_callback instead` from PHP 7.0 to PHP 8.3. The callback does the same work, on a value the code can look at:

```php
// preg_replace(): Unknown modifier 'e'
var_dump(preg_replace('/\w+/e', 'strtoupper("$1")', 'hello')); // NULL
echo preg_replace_callback('/\w+/', static fn (array $m): string => strtoupper($m[0]), 'hello'); // HELLO
```

The releases were read in `ext/pcre/php_pcre.c` at the tags `php-5.6.40`, `php-7.0.0`, `php-8.3.0` and `php-8.4.28` of php-src, and the warning was read on PHP 7.0.33, 7.3.33, 8.3.37 and 8.4.28.

## `r` keeps the case folding of `i` apart from ASCII

From PHP 8.4, `r` restricts `i`: a character matches only characters on its own side of ASCII. Two characters outside ASCII fold into ASCII letters, the Kelvin sign `K` (U+212A) into `k` and the long s `ſ` (U+017F) into `s`, and `r` cuts those two:

```php
var_dump(preg_match('/^k$/iu', "\u{212A}")); // int(1)
var_dump(preg_match('/^k$/iur', "\u{212A}")); // int(0)
```

A fold that stays on one side is kept: `/^é$/iur` matches `É`. Before PHP 8.4 the letter is unknown: PHP 8.3 answers `Unknown modifier 'r'`. That is read in the list of modifiers of `ext/pcre/php_pcre.c`, which has `r` at the `php-8.4.28` tag of php-src and not at `php-8.3.0`, and run on PHP 8.3.37 and 8.4.28. What `r` restricts is read under `PCRE2_EXTRA_CASELESS_RESTRICT` in the `pcre2api` manual of PCRE2 10.49.

## `S` and `X` are accepted and do nothing

PHP 8.0 and later read `S` and `X` after the delimiter and move on: `/a/SX` compiles and matches `a`. That is read in `ext/pcre/php_pcre.c` at the `php-8.0.0` and `php-8.4.28` tags of php-src, and run on PHP 8.1.34 and 8.4.28.

## Inline options turn a modifier on inside the pattern

`(?i)` sets `i` from there on, up to the end of the group around it. In `cat(?i)cat`, the first `cat` is case-sensitive and the second is not:

```php
var_dump(preg_match('/cat(?i)cat/', 'catCAT')); // int(1)
var_dump(preg_match('/cat(?i)cat/', 'CATcat')); // int(0)
```

`(?i:…)` sets it for a group alone, and `(?-i)` switches it off from there on, under the `i` modifier:

```php
var_dump(preg_match('/(?i:cat)dog/', 'CATdog')); // int(1)
var_dump(preg_match('/(?i:cat)dog/', 'CATDOG')); // int(0)
var_dump(preg_match('/x(?-i)y/i', 'Xy')); // int(1)
var_dump(preg_match('/x(?-i)y/i', 'xY')); // int(0)
```

Three things to know:

- `m`, `s`, `x`, `U`, `J`, `n` and `r` are written the same way. `u`, `A` and `D` are not: `(?u)` does not compile.
- An option set in one alternative holds for the alternatives after it, up to the end of the group around them: `/a(?i)b|c/` matches `C`, and `/(?:(?i)a|b)c/` does not match `BC`.
- JavaScript has the scoped form alone, in the grammar of the ECMAScript 2026 specification: `(?i:…)` is there, and a bare `(?i)` is a syntax error.

A modifier set after the delimiter and undone inside the pattern is a finding of PHPRegex, [Inline Flag Override](../reference/rules/inline-flag-override.md), and one set a second time is [Inline Flag Redundant](../reference/rules/inline-flag-redundant.md).

## There is no g modifier in PHP

The `g` of JavaScript asks for every match. PHP has no letter for it, because each function already says what it wants:

- `preg_match()` stops at the first match.
- `preg_match_all()` finds every match.
- `preg_replace()` replaces every match, unless `$limit` says otherwise: `1` replaces the first alone.

```php
var_dump(preg_match('/cat/', 'cat cat')); // int(1)
preg_match_all('/cat/', 'cat cat', $matches);
echo count($matches[0]); // 2
echo preg_replace('/cat/', 'dog', 'cat cat'); // dog dog
echo preg_replace('/cat/', 'dog', 'cat cat', 1); // dog cat
```

A `g` after the delimiter is an unknown modifier. PHP warns `preg_match(): Unknown modifier 'g'`, under the name of the function called, and the call fails: `preg_match()`, `preg_match_all()`, `preg_split()` and `preg_grep()` return `false`, and `preg_replace()` returns `null`.

```php
var_dump(preg_match('/cat/g', 'cat cat')); // bool(false)
var_dump(preg_replace('/cat/g', 'dog', 'cat cat')); // NULL
```

[Unknown modifier](../reference/diagnostics/unknown-modifier.md) shows the other shapes of that warning, most of them a delimiter that closes the pattern early.

**Try it.** [Open `/foo/g` in the playground](/#pattern=%2Ffoo%2Fg&subject=foo%20bar%20foo): the Diagnostics tab shows what PHPRegex says of the flag, and what PHP says.

## The flags of JavaScript and their counterpart in PHP

JavaScript accepts eight flags, `d`, `g`, `i`, `m`, `s`, `u`, `v` and `y`, and refuses any other letter with a `SyntaxError` ([`RegExpInitialize`](https://tc39.es/ecma262/2026/multipage/text-processing.html#sec-regexpinitialize) in the ECMAScript 2026 specification). Here is what PHP has for each:

- `g`, every match: no modifier. `preg_match_all()` finds every match and `preg_replace()` replaces every one by default.
- `y`, sticky, the match starting where `lastIndex` says ([`RegExpBuiltinExec`](https://tc39.es/ecma262/2026/multipage/text-processing.html#sec-regexpbuiltinexec)): no modifier. `\G` anchors the pattern where the search starts, and the `$offset` argument of `preg_match()` moves that start.
- `d`, the start and end index of every capture: no modifier. `PREG_OFFSET_CAPTURE` gives each entry of `$matches` its byte offset.
- `v`, set operations and strings of several characters in a class: no counterpart, and the classes of PHP do not nest. `[a-z[0-9]]` is the class `[a-z[0-9]` followed by a literal `]`: it matches `5]` and not `5`. Write `[a-z0-9]`.
- `u`, `s`, `m`, `i`: the same letters, with the differences of the next section.

```php
var_dump(preg_match('/cat/', 'a cat')); // int(1)
var_dump(preg_match('/\Gcat/', 'a cat')); // int(0)
var_dump(preg_match('/\Gcat/', 'a cat', $matches, 0, 2)); // int(1)
preg_match('/(a)(b)/', 'xab', $matches, PREG_OFFSET_CAPTURE);
echo json_encode($matches); // [["ab",1],["a",1],["b",2]]
var_dump(preg_match('/^[a-z[0-9]]$/', '5')); // int(0)
var_dump(preg_match('/^[a-z[0-9]]$/', '5]')); // int(1)
```

## What PHP reads differently from JavaScript

Three rules look alike in the two languages and end differently. The JavaScript side is read in the [ECMAScript 2026 specification](https://tc39.es/ecma262/2026/multipage/text-processing.html), in the `$` assertion, in the `LineTerminator` production of its lexical grammar and in `WordCharacters`; the PHP side is read on the engine.

**`$` without `m`.** In JavaScript, `$` matches at the end of the input and nowhere else. In PHP, it matches there and also before a line break that ends the subject. `D` gives PHP the rule of JavaScript:

```php
var_dump(preg_match('/^\d+$/', "123\n")); // int(1)
var_dump(preg_match('/^\d+$/D', "123\n")); // int(0)
```

**The line break under `m`.** With `m`, `^` and `$` match at the start and the end of every line in both languages. The line terminators of JavaScript are LF, CR, U+2028 and U+2029. The line break `m` reads in PHP is the LF: `/^b/m` does not match `"a\rb"`, as the fence under `m` above shows, unless the pattern starts with `(*ANY)`.

**`\w` under `u`.** The specification defines `\w` from the ASCII word characters. In PHP, `u` makes `\w` match the letters of every script:

```php
var_dump(preg_match('/^\w+$/u', 'café')); // int(1)
```

## Related

- [Regex in PHP](regex-in-php.md): delimiters, modifiers and the `preg_*` functions from the start.
- [preg_replace in PHP](preg-replace.md): replacing every match or some of them, the references of the replacement, and the callbacks.
- [Regex basics](../tutorial/01-basics.md), the first chapter of the tutorial: what a pattern is, delimiters, and the `i` flag.
- [Anchors and word boundaries](../tutorial/03-anchors-boundaries.md): multiline, `\A`, `\z`, and the trailing newline trap.
- [Quantifiers and greediness](../tutorial/04-quantifiers.md): what `U` swaps.
- [Unknown modifier](../reference/diagnostics/unknown-modifier.md): the warning of a letter PHP does not know.
- [Malformed UTF-8 characters](../reference/diagnostics/malformed-utf-8-characters.md): the failure of `u` on a subject that is not UTF-8.
- [Two named subpatterns have the same name](../reference/diagnostics/two-named-subpatterns-have-the-same-name.md): what `J` lifts.
- [Inline Flag Override](../reference/rules/inline-flag-override.md) and [Inline Flag Redundant](../reference/rules/inline-flag-redundant.md).

PHPRegex reports a modifier that the pattern gives nothing to do:

- [Useless Flag 'i' (Caseless)](../reference/rules/useless-flag-i-caseless.md): no character with another case in the pattern.
- [Useless Flag 'm' (Multiline)](../reference/rules/useless-flag-m-multiline.md): no `^` and no `$`.
- [Useless Flag 's' (DOTALL)](../reference/rules/useless-flag-s-dotall.md): no dot outside a class.
- [Useless Flag 'x' (Extended)](../reference/rules/useless-flag-x-extended.md): no whitespace and no comment.
- [Useless Flag 'D' (Dollar End Only)](../reference/rules/useless-flag-d-dollar-end-only.md): no `$` that `m` does not already move.
- [$ matches before a final newline](../reference/rules/dollar-before-a-trailing-newline.md): the rule that recommends `\z` or `D`.

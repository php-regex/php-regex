---
description: "The PHP regex cheat sheet: every symbol on one line with a checked example, from delimiters and modifiers to groups, lookarounds and the preg functions."
head_title: "PHP regex cheat sheet: syntax and functions"
---
# PHP Regex Cheat Sheet

Every symbol of a PHP pattern on one line, with what it does and a line of code that shows it: the comment at the end of a statement is what the statement prints. The functions come after the syntax, then the messages PHP prints for a pattern it refuses.

How to read the code:

- `echo preg_match(…)` prints `1` when the pattern matches and `0` when it does not.
- A call followed by `? $m[0] : 'none'` prints the text the pattern matched, or `none`.
- Two calls on one line print their results in that order, separated by a space. An array is printed by `json_encode()`.

## Delimiters and modifiers

A pattern is an expression between two delimiters, and its modifiers follow the closing one. In `/^[a-z]+$/i`, the expression is `^[a-z]+$` and the modifier is `i`.

### Delimiters

- `/…/` — the delimiter most PHP code uses. Any character that is not a letter, a digit, a backslash, a space or a NUL byte can be one. `#…#`, `~…~` and `%…%` spare the slashes of a path or a URL.
- `(…)`, `[…]`, `{…}`, `<…>` — paired delimiters. The pattern ends at the closing half that balances the opening one, so the same pair can nest inside: `{\d{2}}`.
- `\/` — the delimiter inside the pattern, escaped. Another delimiter removes the escape.

```php
echo preg_match('/^[a-z]+$/i', 'Abc'); // 1
echo preg_match('#https?://#', 'https://example.com'); // 1
echo preg_match('~[0-9]+~', 'a42'), ' ', preg_match('%[0-9]+%', 'a42'); // 1 1
echo preg_match('{\d{2}}', 'a42'), ' ', preg_match('(\(\d+\))', '(42)'); // 1 1
echo preg_match('[\d+]', 'a42'), ' ', preg_match('<\d+>', 'a42'); // 1 1
echo preg_match('/^\/users\/\d+$/', '/users/42'), ' ', preg_match('#^/users/\d+$#', '/users/42'); // 1 1
```

### Modifiers

The letters after the closing delimiter, one line each:

- `i` — a letter of the pattern matches its upper and its lower case.
- `m` — `^` and `$` match at the start and at the end of every line, not only of the subject.
- `s` — `.` also matches a newline.
- `x` — the spaces and the line breaks of the pattern are ignored, and `#` starts a comment: a long pattern can be written over several lines.
- `u` — the pattern and the subject are read as UTF-8. `\p{…}` needs it, `.` matches one character and not one byte, `\w` and `\d` match the letters and the digits of every script, and `\b` and `\s` follow.
- `U` — the greediness of every quantifier is inverted: lazy by default, and greedy after a `?`.
- `D` — `$` matches at the very end of the subject, not before a final newline. `m` makes it ignored.
- `A` — the pattern can match only where the search starts.
- `J` — two groups may have the same name.
- `n` — a plain `(…)` group captures nothing; only a named group `(?<name>…)` does, and `\1` to a plain group is refused. From PHP 8.2.
- `r` — with `i` and `u`, case is no longer ignored between a letter of ASCII and a character outside ASCII: the Kelvin sign U+212A stops matching `k`. From PHP 8.4.
- `S`, `X` — accepted, and without effect: `S` since PHP 7.3, `X` since PHP 8.0.
- `e` — removed in PHP 7.0. PHP 8.4 answers `Unknown modifier 'e'`; the releases before, `The /e modifier is no longer supported, use preg_replace_callback instead`.

```php
echo preg_match('/cat/', 'CAT'), ' ', preg_match('/cat/i', 'CAT'); // 0 1
echo preg_match('/^b$/', "a\nb"), ' ', preg_match('/^b$/m', "a\nb"); // 0 1
echo preg_match('/a.b/', "a\nb"), ' ', preg_match('/a.b/s', "a\nb"); // 0 1
echo preg_match("/a  b  # two letters\n/x", 'ab'); // 1
echo preg_match('/^\w+$/u', 'café'), ' ', preg_match('/^\w+$/', 'café'); // 1 0
echo preg_match('/<.+>/U', '<b>hi</b>', $m) ? $m[0] : 'none'; // <b>
echo preg_match('/<.+?>/U', '<b>hi</b>', $m) ? $m[0] : 'none'; // <b>hi</b>
echo preg_match('/^cat$/D', "cat\n"), ' ', preg_match('/^cat$/mD', "cat\n"); // 0 1
echo preg_match('/b/A', 'ab', $m, 0, 1), ' ', preg_match('/b/A', 'ab', $m, 0, 0); // 1 0
preg_match('/(?<n>a)|(?<n>b)/J', 'b', $m);
echo $m['n']; // b
preg_match('/(a)(b)/n', 'ab', $m);
echo json_encode($m); // ["ab"]
preg_match('/(?<w>\w+)\d/n', 'a1', $m);
echo $m['w']; // a
echo preg_match('/k/iu', "\u{212A}"), ' ', preg_match('/k/iur', "\u{212A}"); // 1 0
echo preg_match('/a/SX', 'a'); // 1
```

Inside a pattern, `(?i)` turns a modifier on from where it stands and `(?-i)` turns it off. `(?i:…)` applies it to one group only. The letters `u`, `A` and `D` have no inline form: `(?u)` is refused.

```php
echo preg_match('/a(?i)b/', 'aB'), ' ', preg_match('/a(?i)b/', 'Ab'); // 1 0
echo preg_match('/(?i:a)b/', 'Ab'), ' ', preg_match('/(?i:a)b/', 'AB'); // 1 0
echo preg_match('/(?i)a(?-i)b/', 'Ab'), ' ', preg_match('/(?i)a(?-i)b/', 'AB'); // 1 0
```

Each modifier in depth: [PHP regex modifiers](guides/php-regex-modifiers.md).

## Characters and character classes

- `.` — any one character, except a newline. With `s`, the newline too. Under `u`, one character of any script, never one byte of it.
- `[abc]` — one character from the list.
- `[a-z]` — a range: every character from the first to the second, by code point. `[0-9]`, `[A-Za-z0-9]`.
- `[^abc]` — one character that is not in the list.
- `-` — makes a range between two characters. First or last in the class, it is a hyphen.
- `[.]`, `[(]`, `[$]` — inside a class, `.`, `(`, `)`, `{`, `}`, `*`, `+`, `?`, `|` and `$` are plain characters. `\` still escapes, `]` closes the class unless it is escaped or comes first, and `^` negates only as the first character.

```php
echo preg_match('/a.b/', "a\nb"), ' ', preg_match('/a.b/s', "a\nb"); // 0 1
echo preg_match_all('/./u', 'aé', $m), ' ', preg_match_all('/./', 'aé', $m); // 2 3
echo preg_match('/[aeiou]/', 'hello', $m) ? $m[0] : 'none'; // e
echo preg_match('/^#[0-9a-f]{6}$/i', '#F0E0D0'), ' ', preg_match('/^[A-Za-z0-9_]+$/', 'v2_0'); // 1 1
echo preg_match('/[^0-9]+/', 'abc123', $m) ? $m[0] : 'none'; // abc
echo preg_match('/^[a-z]+$/', 'a-z'), ' ', preg_match('/^[a-z-]+$/', 'a-z'); // 0 1
echo preg_match('/[$().*+?{}|]/', '2+2', $m) ? $m[0] : 'none'; // +
echo preg_match('/[]]/', 'a]b'), ' ', preg_match('/[\]]/', 'a]b'); // 1 1
echo preg_match('/[a^b]/', 'x^y', $m) ? $m[0] : 'none'; // ^
```

- `\d` — one digit, `[0-9]`. `\D` — one character that is not a digit.
- `\w` — one word character: a letter, a digit or `_`, as `[a-zA-Z0-9_]`. Under `u`, of every script. `\W` — the others.
- `\s` — one whitespace character: a space, a tab, a newline, a carriage return, a form feed or a vertical tab. Under `u`, the other spaces of Unicode too. `\S` — the others.
- `\h` — one horizontal whitespace character: a space or a tab, and under `u` every space of Unicode. `\v` — one vertical whitespace character: a newline, a vertical tab, a form feed or a carriage return.
- `[[:alpha:]]`, `[[:digit:]]`, `[[:alnum:]]`, `[[:space:]]` — POSIX classes. They stand inside a class; `[:alpha:]` alone is refused.
- `\p{L}` — a letter, `\p{N}` a digit of any script, under `u`. `\P{L}` — one character that is not a letter.
- `|` — or: `cat|dog` matches one or the other. Without a group it reaches both ends of the pattern, so `^cat|dog$` means `^cat` or `dog$`.

```php
echo preg_match('/\d+/', 'Order 42', $m) ? $m[0] : 'none'; // 42
echo preg_match('/\D+/', '123abc', $m) ? $m[0] : 'none'; // abc
echo preg_match('/^\d$/u', '٣'), ' ', preg_match('/^\d$/', '٣'); // 1 0
echo preg_match('/\w+/', 'hello_world!', $m) ? $m[0] : 'none'; // hello_world
echo preg_match('/\W/', 'a!b', $m) ? $m[0] : 'none'; // !
echo preg_match('/^\s$/', "\t"), ' ', preg_match('/^\s$/', 'x'); // 1 0
echo preg_match('/^\s$/u', "\u{A0}"), ' ', preg_match('/^\s$/', "\u{A0}"); // 1 0
echo preg_match('/\S+/', '  hi  ', $m) ? $m[0] : 'none'; // hi
echo preg_match('/^\h$/', "\t"), ' ', preg_match('/^\h$/', "\n"), ' ', preg_match('/^\h$/u', "\u{A0}"); // 1 0 1
echo preg_match('/^\v$/', "\n"), ' ', preg_match('/^\v$/', ' '); // 1 0
echo preg_match('/^[[:alpha:]]+$/', 'Hello'), ' ', preg_match('/^[[:digit:]]{4}$/', '2024'); // 1 1
echo preg_match('/^[[:alnum:]_]+$/', 'v2_0'), ' ', preg_match('/^[[:space:]]*$/', " \t"); // 1 1
echo preg_match('/^\p{L}+$/u', 'café'), ' ', preg_match('/^\p{L}+$/u', 'café!'); // 1 0
echo preg_match('/^\p{N}+$/u', '2024'), ' ', preg_match('/^\P{L}+$/u', '2024'); // 1 1
echo preg_match('/cat|dog/', 'hotdog', $m) ? $m[0] : 'none'; // dog
echo preg_match('/^cat|dog$/', 'the dog'), ' ', preg_match('/^(cat|dog)$/', 'the dog'); // 1 0
```

In depth: [Chapter 2: Character Classes & Escapes](tutorial/02-character-classes.md).

## Anchors and boundaries

An anchor matches a position. It consumes no character.

- `^` — the start of the subject. With `m`, the start of every line.
- `$` — the end of the subject, and also the position just before a newline that ends the subject. With `m`, the end of every line.
- `\A` — the start of the subject, whatever the modifiers.
- `\z` — the end of the subject, and nothing after it.
- `\Z` — the end of the subject, or just before a newline that ends it.
- `\b` — a word boundary: between a word character and a character that is not one, or at the start or the end of the subject.
- `\B` — a position that is not a word boundary.
- `\G` — the position where the search starts: the start of the subject, or the offset given to the function.

```php
echo preg_match('/^cat/', 'cat food'), ' ', preg_match('/^cat/', 'the cat'); // 1 0
echo preg_match('/cat$/', 'the cat'), ' ', preg_match('/cat$/', 'cat food'); // 1 0
echo preg_match('/^cat$/', "cat\n"), ' ', preg_match('/^cat$/', "cat\ncat"); // 1 0
echo preg_match('/^cat$/m', "cat\ncat"), ' ', preg_match('/^cat$/D', "cat\n"); // 1 0
echo preg_match('/\Acat/m', "cat\ncat"), ' ', preg_match('/\Abird/m', "cat\nbird"); // 1 0
echo preg_match('/bird\z/m', "cat\nbird"), ' ', preg_match('/cat\z/', "cat\n"); // 1 0
echo preg_match('/cat\Z/', "cat\n"), ' ', preg_match('/cat\z/', "cat\n"); // 1 0
echo preg_match('/\bcat\b/', 'the cat'), ' ', preg_match('/\bcat\b/', 'category'); // 1 0
echo preg_match('/\bcaf\b/u', 'café'), ' ', preg_match('/\bcaf\b/', 'café'); // 0 1
echo preg_match('/\Bcat\B/', 'concatenate'), ' ', preg_match('/\Bcat\B/', 'the cat'); // 1 0
echo preg_match('/\Gcat/', 'the cat', $m, 0, 4), ' ', preg_match('/\Gcat/', 'the cat', $m, 0, 0); // 1 0
```

In depth: [Chapter 3: Anchors & Boundaries](tutorial/03-anchors-boundaries.md).

## Quantifiers

A quantifier says how many times the element right before it repeats: one character, one class or one group.

- `*` — zero or more times.
- `+` — one or more times.
- `?` — zero or one time: optional.
- `{n}` — exactly `n` times.
- `{n,}` — `n` times or more.
- `{n,m}` — from `n` to `m` times.
- `{,m}` — from zero to `m` times. From PHP 8.4; before, it is the text `{,m}`.
- `?` after a quantifier — lazy: as few times as the rest of the pattern allows. `*?`, `+?`, `??`, `{n,m}?`.
- `+` after a quantifier — possessive: it never gives back what it took. `*+`, `++`, `?+`, `{n,m}+`.

```php
echo preg_match('/ab*/', 'a', $m) ? $m[0] : 'none'; // a
echo preg_match('/ab*/', 'abbb', $m) ? $m[0] : 'none'; // abbb
echo preg_match('/ab+/', 'abbb', $m) ? $m[0] : 'none'; // abbb
echo preg_match('/ab+/', 'a', $m) ? $m[0] : 'none'; // none
echo preg_match('/colou?r/', 'colour', $m) ? $m[0] : 'none'; // colour
echo preg_match('/^\d{4}$/', '2024'), ' ', preg_match('/^\d{4}$/', '202'); // 1 0
echo preg_match('/^\d{2,}$/', '123'), ' ', preg_match('/^\d{2,}$/', '1'); // 1 0
echo preg_match('/^\d{2,4}$/', '1234'), ' ', preg_match('/^\d{2,4}$/', '12345'); // 1 0
echo preg_match('/^a{,2}$/', 'aa'), ' ', preg_match('/^a{,2}$/', 'aaa'); // 1 0
echo preg_match('/<.+>/', '<b>hi</b>', $m) ? $m[0] : 'none'; // <b>hi</b>
echo preg_match('/<.+?>/', '<b>hi</b>', $m) ? $m[0] : 'none'; // <b>
echo preg_match('/a.*?b/', 'abab', $m) ? $m[0] : 'none'; // ab
echo preg_match('/a{2,4}?/', 'aaaa', $m) ? $m[0] : 'none'; // aa
echo preg_match('/a??/', 'a', $m) ? "[$m[0]]" : 'none'; // []
echo preg_match('/a+a/', 'aa', $m) ? $m[0] : 'none'; // aa
echo preg_match('/a++a/', 'aa', $m) ? $m[0] : 'none'; // none
echo preg_match('/a*+a/', 'aa'), ' ', preg_match('/a?+a/', 'a'), ' ', preg_match('/a{1,3}+a/', 'aaa'); // 0 0 0
```

In depth: [Chapter 4: Quantifiers & Greediness](tutorial/04-quantifiers.md).

## Groups, backreferences and recursion

- `(…)` — a capturing group. What it matches goes to `$m`, by number: `$m[0]` is the whole match, `$m[1]` the first group.
- `(?:…)` — a group that captures nothing. It groups for a quantifier or for `|`.
- `(?<name>…)` — a group with a name: `$m['name']`. `(?'name'…)` and `(?P<name>…)` are the same group.
- `\1`, `\2` — a backreference: the text group 1, group 2 matched. `\k<name>` — the text of a named group. `\g{1}` takes a number, `\g{name}` a name.
- `(?R)` — recursion: the whole pattern, again, from where it stands. `(?1)` and `(?&name)` run one group again.
- `(?(1)yes|no)` — a conditional: `yes` if group 1 took part in the match, else `no`.
- `(?>…)` — an atomic group: once it is closed, the engine never backtracks into it.

```php
preg_match('/(\d{4})-(\d{2})-(\d{2})/', '2024-01-15', $m);
echo $m[1], ' ', $m[2], ' ', $m[3]; // 2024 01 15
preg_match('/(?:\d{4})-(\d{2})/', '2024-01-15', $m);
echo json_encode($m); // ["2024-01","01"]
preg_match('/(?<year>\d{4})-(?<month>\d{2})/', '2024-01-15', $m);
echo "$m[year] / $m[month]"; // 2024 / 01
preg_match("/(?'day'\d{2})\/(?P<month>\d{2})/", '15/01', $m);
echo $m['day'], ' ', $m['month']; // 15 01
echo preg_match('/(\w+) \1/', 'hello hello', $m) ? $m[0] : 'none'; // hello hello
echo preg_match('/(?<w>\w+) \k<w>/', 'bye bye', $m) ? $m[0] : 'none'; // bye bye
echo preg_match('/(?<w>\w+) \g{w}/', 'oy oy', $m) ? $m[0] : 'none'; // oy oy
echo preg_match('/(\w+) \g{1}/', 'hi hi', $m) ? $m[0] : 'none'; // hi hi
echo preg_match('/(\w)(\w)\2\1/', 'abba', $m) ? $m[0] : 'none'; // abba
echo preg_match('/\((?:[^()]|(?R))*\)/', 'f(a(b)c)', $m) ? $m[0] : 'none'; // (a(b)c)
echo preg_match('/(\d+)-(?1)/', '12-12', $m) ? $m[0] : 'none'; // 12-12
echo preg_match('/(?<n>\d+)-(?&n)/', '34-34', $m) ? $m[0] : 'none'; // 34-34
echo preg_match('/^(a)?(?(1)b|c)$/', 'ab'), ' ', preg_match('/^(a)?(?(1)b|c)$/', 'c'); // 1 1
echo preg_match('/^(a)?(?(1)b|c)$/', 'ac'); // 0
echo preg_match('/(?>a+)b/', 'aab'), ' ', preg_match('/(?>a+)a/', 'aa'); // 1 0
```

In depth: [Chapter 5: Groups & Alternation](tutorial/05-groups-alternation.md) and [Chapter 7: Backreferences, Subroutines & Recursion](tutorial/07-backreferences-recursion.md).

## Lookarounds

A lookaround checks the text before or after the position, and consumes nothing of it.

- `(?=…)` — positive lookahead: what follows must match.
- `(?!…)` — negative lookahead: what follows must not match.
- `(?<=…)` — positive lookbehind: what precedes must match.
- `(?<!…)` — negative lookbehind: what precedes must not match.
- `(?*…)`, `(?<*…)` — non-atomic lookaround: as `(?=…)` and `(?<=…)`, but the engine may go back into it once it has matched. From PCRE2 10.35, which PHP 8.0 bundles.

The non-atomic lookahead differs from `(?=…)` when the rest of the pattern needs the captured text shorter. On `aaab`, `(?=(a+))` captures `aaa` and keeps it, so `\1ab` has too little left. `(?*(a+))` gives characters back until `\1` is `aa`.

```php
echo preg_match('/\d+(?= USD)/', '100 USD', $m) ? $m[0] : 'none'; // 100
echo preg_match('/\bcat(?!\w)/', 'one cat', $m) ? $m[0] : 'none'; // cat
echo preg_match('/\bcat(?!\w)/', 'two cats', $m) ? $m[0] : 'none'; // none
echo preg_match('/(?<=\$)\d+/', 'Total: $49', $m) ? $m[0] : 'none'; // 49
echo preg_match('/(?<!\$)\b\d+/', 'Total: 49', $m) ? $m[0] : 'none'; // 49
echo preg_match('/(?<!\$)\b\d+/', 'Total: $49', $m) ? $m[0] : 'none'; // none
echo preg_match('/^(?=.*\d).{8,}$/', 'abcdefg1', $m) ? $m[0] : 'none'; // abcdefg1
echo preg_match('/(?=(a+))\1ab/', 'aaab', $m) ? $m[0] : 'none'; // none
echo preg_match('/(?*(a+))\1ab/', 'aaab', $m) ? $m[0] : 'none'; // aaab
echo preg_match('/(?<*\d{2})px/', '24px', $m) ? $m[0] : 'none'; // px
```

A lookbehind needs a maximum length. Before PHP 8.4, each of its branches must have one fixed length: `(?<=a|bb)` is accepted and `(?<=a{1,3})` is refused. From PHP 8.4, a branch may cover several lengths, as long as it has a maximum of 255 characters. `(?<=a+)`, which has none, is refused on every PHP. The message by release, and the rewrites: [lookbehind assertion is not fixed length](reference/diagnostics/lookbehind-assertion-is-not-fixed-length.md).

```php
echo preg_match('/(?<=a|bb)c/', 'bbc'), ' ', preg_match('/(?<=a{1,3})b/', 'aaab'); // 1 1
```

A non-atomic lookbehind of several lengths gives a wrong answer under the JIT of PCRE2 10.44, the release PHP 8.4 to 8.6 bundle: `/(?<*(aaa|a)b)\1c/` finds nothing in `aaabac` with the default `pcre.jit=1`, and `ac` with `pcre.jit=0`. The change log of PCRE2 dates the correction to 10.48.

In depth: [Chapter 6: Lookarounds & Assertions](tutorial/06-lookarounds.md).

## Escapes and special characters

- `\` — makes the next character plain: `\.` is a dot, `\*` a star, `\(` and `\)` parentheses, `\[` and `\]` brackets, `\{` and `\}` braces, and `\?`, `\+`, `\^`, `\$`, `\|` and `\\` the same. In a PHP string between single quotes, one backslash is written `\\`.
- `\n`, `\t`, `\r` — a newline, a tab, a carriage return. `\f` a form feed, `\e` the escape character, `\0` a NUL byte.
- `\xHH` — the character of a two-digit hexadecimal code: `\x41` is `A`. `\x{…}` takes more digits, and needs `u` past `\x{FF}`: `\x{E9}` is `é`.
- `\Q…\E` — every character between the two is plain, metacharacters included.
- `preg_quote()` — the same for a text known at run time only: see [the functions](#the-preg-functions).

```php
echo preg_match('/file\.txt/', 'fileXtxt'), ' ', preg_match('/file.txt/', 'fileXtxt'); // 0 1
echo preg_match('/\[\]\{\}\*\+\?\^\|/', '[]{}*+?^|', $m) ? $m[0] : 'none'; // []{}*+?^|
echo preg_match('/\(\d\)/', '(5)', $m) ? $m[0] : 'none'; // (5)
echo preg_match("/a\nb/", "a\nb"), ' ', preg_match("/a\tb/", "a\tb"), ' ', preg_match("/a\r\nb/", "a\r\nb"); // 1 1 1
echo preg_match('/^\f$/', "\f"), ' ', preg_match('/^\e$/', "\e"), ' ', preg_match('/^\0$/', "\0"); // 1 1 1
echo preg_match('/\x41/', 'A'), ' ', preg_match('/^\x{E9}$/u', "\u{E9}"); // 1 1
echo preg_match('/\Qa+b\E/', 'a+b', $m) ? $m[0] : 'none'; // a+b
echo preg_match('/\Qa+b\E/', 'aab', $m) ? $m[0] : 'none'; // none
echo preg_match('/\\\\/', 'a\b', $m) ? $m[0] : 'none'; // \
echo preg_quote('a+b (c)', '/'); // a\+b \(c\)
```

## The preg functions

- `preg_match()` — `1` if the pattern matches once, `0` if it does not. The third argument receives the match and its groups.
- `preg_match_all()` — the number of matches. The third argument receives them all.
- `preg_replace()` — the subject with every match replaced. `$1` in the replacement is the text of group 1.
- `preg_replace_callback()` — the same, with the replacement built by a function for each match.
- `preg_split()` — an array: the subject cut at every match.
- `preg_grep()` — the entries of an array that match, with their keys.
- `preg_quote()` — a text with its metacharacters escaped, ready to stand in a pattern.
- `preg_last_error()` — the error of the last call, `PREG_NO_ERROR` when all went well; `preg_last_error_msg()` says it in words. A call that fails returns `false`: that is not "no match".

```php
echo preg_match('/\d+/', 'abc123'); // 1
preg_match('/(\d+)-(\d+)/', 'code 12-34', $m);
echo json_encode($m); // ["12-34","12","34"]
echo preg_match_all('/\d+/', 'a1 b22 c333', $m); // 3
echo json_encode($m[0]); // ["1","22","333"]
echo preg_replace('/(\d+)-(\d+)/', '$2-$1', '12-34'); // 34-12
echo preg_replace_callback('/\d+/', fn ($m) => $m[0] * 2, 'a1b22'); // a2b44
echo json_encode(preg_split('/[\s,]+/', 'a, b,c')); // ["a","b","c"]
echo json_encode(preg_grep('/^\d+$/', ['a', '1', '2', 'b'])); // {"1":"1","2":"2"}
echo preg_quote('a.b/c', '/'); // a\.b\/c
var_dump(preg_match('/\p{L}/u', "\xC3\x28")); // bool(false)
echo preg_last_error_msg(); // Malformed UTF-8 characters, possibly incorrectly encoded
var_dump(preg_last_error() === PREG_NO_ERROR); // bool(false)
```

`preg_replace()` in depth: [preg_replace in PHP](guides/preg-replace.md).

## The mistakes everyone meets once

The message PHP prints, and the page that says why and how to fix it:

- `Unknown modifier` — [a delimiter stands inside the pattern](reference/diagnostics/unknown-modifier.md)
- `No ending delimiter found` — [the closing delimiter is missing or escaped](reference/diagnostics/no-ending-delimiter.md)
- `Delimiter must not be alphanumeric, backslash, or NUL byte` — [the pattern has no delimiters](reference/diagnostics/delimiter-must-not-be-alphanumeric.md)
- `Empty regular expression` — [a variable held nothing](reference/diagnostics/empty-regular-expression.md)
- `missing closing parenthesis` — [a `(` opens a group that no `)` closes](reference/diagnostics/missing-closing-parenthesis.md)
- `unmatched closing parenthesis` — [a `)` closes no group](reference/diagnostics/unmatched-closing-parenthesis.md)
- `unrecognized character after (? or (?-` — [`(?` is followed by a letter PCRE2 does not read there](reference/diagnostics/unrecognized-character-after-group-open.md)
- `missing terminating ] for character class` — [a `[` opens a class that no `]` closes](reference/diagnostics/missing-terminating-bracket-for-character-class.md)
- `range out of order in character class` — [a range runs backwards, as `[z-a]`](reference/diagnostics/range-out-of-order-in-character-class.md)
- `quantifier does not follow a repeatable item` — [a quantifier follows an anchor or another quantifier](reference/diagnostics/quantifier-does-not-follow-a-repeatable-item.md)
- `numbers out of order in {} quantifier` — [the maximum comes first, as `{5,2}`](reference/diagnostics/numbers-out-of-order-in-quantifier.md)
- `number too big in {} quantifier` — [a count past 65535](reference/diagnostics/number-too-big-in-quantifier.md)
- `unrecognized character follows \` — [a backslash precedes a letter PCRE2 knows no escape for](reference/diagnostics/unrecognized-character-follows-backslash.md)
- `PCRE2 does not support \F, \L, \l, \N{name}, \U, or \u` — [the `\uXXXX` of JavaScript](reference/diagnostics/pcre2-does-not-support-unicode-escapes.md)
- `unknown property after \P or \p` — [`\p{…}` names no property PCRE2 knows](reference/diagnostics/unknown-unicode-property.md)
- `POSIX named classes are supported only within a class` — [`[:alpha:]` was written outside a class](reference/diagnostics/posix-named-classes.md)
- `syntax error in subpattern name` — [a group name is not closed, or holds a character a name cannot](reference/diagnostics/syntax-error-in-subpattern-name.md)
- `two named subpatterns have the same name` — [rename one, or allow it with `J`](reference/diagnostics/two-named-subpatterns-have-the-same-name.md)
- `reference to non-existent subpattern` — [a backreference or a call names a group the pattern does not have](reference/diagnostics/reference-to-non-existent-subpattern.md)
- `lookbehind assertion is not fixed length` — [a lookbehind has no maximum length](reference/diagnostics/lookbehind-assertion-is-not-fixed-length.md)
- `character code point value in \x{} or \o{} is too large` — [a code point past `10FFFF`, or past `FF` without `u`](reference/diagnostics/character-code-point-value-is-too-large.md)
- `parentheses are too deeply nested` — [250 levels at most](reference/diagnostics/parentheses-are-too-deeply-nested.md)
- `regular expression is too large` — [the compiled pattern passes 64 KiB](reference/diagnostics/regular-expression-is-too-large.md)
- `Malformed UTF-8 characters, possibly incorrectly encoded` — [under `u`, the subject is not valid UTF-8](reference/diagnostics/malformed-utf-8-characters.md)
- `UTF-8 error: illegal byte (0xfe or 0xff)` — [under `u`, the pattern is not valid UTF-8](reference/diagnostics/utf-8-error-in-pattern.md)
- `Backtrack limit exhausted` — [the pattern backtracked past its limit](reference/diagnostics/backtrack-limit-exhausted.md)
- `JIT stack limit exhausted` — [a recursion with no way out](reference/diagnostics/jit-stack-limit-exhausted.md)

Every message has its page under [the errors PHP prints](reference/diagnostics.md), the two warnings of the JIT included.

**The releases.** What this page says of a PHP release comes from the sources: [`ext/pcre/php_pcre.c`](https://github.com/php/php-src/blob/php-8.4.28/ext/pcre/php_pcre.c) and the bundled [`ext/pcre/pcre2lib/pcre2.h`](https://github.com/php/php-src/blob/php-8.4.0/ext/pcre/pcre2lib/pcre2.h) at the release tags of php-src, the `UPGRADING` notes of [PHP 8.2.0](https://github.com/php/php-src/blob/php-8.2.0/UPGRADING) and [PHP 8.4.0](https://github.com/php/php-src/blob/php-8.4.0/UPGRADING), the pages of the PHP manual on [the modifiers](https://www.php.net/manual/en/reference.pcre.pattern.modifiers.php) and on [assertions](https://www.php.net/manual/en/regexp.reference.assertions.php), and [the change log of PCRE2](https://github.com/PCRE2Project/pcre2/blob/pcre2-10.49/ChangeLog). Every line of code was then run on the official `php:X.Y-cli` images, 8.0 to 8.6, and prints what its comment says on every release it names.

**Try it.** [Open `/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})$/` in the playground](/#pattern=%2F%5E%28%3F%3Cyear%3E%5Cd%7B4%7D%29-%28%3F%3Cmonth%3E%5Cd%7B2%7D%29-%28%3F%3Cday%3E%5Cd%7B2%7D%29%24%2F&subject=2024-01-15): the Diagnostics tab shows what the linter says of it.

## Learn more

- [The tutorial](tutorial/README.md): ten chapters, from the first pattern to recursion.
- [The cookbook](cookbook.md): ready-made patterns for emails, URLs, dates and more.
- [PHP regex modifiers](guides/php-regex-modifiers.md) and [preg_replace in PHP](guides/preg-replace.md).
- [Every message of the engine and its fix](reference/diagnostics.md).

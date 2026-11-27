---
description: "How to replace with a regex in PHP: every match or the first ones, $1 and ${1} in the replacement, arrays, callbacks, line breaks, and a failed call."
head_title: "preg_replace in PHP: replace all, callback, first"
---
# preg_replace in PHP
`preg_replace()` finds what a pattern matches in a subject and puts a replacement in its place. This page shows what the call returns, how to replace every match or only some of them, how the replacement refers to the captured groups, the array and callback forms, and what happens when the call fails.

In every example, the comment at the end of a line gives what the line prints.

## What preg_replace() returns

```
preg_replace(
    string|array $pattern,
    string|array $replacement,
    string|array $subject,
    int $limit = -1,
    int &$count = null,
): string|array|null
```

The result has three shapes:

- a string, when the subject is a string: the subject with every replacement made, or the subject as it was when nothing matched;
- an array, when the subject is an array: one result per entry, under the keys of the subject;
- `null`, when the call fails. [When the call returns null](#when-the-call-returns-null) tells why.

```php
echo json_encode(preg_replace('/\d+/', 'N', 'a1b22c')); // "aNbNc"
echo json_encode(preg_replace('/\d+/', 'N', ['a1', 'b22'])); // ["aN","bN"]
echo json_encode(preg_replace('/\d+/', 'N', 'no digit here')); // "no digit here"
// preg_replace(): Compilation failed: missing terminating ] for character class at offset 1
echo json_encode(preg_replace('/[/', 'N', 'a1b22c')); // null
```

Two parameters bound the work:

- `$limit` is the greatest number of replacements one pattern makes in one subject. The default, `-1`, means no limit. `1` replaces the first occurrence alone, and `0` replaces nothing.
- `$count`, passed by reference, receives the number of replacements made.

## How to replace all occurrences, or only the first ones

`preg_replace()` replaces every match by default. There is no flag to add for "replace all". For the first occurrence alone, pass `1` as `$limit`; for the first N, pass `N`.

```php
echo preg_replace('/two/', 'TWO', 'two two two'); // TWO TWO TWO
echo preg_replace('/two/', 'TWO', 'two two two', 1); // TWO two two
echo preg_replace('/two/', 'TWO', 'two two two', 2); // TWO TWO two
echo preg_replace('/two/', 'TWO', 'two two two', 0); // two two two
```

`$count` says how many were replaced:

```php
$count = 0;
$text = preg_replace('/two/', 'TWO', 'two two two', -1, $count);
echo $text, ': ', $count; // TWO TWO TWO: 3
```

A pattern that can match the empty string replaces between the characters too:

```php
echo preg_replace('/x*/', '-', 'abc'); // -a-b-c-
```

## When str_replace() is enough

A text known in advance is found without a pattern:

```php
echo str_replace('cat', 'dog', 'The cat sat'); // The dog sat
echo str_ireplace('CAT', 'dog', 'The cat sat'); // The dog sat
echo str_replace('a.c', 'X', 'a.c axc'); // X axc
echo preg_replace('/a.c/', 'X', 'a.c axc'); // X X
```

When the text to find is known and fixed, `str_replace()` is the tool: no delimiters, no metacharacter to escape, no reference in the replacement. `str_ireplace()` is its case-insensitive twin. A pattern earns its place when the text varies: `a.c` is a literal text to `str_replace()`, and "an `a`, any character, a `c`" to the engine.

The two also differ on a run: `str_replace()` replaces each space, and a quantifier takes the run at once.

```php
echo str_replace(' ', '_', 'a  b'); // a__b
echo preg_replace('/ +/', '_', 'a  b'); // a_b
```

## Refer to the captured groups in the replacement

The replacement is a string in which a reference puts back what a group captured. PHP reads it itself, match by match: the rules below are those of PHP, not of PCRE2, and they are not those of JavaScript.

- `$1`, `$2`… hold the text of group 1, group 2…
- `$0` holds the whole match.
- `\1` is `$1` written with a backslash, and `\0` is `$0`.
- `${1}` is `$1` between braces. It is the form to use when a digit follows.

```php
echo preg_replace('/(\d{4})-(\d{2})-(\d{2})/', '$3/$2/$1', '2026-11-27'); // 27/11/2026
echo preg_replace('/(\d{4})-(\d{2})-(\d{2})/', '${3}/${2}/${1}', '2026-11-27'); // 27/11/2026
echo preg_replace('/(\d{4})-(\d{2})-(\d{2})/', '\3/\2/\1', '2026-11-27'); // 27/11/2026
echo preg_replace('/\d+/', '[$0]', 'a1b22'); // a[1]b[22]
```

A group that took no part in the match gives an empty string, and so does a reference to a group the pattern does not have. Nothing warns of the second, and JavaScript keeps such a reference as written, where PHP drops it:

```php
echo preg_replace('/(a)|b/', '[$1]', 'ab'); // [a][]
echo preg_replace('/(a)/', '[$2]', 'a'); // []
```

## A digit right after a reference

A letter after `$1` belongs to the replacement. A digit does not: a reference reads one or two digits, so `$12` is group 12. When the pattern has fewer groups than that, the reference disappears, as above. Close it with braces before the digit:

```php
echo preg_replace('/(a)/', '$1abc', 'a'); // aabc
echo '[', preg_replace('/(a)/', '$12', 'a'), ']'; // []
echo '[', preg_replace('/(a)/', '\12', 'a'), ']'; // []
echo preg_replace('/(a)/', '${1}2', 'a'); // a2
```

## A literal dollar sign or backslash

Write the replacement in single quotes, and put a backslash before a `$` that should stay a dollar sign:

```php
echo preg_replace('/a/', '\$', 'a'); // $
echo preg_replace('/(a)/', '\$1', 'a'); // $1
echo preg_replace('/(a)/', '\${1}', 'a'); // ${1}
echo preg_replace('/(a)/', 'cost: \$1, group: $1', 'a'); // cost: $1, group: a
```

A `$` that opens no reference stays as written: `$x`, `${x}`, a `$` at the very end.

```php
echo preg_replace('/a/', '$x, ${x}, $', 'a'); // $x, ${x}, $
```

A backslash of its own, before a reference, needs a second one in a single-quoted string, where `\\` is one backslash:

```php
echo preg_replace('/(a)/', '\\\\$1', 'a'); // \a
```

In double quotes, PHP removes the backslash of `\$` first, and the call then reads a bare `$`: `"\$1"` is the reference `$1`. A dollar sign that must reach the call from a double-quoted string is written `"\\$1"`.

```php
echo preg_replace('/(a)/', "\$1", 'a'); // a
echo preg_replace('/(a)/', "\\$1", 'a'); // $1
```

In double quotes too, `"${1}"` never reaches the call: PHP reads `${…}` as its own interpolation, of a variable named `1` that does not exist, and from PHP 8.2 reports the syntax as deprecated. That was read on PHP 7.4.33, 8.1.34, 8.2.36, 8.3.37 and 8.4.28.

## Named groups keep their number

A replacement refers to a group by number only. `${name}` is not a reference in PHP: it stays as written, and so does the `$<name>` of the `replace()` of JavaScript.

```php
echo preg_replace('/(?<year>\d{4})-(?<month>\d{2})/', '${year}', '2026-11'); // ${year}
echo preg_replace('/(?<year>\d{4})-(?<month>\d{2})/', '${2}/${1}', '2026-11'); // 11/2026
echo preg_replace_callback('/(?<year>\d{4})-(?<month>\d{2})/', static fn (array $m): string => $m['month'].'/'.$m['year'], '2026-11'); // 11/2026
```

A named group is numbered like any other, so `(?<year>…)` is group 1 above. Use that number, or a callback, which receives the names as keys of its argument.

## Replace with arrays

The patterns, the replacements and the subjects each accept an array:

- Patterns as an array and one replacement: every pattern uses it, in order.
- Patterns and replacements as arrays: they pair by position, and the keys are ignored. Where the replacements run out, the patterns left replace with an empty string.
- Subjects as an array: each goes through the patterns, and the results come back under the keys of the subjects.

```php
echo preg_replace(['/a/', '/b/'], 'X', 'ab'); // XX
echo preg_replace(['/a/', '/b/'], ['1', '2'], 'ab'); // 12
echo preg_replace(['first' => '/a/', 'second' => '/b/'], ['B', 'A'], 'ab'); // BA
echo preg_replace(['/a/', '/b/', '/c/'], ['1', '2'], 'abc'); // 12
echo json_encode(preg_replace('/\d+/', 'N', ['a1' => 'x1', 'b2' => 'y22'])); // {"a1":"xN","b2":"yN"}
```

Each pattern works on the result of the one before it, so the order matters. `$limit` applies to each pattern, and `$count` counts them all:

```php
echo preg_replace(['/\s+/', '/-/'], ['_', ' '], 'a  b-c'); // a_b c
echo preg_replace(['/-/', '/\s+/'], [' ', '_'], 'a  b-c'); // a_b_c
echo preg_replace(['/a/', '/b/'], 'X', 'aabb', 1); // XaXb
```

## Replace without regard to case, in Unicode, line by line

The modifiers of the pattern apply to a replacement as they do to a match:

- `i` makes it case-insensitive.
- `u` reads the text as UTF-8. Without it, `\w` stops before `é`.

```php
echo preg_replace('/the/', 'a', 'The cat saw the dog'); // The cat saw a dog
echo preg_replace('/the/i', 'a', 'The cat saw the dog'); // a cat saw a dog
echo preg_replace('/\w+/', 'X', 'café'); // Xé
echo preg_replace('/\w+/u', 'X', 'café'); // X
```

- `m` moves `^` and `$` to the line ends.
- `s` lets the dot match line breaks.

```php
echo preg_replace('/^\w+/', 'X', "one\ntwo"); // X\ntwo
echo preg_replace('/^\w+/m', 'X', "one\ntwo"); // X\nX
echo preg_replace('/<p>.*<\/p>/', '', "<p>a\nb</p>tail"); // <p>a\nb</p>tail
echo preg_replace('/<p>.*<\/p>/s', '', "<p>a\nb</p>tail"); // tail
```

In these comments, `\n` is a line break. Each modifier has its section in [PHP regex modifiers](php-regex-modifiers.md).

## Replace with a callback

When the replacement is computed, `preg_replace_callback()` calls a function for every match and puts what it returns in place of the match. The function receives one argument, the array `preg_match()` would have filled. `$limit` and `$count` mean what they mean in `preg_replace()`.

```php
echo preg_replace_callback('/\w+/', static fn (array $m): string => strtoupper($m[0]), 'hello world'); // HELLO WORLD
echo preg_replace_callback('/(?<first>\w+) (?<last>\w+)/', static fn (array $m): string => $m['last'].', '.$m['first'], 'Ada Lovelace'); // Lovelace, Ada
```

**Replace the nth occurrence.** A counter in the callback finds it. The callback returns the replacement for the match numbered `n`, and the match itself for the others:

```php
function replaceNth(string $pattern, string $replacement, string $subject, int $n): string
{
    $seen = 0;

    return preg_replace_callback($pattern, static function (array $m) use (&$seen, $n, $replacement): string {
        return ++$seen === $n ? $replacement : $m[0];
    }, $subject);
}

echo replaceNth('/cat/', 'dog', 'cat cat cat', 2); // cat dog cat
echo replaceNth('/cat/', 'dog', 'cat cat cat', 1); // dog cat cat
```

The first occurrence alone is a case for `$limit`, and needs no callback.

**One callback per pattern.** `preg_replace_callback_array()` takes an array of `pattern => callback`. The patterns run in the order of the array, each on the result of the previous one:

```php
$html = preg_replace_callback_array(
    [
        '/\*([^*]+)\*/' => static fn (array $m): string => '<em>'.$m[1].'</em>',
        '/_([^_]+)_/' => static fn (array $m): string => '<strong>'.$m[1].'</strong>',
    ],
    'Plain, *em*, _strong_',
);
echo $html; // Plain, <em>em</em>, <strong>strong</strong>
```

What a callback returns is matched by the patterns after it. Here the `b` of the first callback becomes a `c`:

```php
echo preg_replace_callback_array(['/a/' => static fn (array $m): string => 'b', '/b/' => static fn (array $m): string => 'c'], 'ab'); // cc
```

## Remove what a class matches

A negated class matches everything except what you keep, and replacing it with an empty string removes it:

```php
echo preg_replace('/[^A-Za-z0-9 ]/', '', 'Hello, World! #1'); // Hello World 1
echo preg_replace('/\D/', '', 'Phone: (555) 123-4567'); // 5551234567
```

The first line keeps the ASCII letters, the digits and the spaces. On text that is not ASCII, that class removes `é` and `ö` too, byte by byte. Add `u`, and name what to keep in Unicode terms:

```php
echo preg_replace('/[^A-Za-z0-9 ]/', '', 'Héllo, Wörld!'); // Hllo Wrld
echo preg_replace('/[^\p{L}\p{N} ]/u', '', 'Héllo, Wörld!'); // Héllo Wörld
```

Under `u`, a subject that is not valid UTF-8 makes the call return `null`.

**Strip HTML tags.** A regex does it on simple markup:

```php
echo preg_replace('/<[^>]*>/', '', '<p>Hello <b>world</b></p>'); // Hello world
```

It stops being right where HTML stops being simple. A `>` inside an attribute ends the tag early for the pattern, and `strip_tags()` keeps reading:

```php
echo '[', preg_replace('/<[^>]*>/', '', '<p title="a > b">Hi</p>'), ']'; // [ b">Hi]
echo '[', strip_tags('<p title="a > b">Hi</p>'), ']'; // [Hi]
```

`strip_tags()` is the right tool for HTML then, with a limit of its own: it removes the tags and keeps what they hold, the text of a `<script>` element included. An element to remove with its content is a case for a pattern:

```php
echo strip_tags('<script>alert(1)</script>Stay'); // alert(1)Stay
echo preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', '<script>alert(1)</script>Stay'); // Stay
```

## Replace line breaks

A text can bring `\r\n` from Windows, `\n` from Unix and a lone `\r` from an old Mac file. In the comments of this section, `\n` is a line break. A `\r` is a carriage return that stayed: the outer call of each line shows it, by replacing it with the two characters `\r`.

```php
$text = "one\r\ntwo\nthree\rfour";
echo preg_replace('/\r/', '\r', preg_replace('/\r?\n/', "\n", $text)); // one\ntwo\nthree\rfour
echo preg_replace('/\r/', '\r', preg_replace('/\R/', "\n", $text)); // one\ntwo\nthree\nfour
```

- `\r?\n` matches LF and CRLF, and leaves a lone `\r` in place.
- `\R` matches every line break: LF, CRLF, a lone `\r`, the vertical tab, the form feed, and under `u` also the NEL (U+0085) and the Unicode separators U+2028 and U+2029.
- `\R` cannot stand inside a class. There, write `[\r\n]`.

The same `\R` answers the other questions about line breaks:

```php
// Join the lines with a space
echo preg_replace('/\R/', ' ', "a\r\nb\nc"); // a b c
// Remove the line breaks at the end
echo '[', preg_replace('/\R+\z/', '', "text\n\n"), ']'; // [text]
// Remove the empty lines
echo preg_replace('/^[ \t]*\R/m', '', "a\n\n  \nb"); // a\nb
// Remove the spaces at the end of each line
echo preg_replace('/[ \t]+\R/', "\n", "a  \nb \nc"); // a\nb\nc
// Split the text into lines
echo json_encode(preg_split('/\R/', "one\r\ntwo\nthree")); // ["one","two","three"]
```

## When the call returns null

`preg_replace()` gives up three ways and returns `null` each time. Compare the result with `=== null`: an empty string is a result, not a failure.

**The pattern does not compile.** PHP prints a warning and returns `null`. An unknown modifier, a closing delimiter missing, a class left open: `preg_last_error()` is then `PREG_INTERNAL_ERROR`.

```php
// preg_replace(): Unknown modifier 'g'
var_dump(preg_replace('/cat/g', 'dog', 'cat')); // NULL
// preg_replace(): Compilation failed: missing terminating ] for character class at offset 1
var_dump(preg_replace('/[/', 'x', 'cat')); // NULL
echo preg_last_error_msg(); // Internal error
```

**The subject is not valid UTF-8 under `u`.** No warning is printed, and `preg_last_error()` is `PREG_BAD_UTF8_ERROR`.

```php
var_dump(preg_replace('/./u', 'x', "touch\xC3")); // NULL
echo preg_last_error_msg(); // Malformed UTF-8 characters, possibly incorrectly encoded
```

**A limit of the engine is reached.** No warning either, and `preg_last_error()` names the limit:

```php
ini_set('pcre.backtrack_limit', '100');
var_dump(preg_replace('/(a+)+$/', 'x', str_repeat('a', 40).'!')); // NULL
echo preg_last_error_msg(); // Backtrack limit exhausted
```

So check the result before using it:

```php
$result = preg_replace('/./u', 'x', "touch\xC3");
if ($result === null) {
    echo 'The call failed: ', preg_last_error_msg(); // The call failed: Malformed UTF-8 characters, possibly incorrectly encoded
}
```

With an array of subjects, an entry that fails is left out of the result, and nothing else says so:

```php
echo json_encode(preg_replace('/./u', 'x', ["touch\xC3", 'fine'])); // {"1":"xxxx"}
```

Each message has its page: [Unknown modifier](../reference/diagnostics/unknown-modifier.md), [No ending delimiter found](../reference/diagnostics/no-ending-delimiter.md), [Malformed UTF-8 characters](../reference/diagnostics/malformed-utf-8-characters.md), [Backtrack limit exhausted](../reference/diagnostics/backtrack-limit-exhausted.md).

## When the pattern contains the delimiter

A delimiter inside the pattern closes it early, and PHP reads what follows as modifiers:

```php
// preg_replace(): Unknown modifier 'd'
var_dump(preg_replace('/<a href="/docs/">x</a>/', '', '<a href="/docs/">x</a>')); // NULL
```

Three ways out:

- Escape each delimiter with a backslash, `\/`.
- Pick a delimiter the pattern does not hold, as `#` or `~`.
- When the text to find comes from a variable, let `preg_quote()` escape the metacharacters and the delimiter together.

```php
echo preg_replace('/<a href="\/docs\/index\.html">/', 'LINK', '<a href="/docs/index.html">Docs</a>'); // LINKDocs</a>
echo preg_replace('#<a href="/docs/index\.html">#', 'LINK', '<a href="/docs/index.html">Docs</a>'); // LINKDocs</a>
echo preg_replace('~<a href="/docs/index\.html">~', 'LINK', '<a href="/docs/index.html">Docs</a>'); // LINKDocs</a>
$url = '/docs/index.html?v=1&page=2';
echo preg_replace('/'.preg_quote($url, '/').'/', 'LINK', 'See /docs/index.html?v=1&page=2 for more'); // See LINK for more
```

## The e modifier is gone

In PHP 5, `/e` made `preg_replace()` run the replacement as PHP code. PHP 7.0 removed it: the call returns `null` and warns. The warning is `Unknown modifier 'e'` on PHP 8.4, and `The /e modifier is no longer supported, use preg_replace_callback instead` from PHP 7.0 to PHP 8.3. A callback does the same work, on a value the code can look at:

```php
// preg_replace(): Unknown modifier 'e'
var_dump(preg_replace('/\w+/e', 'strtoupper("$1")', 'hello')); // NULL
echo preg_replace_callback('/\w+/', static fn (array $m): string => strtoupper($m[0]), 'hello'); // HELLO
```

The releases were read in `ext/pcre/php_pcre.c` at the tags `php-5.6.40`, `php-7.0.0`, `php-8.3.0` and `php-8.4.28` of php-src, and the warning on PHP 7.0.33, 7.3.33, 8.3.37 and 8.4.28.

## When a regex is the wrong tool

When the text to find never varies, `str_replace()` does the job without a pattern; when the text is HTML, `strip_tags()` reads it better than a class does. And when the pattern itself is the problem, the [first chapter of the tutorial](../tutorial/01-basics.md) starts at what goes between the delimiters, and the [cookbook](../cookbook.md) gives ready patterns for emails, URLs, dates and other common values.

## Related

- [PHP regex modifiers](php-regex-modifiers.md): each modifier with and without it, the inline options, and why there is no `g`.
- [Regex in PHP](regex-in-php.md): the `preg_*` functions, delimiters and modifiers from the start.
- [Real-world PHP regex patterns](../tutorial/10-real-world-php.md): replacing text among everyday tasks.
- [Unknown modifier](../reference/diagnostics/unknown-modifier.md)
- [No ending delimiter found](../reference/diagnostics/no-ending-delimiter.md)
- [Malformed UTF-8 characters](../reference/diagnostics/malformed-utf-8-characters.md)
- [Backtrack limit exhausted](../reference/diagnostics/backtrack-limit-exhausted.md)

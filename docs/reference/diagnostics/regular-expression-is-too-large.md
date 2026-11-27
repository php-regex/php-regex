---
description: "PCRE2 compiles a pattern to 64 KiB at most: a generated alternation, two thousand classes side by side. Build the pattern shorter, or match in several passes."
head_title: "\"regular expression is too large\" in PHP"
parent: /reference/diagnostics/
engine_family: "Compilation failed"
engine_order: 20
engine:
  message: "Compilation failed: regular expression is too large at offset 0"
  pattern: "/(?:a){8192}/"
codes:
  - regex.pattern.too_large
---
# Regular expression is too large

The pattern compiles to more than PCRE2 can hold, and the compilation stops before any match.

```php
preg_match('/(?:a){8192}/', $value);
// preg_match(): Compilation failed: regular expression is too large at offset 0
```

The limit is on the compiled pattern, not on the text: this one is 13 characters long. PCRE2 keeps a group repeated by a count as one copy per repetition, 8192 here. The limit is 64 KiB, 65536 bytes of compiled pattern.

## Why PHP prints it

PCRE2 turns the pattern into a program before it matches with it, and that program has a size limit of 64 KiB. A long pattern is not always a big one, and a short one can be huge.

**A group with a count.** Each repetition is a copy of the group:

```php
preg_match('/(?:\d{1,3}\.){5041}/', $value);
// preg_match(): Compilation failed: regular expression is too large at offset 0
```

`(?:\d{1,3}\.){5040}` compiles. The count on one item costs nothing: `/a{65535}/` compiles.

**A generated alternation.** 3566 words in one group reach the limit, for words of the form `word0`, `word1`:

```php
$words = array_map(static fn (int $i): string => 'word' . $i, range(0, 4999));
$pattern = '/\b(?:' . implode('|', $words) . ')\b/';
preg_match($pattern, $text);
// preg_match(): Compilation failed: regular expression is too large at offset 0
```

**A long text.** Each character of a literal takes its share:

```php
$pattern = '/' . str_repeat('a', 32765) . '/';
preg_match($pattern, $value);
// preg_match(): Compilation failed: regular expression is too large at offset 32765
```

32764 characters compile. So do 1985 classes side by side, and the 1986th is refused whether it is `[abc]`, `[a-z]` or a class of 26 members. The offset moves with the shape of the pattern: the limit is on the whole of it.

## How to fix it

```php
// One item with a count is kept once: drop the group around it
preg_match('/a{8192}/', str_repeat('a', 8192)); // 1

// A list of words: one pattern per chunk
$found = null;
foreach (array_chunk($words, 1000) as $chunk) {
    if (preg_match('/\b(?:' . implode('|', $chunk) . ')\b/', $text, $match)) {
        $found = $match[0]; // "word4998" in "the word4998 is here"
        break;
    }
}
```

Several patterns return the match of the first chunk that has one, not the leftmost match of the whole list. For the words `zebra` and `apple` in `apple zebra`, one pattern matches `apple`, and one pattern per word matches `zebra`.

## Where it hides

- **A count computed by the code.** `'{' . $count . '}'` after a group copies it `$count` times.
- **A keyword filter built from a list.** Thousands of words joined by `|` in one group.
- **A group that wraps one item.** `/(?:a){8192}/` is refused, where `/a{8192}/` compiles.

## What PHPRegex reports

- `regex.pattern.too_large` for `/(?:a){8192}/`: "Regular expression is too large: PCRE would compile it to more than 64 KiB." The hint follows: "A group repeated with a count is compiled once per repetition: lower the count, or repeat a single item."

[Open `/(?:a){8192}/` in the playground](/#pattern=%2F%28%3F%3Aa%29%7B8192%7D%2F&subject=a): the Diagnostics tab shows the code, its message, and what PHP says.

## Related

- [Parentheses are too deeply nested](parentheses-are-too-deeply-nested.md): the other limit a generated pattern meets.
- [Number too big in {} quantifier](number-too-big-in-quantifier.md): the ceiling of a count itself.
- [Redundant Non-Capturing Group](../rules/redundant-non-capturing-group.md): the linter also reports a group around one atom.
- [Regex in PHP](../../guides/regex-in-php.md): the limits of PCRE in PHP.

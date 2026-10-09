---
description: "When a string function can answer before preg_match() runs: the literals every match holds, and the patterns the automata prove equal to str_contains() and friends."
---

# Prefilters

A regex engine is fast, and a `str_contains()` is faster. Two analyses tell when a cheap
string function can answer before, or instead of, `preg_match()`.

The two live in separate packages: `RequiredLiteralAnalyzer` in `php-regex/regex-parser`,
`TrivialMatchClassifier` in `php-regex/regex-automata` — which requires `regex-parser`
itself, so `composer require php-regex/regex-automata` (PHP 8.2+, `ext-mbstring`) brings
both.

## Required literals

`RequiredLiteralAnalyzer` lists the strings every match of a pattern holds. A subject that
lacks one of them cannot match, so a `str_contains()` may turn it away before the engine
runs, as RE2's prefilter does.

```php
use PHPRegex\Parser\Analysis\RequiredLiteralAnalyzer;
use PHPRegex\Parser\RegexParser;

$analyzer = new RequiredLiteralAnalyzer();
$parser = RegexParser::create();

$analyzer->analyze($parser->parse('/foo\d+bar/'));       // ['foo', 'bar']
$analyzer->analyze($parser->parse('/foobar|foobaz/'));   // ['fooba']
$analyzer->analyze($parser->parse('/(?:abc)+x/'));       // ['abcx']
$analyzer->analyze($parser->parse('/abc/i'));            // []: case varies
```

Runs of exact text grow with what the part before them always ends with and what the part
after them always starts with: the last `abc` of `(?:abc)+` sits right before the `x`. An
alternation requires what all its branches require, and the start and end they share. No
string is listed inside another.

The list is sound, not complete: every string in it is in every match, checked against the
engine on the matches of each pattern of the lint corpus, but a pattern may require more
than it says, a letter in either case for instance.

## String functions in disguise

`TrivialMatchClassifier` tells when `preg_match()` is a string function in disguise, and
names the function only once the automata prove that `preg_match()` returns 1 for exactly
the subjects the function says true for.

```php
use PHPRegex\Automata\TrivialMatchClassifier;

$classifier = new TrivialMatchClassifier();

$classifier->classify('/^https:/')->phpExpression('$url');     // str_starts_with($url, 'https:')
$classifier->classify('/\.php\z/')->phpExpression('$file');    // str_ends_with($file, '.php')
$classifier->classify('/^(?:GET|POST)\z/')->phpExpression('$m'); // in_array($m, ['GET', 'POST'], true)
$classifier->classify('/^foo$/')->phpExpression('$s');         // in_array($s, ['foo', "foo\n"], true)
$classifier->classify('/fo+/');                                // null
```

| kind | function | pattern shape |
|---|---|---|
| `Contains` | `str_contains()` | a literal |
| `StartsWith` | `str_starts_with()` | `^` then a literal |
| `EndsWith` | `str_ends_with()` | a literal then `\z` |
| `Equals` | `===` | `^`, a literal, `\z` (or `$` under `/D`) |
| `OneOf` | `in_array(..., true)` | `^`, a few literals, `\z` or `$` |
| `IsEmpty` | `'' ===` | `^\z` |

Two traps it does not fall into. Without `/D`, `$` also matches before a newline that ends
the subject, so `/^foo$/` is not `$s === 'foo'`: it takes `"foo\n"` too. And a pattern in
UTF mode is left alone: on a subject that is not UTF-8, `preg_match()` fails where a string
function answers.

It also leaves alone, before any proof, a pattern whose answer depends on more than the
subject:

- a pattern matched without case in any form (`/i`, `(?i)`, `(?i:...)`), and one using a
  shorthand class (`\d`, `\s`, `\w`, `\h`, `\v` and their negations, inside a class too) or
  a POSIX class (`[[:alpha:]]`). Once a program calls `setlocale()`, PHP builds PCRE's
  character and case tables from `LC_CTYPE`: under `fr_FR.ISO8859-1`, `/^[[:alpha:]]\z/` and
  `/^\w\z/` match `"\xE9"`, and `/^\xe9\z/i` matches `"\xC9"`, where a string function
  answers the same in every locale;
- a pattern that opens with a verb, or holds one: `(*LIMIT_MATCH=1)foo` makes `preg_match()`
  return `false` without the JIT, where `str_contains()` answers. The newline and `\R`
  conventions, `(*UCP)` and `(*UTF)` are refused alike;
- a pattern under `/A`, which is tried at offset 0 only;
- in extended mode (`x`, `xx`, `(?x)`), a pattern holding a raw byte above 0x7F: PCRE skips
  the pattern bytes its tables call white space, and under `nl_NL.UTF-8` on macOS a raw
  0xA0 is one, so `"/prix\xA0eur/x"` matches `prixeur`. The escape `\xa0` is a byte the
  locale does not touch.

It also counts the paths through the pattern, not the strings: a pattern that reaches one
string along two paths is left alone, as the engine tries both. `(?:a|a)` reaches `a` twice;
twenty of them reach a string 2^20 ways, and on forty `a` and a `c`,
`preg_match('/(?:a|a){4}(?:a|a){4}(?:a|a){4}(?:a|a){4}(?:a|a){4}b/', $s)` returns `false`
("Backtrack limit exhausted") where `str_contains()` answers. `/^a?a?\z/` reaches `a` twice
too. At most 16 paths are read.

```php
$classifier->classify('/^\s\z/');              // null
$classifier->classify('/^foo\z/i');             // null
$classifier->classify('/(*LIMIT_MATCH=1)foo/'); // null
$classifier->classify('/(?:a|a)/');             // null
```

With optimizations on, the PHPStan rule reports such a `preg_match($pattern, $subject)` under
`regex.trivialMatch`; a call that fills `$matches`, or passes flags, is left alone.

`matchedLiteral()` answers for `preg_replace()` and `preg_split()`: the one non-empty string
the pattern's full-match language holds, or `null`. Both functions scan left to right and
take non-overlapping matches, as `str_replace()` and `explode()` do, so with that string they
answer alike. It refuses what `classify()` refuses, reads only literals, groups,
one-member classes and fixed repetitions (an alternation is two paths, so two strings or
one string the engine backtracks through twice), and then has the automata prove the
language in full-match mode: `/ab?/` finds what `/a/` finds, yet
`preg_replace('/ab?/', 'X', 'ab')` is `X` where `str_replace('a', 'X', 'ab')` is `Xb`.

```php
$classifier->matchedLiteral('/a\.b/'); // 'a.b'
$classifier->matchedLiteral('/x{3}/'); // 'xxx'
$classifier->matchedLiteral('/ab?/');  // null
$classifier->matchedLiteral('/^foo/'); // null: an anchor
$classifier->matchedLiteral('/(?:a|a){20}b/'); // null: one string, 2^20 paths
```

The Rector rules of `php-regex/regex-rector` rewrite the code with both methods: see
[the Rector guide](../guides/rector.md).

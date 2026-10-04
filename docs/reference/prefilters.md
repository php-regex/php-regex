# Prefilters

A regex engine is fast, and a `str_contains()` is faster. Two analyses tell when a cheap
string function can answer before, or instead of, `preg_match()`.

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

With optimizations on, the PHPStan rule reports such a `preg_match($pattern, $subject)` under
`regex.trivialMatch`; a call that fills `$matches`, or passes flags, is left alone.

---

Previous: [Reference Index](README.md) | Next: [API Reference](api.md)

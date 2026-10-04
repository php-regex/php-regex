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

---

Previous: [Reference Index](README.md) | Next: [API Reference](api.md)

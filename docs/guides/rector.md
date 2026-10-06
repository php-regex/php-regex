# Rector

`php-regex/regex-rector` adds Rector rules that rewrite a `preg_*` call into
the string function that does the same thing: `preg_match('/^https:/', $url)`
becomes `\str_starts_with($url, 'https:')`, `preg_split('/;/', $csv)` becomes
`\explode(';', $csv)`. A rewrite happens only when the automata prove, while
Rector runs, that the two answer alike on every subject. When they cannot,
the code is left as it is.

## Installation

```bash
composer require --dev php-regex/regex-rector
```

The package runs on PHP 8.2 or later. A project that targets an older PHP can
still use it: install Rector and the rules in a directory of their own, run
them with PHP 8.2 or later, and let Rector read the target from your
`composer.json` (`require.php`) or from `withPhpVersion()`.

```bash
mkdir -p tools/rector
composer require --working-dir=tools/rector --dev rector/rector php-regex/regex-rector
tools/rector/vendor/bin/rector process
```

## Configuration

The set registers the three rules:

```php
use PHPRegex\Rector\Set\RegexSetList;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src'])
    ->withSets([RegexSetList::STRING_FUNCTIONS]);
```

One rule alone is registered with `withRules()`:

```php
use PHPRegex\Rector\PregSplitToExplodeRector;
use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([__DIR__.'/src'])
    ->withRules([PregSplitToExplodeRector::class]);
```

The rules take no configuration. After upgrading the package, run Rector once
with `--clear-cache`: Rector skips the files it found nothing to change in, and
a new release may prove patterns the previous one could not.

## What the rules rewrite

Rector produced every "after" below from the "before" above it.

```php
function check(string $url, string $path, string $method, string $status, string $text, string $csv): void
{
    if (preg_match('/^https:/', $url)) {
        echo 'secure';
    }

    $isJson = preg_match('/\.json\z/', $path) === 1;
    $isSafe = !preg_match('/^(?:GET|HEAD)\z/', $method);
    $isFinal = preg_match('/^draft\z/', $status) === 0;
    $isEmpty = (bool) preg_match('/^\z/', $text);
    $line = preg_match('/^end$/', $text) ? 'last' : 'more';

    $unix = preg_replace('/\r\n/', "\n", $text);
    $dots = preg_replace('/\.{3}/', '…', $text);

    $cells = preg_split('/;/', $csv);
    $parts = preg_split('/\|/', $csv, -1);
}
```

```php
function check(string $url, string $path, string $method, string $status, string $text, string $csv): void
{
    if (\str_starts_with($url, 'https:')) {
        echo 'secure';
    }

    $isJson = \str_ends_with($path, '.json');
    $isSafe = !\in_array($method, ['GET', 'HEAD'], true);
    $isFinal = 'draft' !== $status;
    $isEmpty = '' === $text;
    $line = \in_array($text, ['end', "end\n"], true) ? 'last' : 'more';

    $unix = \str_replace("\r\n", "\n", $text);
    $dots = \str_replace('...', '…', $text);

    $cells = \explode(';', $csv);
    $parts = \explode('|', $csv);
}
```

The functions are called fully qualified, `\str_contains()`: in a namespace
that declares or imports a function of the same name, as `use function
Other\explode;` does, an unqualified call would reach that one.

| rule | from | to |
|---|---|---|
| `PregMatchToStringComparisonRector` | `preg_match($pattern, $subject)` read as a boolean | `str_contains()`, `str_starts_with()`, `str_ends_with()`, `===`, `in_array(…, true)` |
| `PregReplaceToStrReplaceRector` | `preg_replace($pattern, $replacement, $subject)` | `str_replace()` |
| `PregSplitToExplodeRector` | `preg_split($pattern, $subject)`, limit `-1` or `0` | `explode()` |

## What is proven

For `preg_match()`, `TrivialMatchClassifier::classify()` names the function
only once the automata prove that `preg_match()` returns 1 for exactly the
subjects the function says true for. `/^end$/` is no `===`: `$` also matches
before a final newline, so the rewrite keeps `"end\n"`.

For `preg_replace()` and `preg_split()`, `TrivialMatchClassifier::matchedLiteral()`
proves that the pattern matches exactly one non-empty string. Then both
functions scan the subject from left to right and take the occurrences that do
not overlap, as `str_replace()` and `explode()` do. One string is the
condition, not "contains this string": `/ab?/` finds what `/a/` finds, yet
`preg_replace('/ab?/', 'X', 'ab')` is `X` where `str_replace('a', 'X', 'ab')`
is `Xb`. See [Prefilters](../reference/prefilters.md) for both methods.

The pattern must be one constant string, written as a literal or a constant,
and compile on the PHP running Rector and on the PHP your code targets. The
proof is made twice: with the PCRE2 the running PHP links, and with the one
the target PHP bundles (10.40 up to PHP 8.2, 10.42 for 8.3, 10.44 for 8.4 and
8.5); the call is rewritten only when both name the same function and
strings.

## Where a `preg_match()` is rewritten

`preg_match()` returns `1`, `0` or `false`; a string function returns `true`
or `false`. The call is rewritten only where its value is read as true or
false:

- the condition of `if`, `elseif`, `while`, `do … while`, a full ternary, and
  the last expression of a `for` condition;
- an operand of `!`, `&&`, `||`, `and`, `or`, `xor`;
- a `(bool)` cast;
- a strict comparison with `1` or `0`, on either side: `=== 1` and `!== 0`
  keep the meaning, `=== 0` and `!== 1` negate it; and `> 0`.

Anywhere else it is left alone: an assignment, a `return`, an argument, `?:`,
`??`, `match`, `== 1`, `=== true`, `=== false`, `=== null`.

## What is left alone

Whatever the rule:

- named or unpacked arguments, a first-class callable, a call directly under
  `@` (`@preg_match(…)`): `preg_*()` reports through the warning the `@`
  hides, and the rewrite would hide another function's. A call nested deeper
  in an `@` expression, as in `@($ok && preg_match(…))` or
  `@trim(preg_replace(…))`, is rewritten: the function that replaces it
  raises nothing the `@` would have hidden;
- a pattern that is not one constant string, or that the running PHP refuses;
- `/u`: on a subject that is not valid UTF-8, `preg_*()` fails where the
  string function answers;
- `/i` in any form, `\d`, `\s`, `\w`, `\h`, `\v` and their negations, POSIX
  classes: once a program calls `setlocale()`, PHP builds PCRE's character
  tables from the locale;
- `/A`, and any verb such as `(*LIMIT_MATCH=1)` or `(*CRLF)`;
- a pattern that reaches one string along two paths, as `(?:a|a)`: the engine
  tries both, and repeated they multiply: `preg_replace('/(?:a|a){20}b/', 'X',
  $s)` returns `null` ("Backtrack limit exhausted") on forty `a` and a `c`,
  where `str_replace()` answers. `/^a?a?\z/` reaches `a` twice and is left
  alone too;
- with `x` or `xx` on, as a modifier or as `(?x)`, a raw byte above 0x7F
  anywhere in the pattern: in extended mode PCRE skips the bytes the locale's
  tables call white space, and under `nl_NL.UTF-8` on macOS a raw 0xA0 is one,
  so `/prix\xA0eur/x` written with the raw byte matches `prixeur`. The escape
  `\xa0` is fine, and so is the raw byte without `x`;
- a literal that would hold DEL (0x7F): the printer writes it as an invisible
  raw byte;
- a subject that may be something else than a string: the string functions
  throw a `TypeError` under `strict_types` where `preg_*()` converts. `===` and
  `in_array()` compare without converting, so for them the subject's declared
  type must be `string`; a `@param string` is not enough;
- a pattern the PHP your code targets refuses: `n` before 8.2, `r` before
  8.4, a raw NUL byte before 8.2 (the escape `\x00` is fine);
- a pattern the target PHP reads otherwise: with the PCRE2 of PHP 8.3 and
  older, `/a{,0}b/` is the text `a{,0}b`, from 10.43 it is `b`.

`preg_match()` with `$matches`, flags or an offset is left alone, and the rule
does nothing for code that targets PHP below 8.0, which has no
`str_contains()`. Where the rewrite is a `===` or a `!==`, the subject must be
a variable, a property, an array element, a call or a constant: out of the
call's parentheses, `$a ?: $b`, `$a ?? $b`, an assignment or a concatenation
would bind to the comparison, so such a call is left alone.

`preg_replace()` is left alone with arrays, a limit or a count, and when the
replacement is not one constant string or holds a `$` or a `\`, where
`preg_replace()` reads group references (`$1`, `${1}`, `\1`). An anchor, `\b`,
a lookaround, `\K` or a backreference in the pattern is refused before any
proof.

`preg_split()` is left alone with flags, or a limit other than a literal `-1`
or `0`; a `null` limit, which `preg_split()` refuses under `strict_types`,
included.

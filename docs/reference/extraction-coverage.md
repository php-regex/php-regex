---
description: "Which calls each PHPRegex tool reads a regex pattern from: regex lint with and without nikic/php-parser, the language server, the PHPStan and Psalm extensions, and the Laravel and Symfony regex:lint commands."
---

# Extraction Coverage

A tool can only judge the patterns it finds. This page lists, call by call,
which of them reads the pattern of a PHP call: `regex lint`, with
nikic/php-parser installed or with PHP's tokenizer alone, the language server,
the PHPStan extension, the Psalm plugin, and `regex:lint` of the Laravel
package and of the Symfony bundle.

`regex lint` reads the same patterns with either strategy: the test suite runs
every extraction case through both. The Laravel and Symfony commands run that
same extractor on PHP files, configured by their own `extraction` keys rather
than by `regex.json` (see the [Laravel](../guides/laravel.md#configuration)
and [Symfony](../guides/symfony.md#configuration) guides), and add what only
their framework holds: route constraints and validation rules, route
requirements and validator constraints. The language server takes its
configuration from `regex.json`.

Legend:
- **Yes**: the pattern is read.
- **Types**: the pattern is read when the tool's type inference knows the
  argument as a constant string, from any file. PHPStan reads each of the
  constant strings an argument may be; Psalm reads an argument whose type is
  one literal string, so not a variable that may hold one of two.
- **No**: the pattern is not read.

## Native calls

| Call | CLI, php-parser | CLI, tokenizer | LSP | PHPStan | Psalm | Laravel | Symfony |
|---|---|---|---|---|---|---|---|
| `preg_match()` and the seven other `preg_*` functions | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| Namespaced drop-ins, `Safe\preg_match()` | Yes | Yes | Yes | Yes | No | Yes | Yes |
| `new RegexIterator()`, `new RecursiveRegexIterator()` | Yes | Yes | Yes | No | No | Yes | Yes |
| `filter_var()`, `filter_input()` with `FILTER_VALIDATE_REGEXP` | Yes | Yes | Yes | No | No | Yes | Yes |
| `mb_ereg()`, `mb_split()` (Oniguruma, not PCRE) | No | No | No | No | No | No | No |

## Library calls

Every preset is read by default; `extraction.interop` (`interop` for PHPStan)
narrows them. Psalm reads no preset.

| Call | CLI, php-parser | CLI, tokenizer | LSP | PHPStan | Psalm | Laravel | Symfony |
|---|---|---|---|---|---|---|---|
| Static methods of `composer-pcre`, `nette-utils`, `spatie-regex`, `illuminate-support` | Yes | Yes | Yes | Yes | No | Yes | Yes |
| Nette's `utf8: true` adds `u` to the pattern judged | Yes | Yes | Yes | No | No | Yes | Yes |
| `webmozart-assert`, `beberlei-assert`: `Assert::regex()`, `Assertion::regex()` | Yes | Yes | Yes | Yes | No | Yes | Yes |
| `phpunit` on `$this->`, `self::`, `static::`, `parent::` | Yes | Yes | Yes | No | No | Yes | Yes |
| `phpunit` on `PHPUnit\Framework\Assert::` or `TestCase::` | Yes | Yes | Yes | Yes | No | Yes | Yes |
| `phpunit` functions, `PHPUnit\Framework\assertMatchesRegularExpression()` | Yes | Yes | Yes | No | No | Yes | Yes |
| `symfony-validator`: `new Regex(...)`, `#[Assert\Regex(...)]` | Yes | Yes | Yes | No | No | Yes | Yes¹ |
| A method called on an object of a library (`$str->match()`, `u($s)->match()`, `Assert::that($v)->regex()`) | No | No | No | No | No | No | No |

¹ The Symfony bundle also reads every `Regex` constraint from the validator's
metadata, YAML and XML mappings included; a constraint both sources read is
reported once.

## Your own helpers

| Call | CLI, php-parser | CLI, tokenizer | LSP | PHPStan | Psalm | Laravel | Symfony |
|---|---|---|---|---|---|---|---|
| A helper named in `extraction.functions` | Yes | Yes | Yes | No | No | Yes | Yes |
| A function or a static method marked `#[RegexPattern]`, called by its name | Yes | Yes | Yes | Yes | No | Yes | Yes |
| The same static method called through `self::`, `static::` or `parent::` | No | No | No | Yes | No | No | No |
| An instance method marked `#[RegexPattern]` (`$this->m()`, `$obj->m()`) | No | No | No | Yes | No | No | No |
| `#[RegexPattern]` declared in `vendor/` | Yes | Yes | Yes | Yes | No | Yes | Yes |

## How the pattern is written

| Argument | CLI, php-parser | CLI, tokenizer | LSP | PHPStan | Psalm | Laravel | Symfony |
|---|---|---|---|---|---|---|---|
| A string literal | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| A named argument, `pattern: '/.../'` | Yes | Yes | Yes | Yes | Yes | Yes | Yes |
| A named argument after a spread, `f(...$args, pattern: '/.../')` | Yes | Yes | Yes | No | No | Yes | Yes |
| A heredoc or a nowdoc | Yes | Yes | No² | Yes | Yes | Yes | Yes |
| A concatenation at the call, `'/'.self::PART.'/'` | Yes | Yes | No² | Types | Types | Yes | Yes |
| A constant of the same file, `self::RE`, `Patterns::RE`, `RE` | Yes | Yes | Yes | Types | Types | Yes | Yes |
| `static::RE` of a class that may be extended | No | No | No | No | No | No | No |
| A constant of another file | No | No | No | Types | Types | No | No |
| A local variable whose every assignment that may reach the call is a literal | Yes | Yes | Yes | Types | Types | Yes | Yes |
| A private or protected property of a `final` class, `$this->re`, out of a closure (protected: the class extends none) | Yes | Yes | Yes | No³ | No³ | Yes | Yes |
| A ternary or a `match` over literals | No | No | No | Types | No | No | No |
| A concatenation with `preg_quote()` of a text not known | No | No | No | No | No⁴ | No | No |

² A diagnostic needs one string literal to land in; `regex lint` reports these
patterns, the editor does not show them.

³ A property's type is its declared type, `string`, whatever its default.

⁴ Psalm types `$matches` from the rest of such a pattern, but judges no
pattern it cannot read whole.

The rules `regex lint` follows to read a name, and where it reports a
pattern read that way, are in [the CLI guide](../guides/cli.md#patterns-held-in-a-constant-a-variable-or-a-property).
PHPStan and Psalm read what their own analysis proves, which reaches further
in some cases and less far in others; they report a pattern at the call.

A later release may read more of these cases: see
[Backward compatibility](backward-compatibility.md#what-a-minor-release-may-change).

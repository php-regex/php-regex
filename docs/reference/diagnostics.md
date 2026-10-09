# Diagnostics and Error Messages

This comprehensive guide explains how PHPRegex reports errors and warnings, how to read diagnostic output, and how to map diagnostics to fixes.

## Table of Contents

| Section                                             | Description           |
|-----------------------------------------------------|-----------------------|
| [Validation Layers](#validation-layers)             | How validation works  |
| [Reading Diagnostics](#reading-diagnostics)         | Understanding output  |
| [ValidationResult Fields](#validationresult-fields) | Result object details |
| [CLI Examples](#cli-examples)                       | Command-line output   |
| [Lint Diagnostics](#lint-diagnostics)               | Linting output        |
| [Common Fixes](#common-fixes)                       | Quick solutions       |
| [Error Codes](#error-codes)                         | Every stable code     |

---

## Validation Layers

PHPRegex validates patterns through four layers, each catching different types of issues:

```
Pattern literal
  -> Parse (Lexer + Parser): syntax errors, malformed patterns
  -> Semantic validation: PCRE rules, group and reference checks
  -> Runtime validation (optional): preg_match compilation
  -> Linting & analysis: ReDoS, performance, best practices
```

Examples by layer:

- Parse: unbalanced brackets `[a-z`, invalid escapes `\x`, missing delimiters `foo`
- Semantic: unbounded lookbehind `(?<=a+)`, backreference to non-existent group `\2`, duplicate group names
- Runtime: engine-specific compilation errors
- Linting & analysis: nested quantifiers `(a+)+`, useless flags `/\d+/i`, redundant groups `(?:foo)`

---

## Reading Diagnostics

### ValidationResult Fields

When you call `Regex::validate()`, you get a `ValidationResult` object:

```php
use PHPRegex\Toolkit\Regex;

$result = Regex::create()->validate('/[unclosed/');

if (!$result->isValid) {
    // Access all diagnostic information
    echo $result->isValid;          // false
    echo $result->error;            // "Unclosed character class "]" at end of input."
    echo $result->errorCode->value; // "regex.charclass.unclosed"
    echo $result->offset;           // 9
    echo $result->caretSnippet;     // See below
    echo $result->hint;             // null: no hint for this one
    echo $result->complexityScore;  // 1
    echo $result->category->value;  // "syntax"
}
```

**Field Reference Table:**

| Field             | Type                    | Description               | Example                          |
|-------------------|-------------------------|---------------------------|----------------------------------|
| `isValid`         | bool                    | Whether pattern passed    | `false`                          |
| `error`           | string\|null            | Human-readable message    | `"Unclosed character class..."`  |
| `errorCode`       | ErrorCode\|null         | Stable code for handling  | `ErrorCode::CharclassUnclosed`   |
| `offset`          | int\|null               | Byte offset from the body | `9`                              |
| `caretSnippet`    | string\|null            | Visual snippet with caret | See below                        |
| `hint`            | string\|null            | Suggested fix             | `"Use \g<0> for recursion..."`   |
| `complexityScore` | int                     | Pattern complexity        | `1`                              |
| `category`        | ValidationErrorCategory | Error type                | `syntax`                         |

### Understanding Caret Snippets

The `caretSnippet` shows exactly where the error occurred:

```
Pattern: [unclosed
          ^
```

This visual representation helps you quickly locate and fix issues.

---

## CLI Examples

### Validation Error with Caret

```bash
vendor/bin/regex --no-ansi validate '/(?<=a+)b/'
```

**Output** (after the version and runtime header):
```
  [1/1] Validating pattern
  Pattern
      → /(?<=a+)b/
  Status : INVALID
  Lookbehind is unbounded. PCRE requires a bounded maximum length.
Line 1: (?<=a+)b
        ^
```

**What to notice:**
1. `Status : INVALID` indicates failure
2. The message explains the problem
3. The caret (`^`) shows exact position

---

### Successful Validation

```bash
vendor/bin/regex --no-ansi validate '/^[a-z]+$/'
```

**Output** (after the version and runtime header):
```
  [1/1] Validating pattern
  Pattern
      → /^[a-z]+$/
  Status : OK
```

---

### ReDoS Analysis Summary

```bash
vendor/bin/regex --no-ansi analyze '/(a+)+$/'
```

**Output:**
```
  [1/4] Parsing pattern
  Pattern
      → /(a+)+$/
  Parse : OK

  [2/4] Validation
  Status : OK

  [3/4] ReDoS analysis
  Status     : Exponential backtracking (proven)
  Severity   : CRITICAL (score 10)
  Mode       : THEORETICAL
  Confidence : MEDIUM
  Attack: "a" x n . "!"
  Hotspot:   1-3

  [4/4] Explanation
Regex matches
  Start Quantified Group (one or more times)
    Capturing group
            'a' (one or more times)
    End group
  End Quantified Group
  Anchor: the end of a line
```

**What to notice:**
- `Parse` and `Validation` show structural validity
- The ReDoS `Status` is the verdict: `(proven)` when the backtracking model decided, `(heuristic)` when structural rules did
- `Attack` is the input that triggers it, as PHP: `str_repeat("a", $n) . "!"`
- `Explanation` translates AST to plain language

---

## CLI Output Types

| Command                 | Output                      | Use Case      |
|-------------------------|-----------------------------|---------------|
| `validate '/pattern/'`  | Validation status + errors  | Quick check   |
| `analyze '/pattern/'`   | Analysis + explanation      | Deep dive     |
| `explain '/pattern/'`   | Plain text explanation      | Documentation |
| `highlight '/pattern/'` | Colored pattern             | Display       |
| `lint src/`             | Issues across codebase      | CI/CD         |

---

## Lint Diagnostics

Linting finds issues beyond validity — performance, security, and best practices.

### CLI Lint Output

```bash
vendor/bin/regex lint app/ --no-ansi
```

**Output (trimmed):**
```
  [1/2] Scanning files
  Scanned 1 files, found 1 patterns.

  [2/2] Analyzing patterns
  app/Service/Validator.php:9:33
      → /^(?:[0-9]+\s?)+$/
    WARN Nested quantifiers can cause catastrophic backtracking.
         ↳ Consider atomic groups (?>...) or possessive quantifiers — verify the rewrite still matches everything you need.
    TIP
         - /^(?:[0-9]+\s?)+$/
         + /^(?:\d+\s?)+$/

  PASS 1 warnings found, 1 optimizations available.
```

Each finding opens on `file:line:column` and the pattern, then one line per issue,
its badge (`FAIL`, `WARN` or `INFO`) and its hint, and a `TIP` for a shorter
equivalent. Warnings alone leave the exit code at 0.

### JSON Output for CI

```bash
vendor/bin/regex lint app/ --format=json
```

**Output:**
```json
{
    "target": {
        "php": "8.4.26",
        "pcre": "10.49",
        "source": "running PHP",
        "range": [
            {
                "php": "8.4.26",
                "pcre": "10.49"
            }
        ]
    },
    "stats": {
        "errors": 0,
        "warnings": 1,
        "optimizations": 1,
        "redos_errors": 0,
        "infos": 0,
        "lint_errors": 0,
        "parser_fallbacks": 0
    },
    "results": [
        {
            "file": "app/Service/Validator.php",
            "line": 9,
            "column": 33,
            "file_offset": 147,
            "source": "preg_match()",
            "pattern": "/^(?:[0-9]+\\s?)+$/",
            "location": null,
            "issues": [
                {
                    "severity": "warning",
                    "file": "app/Service/Validator.php",
                    "line": 9,
                    "column": 33,
                    "file_offset": 147,
                    "position": 1,
                    "issue_id": "regex.lint.quantifier.nested",
                    "message": "Nested quantifiers can cause catastrophic backtracking.",
                    "hint": "Consider atomic groups (?>...) or possessive quantifiers ...",
                    "tip": null,
                    "source": "preg_match()",
                    "validation": null,
                    "analysis": null,
                    "target": null
                }
            ],
            "optimizations": [
                {
                    "file": "app/Service/Validator.php",
                    "line": 9,
                    "column": 33,
                    "file_offset": 147,
                    "optimization": {
                        "original": "/^(?:[0-9]+\\s?)+$/",
                        "optimized": "/^(?:\\d+\\s?)+$/",
                        "changes": [
                            "Optimized pattern."
                        ]
                    },
                    "savings": 3,
                    "source": "preg_match()"
                }
            ]
        }
    ]
}
```

**Issue Fields:** every issue carries every key, `null` when it does not apply.

| Field         | Description                                                        |
|---------------|--------------------------------------------------------------------|
| `severity`    | `error`, `warning` or `info`                                       |
| `file`        | Source file path, relative to the working directory when under it |
| `line`        | Line number, 1-based                                               |
| `column`      | Column number, 1-based, in bytes; `null` when unknown              |
| `file_offset` | Byte offset of the pattern in the file, 0-based                    |
| `position`    | Byte offset of the issue in the pattern body, 0-based              |
| `issue_id`    | Diagnostic identifier; for an invalid pattern, its error code      |
| `message`     | Human-readable explanation                                         |
| `hint`        | Suggested fix                                                      |
| `tip`         | A further suggestion for an invalid pattern                        |
| `source`      | The call the pattern was found in, as `preg_match()`               |
| `validation`  | Why an invalid pattern is invalid                                  |
| `analysis`    | The ReDoS verdict of a ReDoS issue                                 |
| `target`      | The later PHP and PCRE2 that refuse a pattern the floor accepts    |

The [JSON output reference](json-output.md) lists every key of the report,
with its type, and the rules every JSON document follows.

**Stats Fields:** the JSON report always carries every key, in this order, `0` when there
is none:

| Field           | Counts                                                                        |
|-----------------|-------------------------------------------------------------------------------|
| `errors`        | Every issue of severity `error`: invalid patterns, ReDoS errors and lint errors |
| `warnings`      | Every issue of severity `warning`                                             |
| `optimizations` | The optimization suggestions                                                  |
| `redos_errors`  | The ReDoS errors, among `errors`                                              |
| `infos`         | Every issue of severity `info`                                                |
| `lint_errors`   | The lint rules of error severity that fired, among `errors`                   |
| `parser_fallbacks` | The files the PHP parser could not read, read with the tokenizer instead   |

The console summary names each kind of error apart: `1 invalid patterns, 1 ReDoS errors,
1 lint errors, 2 warnings, 0 optimizations.` An invalid pattern is one PCRE refuses to
compile; a lint error is a pattern that compiles but that a rule of error severity reports.

### Severity in Each Format

Each lint rule declares a severity, and every output format maps it the same way:

| Rule severity           | Console | JSON `severity` | GitHub    | Checkstyle | JUnit        | LSP         |
|-------------------------|---------|-----------------|-----------|------------|--------------|-------------|
| `critical`              | `FAIL`  | `error`         | `error`   | `error`    | `error`      | Error       |
| `error`                 | `FAIL`  | `error`         | `error`   | `error`    | `failure`    | Error       |
| `warning`               | `WARN`  | `warning`       | `warning` | `warning`  | `system-out` | Warning     |
| `style`, `perf`, `info` | `INFO`  | `info`          | `notice`  | `info`     | `system-out` | Information |

An issue of severity `error` makes `regex lint` exit with 1; warnings and infos leave 0. In
JUnit, a `critical` problem is an `<error>` element and an `error` one a `<failure>`; a
warning or an info is a passing test case that carries the message in `system-out`.
See the [severity table](../reference.md#quick-reference-table) for the severity of each rule.

---

## Common Fixes

Quick solutions for frequently encountered diagnostics:

### Lookbehind Errors

**Problem:** `Lookbehind is unbounded`

**Solution:** Make the lookbehind bounded or use lookahead

```php
// ERROR: (?<=a+) is unbounded
preg_match('/(?<=a+)b/', $input);

// FIX 1: Use bounded quantifier
preg_match('/(?<=a{1,10})b/', $input);

// FIX 2: Use lookahead + capture
preg_match('/(?=(a+))b\1/', $input);
```

---

### Backreference Errors

**Problem:** `Backreference to non-existent group`

**Solution:** Ensure the referenced group exists

```php
// ERROR: \2 refers to non-existent group
preg_match('/(\w+)\2/', $input);  // Only one group

// FIX 1: Use correct group number
preg_match('/(\w+)\1/', $input);  // \1 refers to group 1

// FIX 2: Add the missing group
preg_match('/(\w+)(\w+)\2/', $input);  // Now \2 exists
```

---

### Nested Quantifiers (ReDoS)

**Problem:** `Nested quantifiers detected`

**Solution:** Use atomic groups or simplify

```php
// VULNERABLE: (a+)+ can cause ReDoS
preg_match('/(a+)+$/', $input);

// FIX 1: Use atomic group
preg_match('/(?>a+)+$/', $input);

// FIX 2: Simplify (often equivalent)
preg_match('/a+$/', $input);
```

---

### Duplicate Group Names

**Problem:** `Duplicate group name`

**Solution:** Use unique names or enable J flag

```php
// ERROR: Duplicate name 'id'
preg_match('/(?<id>\w+)(?<id>\d+)/', $input);

// FIX 1: Use unique names
preg_match('/(?<id>\w+)(?<number>\d+)/', $input);

// FIX 2: Enable J flag for duplicates
preg_match('/(?J)(?<id>\w+)(?<id>\d+)/', $input);
```

---

### Invalid Quantifier Range

**Problem:** `Invalid quantifier range: min > max`

**Solution:** Swap or fix the range

```php
// ERROR: {5,2} is invalid (min > max)
preg_match('/\d{5,2}/', $input);

// FIX: Swap to {2,5}
preg_match('/\d{2,5}/', $input);
```

---

### Character Class Pitfalls

**Problem:** Suspicious ASCII ranges or alternation-like character classes.

**Solution:** Split ranges or use alternation groups.

```php
// WARNING: Includes punctuation between Z and a
preg_match('/[A-z]/', $input);

// FIX: Split ranges
preg_match('/[A-Za-z]/', $input);

// WARNING: | is literal in []
preg_match('/[error|failure]/', $input);

// FIX: Use alternation
preg_match('/(error|failure)/', $input);
```

---

## Error Code Categories

`ValidationResult::$category` tells which layer refused the pattern:

| Category       | Meaning                                    | Examples                                 |
|----------------|--------------------------------------------|------------------------------------------|
| `syntax`       | The pattern cannot be read                 | Unclosed class, missing delimiter        |
| `semantic`     | The pattern reads, but PCRE refuses it     | Unbounded lookbehind, missing group      |
| `pcre-runtime` | PCRE itself failed on the pattern          | Compilation error the checks do not know |

---

## Error Codes

Every `RegexException` carries an `ErrorCode` (`RegexException::getErrorCode()`), and so does a
failed validation (`ValidationResult::$errorCode`). An invalid option (`InvalidRegexOptionException`)
or a cache failure (`CacheException`) is no judgement on a pattern and carries none. Lint advice has
ids of its own, `regex.lint.<area>.<rule>` in camelCase (see the lint rule reference): PHPStan reports
them as identifiers, and PHPStan identifiers take no underscore. The values are stable: match
on the enum case, or on its string value when the code crosses a process boundary (the CLI's
JSON output and the lint problems carry the string).

```php
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Toolkit\Regex;

$result = Regex::create()->validate('/(?<=a+)b/');

if (ErrorCode::LookbehindUnbounded === $result->errorCode) {
    // ...
}
```

Each value reads `regex.<area>.<problem>`. The one value without a problem segment is
`regex.complexity`, raised when an analysis runs out of its work budget.

| Code | Case | Meaning |
|------|------|---------|
| `regex.assertion.invalid` | `AssertionInvalid` | An escape written as an assertion is not one PCRE knows. |
| `regex.backref.invalid_syntax` | `BackrefInvalidSyntax` | A backreference is not written in a form PCRE reads. |
| `regex.backref.missing_group` | `BackrefMissingGroup` | A backreference points to a group number the pattern does not have. |
| `regex.backref.missing_named_group` | `BackrefMissingNamedGroup` | A backreference names a group the pattern does not have. |
| `regex.backref.relative` | `BackrefRelative` | A relative backreference points before the first group or past the last one. |
| `regex.backref.zero` | `BackrefZero` | A backreference points to group 0, which is the whole match and no group. |
| `regex.callout.invalid_delimiter` | `CalloutInvalidDelimiter` | A callout string opens with a character that is no string delimiter. |
| `regex.callout.out_of_range` | `CalloutOutOfRange` | A callout number is above 255. |
| `regex.callout.unclosed` | `CalloutUnclosed` | A callout argument is not followed by ")". |
| `regex.callout.unclosed_string` | `CalloutUnclosedString` | A callout string is not closed by its delimiter. |
| `regex.charclass.invalid_escape` | `CharclassInvalidEscape` | An escape is not allowed inside a character class. |
| `regex.charclass.unclosed` | `CharclassUnclosed` | A character class is not closed by "]". |
| `regex.comment.unclosed` | `CommentUnclosed` | A "(?#" comment is not closed by ")". |
| `regex.complexity` | `Complexity` | The pattern is past the budget of the automata analysis. |
| `regex.condition.assertion_expected` | `ConditionAssertionExpected` | A conditional group needs a lookaround assertion as its condition here. |
| `regex.condition.missing_group` | `ConditionMissingGroup` | A condition names a group the pattern does not have. |
| `regex.condition.unclosed` | `ConditionUnclosed` | The condition of a conditional group is not closed by ")". |
| `regex.condition.version_operator` | `ConditionVersionOperator` | A VERSION condition compares with an operator other than "=" or ">=". |
| `regex.condition.version_syntax` | `ConditionVersionSyntax` | A VERSION condition does not name a version as major.minor. |
| `regex.conditional.invalid` | `ConditionalInvalid` | A condition is neither a group reference, a lookaround, nor DEFINE. |
| `regex.conditional.too_many_branches` | `ConditionalTooManyBranches` | A conditional group has more than two branches. |
| `regex.control_char.invalid` | `ControlCharInvalid` | "\c" is not followed by a printable ASCII character. |
| `regex.define.too_many_branches` | `DefineTooManyBranches` | A "(?(DEFINE)...)" group has more than one branch. |
| `regex.delimiter.invalid` | `DelimiterInvalid` | The pattern opens with an alphanumeric, backslash or NUL delimiter. |
| `regex.delimiter.unclosed` | `DelimiterUnclosed` | The pattern has no closing delimiter. |
| `regex.delimiter.unescaped` | `DelimiterUnescaped` | An unescaped delimiter ends the pattern early, and what follows reads as modifiers. |
| `regex.encoding.invalid_utf8` | `EncodingInvalidUtf8` | The pattern is not valid UTF-8 under the "u" modifier. |
| `regex.escape.digits_missing` | `EscapeDigitsMissing` | An escape such as "\x{}", "\o{}" or "\N{U+}" holds no digit. |
| `regex.escape.single_byte_in_utf` | `EscapeSingleByteInUtf` | "\C" matches a single byte, which UTF mode does not allow. |
| `regex.escape.trailing_backslash` | `EscapeTrailingBackslash` | A backslash ends the pattern with nothing to escape. |
| `regex.escape.unrecognized` | `EscapeUnrecognized` | A backslash is followed by a letter PCRE knows no escape for. |
| `regex.escape.unsupported` | `EscapeUnsupported` | The escape is not supported by PCRE, or not by the targeted PCRE release. |
| `regex.extended_class.bracket_without_paren` | `ExtendedClassBracketWithoutParen` | The "]" closing an extended class is not followed by ")". |
| `regex.extended_class.empty_expression` | `ExtendedClassEmptyExpression` | An extended class, or a parenthesis in it, holds no expression. |
| `regex.extended_class.missing_operand` | `ExtendedClassMissingOperand` | An operator in an extended class has no operand before or after it. |
| `regex.extended_class.missing_operator` | `ExtendedClassMissingOperator` | Two operands in an extended class have no operator between them. |
| `regex.extended_class.nested_too_deep` | `ExtendedClassNestedTooDeep` | Parentheses in an extended class are nested deeper than PCRE allows. |
| `regex.extended_class.too_complex` | `ExtendedClassTooComplex` | An extended class runs out of the operation budget. |
| `regex.extended_class.unclosed` | `ExtendedClassUnclosed` | An extended class "(?[" is not closed by "])". |
| `regex.extended_class.unclosed_paren` | `ExtendedClassUnclosedParen` | A parenthesis in an extended class is not closed by ")". |
| `regex.extended_class.unexpected_character` | `ExtendedClassUnexpectedCharacter` | An extended class holds a character that is no operand and no operator. |
| `regex.extended_class.unmatched_close` | `ExtendedClassUnmatchedClose` | A ")" in an extended class closes no open parenthesis. |
| `regex.flag.removed_e` | `FlagRemovedE` | The "e" modifier was removed in PHP 7.0. |
| `regex.flag.unknown` | `FlagUnknown` | A modifier after the closing delimiter is not one PHP knows. |
| `regex.generate.no_match` | `GenerateNoMatch` | No sample the pattern matches was found. |
| `regex.group.duplicate_name` | `GroupDuplicateName` | Two groups share a name, which needs the "J" modifier or "(?J)". |
| `regex.group.name_conflict` | `GroupNameConflict` | Groups of the same number in a branch reset have different names. |
| `regex.group.name_expected` | `GroupNameExpected` | A group name is expected where none is written. |
| `regex.group.name_invalid` | `GroupNameInvalid` | A group name holds a non-word character, or starts with a digit. |
| `regex.group.name_too_long` | `GroupNameTooLong` | A group name is longer than PCRE allows. |
| `regex.group.name_unterminated` | `GroupNameUnterminated` | A group name is not closed by its ">", "'" or "}". |
| `regex.group.nested_too_deep` | `GroupNestedTooDeep` | Groups are nested deeper than PCRE allows. |
| `regex.group.number_too_big` | `GroupNumberTooBig` | A group number is above 65535. |
| `regex.group.option_hyphen` | `GroupOptionHyphen` | An option setting has a hyphen PCRE does not take: a second one, or one after "(?^". |
| `regex.group.syntax` | `GroupSyntax` | A "(?" is followed by a character PCRE does not read there. |
| `regex.group.unclosed` | `GroupUnclosed` | A group is not closed by ")". |
| `regex.group.unmatched_close` | `GroupUnmatchedClose` | A ")" closes no open group. |
| `regex.group_list.item_expected` | `GroupListItemExpected` | A list of groups, as "(*scs:(1,<n>)" or "(?1(2))" holds, has an item that is no group number or name. |
| `regex.group_list.missing_group` | `GroupListMissingGroup` | A list of groups, as "(*scs:(1,<n>)" or "(?1(2))" holds, names a group the pattern does not have. |
| `regex.group_list.relative_zero` | `GroupListRelativeZero` | A list of groups, as "(*scs:(1,<n>)" or "(?1(2))" holds, has the relative number zero. |
| `regex.internal.pcre_failure` | `InternalPcreFailure` | PCRE failed while the library was reading the pattern. |
| `regex.internal.unexpected_state` | `InternalUnexpectedState` | The library reached a state it does not expect, which is a bug to report. |
| `regex.keep.in_lookaround` | `KeepInLookaround` | "\K" is used inside a lookaround, which the targeted PHP refuses. |
| `regex.lookbehind.too_complex` | `LookbehindTooComplex` | PCRE gave up measuring the lookbehinds: past 2,001 branches measured for all of them, a group counted again at each call after a branch reset. |
| `regex.lookbehind.too_long` | `LookbehindTooLong` | A lookbehind is longer than PCRE allows. |
| `regex.lookbehind.unbounded` | `LookbehindUnbounded` | A lookbehind can match text of unbounded length. |
| `regex.lookbehind.variable_length_not_supported` | `LookbehindVariableLengthNotSupported` | A lookbehind of variable length needs a newer PCRE than the target. |
| `regex.nesting.too_deep` | `NestingTooDeep` | The pattern nests deeper than the configured recursion limit. |
| `regex.octal.invalid_digit` | `OctalInvalidDigit` | An octal escape holds a digit that is not octal. |
| `regex.octal.missing_brace` | `OctalMissingBrace` | "\o" is not followed by "{". |
| `regex.octal.out_of_range` | `OctalOutOfRange` | An octal escape names a code point past the allowed maximum. |
| `regex.pattern.empty` | `PatternEmpty` | The pattern is empty, or only whitespace. |
| `regex.pattern.nul_byte` | `PatternNulByte` | The pattern holds a NUL byte, which PHP refuses before 8.2. |
| `regex.pattern.too_large` | `PatternTooLarge` | The pattern compiles to more than PCRE's 64 KiB. |
| `regex.pattern.too_long` | `PatternTooLong` | The pattern is longer than the configured maximum length. |
| `regex.pcre.runtime` | `PcreRuntime` | PHP refused to compile the pattern. |
| `regex.posix.collating_element` | `PosixCollatingElement` | A POSIX collating element such as "[.a.]" or "[=a=]" is not supported. |
| `regex.posix.invalid` | `PosixInvalid` | A POSIX class name is not one PCRE knows. |
| `regex.posix.outside_class` | `PosixOutsideClass` | A POSIX class is written outside a character class. |
| `regex.quantifier.invalid_range` | `QuantifierInvalidRange` | A "{min,max}" quantifier has its numbers out of order. |
| `regex.quantifier.nothing_to_repeat` | `QuantifierNothingToRepeat` | A quantifier follows nothing it can repeat. |
| `regex.quantifier.too_big` | `QuantifierTooBig` | A quantifier number is above 65535. |
| `regex.range.invalid_bounds` | `RangeInvalidBounds` | A range in a character class runs from or to something that is not a character. |
| `regex.range.invalid_end` | `RangeInvalidEnd` | A range in a character class ends on more than one character. |
| `regex.range.invalid_start` | `RangeInvalidStart` | A range in a character class starts on more than one character. |
| `regex.range.reversed` | `RangeReversed` | A range in a character class runs from a higher code point to a lower one. |
| `regex.scan_substring.missing_list` | `ScanSubstringMissingList` | A scan substring assertion has no "(" to open its group list. |
| `regex.subroutine.invalid_syntax` | `SubroutineInvalidSyntax` | A subroutine call is not written in a form PCRE reads. |
| `regex.subroutine.missing_group` | `SubroutineMissingGroup` | A subroutine call points to a group number the pattern does not have. |
| `regex.subroutine.missing_named_group` | `SubroutineMissingNamedGroup` | A subroutine call names a group the pattern does not have. |
| `regex.subroutine.recursion` | `SubroutineRecursion` | A recursion condition points to a group the pattern does not have. |
| `regex.subroutine.relative_missing` | `SubroutineRelativeMissing` | A relative subroutine call points before the first group or past the last one. |
| `regex.subroutine.relative_zero` | `SubroutineRelativeZero` | A subroutine call holds the relative number zero. |
| `regex.token.unexpected` | `TokenUnexpected` | A token stands where the pattern cannot take it. |
| `regex.transpile.unsupported` | `TranspileUnsupported` | The pattern holds a construct the target dialect cannot express. |
| `regex.unicode.invalid_digit` | `UnicodeInvalidDigit` | A braced escape holds a character that is not a hexadecimal digit. |
| `regex.unicode.out_of_range` | `UnicodeOutOfRange` | A code point is past U+10FFFF, or past 0xFF outside UTF mode. |
| `regex.unicode.property_invalid` | `UnicodePropertyInvalid` | A Unicode property is unknown, or needs a newer PCRE than the target. |
| `regex.unicode.property_malformed` | `UnicodePropertyMalformed` | A "\p" or "\P" escape is not written in a form PCRE reads. |
| `regex.unicode.surrogate` | `UnicodeSurrogate` | A code point is a surrogate, which UTF mode does not allow. |
| `regex.unicode_named.requires_utf` | `UnicodeNamedRequiresUtf` | "\N{U+hhhh}" needs the "u" modifier. |
| `regex.verb.conflicting_casings` | `VerbConflictingCasings` | "(*TURKISH_CASING)" and "(*CASELESS_RESTRICT)" are used together. |
| `regex.verb.invalid` | `VerbInvalid` | A verb, or an alphabetic assertion, is unknown or malformed. |
| `regex.verb.limit_too_large` | `VerbLimitTooLarge` | A "(*LIMIT_...)" value is larger than PCRE takes. |
| `regex.verb.mark_name_missing` | `VerbMarkNameMissing` | "(*MARK)" has no name. |
| `regex.verb.misplaced` | `VerbMisplaced` | A start-of-pattern verb is written past the start of the pattern. |
| `regex.verb.name_too_long` | `VerbNameTooLong` | The name of a verb is longer than PCRE allows. |
| `regex.verb.turkish_casing_without_utf` | `VerbTurkishCasingWithoutUtf` | "(*TURKISH_CASING)" is used without UTF mode. |
| `regex.verb.unclosed` | `VerbUnclosed` | A verb such as "(*MARK:name" is not closed by ")". |

The delimiter follows PHP: it must not be alphanumeric, a backslash or a NUL byte, and the
leading whitespace before it (space, tab, newline, carriage return, vertical tab, form feed)
is skipped, as PHP does. `"\f/a/"` is the pattern `a`; `"\0/a/"` is refused with
`regex.delimiter.invalid`: `Invalid delimiter "\x00". Delimiters must not be alphanumeric,
backslash, or NUL byte.`

---

## Quick Reference

| Error                | Fix                                  |
|----------------------|--------------------------------------|
| Lookbehind unbounded | Add bounds `{1,10}` or use lookahead |
| Bad backreference    | Check group numbers/names            |
| Nested quantifiers   | Use atomic groups or simplify        |
| Duplicate name       | Use unique names or `(?J)`           |
| Invalid range        | Swap min/max in `{min,max}`          |
| Useless flag         | Remove unused flag                   |

---

Previous: [CLI Guide](../guides/cli.md) | Next: [Lint Rule Reference](../reference.md)

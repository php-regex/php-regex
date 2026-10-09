---
permalink: /nodes/
description: "Every AST node type in PHPRegex with its fields and runnable examples — groups, quantifiers, classes, verbs — and the position rules all nodes share."
---
# AST Node Reference

This reference documents every node type in the PHPRegex AST. Nodes are the building blocks that represent parsed regex patterns. Understanding nodes is essential for building custom visitors, debugging parsing issues, or extending the library.

## How to Read This Reference

Each node includes:
- **Purpose** — What the node represents in a pattern
- **Fields** — Public properties you can access
- **Example** — A PHP code snippet showing how the node is created
- **Common Errors** — Pitfalls to avoid when working with this node type

If you are new to regex, start with the [Tutorial](../tutorial/README.md) first.

---

## Core Structure Nodes

These nodes form the backbone of every parsed pattern.

### RegexNode

**Purpose:** The root node that wraps the entire pattern, including delimiter, pattern body, and flags.


**Fields:**

| Field       | Type          | Description                                   |
|-------------|---------------|-----------------------------------------------|
| `delimiter` | string        | The delimiter character (e.g., `/`, `#`, `~`) |
| `pattern`   | NodeInterface | The parsed pattern body                       |
| `flags`     | string        | All flags combined (e.g., `imsxu`)            |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/foo/i');

echo $ast->delimiter;  // '/'
echo $ast->flags;      // 'i'
echo $ast->pattern;    // SequenceNode instance
```

**Common Errors:**
```php
// WRONG: Trying to change the pattern directly
$ast->flags = 'g';  // Flags are immutable

// RIGHT: Create a new RegexNode
$newFlags = $ast->flags . 's';
```

---

### SequenceNode

**Purpose:** An ordered list of nodes that must match in sequence from left to right.


**Fields:**
| Field | Type | Description |
|-------|------|-------------|
| `children` | array | Array of child nodes in order |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/foo/');
$sequence = $ast->pattern;

echo count($sequence->children);  // 3
echo $sequence->children[0]->value;  // 'f'
echo $sequence->children[1]->value;  // 'o'
echo $sequence->children[2]->value;  // 'o'
```

**Common Errors:**
```php
// WRONG: Modifying children array directly
$sequence->children[] = new LiteralNode('x');

// RIGHT: Create a new SequenceNode
$newChildren = array_merge($sequence->children, [new LiteralNode('x')]);
$newSequence = new SequenceNode($newChildren, $sequence->startPosition, $sequence->endPosition);
```

---

### AlternationNode

**Purpose:** Branches separated by the `|` operator. Matches if any alternative matches.


**Fields:**

| Field          | Type  | Description                                                   |
|----------------|-------|---------------------------------------------------------------|
| `alternatives` | array | The alternative nodes: a multi-item branch is a `SequenceNode`, a single atom stands bare |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/foo|bar|baz/');
$alternation = $ast->pattern;

foreach ($alternation->alternatives as $index => $alt) {
    $text = '';
    foreach ($alt->children as $child) {
        $text .= $child->value;
    }
    echo "Alternative $index: $text\n";
}
// Output:
// Alternative 0: foo
// Alternative 1: bar
// Alternative 2: baz
```

**Common Errors:**
```php
// WRONG: Assuming every alternative is a SequenceNode
// Pattern: /a|b/ holds two bare LiteralNodes, one per alternative;
// only a multi-item branch such as "foo" in /foo|bar/ is a SequenceNode.
foreach ($alternation->alternatives as $alt) {
    echo $alt->children[0]->value;  // fails on /a|b/: a LiteralNode has no children
}

// RIGHT: Compile an alternative when you need its text
$printer = new \PHPRegex\Parser\Printer\PatternPrinter();
foreach ($alternation->alternatives as $alt) {
    echo $alt->accept($printer), "\n";  // 'a', 'b' for /a|b/
}
```

---

### GroupNode

**Purpose:** Groups multiple nodes together. Can be capturing, non-capturing, lookaround, atomic, or inline flags.


**Fields:**

| Field   | Type          | Description                            |
|---------|---------------|----------------------------------------|
| `child` | NodeInterface | The grouped content                    |
| `type`  | GroupType     | The type of group                      |
| `name`  | string\|null  | Group name for named groups; for a substring scan, its spelling (`scs` or `scan_substring`) |
| `flags` | string\|null  | Inline flags (e.g., `i` in `(?i:foo)`) |
| `scannedGroups` | list\<string\> | For a substring scan, the groups it matches, as written (`1`, `-1`, `<name>`, `'name'`) |

**Group Types:**

| Case                            | Pattern         | Description                                                            |
|---------------------------------|-----------------|------------------------------------------------------------------------|
| `GroupType::Capturing`          | `(foo)`         | Captures matched text                                                  |
| `GroupType::NonCapturing`       | `(?:foo)`       | Groups without capture                                                 |
| `GroupType::Named`              | `(?<name>foo)`  | Captures with name                                                     |
| `GroupType::LookaheadPositive`  | `(?=foo)`       | Lookahead (matches position)                                           |
| `GroupType::LookaheadNegative`  | `(?!foo)`       | Negative lookahead                                                     |
| `GroupType::LookbehindPositive` | `(?<=foo)`      | Lookbehind                                                             |
| `GroupType::LookbehindNegative` | `(?<!foo)`      | Negative lookbehind                                                    |
| `GroupType::InlineFlags`        | `(?i:foo)`      | Inline flag modification                                               |
| `GroupType::Atomic`             | `(?>foo)`       | Atomic group (no backtracking)                                         |
| `GroupType::BranchReset`        | `(?\|foo\|bar)` | Same group numbers in branches                                         |
| `GroupType::ScanSubstring`      | `(*scs:(1)foo)` | Matches its body against what the listed groups captured (PCRE2 10.45) |

**Example:**
```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\Node\GroupType;

$ast = Regex::create()->parse('/(?<year>\d{4})-(?<month>\d{2})/');
$group = $ast->pattern->children[0];  // First child is GroupNode

echo $group->type === GroupType::Named;   // true
echo $group->name;                        // 'year'
echo $group->child->quantifier;           // '{4}' — the group holds a QuantifierNode over \d
echo $group->child->node->value;          // 'd' — the CharTypeNode being quantified
```

**Common Errors:**
```php
// WRONG: Assuming lookarounds consume characters
// Pattern: /(?=foo)bar/ matches "bar" at position where "foo" follows
// It does NOT match "foobar"!
$lookahead = $ast->pattern->children[0];
echo $lookahead->type === GroupType::LookaheadPositive;  // true

// RIGHT: Lookarounds are zero-width assertions
// They match a POSITION, not characters
```

---

### QuantifierNode

**Purpose:** Repeats a node a specified number of times.


**Fields:**

| Field        | Type           | Description                                                       |
|--------------|----------------|-------------------------------------------------------------------|
| `node`       | NodeInterface  | The node being quantified                                         |
| `quantifier` | string         | The quantifier token, without its greed suffix: `+`, `*`, `{2,4}` |
| `type`       | QuantifierType | Greedy, lazy, or possessive — the greed suffix                    |

The repetition bounds are not fields. `QuantifierBounds` parses them from the
token — the same way the parser does, for `{n}`, `{n,}`, `{n,m}`, `{,m}` and
the `*` / `+` / `?` shorthands alike.

**Quantifier Types:**

| Case                         | Pattern              | Behavior                                     |
|------------------------------|----------------------|----------------------------------------------|
| `QuantifierType::Greedy`     | `+`, `*`, `{m,n}`    | Matches as much as possible, then backtracks |
| `QuantifierType::Lazy`       | `+?`, `*?`, `{m,n}?` | Matches as little as possible                |
| `QuantifierType::Possessive` | `++`, `*+`, `{m,n}+` | Matches as much as possible, no backtracking |

**Example:**
```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierType;

$ast = Regex::create()->parse('/a{2,4}?/');  // Lazy quantifier
$quantifier = $ast->pattern;

echo $quantifier->quantifier;  // '{2,4}' — the lazy '?' lives in the type
echo $quantifier->type === QuantifierType::Lazy;  // true

$bounds = QuantifierBounds::parse($quantifier->quantifier);
echo $bounds->min;   // 2
echo $bounds->max;   // 4
var_dump($bounds->max);  // int(4); null when unbounded, as in '{2,}'
```

**Common Errors:**
```php
// WRONG: Confusing quantifier with literal
// Pattern: /a+/ contains a QUANTIFIER, not a plus literal
$literal = $ast->pattern;
echo $literal instanceof QuantifierNode;  // true

// RIGHT: Access the quantified node
echo $quantifier->node instanceof LiteralNode;  // true
echo $quantifier->node->value;  // 'a'
```

---

## Literal and Character Nodes

### LiteralNode

**Purpose:** One literal character — unescaped, or an escaped form of one (`\.`, `\-`): `value` holds the character itself.


**Fields:**

| Field   | Type   | Description                   |
|---------|--------|-------------------------------|
| `value` | string | The character itself, one long |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/hello/');
$literal = $ast->pattern->children[0];

echo $literal->value;  // 'h'
```

**Common Errors:**
```php
// WRONG: Expecting one LiteralNode per run of text
// The parser emits one LiteralNode per character: /hello/ holds five
// ('h', 'e', 'l', 'l', 'o'), and /he l lo/ holds seven, spaces included.
echo count($ast->pattern->children);  // 5 for /hello/

// RIGHT: Concatenate the values when you need the text
$text = '';
foreach ($ast->pattern->children as $child) {
    $text .= $child->value;
}
echo $text;  // 'hello'
```

---

### CharLiteralNode

**Purpose:** Represents a single escaped character with a specific representation (Unicode, octal, hex, etc.).


**Fields:**

| Field                    | Type            | Description                  |
|--------------------------|-----------------|------------------------------|
| `originalRepresentation` | string          | The original escape sequence |
| `codePoint`              | int             | The Unicode code point value |
| `type`                   | CharLiteralType | The type of escape           |

**CharLiteralType Values:**

| Value                        | Example                    | Description                  |
|------------------------------|----------------------------|------------------------------|
| `Unicode`                    | `\x{1F600}`                | Unicode code point escape (`\x{...}`, `\u{...}`, `\uFFFF`, `\xFF`) |
| `UnicodeNamed`               | `\N{LATIN SMALL LETTER A}` | Named Unicode character      |
| `Octal`                      | `\o{141}`                  | Octal representation         |
| `OctalLegacy`                | `\141`, `\012`             | Legacy octal: up to 3 octal digits, `\0` first or a number past the groups opened before it (`\101` before group 101 exists is `A`, `\1000` is `@` then `0`) |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/\x{1F600}/');  // Grinning face emoji
$char = $ast->pattern;

echo $char->codePoint;        // 128512
echo $char->originalRepresentation;  // '\x{1F600}'
```

---

### ControlCharNode

**Purpose:** A control-character escape `\cX`: the letter after `\c`, as written, with the control code point it produces.


**Fields:**

| Field      | Type   | Description                            |
|------------|--------|----------------------------------------|
| `char`     | string | The letter after `\c`, as written      |
| `codePoint`| int    | The control character it stands for    |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/\cM/');
$ctrl = $ast->pattern;

echo $ctrl->char;      // 'M'
echo $ctrl->codePoint; // 13 — \cM is CR
```

---

### CharTypeNode

**Purpose:** Character type escapes like `\d`, `\w`, `\s`, and their negations.


**Fields:**

| Field   | Type   | Description                                       |
|---------|--------|---------------------------------------------------|
| `value` | string | The type character (`d`, `D`, `w`, `W`, `s`, `S`) |

**Type Reference:**


| Value | Meaning                      | Negation |
|-------|------------------------------|----------|
| `\d`  | Digits [0-9]                 | `\D`     |
| `\w`  | Word characters [a-zA-Z0-9_] | `\W`     |
| `\s`  | Whitespace                   | `\S`     |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/\d+/');
$digitClass = $ast->pattern;

echo $digitClass->value;  // 'd'
```

---

### DotNode

**Purpose:** The dot token `.` which matches any character except newlines (unless `s` flag is set).


**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/./');
$dot = $ast->pattern;

echo $dot instanceof \PHPRegex\Parser\Node\DotNode;  // true
```

**Common Errors:**
```php
// WRONG: Assuming . matches newlines
// Without /s flag, . does NOT match \n
preg_match('/./', "\n", $matches);  // Match: no

// With /s flag, . matches newlines
preg_match('/./s', "\n", $matches);  // Match: yes
```

---

### AnchorNode

**Purpose:** Start (`^`) and end (`$`) anchors, and string anchors like `\A`, `\z`.


**Fields:**

| Field   | Type   | Description          |
|---------|--------|----------------------|
| `value` | string | The anchor character |

**Anchor Reference:**

| Anchor | Meaning                                   |
|--------|-------------------------------------------|
| `^`    | Start of string (or line with `m` flag)   |
| `$`    | End of string (or line with `m` flag)     |
| `\A`   | Absolute start of string                  |
| `\z`   | Absolute end of string                    |
| `\Z`   | End of string, or before trailing newline |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/^foo$/');
$startAnchor = $ast->pattern->children[0];  // the sequence starts with '^'
$endAnchor = $ast->pattern->children[4];    // after 'f', 'o', 'o'

echo $startAnchor->value;  // '^'
echo $endAnchor->value;    // '$'
```

---

### AssertionNode

**Purpose:** Zero-width assertions that are not anchors, like word boundaries (`\b`, `\B`).


**Fields:**

| Field   | Type   | Description             |
|---------|--------|-------------------------|
| `value` | string | The assertion character |

**Assertion Reference:**

| Assertion  | Meaning                                      |
|------------|----------------------------------------------|
| `\b`       | Word boundary (transition between \w and \W) |
| `\B`       | Not a word boundary                          |
| `(?=...)`  | Positive lookahead (GroupNode)               |
| `(?!...)`  | Negative lookahead (GroupNode)               |
| `(?<=...)` | Positive lookbehind (GroupNode)              |
| `(?<!...)` | Negative lookbehind (GroupNode)              |
| `(?*...)`, `(*napla:...)` | Non-atomic positive lookahead: a `GroupType::LookaheadPositive` GroupNode whose `flags` is `*` |
| `(?<*...)`, `(*naplb:...)` | Non-atomic positive lookbehind: a `GroupType::LookbehindPositive` GroupNode whose `flags` is `*` |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/\bword\b/');
$assertion = $ast->pattern->children[0];

echo $assertion->value;  // 'b'
```

**Common Errors:**
```php
// WRONG: Confusing \b with [a-zA-Z_]
// \b is a POSITION assertion, not a character class
preg_match('/\b/', 'word', $matches);  // Match: yes (at position 0)
preg_match('/[a-z]/', 'word', $matches);  // Match: yes ('w')

// They are different!
```

---

## Character Class Nodes

### CharClassNode

**Purpose:** Character classes `[...]` including negated classes `[^...]`. PHP has no class intersection or subtraction: inside a class, `&&` is two `&` members and `--` a range through `-`, so `[a&&b]` matches `&` and `[a--b]` is refused as a range out of order.


**Fields:**

| Field        | Type          | Description                                        |
|--------------|---------------|----------------------------------------------------|
| `expression` | NodeInterface | The class content (ranges, characters, escapes)    |
| `isNegated`  | bool          | True for `[^...]`, false for `[...]`               |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/[a-z]/');
$class = $ast->pattern;

echo $class->isNegated;  // false
echo $class->expression instanceof \PHPRegex\Parser\Node\RangeNode;  // true
```

**Common Errors:**
```php
// WRONG: [A-z] includes characters between Z and a
// In ASCII: [, \, ], ^, _, `
preg_match('/[A-z]/', '_', $matches);  // Match: yes (includes _)

// RIGHT: Use [A-Za-z]
preg_match('/[A-Za-z]/', '_', $matches);  // Match: no (no underscore)
```

---

### RangeNode

**Purpose:** A range within a character class, like `a-z` or `0-9`.


**Fields:**

| Field   | Type          | Description                                  |
|---------|---------------|----------------------------------------------|
| `start` | NodeInterface | The start of range (usually CharLiteralNode) |
| `end`   | NodeInterface | The end of range (usually CharLiteralNode)   |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/[a-z]/');
$range = $ast->pattern->expression;

echo $range->start->value;  // 'a'
echo $range->end->value;    // 'z'
```

---

### PosixClassNode

**Purpose:** POSIX character classes inside character classes, like `[[:alpha:]]`.


**Fields:**

| Field   | Type   | Description          |
|---------|--------|----------------------|
| `class` | string | The POSIX class name |

**POSIX Classes:**

| Class        | Meaning            |
|--------------|--------------------|
| `[:alpha:]`  | Letters            |
| `[:digit:]`  | Digits             |
| `[:alnum:]`  | Letters and digits |
| `[:space:]`  | Whitespace         |
| `[:upper:]`  | Uppercase letters  |
| `[:lower:]`  | Lowercase letters  |
| `[:xdigit:]` | Hexadecimal digits |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/[[:digit:]]/');
$posix = $ast->pattern->expression;  // the class content, under the CharClassNode

echo $posix->class;  // 'digit'
```

---

### UnicodePropNode

**Purpose:** Unicode property escapes like `\p{L}` (letters) or `\P{Lu}` (non-uppercase letters).


**Fields:**

| Field       | Type   | Description                                        |
|-------------|--------|----------------------------------------------------|
| `prop`      | string | The property specifier, as written (`{L}`, `L`)    |
| `hasBraces` | bool   | True for `\p{L}`, false for `\pL`                  |

**Common Unicode Properties:**

| Property | Meaning          |
|----------|------------------|
| `\p{L}`  | Any letter       |
| `\p{Lu}` | Uppercase letter |
| `\p{Ll}` | Lowercase letter |
| `\p{N}`  | Any number       |
| `\p{P}`  | Any punctuation  |
| `\p{S}`  | Any symbol       |
| `\p{Z}`  | Any separator    |
| `\p{Sc}` | Currency symbol  |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/^\p{L}+$/u');  // Unicode letters only
$prop = $ast->pattern->children[1]->node;  // the \p{L} under its '+' quantifier

echo $prop->prop;       // '{L}' — kept as written; bare '\pL' would give 'L'
echo $prop->hasBraces;  // true
```

---

### ExtendedCharClassNode

**Purpose:** A Perl extended class, `(?[ \p{L} - [aeiou] ])`: a set expression
over classes, read for a target on PCRE2 10.45 or later. It matches one
character, like a character class.


**Fields:**

| Field        | Type          | Description                                   |
|--------------|---------------|-----------------------------------------------|
| `expression` | NodeInterface | An operand, or a `ClassSetOperationNode` tree |
| `text`       | string        | The class as written, `(?[` to `])`; empty for a node built by hand |

Operands are the nodes a class holds: a `CharClassNode`, a `PosixClassNode`
(`[:alpha:]`), a `CharTypeNode` (`\d`), a `UnicodePropNode` (`\p{L}`) or a
character (`\n`, `\x61`, `\!`). An escape is read as inside a class, so `\b`
is a backspace and `\1` an octal escape; a nested class is read as under `xx`,
where spaces and tabs are skipped. A plain character is no operand.

### ClassSetOperationNode

**Purpose:** One set operation inside an extended class.

**Fields:**

| Field      | Type               | Description                                  |
|------------|--------------------|----------------------------------------------|
| `operator` | ClassSetOperator   | The operation                                |
| `left`     | NodeInterface\|null | The left operand; null for a complement      |
| `right`    | NodeInterface      | The right operand                            |
| `symbol`   | string             | The operator as written (`+` or `\|` for a union) |

**Operators**, `!` binding tightest, then `&`, then the others left to right:

| Operator                                | Syntax       |
|-----------------------------------------|--------------|
| `ClassSetOperator::Complement`          | `!a`         |
| `ClassSetOperator::Intersection`        | `a & b`      |
| `ClassSetOperator::Union`               | `a + b`, `a \| b` |
| `ClassSetOperator::Difference`          | `a - b`      |
| `ClassSetOperator::SymmetricDifference`| `a ^ b`      |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create(['pcre_version' => '10.45'])->parse('/(?[ \p{L} - [aeiou] ])/u');
$difference = $ast->pattern->expression;

echo $difference->operator->name;  // 'Difference'
```

---

## Group and Reference Nodes

### BackrefNode

**Purpose:** Backreference to a previously captured group, like `\1` or `\k<name>`.


**Fields:**

| Field | Type   | Description                                       |
|-------|--------|---------------------------------------------------|
| `ref` | string | The group number or name, as written (`\1`, `\k<name>`) |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/(\w+)\1/');  // Match doubled word
$backref = $ast->pattern->children[1];

echo $backref->ref;  // '\1' — the reference keeps its original spelling
```

The same node serves as a conditional's group test, where `ref` holds the bare
number: in `/(?(1)b|c)/` the condition is a `BackrefNode` whose `ref` is `'1'`.

**Common Errors:**
```php
// WRONG: Backreference before capture
// Pattern: /\1(\w)/ is invalid - no group 1 yet
preg_match('/\1(\w)/', 'a', $matches);  // Error or no match

// RIGHT: Reference after capture
preg_match('/(\w)\1/', 'aa', $matches);  // Match: yes
```

---

### ConditionalNode

**Purpose:** Conditional pattern `(?(condition)yes|no)` that matches different things based on a condition.


**Fields:**

| Field       | Type          | Description                              |
|-------------|---------------|------------------------------------------|
| `condition` | NodeInterface | The condition (usually BackrefNode)      |
| `yes`       | NodeInterface | Pattern if condition is true             |
| `no`        | NodeInterface | Pattern if condition is false (optional) |

A group test, `(?(1)...)` or `(?(<n>)...)`, is a `BackrefNode`; a recursion
test, `(?(R)...)`, `(?(R2)...)` or `(?(R&n)...)`, is a `SubroutineNode`. As in
PCRE2, `(?(R)...)` and `(?(R2)...)` test a group instead when one is named `R`
or `R2`, before or after the condition: `/(?<R2>a)(?(R2)b|c)/` holds a
`BackrefNode` whose `ref` is `'R2'`.

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/(a)?(?(1)b|c)/');  // If 'a' captured, expect 'b'; else expect 'c'
$conditional = $ast->pattern->children[1];  // after the quantified (a)?

echo $conditional->condition instanceof \PHPRegex\Parser\Node\BackrefNode;  // true
echo $conditional->condition->ref;  // '1'
echo $conditional->yes->value;      // 'b'
```

---

### VersionConditionNode

**Purpose:** The PCRE2 version condition, `(?(VERSION=10.44)...)` or `(?(VERSION>=10.44)...)`: the engine tests its own version before choosing a branch.


**Fields:**

| Field     | Type   | Description                          |
|-----------|--------|--------------------------------------|
| `operator`| string | `=` or `>=`                          |
| `version` | string | The version compared against         |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/(?(VERSION>=10.44)a|b)/');
$condition = $ast->pattern->condition;  // the ConditionalNode's condition

echo $condition->operator;  // '>='
echo $condition->version;   // '10.44'
```

---

### SubroutineNode

**Purpose:** Subroutine call to reuse a capture group pattern, like `(?1)` or `(?&name)`.


**Fields:**

| Field       | Type   | Description                                                       |
|-------------|--------|-------------------------------------------------------------------|
| `reference` | string | The group number or name                                          |
| `syntax`    | string | The call marker: `''` for `(?1)`, `'&'` for `(?&name)`, `'g'` for `\g{...}`, `'P>'` for `(?P>name)` |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/(?<paren>\((?:[^()]++|(?&paren))*\))/');
// Match balanced parentheses using recursion
$subroutine = $ast->pattern              // the (?<paren>...) group
    ->child                              // its sequence: \( ... \)
    ->children[1]                        // the quantified (?:...)*
    ->node                               // the non-capturing group
    ->child                              // the alternation [^()]++ | (?&paren)
    ->alternatives[1];                   // the (?&paren) call

echo $subroutine->reference;  // 'paren'
echo $subroutine->syntax;     // '&', the (?&name) call marker
```

---

### DefineNode

**Purpose:** `(?DEFINE...)` block that defines subpatterns without matching them.


**Fields:**

| Field     | Type          | Description             |
|-----------|---------------|-------------------------|
| `content` | NodeInterface | The defined subpatterns |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/(?(DEFINE)(?<digit>\d+)(?<number>\g<digit>))/');
$define = $ast->pattern;

echo $define->content instanceof \PHPRegex\Parser\Node\SequenceNode;  // true
```

---

## Advanced Nodes

### PcreVerbNode

**Purpose:** PCRE verbs like `(*ACCEPT)`, `(*FAIL)`, `(*SKIP)` that control matching behavior.


**Fields:**

| Field  | Type   | Description   |
|--------|--------|---------------|
| `verb` | string | The verb name |

**PCRE Verbs:**

| Verb        | Meaning                    |
|-------------|----------------------------|
| `(*ACCEPT)` | Force match success        |
| `(*FAIL)`   | Force match failure        |
| `(*SKIP)`   | Skip and remember position |
| `(*COMMIT)` | No backtracking on failure |
| `(*PRUNE)`  | Prune backtracking stack   |
| `(*THEN)`   | Jump to alternation branch |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/foo(*FAIL)bar/');
$verb = $ast->pattern->children[3];  // after 'f', 'o', 'o'

echo $verb->verb;  // 'FAIL'
```

---

### LimitMatchNode

**Purpose:** `(*LIMIT_MATCH=...)` verb that sets a protective limit against runaway backtracking.


**Fields:**

| Field   | Type | Description     |
|---------|------|-----------------|
| `limit` | int  | The match limit |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/(*LIMIT_MATCH=1000)foo/');
$limit = $ast->pattern->children[0];

echo $limit->limit;  // 1000
```

---

### ScriptRunNode

**Purpose:** `(*script_run:...)`, short `(*sr:...)`, which matches its body only when every character it matches belongs to one script. `(*atomic_script_run:...)`, short `(*asr:...)`, is the same with an atomic body: `(*sr:(?>...))`.


**Fields:**

| Field     | Type                | Description                                        |
|-----------|---------------------|----------------------------------------------------|
| `script`  | string              | The body as written                                |
| `content` | NodeInterface\|null | The parsed body; its positions count from its start |
| `atomic`  | bool                | Whether the body is atomic (`(*asr:...)`)          |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/(*asr:\d+)/');
$run = $ast->pattern;

echo $run->script;          // '\d+'
var_dump($run->atomic);     // bool(true)
```

---

### KeepNode

**Purpose:** The `\K` keep assertion: everything matched before it stays out of the reported match, which starts at this position instead. It is zero-width, and does not change the length range of a match (see [LengthRangeCalculator](../visitors/README.md#lengthrangecalculator)).

**Fields:** none of its own — every node carries `startPosition` and `endPosition`.

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/foo\Kbar/');
$keep = $ast->pattern->children[3];  // after 'f', 'o', 'o'

var_dump($keep instanceof \PHPRegex\Parser\Node\KeepNode);  // bool(true)
```

---

### CommentNode

**Purpose:** A comment in the pattern: a `(?#...)` group, or a `# ...` line under the `x` flag.


**Fields:**

| Field      | Type   | Description                                       |
|------------|--------|---------------------------------------------------|
| `comment`  | string | The comment text, as written                      |
| `extended` | bool   | True for a `# ...` line under `x`, false for `(?#...)` |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/a(?# a note)b/');
$comment = $ast->pattern->children[1];

echo $comment->comment;         // ' a note'
var_dump($comment->extended);   // bool(false)

$ast = Regex::create()->parse('/a # trailing note/x');
$comment = $ast->pattern->children[1];

echo $comment->comment;         // '# trailing note'
var_dump($comment->extended);   // bool(true)
```

---

### CalloutNode

**Purpose:** A callout — `(?C)`, `(?C1)` or `(?C"name")` — where PCRE2 hands control to the embedder's callback mid-match.


**Fields:**

| Field                | Type              | Description                              |
|----------------------|-------------------|------------------------------------------|
| `identifier`         | int\|string\|null | The callout number or name, null for `(?C)` |
| `isStringIdentifier` | bool              | Whether the identifier was a quoted name |

**Example:**
```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/a(?C1)b(?C"tag")/');
$numbered = $ast->pattern->children[1];

var_dump($numbered->identifier);          // int(1)
var_dump($numbered->isStringIdentifier);  // bool(false)

$named = $ast->pattern->children[3];

var_dump($named->identifier);          // string(3) "tag"
var_dump($named->isStringIdentifier);  // bool(true)
```

---

## Supporting Types

### NodeInterface

**Purpose:** The base interface that all nodes implement. Defines the visitor pattern entry point.

```php
interface NodeInterface
{
    public function accept(NodeVisitorInterface $visitor): mixed;
}
```

---

### AbstractNode

**Purpose:** Base class for nodes with position tracking. All major nodes extend this.

**Fields:**

| Field           | Type | Description                         |
|-----------------|------|-------------------------------------|
| `startPosition` | int  | Offset in pattern where node begins |
| `endPosition`   | int  | Offset in pattern where node ends   |

**Position Reference:**

Positions are byte offsets into the pattern body — the text between the
delimiters, flags excluded — and they are 0-based. `endPosition` is exclusive:
one past the node's last byte.

```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/foo/');
$literal = $ast->pattern->children[0];

echo $literal->startPosition;  // 0 — the body 'foo' starts at 0
echo $literal->endPosition;    // 1 — one past the node's single byte

echo $ast->pattern->children[2]->endPosition;  // 3, the body's length
```

---

## Quick Reference Table

| Node                       | Purpose          | Key Fields                                |
|----------------------------|------------------|-------------------------------------------|
| `RegexNode`                | Root of AST      | `delimiter`, `pattern`, `flags`           |
| `SequenceNode`             | Ordered list     | `children[]`                              |
| `AlternationNode`          | Branches         | `alternatives[]`                          |
| `GroupNode`                | Grouping         | `child`, `type`, `name`, `flags`          |
| `QuantifierNode`           | Repetition       | `node`, `quantifier`, `type`              |
| `LiteralNode`              | Literal char     | `value`                                   |
| `CharLiteralNode`          | Escaped char     | `codePoint`, `type`                       |
| `ControlCharNode`          | `\cX` escape     | `char`, `codePoint`                       |
| `CharTypeNode`             | Type escape      | `value`                                   |
| `DotNode`                  | Any char         | (none)                                    |
| `AnchorNode`               | Position anchor  | `value`                                   |
| `AssertionNode`            | Assertion        | `value`                                   |
| `KeepNode`                 | `\K` keep        | (none)                                    |
| `CharClassNode`            | Character set    | `expression`, `isNegated`                 |
| `RangeNode`                | Range in class   | `start`, `end`                            |
| `PosixClassNode`           | `[:alpha:]` etc. | `class`                                   |
| `UnicodePropNode`          | `\p{...}`        | `prop`, `hasBraces`                       |
| `ExtendedCharClassNode`    | Extended class   | `expression`, `text`                      |
| `ClassSetOperationNode`    | Set operation    | `operator`, `left`, `right`               |
| `BackrefNode`              | Backreference    | `ref`                                     |
| `ConditionalNode`          | Conditional      | `condition`, `yes`, `no`                  |
| `VersionConditionNode`     | VERSION test     | `operator`, `version`                     |
| `SubroutineNode`           | Group call       | `reference`, `syntax`                     |
| `DefineNode`               | `(?DEFINE...)`   | `content`                                 |
| `CommentNode`              | Comment          | `comment`, `extended`                     |
| `CalloutNode`              | `(?C...)`        | `identifier`, `isStringIdentifier`        |
| `PcreVerbNode`             | `(*VERB)`        | `verb`                                    |
| `LimitMatchNode`           | `(*LIMIT_MATCH=)`| `limit`                                   |
| `ScriptRunNode`            | `(*sr:...)`      | `script`, `content`, `atomic`             |

---

## Summary

Understanding nodes is essential for working with the AST directly. Key takeaways:

1. **Nodes are immutable** — create new instances to transform
2. **Positions are important** — 0-based byte offsets into the pattern body, preserved for diagnostics
3. **Literals are per character** — one `LiteralNode` per character, escaped or not
4. **GroupNode is versatile** — handles many group types
5. **QuantifierNode has types** — greedy, lazy, possessive; bounds come from `QuantifierBounds`
6. **Character classes are complex** — can contain ranges, operations, POSIX classes

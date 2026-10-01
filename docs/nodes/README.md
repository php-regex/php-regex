# AST Node Reference

This reference documents every node type in the RegexParser AST. Nodes are the building blocks that represent parsed regex patterns. Understanding nodes is essential for building custom visitors, debugging parsing issues, or extending the library.

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
use PhpRegex\Toolkit\Regex;

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
use PhpRegex\Toolkit\Regex;

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

| Field          | Type  | Description                        |
|----------------|-------|------------------------------------|
| `alternatives` | array | Array of SequenceNode alternatives |

**Example:**
```php
use PhpRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/foo|bar|baz/');
$alternation = $ast->pattern;

foreach ($alternation->alternatives as $index => $alt) {
    echo "Alternative $index: ";
    echo $alt->children[0]->value . "\n";
}
// Output:
// Alternative 0: foo
// Alternative 1: bar
// Alternative 2: baz
```

**Common Errors:**
```php
// WRONG: Assuming alternation contains LiteralNodes
// Pattern: /foo|bar/ contains SEQUENCE nodes, not literals!
foreach ($alternation->alternatives as $alt) {
    // This crashes if alternative has multiple children
    echo $alt->children[0]->value;  // Safe for single-char alternatives
}

// RIGHT: Handle sequences
foreach ($alternation->alternatives as $alt) {
    $text = '';
    foreach ($alt->children as $child) {
        $text .= $child->value ?? '...';
    }
    echo $text . "\n";
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

| Type Constant                 | Pattern        | Description                    |
|-------------------------------|----------------|--------------------------------|
| `T_GROUP_CAPTURING`           | `(foo)`        | Captures matched text          |
| `T_GROUP_NON_CAPTURING`       | `(?:foo)`      | Groups without capture         |
| `T_GROUP_NAMED`               | `(?<name>foo)` | Captures with name             |
| `T_GROUP_LOOKAHEAD_POSITIVE`  | `(?=foo)`      | Lookahead (matches position)   |
| `T_GROUP_LOOKAHEAD_NEGATIVE`  | `(?!foo)`      | Negative lookahead             |
| `T_GROUP_LOOKBEHIND_POSITIVE` | `(?<=foo)`     | Lookbehind                     |
| `T_GROUP_LOOKBEHIND_NEGATIVE` | `(?<!foo)`     | Negative lookbehind            |
| `T_GROUP_INLINE_FLAGS`        | `(?i:foo)`     | Inline flag modification       |
| `T_GROUP_ATOMIC`              | `(?>foo)`      | Atomic group (no backtracking) |
| `T_GROUP_BRANCH_RESET`        | `(?\|foo\|bar)` | Same group numbers in branches |
| `T_GROUP_SCAN_SUBSTRING`      | `(*scs:(1)foo)` | Matches its body against what the listed groups captured (PCRE2 10.45) |

**Example:**
```php
use PhpRegex\Toolkit\Regex;
use PhpRegex\Parser\Node\GroupType;

$ast = Regex::create()->parse('/(?<year>\d{4})-(?<month>\d{2})/');
$group = $ast->pattern->children[0];  // First child is GroupNode

echo $group->type === GroupType::T_GROUP_NAMED;  // true
echo $group->name;  // 'year'
echo $group->child->children[0]->value;  // '4 digits'
```

**Common Errors:**
```php
// WRONG: Assuming lookarounds consume characters
// Pattern: /(?=foo)bar/ matches "bar" at position where "foo" follows
// It does NOT match "foobar"!
$lookahead = $ast->pattern->children[0];
echo $lookahead->type === GroupType::T_GROUP_LOOKAHEAD_POSITIVE;  // true

// RIGHT: Lookarounds are zero-width assertions
// They match a POSITION, not characters
```

---

### QuantifierNode

**Purpose:** Repeats a node a specified number of times.


**Fields:**

| Field        | Type           | Description                                     |
|--------------|----------------|-------------------------------------------------|
| `node`       | NodeInterface  | The node being quantified                       |
| `quantifier` | string         | The quantifier string (e.g., `+`, `*`, `{2,5}`) |
| `type`       | QuantifierType | Greedy, lazy, or possessive                     |
| `min`        | int            | Minimum repetitions                             |
| `max`        | int\|null      | Maximum repetitions (null = unbounded)          |

**Quantifier Types:**

| Type Constant  | Pattern              | Behavior                                     |
|----------------|----------------------|----------------------------------------------|
| `T_GREEDY`     | `+`, `*`, `{m,n}`    | Matches as much as possible, then backtracks |
| `T_LAZY`       | `+?`, `*?`, `{m,n}?` | Matches as little as possible                |
| `T_POSSESSIVE` | `++`, `*+`, `{m,n}+` | Matches as much as possible, no backtracking |

**Example:**
```php
use PhpRegex\Toolkit\Regex;
use PhpRegex\Parser\Node\QuantifierType;

$ast = Regex::create()->parse('/a{2,4}?/');  // Lazy quantifier
$quantifier = $ast->pattern;

echo $quantifier->min;      // 2
echo $quantifier->max;      // 4
echo $quantifier->type === QuantifierType::T_LAZY;  // true
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

**Purpose:** Represents literal (unescaped) characters or escaped literal sequences.


**Fields:**

| Field   | Type   | Description      |
|---------|--------|------------------|
| `value` | string | The literal text |

**Example:**
```php
use PhpRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/hello/');
$literal = $ast->pattern->children[0];

echo $literal->value;  // 'hello'
```

**Common Errors:**
```php
// WRONG: Assuming LiteralNode only for single characters
// Pattern: /hello/ creates ONE LiteralNode with value "hello"
echo count($ast->pattern->children);  // 1 (one literal for "hello")

// Pattern: /he l lo/ creates THREE LiteralNodes
// "he", "l", "lo"
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

| Value           | Example                    | Description                  |
|-----------------|----------------------------|------------------------------|
| `UNICODE`       | `\x{1F600}`                | Unicode code point escape (`\x{...}`, `\u{...}`, `\uFFFF`, `\xFF`) |
| `UNICODE_NAMED` | `\N{LATIN SMALL LETTER A}` | Named Unicode character      |
| `OCTAL`         | `\o{141}`                  | Octal representation         |
| `OCTAL_LEGACY`  | `\141`, `\012`             | Legacy octal: up to 3 octal digits, `\0` first or a number past the groups opened before it (`\101` before group 101 exists is `A`, `\1000` is `@` then `0`) |

**Example:**
```php
use PhpRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/\x{1F600}/');  // Grinning face emoji
$char = $ast->pattern;

echo $char->codePoint;        // 128512
echo $char->originalRepresentation;  // '\x{1F600}'
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
use PhpRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/\d+/');
$digitClass = $ast->pattern;

echo $digitClass->value;  // 'd'
```

---

### DotNode

**Purpose:** The dot token `.` which matches any character except newlines (unless `s` flag is set).


**Example:**
```php
use PhpRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/./');
$dot = $ast->pattern;

echo $dot instanceof \PhpRegex\Parser\Node\DotNode;  // true
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
use PhpRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/^foo$/');
$startAnchor = $ast->pattern->children[0];
$endAnchor = $ast->pattern->children[1];

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
| `(?*...)`, `(*napla:...)` | Non-atomic positive lookahead: a `T_GROUP_LOOKAHEAD_POSITIVE` GroupNode whose `flags` is `*` |
| `(?<*...)`, `(*naplb:...)` | Non-atomic positive lookbehind: a `T_GROUP_LOOKBEHIND_POSITIVE` GroupNode whose `flags` is `*` |

**Example:**
```php
use PhpRegex\Toolkit\Regex;

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
use PhpRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/[a-z]/');
$class = $ast->pattern;

echo $class->isNegated;  // false
echo $class->expression instanceof \PhpRegex\Parser\Node\RangeNode;  // true
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
use PhpRegex\Toolkit\Regex;

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
use PhpRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/[[:digit:]]/');
$posix = $ast->pattern;

echo $posix->class;  // 'digit'
```

---

### UnicodePropNode

**Purpose:** Unicode property escapes like `\p{L}` (letters) or `\P{Lu}` (non-uppercase letters).


**Fields:**

| Field       | Type   | Description                       |
|-------------|--------|-----------------------------------|
| `prop`      | string | The property specifier            |
| `hasBraces` | bool   | True for `\p{L}`, false for `\pL` |

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
use PhpRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/^\p{L}+$/u');  // Unicode letters only
$prop = $ast->pattern->children[0];

echo $prop->prop;       // 'L'
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
| `ClassSetOperator::COMPLEMENT`          | `!a`         |
| `ClassSetOperator::INTERSECTION`        | `a & b`      |
| `ClassSetOperator::UNION`               | `a + b`, `a \| b` |
| `ClassSetOperator::DIFFERENCE`          | `a - b`      |
| `ClassSetOperator::SYMMETRIC_DIFFERENCE`| `a ^ b`      |

**Example:**
```php
use PhpRegex\Toolkit\Regex;

$ast = Regex::create(['pcre_version' => '10.45'])->parse('/(?[ \p{L} - [aeiou] ])/u');
$difference = $ast->pattern->expression;

echo $difference->operator->name;  // 'DIFFERENCE'
```

---

## Group and Reference Nodes

### BackrefNode

**Purpose:** Backreference to a previously captured group, like `\1` or `\k<name>`.


**Fields:**

| Field | Type   | Description              |
|-------|--------|--------------------------|
| `ref` | string | The group number or name |

**Example:**
```php
use PhpRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/(\w+)\1/');  // Match doubled word
$backref = $ast->pattern->children[1];

echo $backref->ref;  // '1'
```

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

**Example:**
```php
use PhpRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/(a)?(?(1)b|c)/');  // If 'a' captured, expect 'b'; else expect 'c'
$conditional = $ast->pattern;

echo $conditional->condition instanceof \PhpRegex\Parser\Node\BackrefNode;  // true
echo $conditional->condition->ref;  // '1'
```

---

### SubroutineNode

**Purpose:** Subroutine call to reuse a capture group pattern, like `(?1)` or `(?&name)`.


**Fields:**

| Field       | Type   | Description              |
|-------------|--------|--------------------------|
| `reference` | string | The group number or name |
| `syntax`    | string | The original syntax used |

**Example:**
```php
use PhpRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/(?<paren>\((?:[^()]++|(?&paren))*\))/');
// Match balanced parentheses using recursion
$subroutine = $ast->pattern->children[0]->child->children[1];  // The (?&paren) part

echo $subroutine->reference;  // 'paren'
echo $subroutine->syntax;     // '?&paren'
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
use PhpRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/(?(DEFINE)(?<digit>\d+)(?<number>\g<digit>))/');
$define = $ast->pattern;

echo $define->content instanceof \PhpRegex\Parser\Node\SequenceNode;  // true
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
use PhpRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/foo(*FAIL)bar/');
$verb = $ast->pattern->children[1];

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
use PhpRegex\Toolkit\Regex;

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
use PhpRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/(*asr:\d+)/');
$run = $ast->pattern;

echo $run->script;          // '\d+'
var_dump($run->atomic);     // bool(true)
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
```php
use PhpRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/foo/');
$literal = $ast->pattern->children[0];

echo $literal->startPosition;  // 1 (after '/')
echo $literal->endPosition;    // 4 (position of last 'o' + 1)
echo strlen('/foo/');          // 6
echo $literal->startPosition;  // positions are 0-based
```

---

## Quick Reference Table

| Node              | Purpose         | Key Fields                                 |
|-------------------|-----------------|--------------------------------------------|
| `RegexNode`       | Root of AST     | `delimiter`, `pattern`, `flags`            |
| `SequenceNode`    | Ordered list    | `children[]`                               |
| `AlternationNode` | Branches        | `alternatives[]`                           |
| `GroupNode`       | Grouping        | `child`, `type`, `name`                    |
| `QuantifierNode`  | Repetition      | `node`, `quantifier`, `type`, `min`, `max` |
| `LiteralNode`     | Literal text    | `value`                                    |
| `CharLiteralNode` | Escaped char    | `codePoint`, `type`                        |
| `CharTypeNode`    | Type escape     | `value`                                    |
| `DotNode`         | Any char        | (none)                                     |
| `AnchorNode`      | Position anchor | `value`                                    |
| `AssertionNode`   | Assertion       | `value`                                    |
| `CharClassNode`   | Character set   | `expression`, `isNegated`                  |
| `RangeNode`       | Range in class  | `start`, `end`                             |
| `ExtendedCharClassNode` | Extended class | `expression`                        |
| `ClassSetOperationNode` | Set operation  | `operator`, `left`, `right`         |
| `BackrefNode`     | Backreference   | `ref`                                      |
| `ConditionalNode` | Conditional     | `condition`, `yes`, `no`                   |

---

## Summary

Understanding nodes is essential for working with the AST directly. Key takeaways:

1. **Nodes are immutable** — create new instances to transform
2. **Positions are important** — preserved for diagnostics
3. **GroupNode is versatile** — handles many group types
4. **QuantifierNode has types** — greedy, lazy, possessive
5. **Character classes are complex** — can contain ranges, operations, POSIX classes

---

Previous: [Docs Home](../README.md) | Next: [AST Visitors](../visitors/README.md)

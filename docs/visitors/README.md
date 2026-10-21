---
permalink: /visitors/
description: "The built-in PHPRegex visitors — print, rewrite, modernize, validate, explain, render — each with its real output, and the base classes to extend for your own."
---
# AST Visitor Reference

Visitors are the algorithms that process the AST. They implement all the interesting behaviors — validation, optimization, explanation, visualization, and more. This reference documents every built-in visitor and shows how to build custom ones.

<div class="page-filter">
  <label class="visually-hidden" for="page-filter-input">Filter the visitor reference</label>
  <input type="search" id="page-filter-input" data-unit="visitors" placeholder="Filter by visitor or method — e.g. Visitor, enter, leave" autocomplete="off" spellcheck="false">
  <span class="page-count"></span>
</div>
<p class="page-empty" role="status" hidden>Nothing matches that filter — try a shorter fragment, or press Escape to see the whole page again.</p>

## How Visitors Work

Visitors walk the AST using `accept()` on each node. Each node dispatches to the matching `visit*()` method on the visitor, and the visitor controls whether and how to continue traversal.

---

## Base Classes and Interfaces

### Finding nodes without a visitor

Most tools only need to look at some nodes: every backreference, every
quantifier, the first lookbehind. `NodeFinder` does that for any tree,
without a method for each kind of node:

```php
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\NodeFinder;
use PHPRegex\Parser\RegexParser;

$tree = RegexParser::create()->parse('/(a)(b)\\2\\1/');

NodeFinder::findInstanceOf($tree, BackrefNode::class);            // both references, in order
NodeFinder::findFirst($tree, static fn (NodeInterface $node): bool => $node instanceof QuantifierNode);  // null: none
```

Every node gives its children in pattern order through `getChildren()`.
Anything more — the groups around a node, a depth, a rewrite — is a visitor:
extend one of the two base classes below.

### NodeVisitorInterface

**Purpose:** The contract every visitor answers to, with a `visitX()` method
for each node type.

**Usage:** extend `AbstractTraversingVisitor` or `AbstractNodeVisitor`
rather than implementing this interface. A new kind of node, as PCRE2 adds
syntax, adds a method to the interface in a minor release; both base classes
give it a default, so a visitor that extends one keeps working, and one that
implements the interface directly does not.

---

### Which base to extend

| You want to | Extend | A node you do not override |
|---|---|---|
| collect or check something wherever it stands in the pattern | `AbstractTraversingVisitor` | has its children visited, then returns `null` |
| compute a value per node and decide yourself which children to visit | `AbstractNodeVisitor` | returns the default value (`null`) without visiting its children |

With `AbstractNodeVisitor`, a node type added in a minor release returns the
default and the nodes below it are never visited. With
`AbstractTraversingVisitor`, they still reach your overrides.

### AbstractTraversingVisitor

**Purpose:** Base class that walks the whole tree: every `visitX()` method
visits the node's children, in the order they stand in the pattern, and
returns `null`.

**Usage:** Override the node types you care about. Call the parent method to
keep descending below the node; return without calling it to skip the
subtree.

```php
use PHPRegex\Parser\Node;
use PHPRegex\Parser\AbstractTraversingVisitor;
use PHPRegex\Toolkit\Regex;

class LiteralCollector extends AbstractTraversingVisitor
{
    private array $literals = [];

    public function visitLiteral(Node\LiteralNode $node)
    {
        $this->literals[] = $node->value;

        return parent::visitLiteral($node);
    }

    public function visitGroup(Node\GroupNode $node)
    {
        // Skip what lookaheads hold; descend into every other group.
        if (Node\GroupType::LookaheadPositive === $node->type) {
            return null;
        }

        return parent::visitGroup($node);
    }

    public function getLiterals(): array
    {
        return $this->literals;
    }
}

$visitor = new LiteralCollector();
Regex::create()->parse('/ab(?=cd)e/')->accept($visitor);

echo implode('', $visitor->getLiterals()); // abe
```

---

### AbstractNodeVisitor

**Purpose:** Base class that returns a default value for every node, `null`
unless you override `defaultReturn()`, and visits no children.

**Usage:** Extend it when each method computes the node's value and you
decide which children to visit, as the compiler and the explainer do.

```php
use PHPRegex\Parser\Node;
use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Toolkit\Regex;

/** @extends AbstractNodeVisitor<bool> */
class StartsWithCaret extends AbstractNodeVisitor
{
    public function visitRegex(Node\RegexNode $node): bool
    {
        return $node->pattern->accept($this);
    }

    public function visitSequence(Node\SequenceNode $node): bool
    {
        return [] !== $node->children && $node->children[0]->accept($this);
    }

    public function visitAnchor(Node\AnchorNode $node): bool
    {
        return '^' === $node->value;
    }

    protected function defaultReturn(): bool
    {
        return false;
    }
}

var_dump(Regex::create()->parse('/^abc/')->accept(new StartsWithCaret())); // bool(true)
```

---

## Compilation and Transformation Visitors

### PatternPrinter

**Purpose:** Converts the AST back into a PCRE string. Useful for round-tripping or pattern normalization.

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\Printer\PatternPrinter;

$ast = Regex::create()->parse('/foo/i');

// Compile back to string, as written
$pattern = $ast->accept(PatternPrinter::asWritten());
echo $pattern;  // '/foo/i'
```

Three printers write a pattern back:

- `PatternPrinter::asWritten()` gives it back as its author wrote it: the
  escapes, the comments and the white space `x` skips come from the source,
  and a node the source does not hold, one an optimizer wrote, is spelled by
  the library. `new PatternPrinter()` is the same printer.
- `PatternPrinter::normalized()` writes it in the library's spelling whatever
  its source, so that two patterns written differently print the same:
  `/a\-b/` prints `/a-b/`.
- `PatternPrinter::pretty()` writes the normalized spelling too and, under `x`
  only, lays the pattern out over lines with the rules of
[the formatter](../guides/formatting.md): a construct that fits the line stays
on it, a broken group indents its body, and a broken alternation hangs its
`| ` in the indent. Without `x` the newlines and indents would change what the
pattern matches, so it stays on one line: the output is always the same
pattern. To keep the author's spelling, add `x` where it is missing, and be
told when a layout would be unsafe, use `PatternFormatter` instead.

**Use Cases:**
- Normalize patterns (remove unnecessary whitespace, standardize escapes)
- Round-trip parsing and compilation
- Transform patterns programmatically

---

### Rewriter

**Purpose:** Applies safe optimizations to make patterns more efficient without
changing behavior. It is internal: call `Regex::optimize()` (or
`Optimizer::optimize()`), which runs it and returns an `OptimizationResult`.

**Optimizations Applied:**

| Before          | After  | Why                   |
|-----------------|--------|-----------------------|
| `[0-9]`         | `\d`   | Shorthand is faster   |
| `[a-zA-Z_0-9]`  | `\w`   | Same set, shorter     |
| `[0123456789]`  | `[0-9]`| Digits fold into a range |
| `(?:a)`         | `a`    | Unnecessary group     |
| `a{1}`          | `a`    | Redundant quantifier  |
| `a{0,1}`        | `a?`   | Same bounds, shorter  |

```php
use PHPRegex\Optimizer\Optimizer;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Toolkit\Regex;

echo Regex::create()->optimize('/(?:foo)/')->optimized;  // '/foo/'

$optimizer = new Optimizer(RegexParser::create());
echo $optimizer->optimize('/a{0,1}/')->optimized;  // '/a?/'
```

---

### Modernizer

**Purpose:** Converts legacy or verbose syntax to modern equivalents.

**Transformations:**

| Before     | After       | Why                                       |
|------------|-------------|-------------------------------------------|
| `[0-9]`    | `\d`        | The shorthand says it shorter; kept under `/u` and `(*UCP)`, where `\d` matches every decimal digit |
| `(?:foo)`  | `foo`       | A plain non-capturing group that changes nothing |
| `\-`       | `-`         | An escape of a character that needs none  |
| `\1`       | `\g{1}`     | The braces end the number: no digit after it can join it |
| `[\t\n\x0B\f\r ]` | `\s` | The same six characters under the `C` locale: only with `new Modernizer('C')` or `'POSIX'`, and kept under `/u` and `(*UCP)`, where `\s` matches more |

Without `u`, PHP builds the table of `\s` from `LC_CTYPE` once a program
calls `setlocale()`, and many locales give it the no-break space, byte `0xA0`:
more than half of those macOS installs. The Modernizer writes `\s` only
when told the pattern runs under `C` or `POSIX`, its one constructor argument;
by default the class stays as written. `\d` is the ten ASCII digits under every
locale, so `[0-9]` becomes `\d` without it.

Its scope is deliberately narrow: it leaves inline flags (`(?i)foo`), legacy
escapes that PCRE2 still reads (`\0`), redundant quantifiers (`a{1}`) and the
reference of a condition (`(?(1)a|b)`) alone — those pass through unchanged.

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Optimizer\Modernizer;

$ast = Regex::create()->parse('/[0-9](?:foo)/');
$modernized = $ast->accept(new Modernizer());

$pattern = $modernized->accept(PatternPrinter::asWritten());
echo $pattern;  // '/\dfoo/'
```

---

## Validation and Linting Visitors

### Validator

**Purpose:** Performs semantic validation of the pattern. It is internal: call
`Regex::validate()` (or `RegexParser::validate()`), which runs it and returns a
`ValidationResult`.

**Checks Performed:**
- Valid backreference targets
- Lookbehind length constraints
- Balanced groups
- Valid group numbers and names

```php
use PHPRegex\Toolkit\Regex;

$result = Regex::create()->validate('/(?<n>a)\k<m>/');  // Invalid: no group "m"

var_dump($result->isValid);       // bool(false)
echo $result->errorCode?->value;  // regex.backref.missing_named_group
```

---

### PatternLinter

**Purpose:** Checks for performance issues, anti-patterns, and readability problems. Used by CLI linter and PHPStan rule.

**Linting Rules:** each issue carries a rule id. A few of them:

| Rule ID                              | Description                                       | Severity                         |
|--------------------------------------|---------------------------------------------------|----------------------------------|
| `regex.lint.quantifier.nested`       | Nested quantifiers can backtrack catastrophically | warning                          |
| `regex.lint.group.quantifiedCapture` | A repeated capture keeps only its last iteration  | info (warning for a named group) |
| `regex.lint.group.redundant`         | A group that changes nothing                      | warning                          |
| `regex.lint.escape.suspicious`       | An escape that does not mean what it looks like   | warning                          |

Every rule id is listed in the [Rule Reference](../reference/rules.md#quick-reference-table).

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Linter\PatternLinter;

$linter = new PatternLinter();
Regex::create()->parse('/(a+)+b/')->accept($linter);

foreach ($linter->getIssues() as $issue) {
    echo $issue->message, "\n";
}
// Nested quantifiers can cause catastrophic backtracking.
// Quantified capturing group "(...)" with "+": only the last iteration's capture is retained.
```

The lint rules read the shape of the pattern. The ReDoS verdict comes from `Regex::redos()`, below.

---

### RedosProfiler (Internal)

**Purpose:** Internal visitor behind the structural heuristics of `Regex::redos()`. The verdict itself comes from a model of PCRE's backtracking built from the AST; the heuristics decide for the constructs outside that model (see [the ReDoS guide](../guides/redos.md)).

**Severities:**

| Severity   | Proven verdict                       | Action                 |
|------------|--------------------------------------|------------------------|
| `safe`     | linear: no input blows up an attempt | Accept pattern         |
| `low`      | (heuristics only) minimal risk       | Accept with monitoring |
| `medium`   | polynomial, degree 2                 | Consider refactoring   |
| `high`     | polynomial, degree 3 or more         | Refactor               |
| `critical` | exponential                          | Refactor               |
| `unknown`  | the analysis failed                  | Check the error        |

```php
use PHPRegex\Toolkit\Regex;

$analysis = Regex::create()->redos('/(a+)+b/');
echo $analysis->severity->value;            // 'critical'
echo $analysis->proof->value;               // 'proven'
echo $analysis->confidence->value;   // 'medium': not replayed on the engine yet
```

---

### ComplexityScorer

**Purpose:** Returns a numeric complexity score for a pattern. Useful for CI quality gates.

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\Analysis\ComplexityScorer;

$ast = Regex::create()->parse('/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/');
$score = $ast->accept(new ComplexityScorer());

echo $score;  // 65
```

**Interpretation:**

| Score  | Complexity   |
|--------|--------------|
| 0-20   | Simple       |
| 21-50  | Moderate     |
| 51-100 | Complex      |
| 100+   | Very Complex |

The IPv4 pattern above lands at 65 — Complex.

---

### MetricsCollector

**Purpose:** Counts the nodes of the AST by type and measures its depth.

`accept()` returns an array with three keys:

| Key        | Description                                                |
|------------|------------------------------------------------------------|
| `counts`   | Number of nodes per node type, keyed by short class name   |
| `total`    | Total number of nodes in the AST, the `RegexNode` included |
| `maxDepth` | Maximum nesting depth, the `RegexNode` counting as 1       |

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\Analysis\MetricsCollector;

$ast = Regex::create()->parse('/\d{4}-\d{2}-\d{2}/');
$metrics = $ast->accept(new MetricsCollector());

echo $metrics['total'];                     // 10
echo $metrics['maxDepth'];                  // 4
echo $metrics['counts']['QuantifierNode'];  // 3
```

The full `counts` entry for this pattern:

```text
RegexNode => 1, SequenceNode => 1, QuantifierNode => 3, CharTypeNode => 3, LiteralNode => 2
```

A node type that does not occur in the pattern has no key in `counts`.

---

### LengthRangeCalculator

**Purpose:** Computes the minimum and maximum length of the text a match consumes, as `[min, max]`, with `null` for
no upper bound. Lengths count bytes, or UTF-8 characters when the pattern is in UTF mode. `(*ACCEPT)` ends the match
early and lowers the minimum; `\K` only moves the start of the reported match and does not change the range.

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\Analysis\LengthRangeCalculator;

$ast = Regex::create()->parse('/a{2,4}b*/');
[$min, $max] = $ast->accept(new LengthRangeCalculator());

echo $min;        // 2 (aa)
var_dump($max);   // NULL (unbounded)
```

---

## Extraction and Generation Visitors

### LiteralExtractor

**Purpose:** Extracts fixed literals from the pattern, useful for optimization or indexing.

`accept()` returns a `LiteralSet`. Its `prefixes` list the strings every match starts with one of, and its `suffixes`
the strings every match ends with one of. An empty list says nothing about that end. `complete` is `true` when the
prefixes list every string the pattern matches.

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\Analysis\LiteralExtractor;

$ast = Regex::create()->parse('/user-\d{4}\.log/');
$literals = $ast->accept(new LiteralExtractor());

var_dump($literals->prefixes);            // ['user-']
var_dump($literals->suffixes);            // ['.log']
var_dump($literals->getLongestPrefix());  // 'user-'
var_dump($literals->getLongestSuffix());  // '.log'
var_dump($literals->complete);            // false
```

`getLongestPrefix()` and `getLongestSuffix()` return `null` when the list is empty.

---

### SampleGenerator

**Purpose:** Generates a sample string that matches the pattern. Used by `Regex::generate()`.

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Generator\SampleGenerator;

$ast = Regex::create()->parse('/[A-Z][a-z]{3,5}\d{2}/');
$sample = $ast->accept(new SampleGenerator());

echo $sample;  // e.g., "Word12"
```

Visiting the tree draws a sample from the tree alone: an assertion, a condition or a
backreference can make it one the engine does not match (`/(?!^)a/` draws `a`). `generate()`
draws again and checks each sample with the running engine, as `Regex::generate()` does, and
throws `SampleGenerationException` when none matches:

```php
use PHPRegex\Generator\SampleGenerator;

echo (new SampleGenerator())->generate('/(?!^)a/');  // "aa"
```

---

### TestCaseGenerator

**Purpose:** Generates test cases for the pattern, useful for QA tooling. Every case is
checked against the running engine: `preg_match()` gives 1 on each matching case and 0 on each
non-matching one. A pattern the engine refuses has no case, and one that matches every subject
(`/a*/`) has no non-matching case.

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Generator\TestCaseGenerator;

$ast = Regex::create()->parse('/\d{3}-\d{4}/');
$cases = $ast->accept(new TestCaseGenerator());

// ['matching' => ['000-0000'], 'non_matching' => ['00.000', '00.00000', '0000.000']]
```

---

## Presentation and Visualization Visitors

### TextExplainer

**Purpose:** Generates a plain-text explanation of what the pattern does. Used by `Regex::explain()`.

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Explain\TextExplainer;

$ast = Regex::create()->parse('/\d{3}-\d{4}/');
$explanation = $ast->accept(new TextExplainer());

echo $explanation;
/*
Regex matches
  Character Type: A digit: [0-9] (exactly 3 times)
  '-'
  Character Type: A digit: [0-9] (exactly 4 times)
*/
```

---

### HtmlExplainer

**Purpose:** Generates HTML explanation for use in documentation or web UIs.

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Explain\HtmlExplainer;

$ast = Regex::create()->parse('/\w+@\w+\.\w+/');
$html = $ast->accept(new HtmlExplainer());

echo $html;
// <div class="regex-explain">
// <strong>Regex matches:</strong>
// <ul><li><span title="Character Type: A word character: [a-zA-Z_0-9], and the letters of the LC_CTYPE locale when it is not C (one or more times)">Character Type: <strong>\w</strong> (A word character: [a-zA-Z_0-9], and the letters of the LC_CTYPE locale when it is not C)</span> (one or more times)</li>
// <li><span title="&#039;@&#039;"><strong>&#039;@&#039;</strong></span></li>
// <li><span title="Character Type: A word character: [a-zA-Z_0-9], and the letters of the LC_CTYPE locale when it is not C (one or more times)">Character Type: <strong>\w</strong> (A word character: [a-zA-Z_0-9], and the letters of the LC_CTYPE locale when it is not C)</span> (one or more times)</li>
// <li><span title="&#039;.&#039;"><strong>&#039;.&#039;</strong></span></li>
// <li><span title="Character Type: A word character: [a-zA-Z_0-9], and the letters of the LC_CTYPE locale when it is not C (one or more times)">Character Type: <strong>\w</strong> (A word character: [a-zA-Z_0-9], and the letters of the LC_CTYPE locale when it is not C)</span> (one or more times)</li></ul>
// </div>
```

---

### NodeDumper

**Purpose:** Generates a debug-friendly AST dump. Useful for development and debugging.

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\Printer\NodeDumper;

$ast = Regex::create()->parse('/foo/');
$dump = $ast->accept(new NodeDumper());

echo $dump;
/*
Regex(delimiter: /, flags: )
  Sequence:
    Literal('f')
    Literal('o')
    Literal('o')
*/
```

---

### JsonDumper

**Purpose:** Exports the tree as data, for a playground, an editor client or a snapshot test. The result is an array ready for `json_encode()`; [`ast.schema.json`](https://github.com/php-regex/php-regex/blob/2.x/src/Parser/ast.schema.json), shipped with `php-regex/regex-parser`, describes it.

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\Printer\JsonDumper;

$ast = Regex::create()->parse('/(a)+\1/i');

echo json_encode($ast->accept(new JsonDumper()), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
```

The document carries the format's `version`, the `delimiter`, the `flags`, the `source` between the delimiters and the tree under `pattern`. Each node gives its kind under `type`, a stable name such as `group`, `quantifier` or `char_class` rather than a class name, its byte offsets in the pattern under `start` and `end`, and its own fields:

```json
{"type": "quantifier", "start": 0, "end": 4, "min": 1, "max": null, "mode": "greedy",
 "child": {"type": "group", "start": 0, "end": 3, "kind": "capturing", "name": null, "flags": null,
           "number": 1, "scanned_groups": [], "child": {"type": "literal", "start": 1, "end": 2, "value": "a"}}}
```

Group numbers, and the groups a back reference or a call resolves to (`groups`), come from the whole pattern: a subtree exported alone has none. Every string is valid UTF-8; a literal holding bytes that are not gives them in hexadecimal under `bytes`. A minor release may add keys and node kinds; `version` changes only when a key is renamed, removed or given another meaning.

---

### MermaidRenderer

**Purpose:** Renders the AST as a Mermaid diagram for documentation or visualization.

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Explain\MermaidRenderer;

$ast = Regex::create()->parse('/a|b/');
$mermaid = $ast->accept(new MermaidRenderer());

echo $mermaid;
/*
graph TD;
    node0["Regex: none"]
    node1{"Alternation"}
    node2["Literal: a"]
    node1 --> node2
    node3["Literal: b"]
    node1 --> node3
    node0 --> node1
*/
```

---

### AsciiTreeRenderer

**Purpose:** Renders a text-based tree of the AST for quick inspection.

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Explain\AsciiTreeRenderer;

$ast = Regex::create()->parse('/^a+$/');
$tree = $ast->accept(new AsciiTreeRenderer());

echo $tree;
/*
Regex
\-- Sequence
    |-- Anchor (^)
    |-- Quantifier (+, greedy)
    |   \-- Literal ('a')
    \-- Anchor ($)
*/
```

---

### RailroadSvgRenderer

**Purpose:** Renders a railroad-style SVG diagram suitable for graphical output.

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Explain\RailroadSvgRenderer;

$ast = Regex::create()->parse('/a|b/');
$svg = $ast->accept(new RailroadSvgRenderer());

echo $svg;
// <svg ...>...</svg>
```

---

### Highlighting Visitors

Base classes for syntax highlighting:

| Visitor                     | Output Format | Use Case   |
|-----------------------------|---------------|------------|
| `ConsoleHighlighter` | ANSI colors   | CLI output |
| `HtmlHighlighter`    | HTML spans    | Web output |

HTML spans include a base `regex-token` class plus semantic classes like `regex-escape`, `regex-group`, `regex-comment`, and `regex-backref` so themes can style them distinctly.

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;

$ast = Regex::create()->parse('/\d+/');
$highlighted = $ast->accept(new ConsoleHighlighter());

echo $highlighted;
// "\033[38;2;78;201;176m\\d\033[0m\033[38;2;215;186;125m+\033[0m"
```

---

## Building Custom Visitors

### Pattern 1: Stateless Visitor (Returns a Value)

```php
use PHPRegex\Parser\AbstractNodeVisitor;

class LiteralCountVisitor extends AbstractNodeVisitor
{
    public function visitRegex(Node\RegexNode $node): int
    {
        return $node->pattern->accept($this);
    }

    public function visitLiteral(Node\LiteralNode $node): int
    {
        return 1;
    }

    public function visitSequence(Node\SequenceNode $node): int
    {
        return array_sum(
            array_map(fn($child) => $child->accept($this), $node->children)
        );
    }
}

// Usage
$ast = Regex::create()->parse('/hello world/');
$visitor = new LiteralCountVisitor();
$count = $ast->accept($visitor);

echo $count;  // 11, one literal per character
```

---

### Pattern 2: Stateful Visitor (Accumulates State)

```php
use PHPRegex\Parser\AbstractTraversingVisitor;

class GroupCollectorVisitor extends AbstractTraversingVisitor
{
    private array $groups = [];

    public function visitGroup(Node\GroupNode $node)
    {
        if ($node->name !== null) {
            $this->groups[] = $node->name;
        }

        return parent::visitGroup($node); // keep descending: groups nest
    }

    public function getGroupNames(): array
    {
        return $this->groups;
    }
}

// Usage
$ast = Regex::create()->parse('/(?<year>\d{4})-(?<month>\d{2})/');
$visitor = new GroupCollectorVisitor();
$ast->accept($visitor);

print_r($visitor->getGroupNames());
// Array ( [0] => year [1] => month )
```

---

### Pattern 3: Transforming Visitor

`AbstractNodeVisitor` returns `null` for every node it does not override: a
transformer rebuilds the tree itself. Override the node types you rewrite, and
the containers that hold them — `visitRegex` and `visitSequence` at minimum,
since every pattern is a `RegexNode` around a `SequenceNode`:

```php
use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Toolkit\Regex;

class UppercaserVisitor extends AbstractNodeVisitor
{
    public function visitRegex(RegexNode $node): RegexNode
    {
        return new RegexNode(
            $node->pattern->accept($this),
            $node->flags,
            $node->delimiter,
            $node->startPosition,
            $node->endPosition,
        );
    }

    public function visitSequence(SequenceNode $node): SequenceNode
    {
        return new SequenceNode(
            array_map(
                fn (NodeInterface $child): NodeInterface => $child->accept($this),
                $node->children,
            ),
            $node->startPosition,
            $node->endPosition,
        );
    }

    public function visitLiteral(LiteralNode $node): LiteralNode
    {
        return new LiteralNode(
            strtoupper($node->value),
            $node->startPosition,
            $node->endPosition
        );
    }
}

// Usage: Transform /hello/ to /HELLO/
$ast = Regex::create()->parse('/hello/');
$newAst = $ast->accept(new UppercaserVisitor());

echo $newAst->accept(PatternPrinter::asWritten());  // '/HELLO/'
```

For a transformation that spans the whole tree, every container node
(`GroupNode`, `QuantifierNode`, `AlternationNode`…) needs the same rebuild —
`Rewriter` and `Modernizer` are full working examples of the pattern.

---

## Visitor Quick Reference

Compilation and transformation:
- `PatternPrinter` converts the AST to a pattern string.
- `Rewriter` applies safe optimizations (internal: run it through `Regex::optimize()`).
- `Modernizer` modernizes legacy syntax.

Validation and linting:
- `Validator` performs semantic validation.
- `PatternLinter` checks performance and readability.
- `ComplexityScorer` computes a complexity score.
- `MetricsCollector` collects metrics.
- `LengthRangeCalculator` estimates match length.

Extraction and generation:
- `LiteralExtractor` extracts literals.
- `SampleGenerator` generates matching strings.
- `TestCaseGenerator` generates test cases.

Presentation and visualization:
- `TextExplainer` provides plain-text explanations.
- `HtmlExplainer` provides HTML explanations.
- `NodeDumper` dumps the AST for debugging.
- `JsonDumper` exports the AST as data, described by `ast.schema.json`.
- `MermaidRenderer` builds Mermaid diagrams.
- `AsciiTreeRenderer` prints an ASCII tree.
- `RailroadSvgRenderer` renders railroad SVGs.
- `ConsoleHighlighter` outputs ANSI highlighting.
- `HtmlHighlighter` outputs HTML highlighting.

---

## Common Use Cases

| Use Case                 | Visitor(s)                               |
|--------------------------|------------------------------------------|
| Check pattern validity   | `Regex::validate()` (uses internal visitor) |
| Check for ReDoS          | `Regex::redos()` (uses internal visitor) |
| Optimize pattern         | `Regex::optimize()` (uses internal visitor) |
| Explain pattern to users | `TextExplainer`                     |
| Generate test cases      | `TestCaseGenerator`           |
| Count groups/metrics     | `MetricsCollector`                     |
| Visualize AST            | `MermaidRenderer`, `AsciiTreeRenderer`, `RailroadSvgRenderer` |
| Highlight in CLI         | `ConsoleHighlighter`              |
| Round-trip parsing       | `PatternPrinter`                    |

---

## Summary

| Category  | Key Visitors                                                 |
|-----------|--------------------------------------------------------------|
| Base      | `NodeVisitorInterface`, `AbstractTraversingVisitor`, `AbstractNodeVisitor` |
| Compile   | `PatternPrinter`, `Rewriter`                |
| Validate  | `Validator`, `PatternLinter`                  |
| Analyze   | `ComplexityScorer`, `MetricsCollector`           |
| Generate  | `SampleGenerator`, `TestCaseGenerator` |
| Visualize | `TextExplainer`, `MermaidRenderer`, `AsciiTreeRenderer`, `RailroadSvgRenderer` |

# AST Visitor Reference

Visitors are the algorithms that process the AST. They implement all the interesting behaviors — validation, optimization, explanation, visualization, and more. This reference documents every built-in visitor and shows how to build custom ones.

## How Visitors Work

Visitors walk the AST using `accept()` on each node. Each node dispatches to the matching `visit*()` method on the visitor, and the visitor controls whether and how to continue traversal.

---

## Base Classes and Interfaces

### Walking a tree without a visitor

Most tools only need to look at some nodes: every backreference, every
quantifier, the groups around a node. `NodeFinder` and `NodeTraverser` do that
for any tree, without a method for each kind of node:

```php
use RegexParser\Node\BackrefNode;
use RegexParser\Node\NodeInterface;
use RegexParser\NodeFinder;
use RegexParser\NodeTraverser;
use RegexParser\RegexParser;
use RegexParser\TraversalAction;

$tree = RegexParser::create()->parse('/(a)(b)\\2\\1/');

NodeFinder::findInstanceOf($tree, BackrefNode::class);            // both references, in order

NodeTraverser::walk($tree, static function (NodeInterface $node, array $ancestors): ?TraversalAction {
    // $ancestors: the nodes from the root down to $node's parent
    return null;  // or TraversalAction::SkipChildren, or TraversalAction::Stop
});
```

Every node gives its children in pattern order through `getChildren()`.

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
use RegexParser\Node;
use RegexParser\NodeVisitor\AbstractTraversingVisitor;
use RegexParser\Regex;

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
        if (Node\GroupType::T_GROUP_LOOKAHEAD_POSITIVE === $node->type) {
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
use RegexParser\Node;
use RegexParser\NodeVisitor\AbstractNodeVisitor;
use RegexParser\Regex;

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

### CompilerNodeVisitor

**Purpose:** Converts the AST back into a PCRE string. Useful for round-tripping or pattern normalization.

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\CompilerNodeVisitor;

$ast = Regex::create()->parse('/foo/i');

// Compile back to string
$pattern = $ast->accept(new CompilerNodeVisitor());
echo $pattern;  // '/foo/i'
```

**Use Cases:**
- Normalize patterns (remove unnecessary whitespace, standardize escapes)
- Round-trip parsing and compilation
- Transform patterns programmatically

---

### OptimizerNodeVisitor

**Purpose:** Applies safe optimizations to make patterns more efficient without changing behavior.

**Optimizations Applied:**

| Before   | After | Why                  |
|----------|-------|----------------------|
| `[0-9]`  | `\d`  | Shorthand is faster  |
| `(?:a)`  | `a`   | Unnecessary group    |
| `a{1}`   | `a`   | Redundant quantifier |
| `\x{61}` | `a`   | Unnecessary escape   |

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\OptimizerNodeVisitor;

$ast = Regex::create()->parse('/(?:foo)/');
$optimized = $ast->accept(new OptimizerNodeVisitor());

$pattern = $optimized->accept(new CompilerNodeVisitor());
echo $pattern;  // '/foo/'
```

---

### ModernizerNodeVisitor

**Purpose:** Converts legacy or verbose syntax to modern equivalents.

**Transformations:**

| Before         | After             |
|----------------|-------------------|
| `(?i)foo(?-i)` | `(?i:foo)`        |
| `(?:foo)`      | `foo` (when safe) |
| `\0`           | `\x{00}`          |

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\ModernizerNodeVisitor;

$ast = Regex::create()->parse('/(?i)foo/');
$modernized = $ast->accept(new ModernizerNodeVisitor());

$pattern = $modernized->accept(new CompilerNodeVisitor());
echo $pattern;  // Modernized version
```

---

## Validation and Linting Visitors

### ValidatorNodeVisitor

**Purpose:** Performs semantic validation of the pattern. Used internally by `Regex::validate()`.

**Checks Performed:**
- Valid backreference targets
- Lookbehind length constraints
- Balanced groups
- Valid group numbers and names

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\ValidatorNodeVisitor;

$ast = Regex::create()->parse('/\1(foo)/');  // Invalid: \1 before capture
$result = $ast->accept(new ValidatorNodeVisitor());

echo $result->isValid();      // false
echo count($result->getProblems());
```

---

### LinterNodeVisitor

**Purpose:** Checks for performance issues, anti-patterns, and readability problems. Used by CLI linter and PHPStan rule.

**Linting Rules:**

| Rule                   | Description               | Severity |
|------------------------|---------------------------|----------|
| `PossessiveQuantifier` | Use possessive quantifier | warning  |
| `UnnecessaryGroup`     | Remove unnecessary group  | info     |
| `AmbiguousEscape`      | Clarify ambiguous escape  | warning  |
| `ComplexPattern`       | Pattern is complex        | info     |

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\LinterNodeVisitor;

$ast = Regex::create()->parse('/(a+)+b/');  // Potential ReDoS risk
$result = $ast->accept(new LinterNodeVisitor());

foreach ($result->getIssues() as $issue) {
    echo $issue->getMessage() . "\n";
    // "Potential ReDoS risk (theoretical)"
}
```

---

### ReDoSAnalyzerNodeVisitor (Internal)

**Purpose:** Internal visitor used by `Regex::redos()` to classify ReDoS risk.

**Risk Levels:**

| Level      | Meaning                     | Action                 |
|------------|-----------------------------|------------------------|
| `safe`     | No exponential backtracking | Accept pattern         |
| `low`      | Minimal risk                | Accept with monitoring |
| `medium`   | Requires specific input     | Consider refactoring   |
| `critical` | High structural risk        | Review and refactor    |

```php
use RegexParser\Regex;

$analysis = Regex::create()->redos('/(a+)+b/');
echo $analysis->severity->value;     // 'critical'
echo $analysis->confidence->value;   // 'high'
```

---

### ComplexityScoreNodeVisitor

**Purpose:** Returns a numeric complexity score for a pattern. Useful for CI quality gates.

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\ComplexityScoreNodeVisitor;

$ast = Regex::create()->parse('/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/');
$score = $ast->accept(new ComplexityScoreNodeVisitor());

echo $score;  // e.g., 42
```

**Interpretation:**

| Score  | Complexity   |
|--------|--------------|
| 0-20   | Simple       |
| 21-50  | Moderate     |
| 51-100 | Complex      |
| 100+   | Very Complex |

---

### MetricsNodeVisitor

**Purpose:** Collects various metrics about the pattern structure.

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\MetricsNodeVisitor;

$ast = Regex::create()->parse('/\d{4}-\d{2}-\d{2}/');
$metrics = $ast->accept(new MetricsNodeVisitor());

echo $metrics->getTotalNodeCount();
echo $metrics->getQuantifierCount();
echo $metrics->getCaptureGroupCount();
```

**Available Metrics:**

| Method                   | Description                |
|--------------------------|----------------------------|
| `getTotalNodeCount()`    | Total nodes in AST         |
| `getQuantifierCount()`   | Number of quantifiers      |
| `getCaptureGroupCount()` | Number of capturing groups |
| `getAlternationCount()`  | Number of alternations     |
| `getMaxNestingDepth()`   | Maximum nesting depth      |

---

### LengthRangeNodeVisitor

**Purpose:** Computes the minimum and maximum length of the text a match consumes, as `[min, max]`, with `null` for
no upper bound. Lengths count bytes, or UTF-8 characters when the pattern is in UTF mode. `(*ACCEPT)` ends the match
early and lowers the minimum; `\K` only moves the start of the reported match and does not change the range.

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\LengthRangeNodeVisitor;

$ast = Regex::create()->parse('/a{2,4}b*/');
[$min, $max] = $ast->accept(new LengthRangeNodeVisitor());

echo $min;        // 2 (aa)
var_dump($max);   // NULL (unbounded)
```

---

## Extraction and Generation Visitors

### LiteralExtractorNodeVisitor

**Purpose:** Extracts fixed literals from the pattern, useful for optimization or indexing.

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\LiteralExtractorNodeVisitor;

$ast = Regex::create()->parse('/user-\d{4}/');
$literals = $ast->accept(new LiteralExtractorNodeVisitor());

echo $literals->getLiterals()[0];  // 'user-'
echo $literals->getPrefix();       // 'user-'
echo $literals->getSuffix();       // ''
```

---

### SampleGeneratorNodeVisitor

**Purpose:** Generates a sample string that matches the pattern. Used by `Regex::generate()`.

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\SampleGeneratorNodeVisitor;

$ast = Regex::create()->parse('/[A-Z][a-z]{3,5}\d{2}/');
$sample = $ast->accept(new SampleGeneratorNodeVisitor());

echo $sample;  // e.g., "Word12"
```

---

### TestCaseGeneratorNodeVisitor

**Purpose:** Generates test cases for the pattern, useful for QA tooling.

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\TestCaseGeneratorNodeVisitor;

$ast = Regex::create()->parse('/\d{3}-\d{4}/');
$cases = $ast->accept(new TestCaseGeneratorNodeVisitor());

print_r($cases);
/*
Array (
    [valid] => Array (
        [0] => 123-4567
        [1] => 000-0000
    )
    [invalid] => Array (
        [0] => 12-34567
        [1] => 1234-567
    )
)
*/
```

---

## Presentation and Visualization Visitors

### ExplainNodeVisitor

**Purpose:** Generates a plain-text explanation of what the pattern does. Used by `Regex::explain()`.

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\ExplainNodeVisitor;

$ast = Regex::create()->parse('/\d{3}-\d{4}/');
$explanation = $ast->accept(new ExplainNodeVisitor());

echo $explanation;
/*
Match exactly 3 digits, then hyphen, then exactly 4 digits.
*/
```

---

### HtmlExplainNodeVisitor

**Purpose:** Generates HTML explanation for use in documentation or web UIs.

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\HtmlExplainNodeVisitor;

$ast = Regex::create()->parse('/\w+@\w+\.\w+/');
$html = $ast->accept(new HtmlExplainNodeVisitor());

echo $html;
// <span class="regex-token regex-literal">...</span>
```

---

### DumperNodeVisitor

**Purpose:** Generates a debug-friendly AST dump. Useful for development and debugging.

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\DumperNodeVisitor;

$ast = Regex::create()->parse('/foo/');
$dump = $ast->accept(new DumperNodeVisitor());

echo $dump;
/*
RegexNode {
    delimiter: "/"
    pattern: SequenceNode {
        children: [
            LiteralNode {
                value: "foo"
            }
        ]
    }
    flags: ""
}
*/
```

---

### MermaidNodeVisitor

**Purpose:** Renders the AST as a Mermaid diagram for documentation or visualization.

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\MermaidNodeVisitor;

$ast = Regex::create()->parse('/a|b/');
$mermaid = $ast->accept(new MermaidNodeVisitor());

echo $mermaid;
/*
graph TD
    RegexNode
    RegexNode --> SequenceNode
    SequenceNode --> AlternationNode
    AlternationNode --> Sequence0
    AlternationNode --> Sequence1
*/
```

---

### AsciiTreeVisitor

**Purpose:** Renders a text-based tree of the AST for quick inspection.

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\AsciiTreeVisitor;

$ast = Regex::create()->parse('/^a+$/');
$tree = $ast->accept(new AsciiTreeVisitor());

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

### RailroadSvgVisitor

**Purpose:** Renders a railroad-style SVG diagram suitable for graphical output.

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\RailroadSvgVisitor;

$ast = Regex::create()->parse('/a|b/');
$svg = $ast->accept(new RailroadSvgVisitor());

echo $svg;
// <svg ...>...</svg>
```

---

### Highlighting Visitors

Base classes for syntax highlighting:

| Visitor                     | Output Format | Use Case   |
|-----------------------------|---------------|------------|
| `ConsoleHighlighterVisitor` | ANSI colors   | CLI output |
| `HtmlHighlighterVisitor`    | HTML spans    | Web output |

HTML spans include a base `regex-token` class plus semantic classes like `regex-escape`, `regex-group`, `regex-comment`, and `regex-backref` so themes can style them distinctly.

```php
use RegexParser\Regex;
use RegexParser\NodeVisitor\ConsoleHighlighterVisitor;

$ast = Regex::create()->parse('/\d+/');
$highlighted = $ast->accept(new ConsoleHighlighterVisitor());

echo $highlighted;
// "\033[38;2;78;201;176m\\d\033[0m\033[38;2;215;186;125m+\033[0m"
```

---

## Building Custom Visitors

### Pattern 1: Stateless Visitor (Returns a Value)

```php
use RegexParser\NodeVisitor\AbstractNodeVisitor;

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
use RegexParser\NodeVisitor\AbstractTraversingVisitor;

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

```php
use RegexParser\NodeVisitor\AbstractNodeVisitor;

class UppercaserVisitor extends AbstractNodeVisitor
{
    public function visitLiteral(Node\LiteralNode $node): Node\LiteralNode
    {
        return new Node\LiteralNode(
            strtoupper($node->value),
            $node->startPosition,
            $node->endPosition
        );
    }
}

// Usage: Transform /hello/ to /HELLO/
$ast = Regex::create()->parse('/hello/');
$visitor = new UppercaserVisitor();
$newAst = $ast->accept($visitor);

echo $newAst->accept(new CompilerNodeVisitor());  // '/HELLO/'
```

---

## Visitor Quick Reference

Compilation and transformation:
- `CompilerNodeVisitor` converts the AST to a pattern string.
- `OptimizerNodeVisitor` applies safe optimizations.
- `ModernizerNodeVisitor` modernizes legacy syntax.

Validation and linting:
- `ValidatorNodeVisitor` performs semantic validation.
- `LinterNodeVisitor` checks performance and readability.
- `ComplexityScoreNodeVisitor` computes a complexity score.
- `MetricsNodeVisitor` collects metrics.
- `LengthRangeNodeVisitor` estimates match length.

Extraction and generation:
- `LiteralExtractorNodeVisitor` extracts literals.
- `SampleGeneratorNodeVisitor` generates matching strings.
- `TestCaseGeneratorNodeVisitor` generates test cases.

Presentation and visualization:
- `ExplainNodeVisitor` provides plain-text explanations.
- `HtmlExplainNodeVisitor` provides HTML explanations.
- `DumperNodeVisitor` dumps the AST for debugging.
- `MermaidNodeVisitor` builds Mermaid diagrams.
- `AsciiTreeVisitor` prints an ASCII tree.
- `RailroadSvgVisitor` renders railroad SVGs.
- `ConsoleHighlighterVisitor` outputs ANSI highlighting.
- `HtmlHighlighterVisitor` outputs HTML highlighting.

---

## Common Use Cases

| Use Case                 | Visitor(s)                               |
|--------------------------|------------------------------------------|
| Check pattern validity   | `ValidatorNodeVisitor`                   |
| Check for ReDoS          | `Regex::redos()` (uses internal visitor) |
| Optimize pattern         | `OptimizerNodeVisitor`                   |
| Explain pattern to users | `ExplainNodeVisitor`                     |
| Generate test cases      | `TestCaseGeneratorNodeVisitor`           |
| Count groups/metrics     | `MetricsNodeVisitor`                     |
| Visualize AST            | `MermaidNodeVisitor`, `AsciiTreeVisitor`, `RailroadSvgVisitor` |
| Highlight in CLI         | `ConsoleHighlighterVisitor`              |
| Round-trip parsing       | `CompilerNodeVisitor`                    |

---

## Summary

| Category  | Key Visitors                                                 |
|-----------|--------------------------------------------------------------|
| Base      | `NodeVisitorInterface`, `AbstractTraversingVisitor`, `AbstractNodeVisitor` |
| Compile   | `CompilerNodeVisitor`, `OptimizerNodeVisitor`                |
| Validate  | `ValidatorNodeVisitor`, `LinterNodeVisitor`                  |
| Analyze   | `ComplexityScoreNodeVisitor`, `MetricsNodeVisitor`           |
| Generate  | `SampleGeneratorNodeVisitor`, `TestCaseGeneratorNodeVisitor` |
| Visualize | `ExplainNodeVisitor`, `MermaidNodeVisitor`, `AsciiTreeVisitor`, `RailroadSvgVisitor` |

---

Previous: [AST Nodes](../nodes/README.md) | Next: [Docs Home](../README.md)

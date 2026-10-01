# AST Traversal Design

Understanding how PhpRegex walks through the Abstract Syntax Tree (AST) is essential for building custom visitors, debugging traversal issues, or extending the library's analysis capabilities.

## The Tour Guide Analogy

Think of the AST as a museum floor plan and the visitor as a tour guide. The visitor follows a fixed path through each room (node), visiting every exhibit (child node) in order. The guide does not change the layout; it observes, records, or transforms as needed.

## Why Use the Visitor Pattern?

PhpRegex separates **data** (nodes) from **behavior** (visitors). This separation provides three key benefits:

| Concern         | Without Visitor Pattern | With Visitor Pattern   |
|-----------------|-------------------------|------------------------|
| Adding analysis | Modify every node class | Add one new visitor    |
| Testing         | Complex node setup      | Isolated visitor tests |
| Stability       | Breaking changes ripple | Nodes stay unchanged   |

### The Core Principle

> Nodes are immutable data holders. Visitors implement behavior.

This means you can add a dozen new analysis algorithms without touching a single node class. The AST structure remains stable, while visitors evolve independently.

## How Double Dispatch Works

Every node implements an `accept()` method that receives a visitor. This is called **double dispatch** because the actual method called depends on both:

1. The **runtime type** of the node (which node class it is)
2. The **runtime type** of the visitor (which visitor class it is)

```php
use PhpRegex\Toolkit\Regex;
use PhpRegex\Explain\TextExplainer;

$regex = Regex::create();
$ast = $regex->parse('/foo|bar/');

// Double dispatch in action:
// 1. RegexNode::accept(TextExplainer)
// 2. Calls $visitor->visitRegex($this)
// 3. Which recursively calls accept on children
$result = $ast->accept(new TextExplainer());

echo $result;
/*
Output:
AlternationNode
├── Alternative 1 (SequenceNode)
│   └── LiteralNode("foo")
└── Alternative 2 (SequenceNode)
    └── LiteralNode("bar")
*/
```

### What happens internally

1. `$ast->accept($visitor)` starts the traversal.
2. `RegexNode::accept()` calls `$visitor->visitRegex($this)`.
3. The visitor delegates to `$this->pattern->accept($visitor)`.
4. Each node repeats this pattern and controls how children are visited.

## Traversal strategies

PhpRegex uses depth-first traversal with explicit control in the visitor. Typical delegation:

- `RegexNode` delegates to `pattern`.
- `SequenceNode` iterates children left-to-right.
- `AlternationNode` iterates alternatives in order.
- `GroupNode` delegates to `child`.
- `QuantifierNode` delegates to the repeated node.

### Example: Tracking Depth

```php
use PhpRegex\Toolkit\Regex;
use PhpRegex\Parser\AbstractNodeVisitor;

$regex = Regex::create();
$ast = $regex->parse('/(a(b(c)))+/');

class DepthTrackingVisitor extends AbstractNodeVisitor
{
    private int $maxDepth = 0;
    private int $currentDepth = 0;

    public function visitSequence(Node\SequenceNode $node): Node\SequenceNode
    {
        $this->currentDepth++;
        $this->maxDepth = max($this->maxDepth, $this->currentDepth);
        
        $children = [];
        foreach ($node->children as $child) {
            $children[] = $child->accept($this);
        }
        
        $this->currentDepth--;
        return new Node\SequenceNode($children, $node->startPosition, $node->endPosition);
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }
}

$visitor = new DepthTrackingVisitor();
$ast->accept($visitor);
echo "Maximum nesting depth: " . $visitor->getMaxDepth(); // Output: 3
```

## Return Types: Stateless vs Stateful

Visitors typically follow one of two patterns:

### Pattern 1: Stateless (Returns a Value)

```php
// PatternPrinter - returns a string
$pattern = $ast->accept(new PatternPrinter());
```

### Pattern 2: Stateful (Accumulates and Returns Result)

```php
// MetricsCollector - stores internal state
$visitor = new MetricsCollector();
$ast->accept($visitor);
$metrics = $visitor->getMetrics(); // Get accumulated data
```

| Pattern   | Use Case                       | Example Visitor                              |
|-----------|--------------------------------|----------------------------------------------|
| Stateless | Transformation, compilation    | `PatternPrinter`                        |
| Stateful  | Metrics collection, validation | `MetricsCollector`, `Validator` |

### Choosing a base class

Two base classes give every `visitX()` method a default, so a visitor that
extends one keeps working when a minor release adds a node type:

- `AbstractTraversingVisitor` visits the children of every node, in pattern
  order, and returns `null`. Extend it to collect or check something wherever
  it stands: override the node types you care about and call the parent
  method to keep descending (return without calling it to skip the subtree).
  A node type added later is walked through, so the nodes below it still
  reach your overrides.
- `AbstractNodeVisitor` returns the default value (`null`, or what
  `defaultReturn()` gives) and visits no children. Extend it when each method
  computes the node's value and you choose which children to visit, as the
  compiler does. A node you do not override hides its subtree, a node type
  added later included.

```php
use PhpRegex\Parser\Node;
use PhpRegex\Parser\AbstractTraversingVisitor;

class OnlyLiteralVisitor extends AbstractTraversingVisitor
{
    private array $literals = [];

    public function visitLiteral(Node\LiteralNode $node)
    {
        $this->literals[] = $node->value;

        return parent::visitLiteral($node);
    }

    public function getLiterals(): array
    {
        return $this->literals;
    }
}
```

## Transformations: Creating New Nodes

When building a visitor that transforms the AST (like the optimizer), you must:

1. **Never mutate existing nodes** — they are `readonly`
2. **Preserve source positions** — keep `startPosition` and `endPosition`
3. **Return new node instances** — the transformer pattern

```php
class OptimizingVisitor extends AbstractNodeVisitor
{
    public function visitSequence(Node\SequenceNode $node): Node\SequenceNode
    {
        $optimized = [];
        foreach ($node->children as $child) {
            $optimizedChild = $child->accept($this);
            if ($optimizedChild !== null) {
                $optimized[] = $optimizedChild;
            }
        }
        
        return new Node\SequenceNode(
            $optimized,
            $node->startPosition,  // Preserve original position
            $node->endPosition     // Preserve original position
        );
    }
}
```

### Why Preserve Positions?

Source positions are used for:
- **Error reporting** — showing where syntax errors occurred
- **IDE integration** — highlighting the relevant code
- **Refactoring tools** — mapping changes back to source

If you create new nodes without preserving positions, diagnostics will report wrong locations.

## Common Errors and Pitfalls

### Error 1: Forgetting to Return the Node

```php
// WRONG - loses the transformed node
public function visitSequence(Node\SequenceNode $node): Node\SequenceNode
{
    foreach ($node->children as $child) {
        $child->accept($this);
    }
    // Missing return!
}

// RIGHT - returns the new node
public function visitSequence(Node\SequenceNode $node): Node\SequenceNode
{
    $newChildren = [];
    foreach ($node->children as $child) {
        $newChildren[] = $child->accept($this);
    }
    return new Node\SequenceNode($newChildren, $node->startPosition, $node->endPosition);
}
```

### Error 2: Infinite Recursion on Unhandled Nodes

```php
// WRONG - visits parent node again, causing infinite loop
public function visitGroup(Node\GroupNode $node): Node\GroupNode
{
    return $node->accept($this);  // Calls visitGroup again!
}

// RIGHT - visits the child, not the group itself
public function visitGroup(Node\GroupNode $node): Node\GroupNode
{
    return new Node\GroupNode(
        $node->child->accept($this),  // Visit child, not group
        $node->type,
        $node->name
    );
}
```

### Error 3: Not Handling All Node Types

```php
// WRONG - crashes when visiting unhandled node types
public function visitSequence(Node\SequenceNode $node): Node\SequenceNode
{
    return new Node\SequenceNode(
        array_map(fn($c) => $c->accept($this), $node->children)
    );
}

// RIGHT - AbstractNodeVisitor returns $node for unhandled types
// Or explicitly handle all expected types
```

### Error 4: Mixing Up Child Iteration Order

```php
// Pattern: /ab|cd/
// AlternationNode has two alternatives:
//   alt[0] = SequenceNode([LiteralNode("a"), LiteralNode("b")])
//   alt[1] = SequenceNode([LiteralNode("c"), LiteralNode("d")])

// When iterating, always process alternatives in order
foreach ($node->alternatives as $index => $alternative) {
    // alt[0] is "ab", alt[1] is "cd"
}
```

## Best Practices Checklist

- Use `AbstractTraversingVisitor` for partial implementations that must see every node, `AbstractNodeVisitor` for those that drive the traversal themselves.
- Always return a node from visit methods (even if unchanged).
- Preserve source positions when creating new nodes.
- Delegate to `child->accept($this)`, not `$this->accept($node)`.
- Keep visitors pure when possible for testability.
- Guard against deep recursion with max-depth checks.
- Handle all node types or inherit safe defaults.

## Performance Considerations

For large patterns or adversarial input:

```php
class SafeVisitor extends AbstractNodeVisitor
{
    private const MAX_DEPTH = 100;
    private int $currentDepth = 0;

    public function beforeTraversal(Node\RegexNode $node): void
    {
        // Reset state
        $this->currentDepth = 0;
    }

    public function enterNode(Node\NodeInterface $node): void
    {
        $this->currentDepth++;
        if ($this->currentDepth > self::MAX_DEPTH) {
            throw new \RuntimeException('Maximum traversal depth exceeded');
        }
    }

    public function leaveNode(Node\NodeInterface $node): void
    {
        $this->currentDepth--;
    }
}
```

## Related Documentation

| Topic                 | File                                           |
|-----------------------|------------------------------------------------|
| AST Node Reference    | [nodes](../nodes/README.md)                    |
| AST Visitor Reference | [visitors](../visitors/README.md)              |
| Architecture Overview | [ARCHITURE](../ARCHITECTURE.md)                |
| Tutorial: Basics      | [tutorial/01-basics](../tutorial/01-basics.md) |

---

## Exercises

### Exercise 1: Count the Groups

Write a visitor that counts all capturing groups in a pattern.

**Hint:** Look for `GroupNode` with type `T_GROUP_CAPTURING` or `T_GROUP_NAMED`.

```php
// Starter code:
class GroupCountingVisitor extends AbstractNodeVisitor
{
    // Your code here
}

// Test:
$ast = Regex::create()->parse('/(a)(?:b)(?<name>c)(d)/');
$visitor = new GroupCountingVisitor();
$ast->accept($visitor);
// Should output: 3 capturing groups
```

### Exercise 2: Find Deepest Quantifier

Write a visitor that finds the quantifier with the deepest nesting.

**Hint:** Track depth while traversing, and record the deepest quantifier's position.

### Exercise 3: Extract All Anchors

Write a visitor that extracts all anchors (`^`, `$`, `\b`, `\B`, `(?=...)`, etc.) in order.

**Hint:** Collect anchors during traversal and return an ordered list.

---

## Summary

| Concept               | Key Point                                          |
|-----------------------|----------------------------------------------------|
| Visitor Pattern       | Separates data (nodes) from behavior (visitors)    |
| Double Dispatch       | Both node and visitor types determine behavior     |
| Depth-First           | Visits children before siblings (for most nodes)   |
| Stateless vs Stateful | Return values vs accumulate in properties          |
| Transformations       | Create new nodes, preserve positions               |
| Best Practices        | Return nodes, preserve positions, handle all types |

---

Previous: [Architecture](../ARCHITECTURE.md) | Next: [External References](../references/README.md)
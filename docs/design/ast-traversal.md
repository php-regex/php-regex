---
description: "How PHPRegex walks the AST: double dispatch, choosing a visitor base class, transformations that preserve positions, and guards against deep recursion."
redirect_from:
  - /design/AST_TRAVERSAL/
  - /design/AST_TRAVERSAL.html
---
# AST Traversal Design

Understanding how PHPRegex walks through the Abstract Syntax Tree (AST) is essential for building custom visitors, debugging traversal issues, or extending the library's analysis capabilities. The walk is a fixed route: each node hands the visitor its children in pattern order, and the visitor observes, records, or transforms as it goes.

## Why Use the Visitor Pattern?

PHPRegex separates **data** (nodes) from **behavior** (visitors). This separation provides three key benefits:

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
use PHPRegex\Toolkit\Regex;
use PHPRegex\Explain\TextExplainer;

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
Regex matches
    EITHER
      'f'
      'o'
      'o'
    OR
      'b'
      'a'
      'r'
*/
```

### What happens internally

1. `$ast->accept($visitor)` starts the traversal.
2. `RegexNode::accept()` calls `$visitor->visitRegex($this)`.
3. The visitor delegates to `$this->pattern->accept($visitor)`.
4. Each node repeats this pattern and controls how children are visited.

## Traversal strategies

PHPRegex uses depth-first traversal with explicit control in the visitor. Typical delegation:

- `RegexNode` delegates to `pattern`.
- `SequenceNode` iterates children left-to-right.
- `AlternationNode` iterates alternatives in order.
- `GroupNode` delegates to `child`.
- `QuantifierNode` delegates to the repeated node.

### Example: Tracking Depth

```php
use PHPRegex\Toolkit\Regex;
use PHPRegex\Parser\AbstractTraversingVisitor;
use PHPRegex\Parser\Node;

$regex = Regex::create();
$ast = $regex->parse('/(a(b(c)))+/');

class DepthTrackingVisitor extends AbstractTraversingVisitor
{
    private int $maxDepth = 0;
    private int $currentDepth = 0;

    public function visitGroup(Node\GroupNode $node)
    {
        $this->currentDepth++;
        $this->maxDepth = max($this->maxDepth, $this->currentDepth);

        parent::visitGroup($node);  // keep descending into the group

        $this->currentDepth--;
    }

    public function getMaxDepth(): int
    {
        return $this->maxDepth;
    }
}

$visitor = new DepthTrackingVisitor();
$ast->accept($visitor);
echo 'Maximum nesting depth: ' . $visitor->getMaxDepth(); // Output: Maximum nesting depth: 3
```

The base class does the walking: `AbstractTraversingVisitor` visits every
node's children in pattern order, so the override only counts. Extending
`AbstractNodeVisitor` instead would mean writing the descent by hand — and a
node type you do not override then hides its subtree, which is why a visitor
like this one built on that base reported a depth of 0 for every pattern.

## Return Types: Stateless vs Stateful

Visitors typically follow one of two patterns:

### Pattern 1: Stateless (Returns a Value)

```php
// PatternPrinter - returns a string
$pattern = $ast->accept(new PatternPrinter());
```

### Pattern 2: Stateful (Accumulates and Returns Result)

```php
// MetricsCollector - accumulates counts while it walks the tree
$metrics = Regex::create()->parse('/foo|bar/')->accept(new MetricsCollector());
// [
//     'counts' => ['RegexNode' => 1, 'AlternationNode' => 1, 'SequenceNode' => 2, 'LiteralNode' => 6],
//     'total' => 10,
//     'maxDepth' => 4,
// ]
```

| Pattern   | Use Case                       | Example Visitor                              |
|-----------|--------------------------------|----------------------------------------------|
| Stateless | Transformation, compilation    | `PatternPrinter`                        |
| Stateful  | Metrics collection, validation | `MetricsCollector`, `Validator` |

### Choosing a base class

Two base classes give every `visitX()` method a default, so a visitor that
extends one keeps working when a minor release adds a node type:
`AbstractTraversingVisitor` walks every node's children in pattern order,
`AbstractNodeVisitor` returns a default value and visits none, leaving the
descent to you. The [visitor reference](../visitors/README.md#which-base-to-extend)
shows both side by side, with the example each one fits.

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
        $node->name,
        $node->flags,             // keep inline flags: dropping them
                                   // would change what the group means
        $node->startPosition,     // preserve source positions
        $node->endPosition
    );
}
```

### Error 3: Not Handling All Node Types

```php
// WRONG - loses the positions, and a child of an unhandled type
// maps to the default (null) inside the new sequence
public function visitSequence(Node\SequenceNode $node): Node\SequenceNode
{
    return new Node\SequenceNode(
        array_map(fn($c) => $c->accept($this), $node->children)
    );
}

// RIGHT - AbstractNodeVisitor returns defaultReturn() (null by default)
// for unhandled types: handle every type you will meet, or override
// defaultReturn(), or extend AbstractTraversingVisitor to keep descending
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

For large patterns or adversarial input, bound the depth of the walk. With
`NodeWalker`, the callback receives the ancestors of every node, so the depth
check is one `count()`:

```php
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\NodeWalker;
use PHPRegex\Parser\TraversalAction;

NodeWalker::walk($ast, static function (NodeInterface $node, array $ancestors): ?TraversalAction {
    // $ancestors holds the nodes from the root down to $node's parent:
    // its length is the depth of $node.
    if (count($ancestors) > 100) {
        return TraversalAction::Stop;  // abandon the walk, keep what was collected
    }

    return null;  // keep walking; SkipChildren would prune this subtree
});
```

Inside a visitor, the same guard is a depth counter maintained in an
overridden `visitX()` that calls the parent method — the shape
`DepthTrackingVisitor` uses above.

## Related Documentation

| Topic                 | File                                           |
|-----------------------|------------------------------------------------|
| AST Node Reference    | [nodes](../nodes/README.md)                    |
| AST Visitor Reference | [visitors](../visitors/README.md)              |
| Architecture Overview | [Architecture](../architecture.md)            |
| Tutorial: Basics      | [tutorial/01-basics](../tutorial/01-basics.md) |

---

## Practice

The [visitor reference](../visitors/README.md#building-custom-visitors) walks
through working visitors that do what this page preaches — `GroupCollectorVisitor`
collects named groups, `LiteralCollector` gathers literals, `UppercaserVisitor`
transforms nodes — and the [cookbook](../cookbook.md) holds complete patterns
to run them on.

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

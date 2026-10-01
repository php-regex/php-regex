# Extending PhpRegex

This guide shows how to add new PCRE features, build custom visitors, and integrate PhpRegex into tools.

---

## What You Can Extend

**PhpRegex** is designed for extensibility:

- New AST nodes for new syntax
- New visitors for analysis or transformation
- Custom lint rules
- Framework integrations
- CLI commands

---

## Extension Architecture

PhpRegex core extension points:
- Nodes (`src/Parser/Node/`) for new node types.
- Visitors (`src/Parser/AbstractNodeVisitor.php` and its subclasses in each package) for new analyses or transforms.
- Parser (`src/Parser/Syntax/TokenParser.php`) to recognize new syntax.
- CLI (`src/Cli/`) for new commands.

---

## Step 1: Add a New Node

Create a node class for your new PCRE feature:

**Location:** `src/Parser/Node/YourFeatureNode.php`

```php
<?php

declare(strict_types=1);

namespace PhpRegex\Parser\Node;

use PhpRegex\Parser\NodeVisitorInterface;

/**
 * Represents your new PCRE feature.
 *
 * Example: (?C) or (?C99) callouts
 */
readonly class CalloutNode extends AbstractNode
{
    /**
     * @param int|null $number Callout number (null for (?C))
     * @param int      $startPos 0-based start offset
     * @param int      $endPos 0-based end offset (exclusive)
     */
    public function __construct(
        public ?int $number,
        int $startPos,
        int $endPos,
    ) {
        parent::__construct($startPos, $endPos);
    }

    public function accept(NodeVisitorInterface $visitor): mixed
    {
        return $visitor->visitCallout($this);
    }
}
```

### Checklist for New Nodes

- [ ] Extend `AbstractNode`
- [ ] Implement `NodeInterface` (via `accept()`)
- [ ] Add public readonly properties
- [ ] Call `parent::__construct($startPos, $endPos)`

---

## Step 2: Update the Parser

Add parsing logic in `src/Parser/Syntax/TokenParser.php`:

```php
// In the parseGroup() method, add your feature

private function parseGroup(int $startPosition): NodeInterface
{
    // ... existing code ...

    if ($this->isNextToken('T_GROUP_OPEN')) {
        // Check for callout pattern: (?C) or (?C<number>)
        if ($this->isNextToken('T_PCRE_VERB') && str_starts_with($this->currentToken->value, '(*CALLOUT')) {
            return $this->parseCallout($startPosition);
        }
    }

    // ... continue ...
}

private function parseCallout(int $startPosition): CalloutNode
{
    // Consume the callout token
    $this->consumeToken();

    // Parse callout number if present
    $number = null;
    if ($this->isNextToken('T_NUMBER')) {
        $number = (int) $this->currentToken->value;
        $this->consumeToken();
    }

    // Expect closing parenthesis
    $this->consumeToken('T_GROUP_CLOSE');

    return new CalloutNode(
        $number,
        $startPosition,
        $this->currentToken->endPosition,
    );
}
```

---

## Step 3: Update Visitors

### Add Method to NodeVisitorInterface

**Location:** `src/Parser/NodeVisitorInterface.php`

```php
public function visitCallout(CalloutNode $node): mixed;
```

### Add Default to AbstractNodeVisitor

**Location:** `src/Parser/AbstractNodeVisitor.php`

```php
public function visitCallout(CalloutNode $node): mixed
{
    return $this->defaultReturn();
}
```

### Add Descent to AbstractTraversingVisitor

**Location:** `src/Parser/AbstractTraversingVisitor.php`

The method visits the node's children through `getChildren()`, so a visitor
that extends this base still reaches the nodes below the new one:

```php
public function visitCallout(CalloutNode $node)
{
    return $this->traverse($node);
}
```

A node that holds other nodes returns them from `getChildren()`, in the order
they stand in the pattern.

### Implement in Your Visitor

**Location:** Your custom visitor class

```php
public function visitCallout(CalloutNode $node): string
{
    return $node->number !== null
        ? "(?C{$node->number})"
        : "(?C)";
}
```

---

## Example: Custom Visitor

### Create a Pattern Length Visitor

```php
<?php

declare(strict_types=1);

namespace App\Regex;

use PhpRegex\Parser\Node\AlternationNode;
use PhpRegex\Parser\Node\AnchorNode;
use PhpRegex\Parser\Node\AssertionNode;
use PhpRegex\Parser\Node\CharClassNode;
use PhpRegex\Parser\Node\CharTypeNode;
use PhpRegex\Parser\Node\DotNode;
use PhpRegex\Parser\Node\GroupNode;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\NodeInterface;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\AbstractNodeVisitor;

/**
 * Calculates pattern complexity score.
 */
final class ComplexityVisitor extends AbstractNodeVisitor
{
    private int $score = 0;

    public function getScore(): int
    {
        return $this->score;
    }

    public function visitRegex(RegexNode $node): int
    {
        $this->score = 0;
        $node->pattern->accept($this);

        // Add base score for flags
        $this->score += strlen($node->flags);

        return $this->score;
    }

    public function visitSequence(SequenceNode $node): int
    {
        foreach ($node->children as $child) {
            $child->accept($this);
        }

        return $this->score;
    }

    public function visitAlternation(AlternationNode $node): int
    {
        // Alternations increase complexity
        $this->score += 10;

        foreach ($node->alternatives as $alternative) {
            $alternative->accept($this);
        }

        return $this->score;
    }

    public function visitQuantifier(QuantifierNode $node): int
    {
        // Nested quantifiers increase complexity
        $this->score += 5;

        $node->node->accept($this);

        return $this->score;
    }

    public function visitGroup(GroupNode $node): int
    {
        $node->child->accept($this);

        return $this->score;
    }

    public function visitLiteral(LiteralNode $node): int
    {
        $this->score += strlen($node->value);

        return $this->score;
    }

    public function visitCharClass(CharClassNode $node): int
    {
        $this->score += 5;

        return $this->score;
    }

    // Add other visit methods as needed...
}
```

### Use Your Visitor

```php
use App\Regex\ComplexityVisitor;
use PhpRegex\Toolkit\Regex;

$regex = Regex::create();
$ast = $regex->parse('/^(?:[a-z]+|\d{3,})+$/');

$visitor = new ComplexityVisitor();
$ast->accept($visitor);

echo "Complexity: " . $visitor->getScore();  // Output: Complexity: 25
```

---

## Step 4: Add Tests

Create tests for your extension:

**Location:** `tests/Unit/Node/CalloutNodeTest.php`

```php
<?php

declare(strict_types=1);

namespace PhpRegex\Tests\Unit\Node;

use PHPUnit\Framework\TestCase;
use PhpRegex\Parser\Node\CalloutNode;

class CalloutNodeTest extends TestCase
{
    public function testCreateWithNumber(): void
    {
        $node = new CalloutNode(42, 0, 10);

        $this->assertSame(42, $node->number);
        $this->assertSame(0, $node->startPosition);
        $this->assertSame(10, $node->endPosition);
    }

    public function testCreateWithoutNumber(): void
    {
        $node = new CalloutNode(null, 0, 5);

        $this->assertNull($node->number);
    }
}
```

---

## Step 5: Update Documentation

Add your new feature to:

1. **[AST Nodes](../nodes/README.md)** - Document the node
2. **[Visitor Reference](../visitors/README.md)** - Document the visitor method
3. **Tutorial** - Add examples if it's a user-facing feature
4. **README** - Update feature list

---

## Common Extension Patterns

### Pattern 1: Custom Lint Rule

```php
class YourLinterRule extends AbstractNodeVisitor
{
    /** @var array<int, string> */
    private array $issues = [];

    public function visitQuantifier(QuantifierNode $node): void
    {
        if ($node->quantifier === '*') {
            $this->issues[] = "Avoid * quantifier - use + or bounded {0,n}";
        }

        $node->node->accept($this);
    }

    public function getIssues(): array
    {
        return $this->issues;
    }
}
```

### Pattern 2: Pattern Transformation

```php
class YourTransformer extends AbstractNodeVisitor
{
    public function visitLiteral(LiteralNode $node): NodeInterface
    {
        // Transform: lowercase to uppercase
        return new LiteralNode(
            strtoupper($node->value),
            $node->startPosition,
            $node->endPosition
        );
    }
}
```

### Pattern 3: Custom Analysis

```php
class YourAnalyzer extends AbstractNodeVisitor
{
    /** @var array<string, int> */
    private array $counts = [];

    public function getCounts(): array
    {
        return $this->counts;
    }

    public function visitGroup(GroupNode $node): void
    {
        $this->counts['groups'] = ($this->counts['groups'] ?? 0) + 1;
        $node->child->accept($this);
    }
}
```

---

## Troubleshooting

### "Node not recognized"

Make sure you:
1. Created the node class
2. Updated the parser to recognize it
3. Added the visitor method

### "Visitor method not called"

Check:
1. Node's `accept()` calls the matching `visitXxx()` method on the visitor
2. Visitor implements the method
3. Visitor is registered/used correctly

### "Parse error"

Verify parser logic:
1. Token type check is correct
2. Token consumption order is right
3. Error handling for missing tokens

---

## Best Practices

1. **Immutability** - Nodes should be readonly
2. **Position tracking** - Preserve start/end positions
3. **Error handling** - Throw meaningful exceptions
4. **Testing** - Cover edge cases
5. **Documentation** - Explain the feature clearly

---

## Learning Resources

- **[AST Nodes](../nodes/README.md)** - Node reference
- **[Visitors](../visitors/README.md)** - Visitor patterns
- **[AST Traversal](../design/AST_TRAVERSAL.md)** - Traversal design
- **Source code** - Learn from existing implementations

---

## Next Steps

1. Pick a simple feature to implement
2. Follow the steps above
3. Write tests
4. Update documentation
5. Submit a PR!

---

End of extending guide.

---

Previous: [Architecture](../ARCHITECTURE.md) | Next: [Maintainers Guide](MAINTAINERS_GUIDE.md)
# Understanding Visitors

The **Visitor Pattern** is a common design pattern that allows you to add new operations to objects without changing their structure. In PHPRegex, visitors process the AST to perform analyses and transformations.

## Simple explanation

Imagine you have an AST (tree structure) and you want to do different things with it:

- **Explain** the pattern in plain English
- **Validate** the pattern for errors
- **Highlight** the pattern with colors
- **Analyze** for potential ReDoS risk

Instead of putting all this logic in the AST nodes themselves, we use **visitors** that "walk" the tree and perform specific tasks.

## How visitors work

### Basic Visitor Structure

```php
class MyCustomVisitor extends AbstractNodeVisitor
{
    public function visitRegex(RegexNode $node): void
    {
        $node->pattern->accept($this);
    }

    public function visitLiteral(LiteralNode $node): void
    {
        echo "Found literal: " . $node->value;
    }

    public function visitQuantifier(QuantifierNode $node): void
    {
        echo "Found quantifier: " . $node->quantifier;
        $node->node->accept($this);
    }
}
```

### Using a Visitor

```php
$regex = Regex::create();
$ast = $regex->parse('/hello\d+/');

$visitor = new MyCustomVisitor();
$ast->accept($visitor); // Start the traversal
```

## Built-in visitors

PHPRegex includes several useful visitors:

### 1. PatternPrinter
```php
use PHPRegex\Parser\Printer\PatternPrinter;

$compiler = new PatternPrinter();
$pattern = $ast->accept($compiler); // Regenerate the pattern
```

### 2. TextExplainer
```php
use PHPRegex\Explain\TextExplainer;

$explainer = new TextExplainer();
$explanation = $ast->accept($explainer); // Get plain English explanation
```

### 3. Highlighting Visitors
```php
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Explain\Highlighter\HtmlHighlighter;

$consoleHighlighter = new ConsoleHighlighter();
$htmlHighlighter = new HtmlHighlighter();

$consoleOutput = $ast->accept($consoleHighlighter);
$htmlOutput = $ast->accept($htmlHighlighter);
```

## Creating custom visitors

### Step 1: Extend a base class

Extend `AbstractTraversingVisitor` to act on some node types wherever they
stand: every node you do not override has its children visited. Call the
parent method to keep descending below a node you override.

```php
use PHPRegex\Parser\AbstractTraversingVisitor;
use PHPRegex\Parser\Node;

class QuantifierCounter extends AbstractTraversingVisitor
{
    private int $count = 0;

    public function getCount(): int
    {
        return $this->count;
    }

    public function visitQuantifier(Node\QuantifierNode $node)
    {
        $this->count++;

        return parent::visitQuantifier($node); // Continue traversal
    }
}
```

Extend `AbstractNodeVisitor` instead when each method returns the node's
value and you choose which children to visit: a node you do not override
returns `null` and its children are not visited.

### Step 2: Use Your Visitor

```php
$regex = Regex::create();
$ast = $regex->parse('/a+b*c?/');

$counter = new QuantifierCounter();
$ast->accept($counter);

echo "Quantifiers found: " . $counter->getCount(); // "3"
```

## Real-world examples

### Example 1: Pattern Complexity Analyzer

```php
class ComplexityAnalyzer extends AbstractNodeVisitor
{
    private int $complexityScore = 0;

    public function visitQuantifier(Node\QuantifierNode $node): void
    {
        $this->complexityScore += 2; // Each quantifier adds complexity
        $node->node->accept($this);
    }

    public function visitGroup(Node\GroupNode $node): void
    {
        $this->complexityScore += 3; // Groups are more complex
        $node->child->accept($this);
    }

    public function getComplexityScore(): int
    {
        return $this->complexityScore;
    }
}
```

### Example 2: Named group collector

```php
class GroupNameCollector extends AbstractNodeVisitor
{
    private array $names = [];

    public function visitGroup(Node\GroupNode $node): void
    {
        if ($node->name !== null) {
            $this->names[] = $node->name;
        }
        $node->child->accept($this);
    }

    public function getNames(): array
    {
        return $this->names;
    }
}
```

## Related concepts

- **[What is an AST?](ast.md)** - The structure visitors process
- **[Architecture](../architecture.md)** - How visitors fit into the system
- **[Visitors Reference](../visitors/README.md)** - List of built-in visitors

## Further reading

- [Visitor Pattern (Wikipedia)](https://en.wikipedia.org/wiki/Visitor_pattern) - Design pattern explanation
- [PHPRegex Architecture](../architecture.md) - Technical implementation details
- [Extending Guide](../extending.md) - Building custom tools

---

Previous: [What is an AST?](ast.md) | Next: [ReDoS Deep Dive](redos.md)

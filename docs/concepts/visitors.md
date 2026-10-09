---
description: "The visitor pattern in PHPRegex: how accept() dispatches work, which base class to extend, and working collectors you can copy and run."
---
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
use PHPRegex\Parser\AbstractTraversingVisitor;
use PHPRegex\Parser\Node;

class MyCustomVisitor extends AbstractTraversingVisitor
{
    public function visitLiteral(Node\LiteralNode $node)
    {
        echo "Found literal: " . $node->value . "\n";

        return parent::visitLiteral($node); // Continue traversal
    }

    public function visitQuantifier(Node\QuantifierNode $node)
    {
        echo "Found quantifier: " . $node->quantifier . "\n";

        return parent::visitQuantifier($node); // Continue traversal
    }
}
```

Every node you do not override has its children visited, so this visitor sees the whole tree. The `parent::visit*()` call is what keeps the descent going below a node you override - forget it and nothing below that node is reached.

### Using a Visitor

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
$ast = $regex->parse('/hello\d+/');

$visitor = new MyCustomVisitor();
$ast->accept($visitor); // Start the traversal
```

Running the two blocks above prints:

```
Found literal: h
Found literal: e
Found literal: l
Found literal: l
Found literal: o
Found quantifier: +
```

One line per node - remember the parser emits [one `LiteralNode` per character](ast.md).

## Built-in visitors

PHPRegex includes several useful visitors:

### 1. PatternPrinter
```php
use PHPRegex\Parser\Printer\PatternPrinter;

$compiler = new PatternPrinter();
$pattern = $ast->accept($compiler); // Regenerate the pattern: '/hello\d+/'
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

The full catalogue - validation, linting, rewriting, metrics, generation, rendering - lives in the [Visitors Reference](../visitors/README.md).

## Creating custom visitors

### Step 1: Extend a base class

PHPRegex ships two bases: `AbstractTraversingVisitor`, which walks the whole tree, and `AbstractNodeVisitor`, where each method returns the node's value and you choose which children to visit. The [Visitors Reference](../visitors/README.md#which-base-to-extend) tabulates which one fits which goal; a collector that must see every node of its kind takes the traversing base:

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

### Step 2: Use Your Visitor

```php
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
$ast = $regex->parse('/a+b*c?/');

$counter = new QuantifierCounter();
$ast->accept($counter);

echo "Quantifiers found: " . $counter->getCount(); // "3"
```

## Real-world examples

### Example 1: Pattern complexity analyzer

```php
use PHPRegex\Parser\AbstractTraversingVisitor;
use PHPRegex\Parser\Node;
use PHPRegex\Toolkit\Regex;

class ComplexityAnalyzer extends AbstractTraversingVisitor
{
    private int $complexityScore = 0;

    public function visitQuantifier(Node\QuantifierNode $node)
    {
        $this->complexityScore += 2; // Each quantifier adds complexity

        return parent::visitQuantifier($node);
    }

    public function visitGroup(Node\GroupNode $node)
    {
        $this->complexityScore += 3; // Groups are more complex

        return parent::visitGroup($node);
    }

    public function getComplexityScore(): int
    {
        return $this->complexityScore;
    }
}

$ast = Regex::create()->parse('/(?:(a+)|b){2,4}/');
$analyzer = new ComplexityAnalyzer();
$ast->accept($analyzer);

echo $analyzer->getComplexityScore(); // 10: two groups (3 + 3), two quantifiers ({2,4} and +, 2 + 2)
```

### Example 2: Named group collector

```php
use PHPRegex\Parser\AbstractTraversingVisitor;
use PHPRegex\Parser\Node;
use PHPRegex\Toolkit\Regex;

class GroupNameCollector extends AbstractTraversingVisitor
{
    private array $names = [];

    public function visitGroup(Node\GroupNode $node)
    {
        if ($node->name !== null) {
            $this->names[] = $node->name;
        }

        return parent::visitGroup($node); // Reach groups nested inside this one
    }

    public function getNames(): array
    {
        return $this->names;
    }
}

$ast = Regex::create()->parse('/(?<year>\d{4})-(?<month>\d{2})/');
$collector = new GroupNameCollector();
$ast->accept($collector);

print_r($collector->getNames());
```

The output lists both names, in pattern order:

```
Array
(
    [0] => year
    [1] => month
)
```

On `/(?<date>(?<year>\d{4}))-(?<month>\d{2})/`, the same collector returns `date`, `year`, `month`: the `parent::visitGroup($node)` call descends into nested groups instead of stopping at the outermost one.

## Related concepts

- **[What is an AST?](ast.md)** - The structure visitors process
- **[Visitors Reference](../visitors/README.md)** - Every built-in visitor and the base-class table
- **[Extending Guide](../extending.md)** - Building custom tools
- [Visitor Pattern (Wikipedia)](https://en.wikipedia.org/wiki/Visitor_pattern) - Design pattern explanation

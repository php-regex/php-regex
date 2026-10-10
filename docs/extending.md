---
description: "Add a new PCRE construct to the parser — node, lexer token, parser rule, visitor method, tests — with the callout syntax (?C) as the worked example."
redirect_from:
  - /EXTENDING_GUIDE/
  - /EXTENDING_GUIDE.html
---
# Extending PHPRegex

This guide shows how to add new PCRE features, build custom visitors, and integrate PHPRegex into tools.

---

## What You Can Extend

**PHPRegex** is designed for extensibility:

- New AST nodes for new syntax
- New visitors for analysis or transformation
- Custom lint rules
- Framework integrations
- CLI commands

---

## Extension Architecture

PHPRegex core extension points:
- Nodes (`src/Parser/Node/`) for new node types.
- Visitors (`src/Parser/AbstractNodeVisitor.php` and its subclasses in each package) for new analyses or transforms.
- Parser (`src/Parser/Syntax/TokenParser.php`) to recognize new syntax.
- CLI (`src/Cli/`) for new commands.

---

## Step 1: Add a New Node

Create a node class for your new PCRE feature. The example below is the
callout node, `(?C)`, `(?C1)` or `(?C"name")`, as the library ships it.

**Location:** `src/Parser/Node/YourFeatureNode.php`

```php
<?php

declare(strict_types=1);

namespace PHPRegex\Parser\Node;

use PHPRegex\Parser\NodeVisitorInterface;

final readonly class CalloutNode extends AbstractNode
{
    /**
     * @param int|string|null $identifier         callout number or string, null for "(?C)"
     * @param bool            $isStringIdentifier whether the identifier was a delimited string
     */
    public function __construct(
        public int|string|null $identifier,
        public bool $isStringIdentifier,
        int $startPosition,
        int $endPosition,
    ) {
        parent::__construct($startPosition, $endPosition);
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
- [ ] Call `parent::__construct($startPosition, $endPosition)`

---

## Step 2: Update the Lexer and the Parser

### Lex the new syntax

The lexer (`src/Parser/Lexer.php`) cuts the pattern into tokens. Each token
has a `TokenType` case (`src/Parser/Token/TokenType.php`). A whole callout,
`(?C...)` up to its `)`, is one `TokenType::Callout` token, and its value is
the text between `(?C` and `)`. A new token needs three things:

- a case in `TokenType`, e.g. `case Callout = 'callout';`;
- a pattern in the lexer's token map (`PATTERNS_OUTSIDE`, or `PATTERNS_INSIDE`
  for a token read inside a character class), keyed `T_` plus the upper-cased
  backing value: `'T_CALLOUT' => '...'`. The lexer turns the key back into the
  case with `TokenType::from(strtolower(substr($tokenName, 2)))`, so a key that
  does not spell a backing value is an error;
- the same key in the matching priority list, `TOKENS_OUTSIDE` or
  `TOKENS_INSIDE`: the lexer reads the token's type from the first key of that
  list whose pattern matched. A key missing from the list is never read, and a
  match of its pattern ends the run with a lexer internal error.

The patterns are tried in the order of the token map, and the first that
matches at the current position wins: a new pattern goes before any pattern
that would match a prefix of its syntax (`T_CALLOUT` sits before
`T_GROUP_MODIFIER_OPEN`, which would match the `(?` of `(?C1)`).

### Parse the token

Add parsing logic in `src/Parser/Syntax/TokenParser.php`. The parser reads a
`TokenStream` (`src/Parser/Token/TokenStream.php`):

- `match(TokenType $type)` moves past the current token when it has that type, and says whether it did.
- `previous()` returns the token just moved past.
- `consume(TokenType $type, string $error, ErrorCode $code)` moves past a token of that type, or throws a parser exception with that error code.

An atom is dispatched in `parseAtom()`. Syntax that opens with `(` goes
through `parseGroupOrCharClassAtom()`, and syntax that opens with `(?` goes on
to `parseGroupModifier()`. The callout token is matched in `parseAtom()` of
`src/Parser/Syntax/TokenParser.php`:

```php
if ($this->stream->match(TokenType::Callout)) {
    return $this->parseCallout();
}
```

`parseCallout()` builds the node from the token it just matched, in
`src/Parser/Syntax/TokenParser.php`:

```php
private function parseCallout(): CalloutNode
{
    $token = $this->stream->previous();
    $startPosition = $token->position;
    $value = $token->value; // the text between "(?C" and ")"
    $endPosition = $startPosition + \strlen($value) + self::CALLOUT_WRAPPER_LENGTH;

    if ('' === $value) {
        return new CalloutNode(null, false, $startPosition, $endPosition);
    }

    if (Ascii::isDigit($value)) {
        return new CalloutNode((int) $value, false, $startPosition, $endPosition);
    }

    // ... a delimited string, or a parser exception for anything else
}
```

The parser then returns the node in the tree:

```php
use PHPRegex\Toolkit\Regex;

$ast = Regex::create()->parse('/a(?C1)b(?C"tag")/');
// $ast->pattern->children[1]: CalloutNode, identifier 1, isStringIdentifier false, positions 1 to 6
// $ast->pattern->children[3]: CalloutNode, identifier "tag", isStringIdentifier true, positions 7 to 16
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
    if (null === $node->identifier) {
        return '(?C)';
    }

    return $node->isStringIdentifier
        ? '(?C"'.$node->identifier.'")'
        : '(?C'.$node->identifier.')';
}
```

This sketch does not double a `"` inside the string, as `(?C"a""b")` needs.
`PatternPrinter::visitCallout()` is the full version.

---

## Example: Custom Visitor

### Create a Pattern Length Visitor

```php
<?php

declare(strict_types=1);

namespace App\Regex;

use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\AbstractNodeVisitor;

/**
 * Calculates pattern complexity score.
 *
 * @extends AbstractNodeVisitor<int|null>
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
use PHPRegex\Toolkit\Regex;

$regex = Regex::create();
$ast = $regex->parse('/^(?:[a-z]+|\d{3,})+$/');

$visitor = new ComplexityVisitor();
$ast->accept($visitor);

echo "Complexity: " . $visitor->getScore();  // Output: Complexity: 30
// 10 for the alternation, 3 x 5 for the quantifiers, 5 for the character class
```

A visitor that only collects needs no `@extends`: the visitor base types
default their return to `null`, and an unbound subclass passes PHPStan as a
collector (Psalm still wants the explicit binding). A visitor whose methods return a value binds that type — `null`
included, since the methods it does not override return `null` — as the
`@extends AbstractNodeVisitor<int|null>` above does; `$ast->accept($visitor)`
then reads as `int|null` to PHPStan, and an overridden method whose body
forgets its `return` is flagged.

The library ships its own scorer for this job — [ComplexityScorer](visitors/README.md#complexityscorer),
with bands calibrated for CI gates; the visitor above is a small cousin whose
weights you control.

---

## Step 4: Add Tests

Create tests for your extension:

**Location:** `tests/Unit/Node/CalloutNodeTest.php`

```php
<?php

declare(strict_types=1);

namespace PHPRegex\Tests\Unit\Node;

use PHPUnit\Framework\TestCase;
use PHPRegex\Parser\Node\CalloutNode;

class CalloutNodeTest extends TestCase
{
    public function testCreateWithNumber(): void
    {
        $node = new CalloutNode(42, false, 0, 10);

        $this->assertSame(42, $node->identifier);
        $this->assertFalse($node->isStringIdentifier);
        $this->assertSame(0, $node->startPosition);
        $this->assertSame(10, $node->endPosition);
    }

    public function testCreateWithoutNumber(): void
    {
        $node = new CalloutNode(null, false, 0, 5);

        $this->assertNull($node->identifier);
    }
}
```

---

## Step 5: Update Documentation

Add your new feature to:

1. **[AST Nodes](nodes/README.md)** - Document the node
2. **[Visitor Reference](visitors/README.md)** - Document the visitor method
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

A transformer rebuilds the tree itself — `AbstractNodeVisitor` returns `null`
for the nodes it does not override, so override the containers too, or the
rewritten literal never reaches the new tree:

```php
class YourTransformer extends AbstractNodeVisitor
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

The [visitors reference](visitors/README.md#pattern-3-transforming-visitor)
shows the same transformer with its imports and the `PatternPrinter` round
trip; `Rewriter` and `Modernizer` are full working examples in the source.

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

- **[AST Nodes](nodes/README.md)** - Node reference
- **[Visitors](visitors/README.md)** - Visitor patterns
- **[AST Traversal](design/ast-traversal.md)** - Traversal design
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

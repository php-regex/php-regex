<?php

declare(strict_types=1);

use PHPRegex\Parser\AbstractTraversingVisitor;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Toolkit\Regex;

/**
 * Example: Walk the AST with a custom visitor
 *
 * This example demonstrates:
 * - Extending AbstractTraversingVisitor: traversal of the children is free
 * - Overriding one visit method to observe the nodes you care about
 * - Driving the walk with RegexNode::accept()
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$pattern = '/^(?:https?:\/\/)?[a-z0-9.-]+\/?\d*$/';

/**
 * Counts the literal characters and the quantifiers of a pattern.
 */
final class NodeCounter extends AbstractTraversingVisitor
{
    public int $literals = 0;
    public int $quantifiers = 0;

    public function visitCharLiteral(CharLiteralNode $node): void
    {
        $this->literals++;
    }

    public function visitQuantifier(QuantifierNode $node): void
    {
        $this->quantifiers++;
    }
}

echo "=== Custom Visitor ===\n";
echo "Pattern: {$pattern}\n\n";

$counter = new NodeCounter();
Regex::create()->parse($pattern)->accept($counter);

echo "✓ Literal characters: {$counter->literals}\n";
echo "✓ Quantifiers:        {$counter->quantifiers}\n";

echo "\nThe traversing base visits every child; override only the node types\n";
echo "you need and leave the rest to the defaults.\n";

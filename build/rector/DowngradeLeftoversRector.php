<?php

declare(strict_types=1);

namespace PHPRegex\Build\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeVisitor;
use PhpParser\PrettyPrinter\Standard;
use Rector\Rector\AbstractRector;
use Rector\RuleDoc\CodeSample\CodeSample;
use Rector\RuleDoc\RuleDefinition;

/**
 * Removes what the downgrade writes to keep a construct whole and the twin
 * does not need: the "null;" statement a match arm that does nothing
 * becomes, and the iterator_to_array() fallback an array spread becomes
 * when the spread value is an array (the spreads of the source are all
 * arrays; a Traversable one keeps its fallback).
 */
final class DowngradeLeftoversRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Remove the downgrade leftovers the twin does not need', [
            new CodeSample('array_merge(is_array($a) ? $a : iterator_to_array($a), $b)', 'array_merge($a, $b)'),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [Expression::class, Ternary::class];
    }

    /**
     * @param Expression|Ternary $node
     *
     * @return Node|int|null
     */
    public function refactor(Node $node)
    {
        if ($node instanceof Expression) {
            return $node->expr instanceof ConstFetch && 'null' === strtolower($node->expr->name->toString())
                ? NodeVisitor::REMOVE_NODE
                : null;
        }

        if (!$node->cond instanceof FuncCall || !$this->isName($node->cond, 'is_array') || null === $node->if
            || !$node->else instanceof FuncCall || !$this->isName($node->else, 'iterator_to_array')) {
            return null;
        }

        $checked = $node->cond->getArgs()[0]->value ?? null;
        $converted = $node->else->getArgs()[0]->value ?? null;
        if (null === $checked || null === $converted) {
            return null;
        }

        $printer = new Standard();
        $same = $printer->prettyPrintExpr($checked) === $printer->prettyPrintExpr($node->if)
            && $printer->prettyPrintExpr($checked) === $printer->prettyPrintExpr($converted);

        return $same && $this->getType($checked)->isArray()->yes() ? $node->if : null;
    }
}

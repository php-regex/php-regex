<?php

declare(strict_types=1);

namespace PHPRegex\Build\Rector;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Int_;
use Rector\Rector\AbstractRector;
use Rector\RuleDoc\CodeSample\CodeSample;
use Rector\RuleDoc\RuleDefinition;

/**
 * PHP 8.0 lets array_intersect(), array_diff() and their key and assoc
 * variants take one array, and gives it back; PHP 7.4 asks for two and
 * returns null with a warning. A call that unpacks a list may pass one:
 * the twin gives that array back, as PHP 8 does.
 */
final class SingleArraySetRector extends AbstractRector
{
    private const FUNCTIONS = ['array_diff', 'array_diff_assoc', 'array_diff_key', 'array_intersect', 'array_intersect_assoc', 'array_intersect_key'];

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Give back the one array a set function is unpacked with, as PHP 8 does', [
            new CodeSample(
                'array_intersect_key(...$sets)',
                '1 === \count($sets) ? $sets[\array_key_first($sets)] : \array_intersect_key(...$sets)',
            ),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [FuncCall::class];
    }

    /**
     * @param FuncCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if (!$node->name instanceof Name || !\in_array(strtolower($node->name->toString()), self::FUNCTIONS, true) || true === $node->getAttribute(self::class)) {
            return null;
        }

        $unpacked = [];
        foreach ($node->args as $arg) {
            if ($arg instanceof Arg && $arg->unpack) {
                $unpacked[] = $arg;
            }
        }

        if ([] === $unpacked) {
            return null;
        }

        // one unpacked list, read twice: anything else is ported by hand
        $sets = $unpacked[0]->value;
        if (1 !== \count($node->args) || !$sets instanceof Variable) {
            throw new \RuntimeException(sprintf('%s() unpacks arguments the twin cannot rewrite: one unpacked variable only.', $node->name->toString()));
        }

        $call = new FuncCall(new FullyQualified($node->name->toString()), [new Arg(clone $sets, false, true)]);
        $call->setAttribute(self::class, true);

        return new Ternary(
            new Identical(new Int_(1), new FuncCall(new FullyQualified('count'), [new Arg(clone $sets)])),
            new ArrayDimFetch(clone $sets, new FuncCall(new FullyQualified('array_key_first'), [new Arg(clone $sets)])),
            $call,
        );
    }
}

<?php

declare(strict_types=1);

/*
 * This file is part of the PHPRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PHPRegex\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ErrorSuppress;
use PhpParser\Node\Expr\FuncCall;
use PHPRegex\Rector\Internal\PregCall;
use PHPRegex\Rector\Internal\ProvenLiterals;
use Rector\Php\PhpVersionProvider;
use Rector\Rector\AbstractRector;

/**
 * Rewrites preg_split() into explode() when the automata prove that the
 * pattern matches exactly one string: preg_split('/\|/', $s) becomes
 * explode('|', $s). Both keep the empty pieces, and both return [''] for the
 * empty subject.
 *
 * Any other call is left as it is: flags, a limit other than a literal -1 or
 * 0, a pattern that may match another string or the empty one, a subject
 * that may be no string, a call directly under "@". A call nested deeper in
 * an "@" expression, "@f(preg_split(…))", is rewritten: explode() raises
 * nothing the "@" would have hidden.
 */
final class PregSplitToExplodeRector extends AbstractRector
{
    private readonly ProvenLiterals $literals;

    public function __construct(private readonly PhpVersionProvider $phpVersionProvider)
    {
        $this->literals = new ProvenLiterals();
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [ErrorSuppress::class, FuncCall::class];
    }

    /**
     * @param ErrorSuppress|FuncCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node instanceof ErrorSuppress) {
            PregCall::markSilenced($node);

            return null;
        }

        if (!$node instanceof FuncCall || PregCall::isSilenced($node) || !$this->isName($node, 'preg_split')) {
            return null;
        }

        $arguments = PregCall::arguments($node, 2, 3);
        if (null === $arguments || (isset($arguments[2]) && !$this->isNoLimit($arguments[2]))) {
            return null;
        }
        [$pattern, $subject] = $arguments;

        $patternValue = PregCall::isConstantExpression($pattern) ? $this->constantString($pattern) : null;
        $literal = null === $patternValue ? null : $this->literals->of($patternValue, $this->phpVersionProvider->provide());
        if (null === $literal || !$this->getType($subject)->isString()->yes()) {
            return null;
        }

        return PregCall::call('explode', [PregCall::string($literal), $subject]);
    }

    /**
     * A literal -1 or 0: no limit, as explode() without one. null is no
     * integer: preg_split() refuses it under strict_types.
     */
    private function isNoLimit(Expr $limit): bool
    {
        $type = $this->getType($limit);

        return $type->isInteger()->yes()
            && PregCall::isConstantExpression($limit)
            && \in_array($type->getConstantScalarValues(), [[-1], [0]], true);
    }

    /**
     * The value of an expression PHPStan knows to be one string.
     */
    private function constantString(Expr $expr): ?string
    {
        $type = $this->getType($expr);
        $values = $type->getConstantStrings();

        return 1 === \count($values) && $type->isString()->yes() ? $values[0]->getValue() : null;
    }
}

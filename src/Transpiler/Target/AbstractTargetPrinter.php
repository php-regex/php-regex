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

namespace PHPRegex\Transpiler\Target;

use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Transpiler\TranspileContext;
use PHPRegex\Transpiler\TranspileException;

/**
 * What every target's compiler needs, whatever dialect it writes.
 *
 * The dialects differ in almost everything they emit, so only what they
 * genuinely share lives here: the context they compile against, how they
 * refuse a construct, and the whitespace a quantifier may carry under /x.
 *
 * @extends AbstractNodeVisitor<string>
 *
 * @internal
 */
abstract class AbstractTargetPrinter extends AbstractNodeVisitor
{
    public function __construct(protected readonly TranspileContext $context) {}

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): string
    {
        return $this->unsupported('Perl extended character classes "(?[...])" are not supported by this target.', $node);
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): string
    {
        return $this->unsupported('Perl extended character classes "(?[...])" are not supported by this target.', $node);
    }

    /**
     * Refuse a construct the target has no way to express.
     *
     * @throws TranspileException
     */
    protected function unsupported(string $message, NodeInterface $node): string
    {
        throw new TranspileException(
            $message,
            $node->getStartPosition(),
            $this->context->sourcePattern,
        );
    }

    /**
     * Drop the whitespace /x allows inside a quantifier: "{1, 3}" is "{1,3}"
     * to every target, and none of them read /x the way PCRE does.
     */
    protected function normalizeQuantifier(string $quantifier): string
    {
        return preg_replace('/\s+/', '', $quantifier) ?? $quantifier;
    }
}

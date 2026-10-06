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
 * Rewrites preg_replace() into str_replace() when the automata prove that
 * the pattern matches exactly one string and the replacement refers to no
 * group: preg_replace('/f\.o/', 'X', $s) becomes str_replace('f.o', 'X', $s).
 * Both scan the subject left to right and replace the occurrences that do
 * not overlap.
 *
 * Any other call is left as it is: an array, a limit or a count, a pattern
 * that may match another string ("/ab?/" replaces "ab" whole), a "$" or a
 * "\" in the replacement, a subject that may be no string, a call directly
 * under "@". A call nested deeper in an "@" expression, "@f(preg_replace(…))",
 * is rewritten: str_replace() raises nothing the "@" would have hidden.
 */
final class PregReplaceToStrReplaceRector extends AbstractRector
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

        if (!$node instanceof FuncCall || PregCall::isSilenced($node) || !$this->isName($node, 'preg_replace')) {
            return null;
        }

        $arguments = PregCall::arguments($node, 3, 3);
        if (null === $arguments) {
            return null;
        }
        [$pattern, $replacement, $subject] = $arguments;

        // "$1", "${1}" and "\1" name a group; "\\" and "$" alone are
        // read by preg_replace() too.
        $replacementValue = $this->constantString($replacement);
        if (null === $replacementValue || false !== strpbrk($replacementValue, '$\\')) {
            return null;
        }

        $patternValue = PregCall::isConstantExpression($pattern) ? $this->constantString($pattern) : null;
        $literal = null === $patternValue ? null : $this->literals->of($patternValue, $this->phpVersionProvider->provide());
        if (null === $literal || !$this->getType($subject)->isString()->yes()) {
            return null;
        }

        return PregCall::call('str_replace', [PregCall::string($literal), $replacement, $subject]);
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

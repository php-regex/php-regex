<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Automata\Transform;

use RegexParser\Automata\Model\Nfa;
use RegexParser\Automata\Options\SolverOptions;
use RegexParser\Exception\ComplexityException;
use RegexParser\Node\RegexNode;

/**
 * Transforms a regex AST into an NFA.
 *
 * @internal
 */
interface AstToNfaTransformerInterface
{
    /**
     * @throws ComplexityException When regex exceeds supported subset
     */
    public function transform(RegexNode $regex, SolverOptions $options): Nfa;
}

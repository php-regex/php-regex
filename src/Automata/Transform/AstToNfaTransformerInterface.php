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

namespace PHPRegex\Automata\Transform;

use PHPRegex\Automata\Exception\ComplexityException;
use PHPRegex\Automata\Model\Nfa;
use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Parser\Node\RegexNode;

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

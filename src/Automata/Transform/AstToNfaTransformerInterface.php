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

namespace PhpRegex\Automata\Transform;

use PhpRegex\Automata\Model\Nfa;
use PhpRegex\Automata\Options\SolverOptions;
use PhpRegex\Parser\Node\RegexNode;

/**
 * Transforms a regex AST into an NFA.
 *
 * @internal
 */
interface AstToNfaTransformerInterface
{
    /**
     * @throws \PhpRegex\Automata\Exception\ComplexityException When regex exceeds supported subset
     */
    public function transform(RegexNode $regex, SolverOptions $options): Nfa;
}

<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Tests\Unit\NodeVisitor;

use PhpRegex\Optimizer\Rewriter;
use PhpRegex\Parser\Node\LiteralNode;
use PhpRegex\Parser\Node\RegexNode;
use PhpRegex\Parser\Printer\PatternPrinter;
use PHPUnit\Framework\TestCase;

final class VisitorSyntheticNodesTest extends TestCase
{
    /**
     * Teste la compilation avec un délimiteur inhabituel '('.
     * Couvre la logique de mapping des délimiteurs dans PatternPrinter::visitRegex.
     */
    public function test_compiler_paren_delimiter(): void
    {
        $pattern = new LiteralNode('abc', 0, 3);
        // RegexNode avec '(' comme délimiteur
        $ast = new RegexNode($pattern, 'i', '(', 0, 3);

        $compiler = new PatternPrinter();
        $result = $ast->accept($compiler);

        // Doit produire (abc)i
        $this->assertSame('(abc)i', $result);
    }

    /**
     * Teste l'optimiseur sur un RegexNode qui ne nécessite aucun changement.
     * Vérifie que l'instance retournée est la même (optimisation de mémoire).
     */
    public function test_optimizer_no_change_returns_same_instance(): void
    {
        // Un littéral simple ne change pas
        $pattern = new LiteralNode('abc', 0, 3);
        $ast = new RegexNode($pattern, '', '/', 0, 3);

        $optimizer = new Rewriter();
        $result = $ast->accept($optimizer);

        $this->assertSame($ast, $result);
    }
}

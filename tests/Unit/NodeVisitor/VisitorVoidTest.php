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

namespace PHPRegex\Tests\Unit\NodeVisitor;

use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Validation\Validator;
use PHPUnit\Framework\Attributes\DoesNotPerformAssertions;
use PHPUnit\Framework\TestCase;

final class VisitorVoidTest extends TestCase
{
    /**
     * Couvre les méthodes vides du Validator (visitLiteral, visitDot, etc.)
     * qui sont techniquement du code exécuté même si elles ne font rien.
     */
    #[DoesNotPerformAssertions]
    public function test_validator_visits_simple_nodes(): void
    {
        $validator = new Validator();

        // On appelle manuellement accept() pour être sûr que la méthode visit* est déclenchée
        (new LiteralNode('a', 0, 0))->accept($validator);
        (new DotNode(0, 0))->accept($validator);
        (new AnchorNode('^', 0, 0))->accept($validator);
        (new CharTypeNode('d', 0, 0))->accept($validator);
        (new CommentNode('comment', 0, 0))->accept($validator);
    }
}

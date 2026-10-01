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

namespace PHPRegex\Tests\Unit\Parser;

use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class ParserUnsupportedSyntaxTest extends TestCase
{
    /**
     * Teste la syntaxe de backreference nommée (?P=name).
     */
    public function test_python_named_backref_syntax(): void
    {
        $regex = Regex::create();

        $ast = $regex->parse('/(?P<name>a)(?P=name)/');
        $this->assertInstanceOf(SequenceNode::class, $ast->pattern);
        $backref = $ast->pattern->children[1];
        $this->assertInstanceOf(BackrefNode::class, $backref);
        $this->assertSame('\k<name>', $backref->ref);
    }

    /**
     * Teste une syntaxe invalide après (?P.
     * Couvre le throw final du bloc (?P...).
     */
    public function test_invalid_p_group_syntax(): void
    {
        $regex = Regex::create();

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Invalid syntax after (?P');

        $regex->parse('/(?P!)/');
    }

    /**
     * Teste une conditionnelle avec un lookaround invalide (ex: (? (?~...) )
     * Couvre les "else { throw ... }" dans la détection des lookarounds conditionnels.
     */
    public function test_conditional_invalid_lookaround_char(): void
    {
        $regex = Regex::create();

        $this->expectException(ParserException::class);
        $this->expectExceptionMessage('Invalid conditional condition');

        // (? ( ? ~ ) ) -> ~ n'est pas = ou ! ou <
        $regex->parse('/(?(?~))/');
    }

    /**
     * Teste un conditionnel (?<...) qui n'est ni un lookbehind ni une named ref valide.
     */
    public function test_conditional_invalid_lookbehind_syntax(): void
    {
        $regex = Regex::create();
        $this->expectException(ParserException::class);
        // Si on met (?< mais pas suivi de = ou !, c'est invalide dans ce contexte précis
        // sauf si c'est une named group, mais ici on teste le path du lookbehind
        $regex->parse('/(?(?<*))/');
    }
}

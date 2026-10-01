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

use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Toolkit\Regex;
use PHPUnit\Framework\TestCase;

final class ParserReconstructionTest extends TestCase
{
    private Regex $regexService;

    protected function setUp(): void
    {
        $this->regexService = Regex::create();
    }

    /**
     * Ce test force le parser à reconstruire chaque type de token possible
     * pour les stocker dans le nœud CommentNode.
     * Cela couvre tout le switch de Parser::reconstructTokenValue.
     */
    public function test_parser_reconstructs_all_token_types_in_comment(): void
    {
        // On met tout ce qui ressemble à des tokens spéciaux DANS un commentaire.
        // Le lexer va les tokeniser, et le parser va devoir les remettre en string.
        // Note: parentheses cannot be used inside (?#...) comments as they end the comment
        $pattern = '/(?#
            [abc]           # T_CHAR_CLASS_OPEN, T_CHAR_CLASS_CLOSE
            group           # T_GROUP_OPEN, T_GROUP_CLOSE
            non-capture     # T_GROUP_MODIFIER_OPEN
            * + ?           # T_QUANTIFIER
            |               # T_ALTERNATION
            .               # T_DOT
            ^ $             # T_ANCHOR
            -               # T_RANGE si contexte
            \b \A           # T_ASSERTION
            \K              # T_KEEP
            \d \s           # T_CHAR_TYPE
            \g{1}           # T_G_REFERENCE
            \1 \k<name>     # T_BACKREF
            \01             # T_OCTAL_LEGACY
            \o{123}         # T_OCTAL
            \x00 \u{FFFF}   # T_UNICODE
            \p{L} \P{L}     # T_UNICODE_PROP
            \Q \E           # T_QUOTE_MODE_START, END
            \a              # T_LITERAL_ESCAPED inconnu
            text            # T_LITERAL
            [[:alnum:]]     # T_POSIX_CLASS
            *FAIL           # T_PCRE_VERB
        )/x';

        $ast = $this->regexService->parse($pattern);

        $this->assertInstanceOf(CommentNode::class, $ast->pattern);
        $comment = $ast->pattern->comment;

        // Vérifications basiques pour sassurer que la reconstruction a fonctionné
        $this->assertStringContainsString('[abc]', (string) $comment);
        $this->assertStringContainsString('*FAIL', (string) $comment);
        $this->assertStringContainsString('\p{L}', (string) $comment);
        $this->assertStringContainsString('\d', (string) $comment);
        $this->assertStringContainsString('\Q', (string) $comment);
    }
}

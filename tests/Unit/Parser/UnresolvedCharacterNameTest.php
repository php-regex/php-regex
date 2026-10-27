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

use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Analysis\RequiredLiteralAnalyzer;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Printer\StructuralFingerprint;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A "\N{name}" whose name reads as no character has no code point (-1), and
 * PCRE refuses the pattern ("PCRE2 does not support \F, \L, \l, \N{name},
 * \U, or \u"). A tree parsed from it is still read: as an
 * unknown character, not as the byte chr(-1) gives, 0xFF, which PHP 8.5
 * also reports as a deprecated value.
 */
final class UnresolvedCharacterNameTest extends TestCase
{
    #[Test]
    public function test_the_name_parses_to_a_character_of_no_code_point(): void
    {
        $node = RegexParser::create(['cache' => null])->parse('/\N{x}/')->pattern;

        $this->assertInstanceOf(CharLiteralNode::class, $node);
        $this->assertSame(-1, $node->codePoint);
    }

    #[Test]
    public function test_no_string_every_match_holds_spells_a_byte_for_it(): void
    {
        $required = (new RequiredLiteralAnalyzer())->analyze(RegexParser::create(['cache' => null])->parse('/a\N{x}b/'));

        $this->assertSame(['a', 'b'], $required);
    }

    #[Test]
    public function test_the_group_holding_it_has_no_value_to_list(): void
    {
        $shape = (new CaptureShapeAnalyzer())->analyze(RegexParser::create(['cache' => null])->parse('/(\N{x})/'));

        $this->assertNull($shape->groups[1]->values);
    }

    #[Test]
    public function test_two_names_have_two_fingerprints(): void
    {
        $parser = RegexParser::create(['cache' => null]);

        $this->assertNotSame(StructuralFingerprint::of($parser->parse('/\N{x}/')), StructuralFingerprint::of($parser->parse('/\N{y}/')));
    }
}

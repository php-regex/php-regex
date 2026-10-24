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

namespace PHPRegex\Tests\Unit\Parser\Validation;

use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ValidationLimitVerdictTest extends TestCase
{
    #[Test]
    public function test_a_limit_of_the_library_is_not_a_verdict_on_the_pattern(): void
    {
        $result = RegexParser::create()->validate('/a'.\str_repeat(' ', 100000).'/x');

        self::assertFalse($result->isValid);
        self::assertTrue($result->isLimit(), 'a limit of the library says nothing about the pattern');
        self::assertFalse($result->isPatternRefused(), 'no refusal to read from a limit');
    }

    #[Test]
    public function test_a_limit_result_still_renders_its_message(): void
    {
        $result = RegexParser::create()->validate('/a'.\str_repeat(' ', 100000).'/x');

        self::assertStringContainsString('maximum length', $result->render(), 'the limit renders as the message the library writes');
    }

    #[Test]
    public function test_a_refusal_of_the_engine_is_a_verdict(): void
    {
        $result = RegexParser::create()->validate('/(/');

        self::assertFalse($result->isValid);
        self::assertFalse($result->isLimit());
        self::assertTrue($result->isPatternRefused());
    }

    #[Test]
    public function test_a_valid_pattern_has_no_verdict_to_give(): void
    {
        $result = RegexParser::create()->validate('/a/');

        self::assertTrue($result->isValid);
        self::assertFalse($result->isLimit());
        self::assertNull($result->isPatternRefused());
    }
}

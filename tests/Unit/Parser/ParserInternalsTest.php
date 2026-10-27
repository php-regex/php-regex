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
use PHPRegex\Parser\Syntax\PatternParser;
use PHPRegex\Tests\Support\PhpUnitCompat\PhpUnitForwardCompat;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ParserInternalsTest extends TestCase
{
    use PhpUnitForwardCompat;

    #[Test]
    public function test_extract_pattern_throws_on_preg_replace_error(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains('Regex is too short');
        PatternParser::extractPatternAndFlags('/');
    }

    #[Test]
    public function test_extract_pattern_regex_too_short(): void
    {
        // Calling public method directly to ensure this specific exception path is hit
        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains('Regex is too short');
        PatternParser::extractPatternAndFlags('/');
    }

    #[Test]
    public function test_extract_pattern_no_closing_delimiter(): void
    {
        // Forces the loop to finish without finding the delimiter
        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains('No closing delimiter "/" found. You opened with "/"; expected closing "/". Tip: escape "/" inside the pattern (\\/) or use a different delimiter, e.g. #abc#.');
        PatternParser::extractPatternAndFlags('/abc');
    }

    #[Test]
    public function test_extract_pattern_too_short(): void
    {
        $this->expectException(ParserException::class);
        $this->expectExceptionMessageIsOrContains('Regex is too short');
        PatternParser::extractPatternAndFlags('/');
    }
}

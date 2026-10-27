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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use PHPRegex\Parser\Analysis\CaptureShape;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Psalm\PatternVerdicts;
use PHPRegex\Psalm\QuotedPattern;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The plugin reads a "(?(VERSION>=10.45)" condition as the release it judges
 * for reads it: preg_match('/(?(VERSION>=10.45)(a)|(b))/', 'ab') takes the no
 * arm on PCRE2 10.44 (PHP 8.4.27) and the yes arm on 10.49.
 */
final class PsalmVersionConditionTargetTest extends TestCase
{
    /**
     * @return iterable<string, array{release: string, expected: string}>
     */
    public static function provideReleases(): iterable
    {
        yield '10.44 takes the no arm' => ['release' => '10.44', 'expected' => "array{0: 'b', 1: '', 2: 'b'}"];
        yield '10.49 takes the yes arm' => ['release' => '10.49', 'expected' => "array{0: 'a', 1: 'a'}"];
    }

    #[Test]
    #[DataProvider('provideReleases')]
    public function test_a_verdict_reads_a_version_condition_at_its_target(string $release, string $expected): void
    {
        $shape = PatternVerdicts::verdict(RegexParser::create(['php_version' => '8.4', 'pcre_version' => $release]), '/(?(VERSION>=10.45)(a)|(b))/')['shape'];

        $this->assertInstanceOf(CaptureShape::class, $shape);
        $this->assertSame($expected, $shape->matchShape());
    }

    /**
     * Which arm a release takes changes no group number nor name: the
     * groups a quoted text must leave as they are read the same for every
     * target.
     */
    #[Test]
    #[DataProvider('provideReleases')]
    public function test_a_quoted_pattern_reads_its_groups_at_its_target(string $release, string $expected): void
    {
        $parser = RegexParser::create(['php_version' => '8.4', 'pcre_version' => $release]);
        $this->assertNotSame('', $expected);

        $this->assertSame('/(?(VERSION>=10.45)(a)|(?<n>b))(?:[\s\S]*)/', QuotedPattern::of(['/(?(VERSION>=10.45)(a)|(?<n>b))', null, '/'], $parser));
    }
}

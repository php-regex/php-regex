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

namespace PhpRegex\Tests\Unit;

use PhpRegex\Parser\Cache\ArrayCache;
use PhpRegex\Parser\Node\QuantifierNode;
use PhpRegex\Parser\Node\SequenceNode;
use PhpRegex\Parser\PcreTarget;
use PhpRegex\Toolkit\Regex;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * One PHP version and one PCRE2 release judge a pattern, whoever runs the
 * analysis: the lexer, the parser and the validator all read the same
 * target, and so does the cache.
 */
final class PcreTargetJudgementTest extends TestCase
{
    #[Test]
    public function test_the_whole_pipeline_judges_with_one_release(): void
    {
        // PCRE2 10.44 (PHP 8.4 bundles it) against 10.48: "+" at 0 or 1,
        // an unknown POSIX class on its name or past it, "\x" alone read as
        // NUL or refused (pcre2test on each).
        $bundled = Regex::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.44']);
        $newer = Regex::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.48']);

        $this->assertSame(0, $bundled->validate('/+/')->offset);
        $this->assertSame(1, $newer->validate('/+/')->offset);
        $this->assertSame(3, $bundled->validate('/[[:foo:]]/')->offset);
        $this->assertSame(8, $newer->validate('/[[:foo:]]/')->offset);
        $this->assertTrue($bundled->validate('/a\\xg/')->isValid);
        $this->assertSame(3, $newer->validate('/a\\xg/')->offset);
    }

    #[Test]
    public function test_naming_the_running_php_judges_with_the_pcre2_it_bundles(): void
    {
        // The parser and the validator used to disagree here: the parser
        // took the bundled release, the validator the linked one.
        $named = Regex::create(['cache' => null, 'php_version' => \PHP_VERSION_ID]);
        $bundled = Regex::create(['cache' => null, 'php_version' => \PHP_VERSION_ID, 'pcre_version' => PcreTarget::bundledWith(\PHP_VERSION_ID)->pcreVersion]);

        foreach (['/+/', '/[[:foo:]]/', '/a\\xg/', '/(?i\\y/', '/\\p{L!}/'] as $pattern) {
            $this->assertEquals($bundled->validate($pattern), $named->validate($pattern), $pattern);
        }
    }

    #[Test]
    public function test_a_cached_tree_serves_only_the_release_it_was_read_for(): void
    {
        // "{,2}" repeats from PCRE2 10.43 and is text before.
        $cache = new ArrayCache();
        $older = Regex::create(['cache' => $cache, 'php_version' => '8.4', 'pcre_version' => '10.42']);
        $newer = Regex::create(['cache' => $cache, 'php_version' => '8.4', 'pcre_version' => '10.44']);

        $this->assertInstanceOf(SequenceNode::class, $older->parse('/a{,2}/')->pattern);
        $this->assertInstanceOf(QuantifierNode::class, $newer->parse('/a{,2}/')->pattern);
        $this->assertInstanceOf(SequenceNode::class, $older->parse('/a{,2}/')->pattern);
    }

    #[Test]
    public function test_the_facade_says_what_it_judges_for(): void
    {
        $this->assertEquals(PcreTarget::runtime(), Regex::create(['cache' => null])->target());
        $this->assertEquals(new PcreTarget(80300, '10.42'), Regex::create(['cache' => null, 'php_version' => '8.3'])->target());
    }

    #[Test]
    public function test_whole_version_numbers_are_read_from_pcre2_10_47(): void
    {
        // pcre2test: 10.40 to 10.46 refuse "10.100" at offset 16, 10.47 reads it.
        $this->assertSame(16, Regex::create(['cache' => null, 'pcre_version' => '10.46'])->validate('/(?(VERSION=10.100))/')->offset);
        $this->assertTrue(Regex::create(['cache' => null, 'pcre_version' => '10.47'])->validate('/(?(VERSION=10.100))/')->isValid);
    }

    #[Test]
    public function test_a_script_is_known_from_the_release_that_brought_it(): void
    {
        // pcre2test -LS: Kawi from 10.43 (listed by 10.44), Garay from 10.45,
        // Sidetic and Tolong Siki from 10.48.
        foreach (['/\\p{Kawi}/' => '10.43', '/\\p{Garay}/' => '10.45', '/\\p{Sidetic}/' => '10.48', '/\\p{Tols}/' => '10.48'] as $pattern => $release) {
            [$major, $minor] = explode('.', $release);
            $before = $major.'.'.((int) $minor - 1);

            $this->assertFalse(Regex::create(['cache' => null, 'pcre_version' => $before])->validate($pattern)->isValid, $pattern.' on '.$before);
            $this->assertTrue(Regex::create(['cache' => null, 'pcre_version' => $release])->validate($pattern)->isValid, $pattern.' on '.$release);
        }
    }

    #[Test]
    public function test_a_distribution_php_is_judged_with_the_pcre2_it_links(): void
    {
        // PHP 8.4 on Ubuntu 24.04 links PCRE2 10.42: "(?aD)" is 10.43 syntax.
        $this->assertFalse(Regex::create(['cache' => null, 'php_version' => '8.4', 'pcre_version' => '10.42'])->validate('/(?aD)x/')->isValid);
        $this->assertTrue(Regex::create(['cache' => null, 'php_version' => '8.4'])->validate('/(?aD)x/')->isValid);
    }
}

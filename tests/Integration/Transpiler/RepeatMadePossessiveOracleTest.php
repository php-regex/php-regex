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

namespace PHPRegex\Tests\Integration\Transpiler;

use PHPRegex\Tests\Integration\Transpiler\Oracle\TargetEngine;
use PHPRegex\Toolkit\Regex;
use PHPRegex\Transpiler\TranspileOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * What the warning on a repeat PCRE2 makes possessive says, checked in the
 * engines: PCRE2 makes a character repeated before a group it may skip
 * possessive and finds nothing where the target, which runs the repeat as
 * written, finds the subject; where no warning is given the two agree.
 *
 * The engines are those of the transpiler oracle, Node 20 or later and
 * Python 3.11 or later, and the test runs with it:
 *
 *     tools/phpunit/vendor/bin/phpunit --group transpiler-oracle
 */
#[Group('transpiler-oracle')]
final class RepeatMadePossessiveOracleTest extends TestCase
{
    /**
     * Each pattern with a subject, whether a warning names a repeat of it,
     * and whether PCRE finds the subject: the target always does.
     */
    private const PATTERNS = [
        '/a+(?:b)?+a/' => ['subject' => 'aa', 'warned' => true, 'pcre' => 0],
        '/a+(?>(?:b)?)a/' => ['subject' => 'aa', 'warned' => true, 'pcre' => 0],
        '/xa+c?(?:b)?+a/' => ['subject' => 'xaa', 'warned' => true, 'pcre' => 0],
        '/a+?(?:b)?+a/U' => ['subject' => 'aa', 'warned' => true, 'pcre' => 0],
        '/a+x(?:b)?+a/' => ['subject' => 'aaxa', 'warned' => false, 'pcre' => 1],
        '/(?:a)+(?:b)?+a/' => ['subject' => 'aa', 'warned' => false, 'pcre' => 1],
        '/a+?(?:b)?+a/' => ['subject' => 'aa', 'warned' => false, 'pcre' => 1],
        '/a+(?:b)?+a/U' => ['subject' => 'aa', 'warned' => false, 'pcre' => 1],
    ];

    #[Test]
    #[DataProvider('provideTargets')]
    public function test_the_target_gives_back_where_pcre_made_the_repeat_possessive(string $target, ?string $pythonVersion = null): void
    {
        $engine = 'python' === $target ? TargetEngine::python() : TargetEngine::node();
        $this->assertTrue($engine->isAvailable(), \sprintf('The %s oracle needs %s: TRANSPILER_ORACLE_NODE and TRANSPILER_ORACLE_PYTHON name other binaries.', $target, 'python' === $target ? 'Python 3.11 or later' : 'Node 20 or later'));

        $regex = Regex::create();
        $whole = 'html-pattern' === $target;
        $cases = [];
        foreach (self::PATTERNS as $pattern => ['subject' => $subject, 'warned' => $warned, 'pcre' => $pcre]) {
            $result = $regex->transpile($pattern, $target, new TranspileOptions(pythonVersion: $pythonVersion));

            $this->assertSame($warned, [] !== array_filter($result->warnings, static fn (string $warning): bool => str_contains($warning, 'as if that group could not be skipped')), $pattern.' for '.$target);
            $this->assertSame($pcre, preg_match($pattern, $subject), $pattern.' in PCRE');

            $cases[] = ['pattern' => $result->pattern, 'flags' => $whole ? 'v' : $result->flags, 'whole' => $whole, 'subjects' => [[$subject, 0]]];
        }

        $patterns = array_keys(self::PATTERNS);
        foreach ($engine->run($cases) as $index => $outcome) {
            $this->assertNull($outcome['error'], $patterns[$index].' for '.$target);
            $this->assertFalse($outcome['timedOut'] ?? false, $patterns[$index].' for '.$target);

            // The target runs the repeat as written, and finds the subject.
            $this->assertNotNull($outcome['results'][0], $patterns[$index].' for '.$target);
        }
    }

    /**
     * @return iterable<string, array{target: string, pythonVersion?: string}>
     */
    public static function provideTargets(): iterable
    {
        yield 'javascript' => ['target' => 'javascript'];
        yield 'html-pattern' => ['target' => 'html-pattern'];
        yield 'python 3.11' => ['target' => 'python', 'pythonVersion' => '3.11'];
        yield 'python 3.11.5' => ['target' => 'python', 'pythonVersion' => '3.11.5'];
    }
}

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

namespace PhpRegex\Tests\Unit\ReDoS;

use PhpRegex\Redos\ConfirmationOptions;
use PhpRegex\Redos\ConfirmationRunner;
use PhpRegex\Redos\RedosAnalysis;
use PhpRegex\Redos\RedosSeverity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The runner runs the pattern under the limits of its options, without the
 * JIT, and leaves the PCRE settings of the process as it found them,
 * whatever they held.
 */
final class ReDoSConfirmationRunnerIniTest extends TestCase
{
    private const KEYS = ['pcre.jit', 'pcre.backtrack_limit', 'pcre.recursion_limit'];

    /**
     * @var array<string, string>
     */
    private array $before = [];

    protected function setUp(): void
    {
        $this->before = [];
        foreach (self::KEYS as $key) {
            $this->before[$key] = (string) \ini_get($key);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->before as $key => $value) {
            \ini_set($key, $value);
        }
    }

    #[Test]
    #[DataProvider('provideIniValues')]
    public function test_the_ini_is_left_as_it_was_found(string $value): void
    {
        // PHP warns on a value it reads loosely, and keeps it.
        set_error_handler(static fn (): bool => true);

        try {
            \ini_set('pcre.backtrack_limit', $value);
        } finally {
            restore_error_handler();
        }
        $found = \ini_get('pcre.backtrack_limit');

        (new ConfirmationRunner())->confirm(
            '/(a+)+$/',
            new RedosAnalysis(RedosSeverity::High, 8),
            new ConfirmationOptions(steps: 1, iterations: 1, backtrackLimit: 10),
        );

        $this->assertSame($found, \ini_get('pcre.backtrack_limit'));
    }

    /**
     * @return iterable<string, array{value: string}>
     */
    public static function provideIniValues(): iterable
    {
        yield 'digits' => ['value' => '1000000'];
        yield 'digits around spaces' => ['value' => ' 42 '];
        yield 'small' => ['value' => '7'];
        yield 'empty' => ['value' => ''];
        yield 'negative' => ['value' => '-1'];
        yield 'high byte' => ['value' => "1\xB2"];
        yield 'zero' => ['value' => '0'];
    }

    /**
     * Oracle: without the JIT, "(a+)+$" over fifteen "a" and a "!" runs
     * past ten backtracks.
     */
    #[Test]
    public function test_the_run_reports_the_limits_it_ran_under(): void
    {
        \ini_set('pcre.backtrack_limit', '10');
        set_error_handler(static fn (): bool => true);

        try {
            $oracle = preg_match('/(*NO_JIT)(a+)+$/', str_repeat('a', 15).'!');
        } finally {
            restore_error_handler();
        }
        $this->assertFalse($oracle, 'The oracle backtracks less.');
        $this->assertSame('Backtrack limit exhausted', preg_last_error_msg(), 'The oracle stops for another reason.');
        \ini_set('pcre.backtrack_limit', $this->before['pcre.backtrack_limit']);
        \ini_set('pcre.jit', '1');

        $confirmation = (new ConfirmationRunner())->confirm(
            '/(a+)+$/',
            new RedosAnalysis(RedosSeverity::High, 8),
            new ConfirmationOptions(steps: 1, iterations: 1, backtrackLimit: 10, recursionLimit: 500),
        );

        $this->assertTrue($confirmation->confirmed);
        $this->assertSame('backtrack_limit', $confirmation->evidence);
        $this->assertSame('0', $confirmation->jitSetting);
        $this->assertSame(10, $confirmation->backtrackLimit);
        $this->assertSame(500, $confirmation->recursionLimit);
        $this->assertSame('Backtrack limit exhausted', $confirmation->samples[0]->pregError ?? null);
        $this->assertSame(\PREG_BACKTRACK_LIMIT_ERROR, $confirmation->samples[0]->pregErrorCode ?? null);
        $this->assertSame('1', \ini_get('pcre.jit'));
        $this->assertSame($this->before['pcre.backtrack_limit'], \ini_get('pcre.backtrack_limit'));
    }
}

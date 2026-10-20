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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\LintRunFailure;
use PHPRegex\Linter\LintRunner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The steps of a lint run every front end takes alike, and the failure each
 * one ends with.
 */
final class LintRunnerTest extends TestCase
{
    #[Test]
    public function test_paths_that_exist_pass_the_check(): void
    {
        $this->expectNotToPerformAssertions();

        LintRunner::checkPaths([__DIR__, __FILE__, '']);
        LintRunner::checkPaths([], 'the paths');
    }

    #[Test]
    public function test_a_missing_path_given_on_the_command_line_is_a_usage_error(): void
    {
        $failure = $this->failureOf(static fn () => LintRunner::checkPaths([__DIR__, __DIR__.'/missing', __DIR__.'/other']));

        $this->assertSame('Path not found: '.__DIR__.'/missing', $failure->getMessage());
        $this->assertSame('usage', $failure->stage);
        $this->assertSame(2, $failure->exitCode);
    }

    #[Test]
    public function test_a_missing_configured_path_is_a_configuration_error_naming_the_setting(): void
    {
        $failure = $this->failureOf(static fn () => LintRunner::checkPaths(['missing'], 'php_regex.paths'));

        $this->assertSame('Path not found: missing (from php_regex.paths)', $failure->getMessage());
        $this->assertSame('config', $failure->stage);
        $this->assertSame(2, $failure->exitCode);
    }

    private function failureOf(\Closure $step): LintRunFailure
    {
        try {
            $step();
        } catch (LintRunFailure $failure) {
            return $failure;
        }

        $this->fail('The step did not stop the run.');
    }
}

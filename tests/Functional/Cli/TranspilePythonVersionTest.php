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

namespace PHPRegex\Tests\Functional\Cli;

use PHPRegex\Tests\Support\RunsRegexCli;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * regex transpile takes the Python release the target runs on: an atomic
 * group is carried from Python 3.11, refused before.
 */
final class TranspilePythonVersionTest extends TestCase
{
    use RunsRegexCli;

    #[Test]
    public function test_the_python_version_decides_what_is_carried(): void
    {
        [$refused, $before] = $this->runRegex(['transpile', '/(?>a)b/', '--target=python']);
        $this->assertSame(1, $refused, $before);
        $this->assertStringContainsString('Atomic groups', $before);

        [$carried, $after] = $this->runRegex(['transpile', '/(?>a)b/', '--target=python', '--python-version=3.11']);
        $this->assertSame(0, $carried, $after);
        $this->assertStringContainsString('(?>a)b', $after);
    }

    #[Test]
    public function test_a_python_version_that_names_no_release_is_a_usage_error(): void
    {
        [$code, , $errors] = $this->runRegex(['transpile', '/a/', '--target=python', '--python-version=three']);

        $this->assertSame(2, $code);
        $this->assertStringContainsString('"pythonVersion" must be a Python release', $errors);
    }
}

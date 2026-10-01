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

namespace PHPRegex\Tests\Unit;

use PHPRegex\Cli\Command\LintOutputRenderer;
use PHPRegex\Tests\Support\LintFunctionOverrides;
use PHPUnit\Framework\TestCase;

final class LintOutputRendererEdgeCasesTest extends TestCase
{
    protected function tearDown(): void
    {
        LintFunctionOverrides::reset();
    }

    public function test_relative_path_returns_normalized_when_cwd_missing(): void
    {
        $renderer = new LintOutputRenderer();
        LintFunctionOverrides::queueGetcwd(false);

        $path = $this->invokePrivate($renderer, 'relativePath', ['C:\\root\\file.php']);

        $this->assertSame('C:/root/file.php', $path);
    }

    public function test_relative_path_returns_normalized_when_cwd_empty(): void
    {
        $renderer = new LintOutputRenderer();
        LintFunctionOverrides::queueGetcwd('');

        $path = $this->invokePrivate($renderer, 'relativePath', ['/root/file.php']);

        $this->assertSame('/root/file.php', $path);
    }

    public function test_relative_path_returns_original_when_not_prefixed(): void
    {
        $renderer = new LintOutputRenderer();
        LintFunctionOverrides::queueGetcwd('/base');

        $path = $this->invokePrivate($renderer, 'relativePath', ['/other/file.php']);

        $this->assertSame('/other/file.php', $path);
    }

    /**
     * @param array<int, mixed> $args
     */
    private function invokePrivate(object $target, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionClass($target);
        $refMethod = $ref->getMethod($method);

        return $refMethod->invokeArgs($target, $args);
    }
}

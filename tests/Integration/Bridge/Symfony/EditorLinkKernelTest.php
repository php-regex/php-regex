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

namespace PHPRegex\Tests\Integration\Bridge\Symfony;

use PHPRegex\Tests\Support\FrameworkKernel;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The links of regex:lint's console report open the editor php_regex.ide
 * names, else the one FrameworkBundle links its own pages to: framework.ide,
 * which defaults to the SYMFONY_IDE environment variable.
 */
final class EditorLinkKernelTest extends TestCase
{
    use TemporaryProject;

    private const PROJECT = __DIR__.'/../../../Fixtures/Symfony/ValidatorMapping';

    /**
     * @param array<string, mixed> $phpRegex
     * @param array<string, mixed> $framework
     */
    #[Test]
    #[DataProvider('provideEditors')]
    public function test_the_links_open_the_editor_the_configuration_names(array $phpRegex, array $framework, ?string $link): void
    {
        $display = $this->lint($phpRegex, $framework);

        if (null === $link) {
            $this->assertStringNotContainsString("\e]8;;", $display);
        } else {
            $this->assertStringContainsString("\e]8;;".$link, $display);
        }
    }

    /**
     * @return iterable<string, array{phpRegex: array<string, mixed>, framework: array<string, mixed>, link: string|null}>
     */
    public static function provideEditors(): iterable
    {
        yield 'framework.ide' => ['phpRegex' => [], 'framework' => ['ide' => 'phpstorm'], 'link' => 'phpstorm://open?file='];
        yield 'a URL template in framework.ide' => ['phpRegex' => [], 'framework' => ['ide' => 'myide://%%f@%%l'], 'link' => 'myide://'];
        yield 'php_regex.ide before framework.ide' => ['phpRegex' => ['ide' => 'vscode'], 'framework' => ['ide' => 'phpstorm'], 'link' => 'vscode://file/'];
        yield 'php_regex.ide alone' => ['phpRegex' => ['ide' => 'vscode'], 'framework' => [], 'link' => 'vscode://file/'];
        yield 'neither' => ['phpRegex' => [], 'framework' => [], 'link' => null];
    }

    /**
     * A debug kernel's framework.ide defaults to SYMFONY_IDE.
     */
    #[Test]
    public function test_the_links_open_the_editor_symfony_ide_names_in_a_debug_kernel(): void
    {
        $previous = [getenv('SYMFONY_IDE'), $_SERVER['SYMFONY_IDE'] ?? null, $_ENV['SYMFONY_IDE'] ?? null];
        putenv('SYMFONY_IDE=vscode');
        $_SERVER['SYMFONY_IDE'] = $_ENV['SYMFONY_IDE'] = 'vscode';

        try {
            $display = $this->lint([], [], true);
        } finally {
            putenv(false === $previous[0] ? 'SYMFONY_IDE' : 'SYMFONY_IDE='.$previous[0]);
            if (null === $previous[1]) {
                unset($_SERVER['SYMFONY_IDE']);
            } else {
                $_SERVER['SYMFONY_IDE'] = $previous[1];
            }
            if (null === $previous[2]) {
                unset($_ENV['SYMFONY_IDE']);
            } else {
                $_ENV['SYMFONY_IDE'] = $previous[2];
            }
        }

        $this->assertStringContainsString("\e]8;;vscode://file/", $display);
    }

    /**
     * @param array<string, mixed> $phpRegex
     * @param array<string, mixed> $framework
     */
    private function lint(array $phpRegex, array $framework, bool $debug = false): string
    {
        $kernel = new FrameworkKernel((string) realpath(self::PROJECT), $this->makeProject(), $phpRegex + ['paths' => ['Entity']], $framework, $debug);

        try {
            $tester = new CommandTester((new Application($kernel))->find('regex:lint'));
            $tester->execute(['--jobs' => '1', '--no-validators' => true], ['decorated' => true, 'capture_stderr_separately' => true]);
        } finally {
            $kernel->shutdown();
        }

        return $tester->getDisplay();
    }
}

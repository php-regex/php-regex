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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use PHPStan\Testing\RuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A "phpRegex.phpVersion" the extension refuses stops PHPStan when it
 * starts, before any file is analysed, as a configuration error that names
 * the setting: never as an internal error of PHPStan asking for a bug
 * report. PHPStan runs here as a user runs it, on a project of its own,
 * in process and with its workers.
 */
final class ExtensionTargetConfigurationErrorTest extends TestCase
{
    /**
     * @param list<string> $expected what the error says, each part as written
     */
    #[Test]
    #[DataProvider('provideRefusedSettings')]
    public function test_a_refused_target_is_a_configuration_error(string $parameters, array $expected): void
    {
        foreach ([true, false] as $inProcess) {
            [$status, $output] = self::analyse($parameters, $inProcess);

            $this->assertNotSame(0, $status, $output);
            $this->assertStringNotContainsString('Internal error', $output);
            $this->assertStringNotContainsString('phpstan/issues', $output);
            // PHPStan names the file that stopped it, before any is analysed.
            $this->assertStringContainsString('while loading bootstrap file', $output);
            foreach ($expected as $part) {
                $this->assertStringContainsString($part, $output);
            }
        }
    }

    /**
     * @return iterable<string, array{parameters: string, expected: list<string>}>
     */
    public static function provideRefusedSettings(): iterable
    {
        yield 'a range sharing no version with PHPStan\'s' => [
            'parameters' => "phpVersion:\n        min: 80100\n        max: 80399\n    phpRegex:\n        phpVersion: '>=8.5'",
            'expected' => ['phpRegex.phpVersion ">=8.5" shares no version with phpVersion {min: 80100, max: 80399}'],
        ];
        yield 'a constraint with no lowest version' => [
            'parameters' => "phpRegex:\n        phpVersion: '<8.4'",
            'expected' => ['phpRegex.phpVersion', 'names a lowest version'],
        ];
        yield 'a range on PCRE1 only' => [
            'parameters' => "phpRegex:\n        phpVersion: '~7.1.0'",
            'expected' => ['phpRegex.phpVersion allows PHP 7.1: PHP 7.2 and earlier ship PCRE1'],
        ];
        yield 'min above max' => [
            'parameters' => "phpRegex:\n        phpVersion:\n            min: 80600\n            max: 80100",
            'expected' => ['phpRegex.phpVersion', 'min not above max'],
        ];
    }

    /**
     * The exit code and the output of PHPStan on a project of a few files,
     * analysed in process (--debug) or with its workers.
     *
     * @return array{int, string}
     */
    private static function analyse(string $parameters, bool $inProcess): array
    {
        $root = \dirname(__DIR__, 4);
        $project = sys_get_temp_dir().'/regex-phpstan-target-'.bin2hex(random_bytes(4));
        mkdir($project.'/src', 0o777, true);
        for ($file = 0; $file < 30; $file++) {
            file_put_contents($project.'/src/Probe'.$file.'.php', "<?php\n\npreg_match('/a{$file}/', 'a');\n");
        }
        file_put_contents($project.'/phpstan.neon', \sprintf(
            "includes:\n    - %s/src/PHPStan/extension.neon\n\nparameters:\n    level: 0\n    tmpDir: %s/tmp\n    %s\n",
            $root,
            $project,
            $parameters,
        ));

        try {
            $process = proc_open(
                [
                    \PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'auto_prepend_file=',
                    self::phpstan(), 'analyse', '-c', $project.'/phpstan.neon', $project.'/src',
                    '--no-progress', '--error-format=raw', '--autoload-file='.$root.'/vendor/autoload.php',
                    ...($inProcess ? ['--debug'] : []),
                ],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $project,
            );
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [proc_close($process), $output];
        } finally {
            self::remove($project);
        }
    }

    /**
     * The PHPStan the suite runs its rule tests with.
     */
    private static function phpstan(): string
    {
        $file = (string) (new \ReflectionClass(RuleTestCase::class))->getFileName();
        self::assertSame(1, preg_match('~^phar://(.+\.phar)/~', $file, $match), $file);

        return $match[1];
    }

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    self::remove($path.'/'.$entry);
                }
            }
            rmdir($path);

            return;
        }

        unlink($path);
    }
}

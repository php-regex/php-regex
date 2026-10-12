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

namespace PHPRegex\Tests\Unit\Toolkit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The options of Regex::create() and RegexParser::create() are a closed
 * shape: a misspelt key or a value of the wrong type is a static error
 * where it is written, as the runtime check already refuses it, not a
 * mixed array PHPStan lets through.
 */
final class OptionsShapeTest extends TestCase
{
    private const FIXTURE = <<<'PHP_WRAP'
    <?php
    
    declare(strict_types=1);
    
    \PHPRegex\Toolkit\Regex::create(['max_pattern_lenght' => 5]);
    \PHPRegex\Parser\RegexParser::create(['cache' => 42]);
    \PHPRegex\Toolkit\Regex::create(['php_version' => []]);
    \PHPRegex\Toolkit\Regex::create(['php_version' => '8.2', 'cache' => null, 'redos_ignored_patterns' => ['/a/']]);
    \PHPRegex\Parser\RegexParser::create(['max_recursion_depth' => 10]);
    
    PHP_WRAP;

    #[Test]
    public function test_phpstan_refuses_an_option_outside_the_shape(): void
    {
        $lines = array_map(static fn (array $error): int => $error['line'], self::errors());

        $this->assertSame([5, 6, 7], $lines);
    }

    /**
     * @return list<array{line: int, message: string}>
     */
    private static function errors(): array
    {
        $root = \dirname(__DIR__, 3);
        $directory = sys_get_temp_dir().'/regex-options-shape-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $fixture = $directory.'/OptionsProbe.php';
        file_put_contents($fixture, self::FIXTURE);

        try {
            $process = proc_open(
                [\PHP_BINARY, $root.'/tools/phpstan/vendor/bin/phpstan', 'analyse', $fixture, '--error-format=json', '--no-progress'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
            );
            self::assertIsResource($process);
            $stdout = (string) stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            $json = null;
            foreach (array_reverse(explode("\n", $stdout)) as $line) {
                if (str_starts_with($line, '{')) {
                    $json = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);

                    break;
                }
            }
            self::assertIsArray($json, $stdout);
            $files = $json['files'] ?? [];
            self::assertIsArray($files);
            $file = $files[$fixture] ?? [];
            self::assertIsArray($file);
            $messages = $file['messages'] ?? [];
            self::assertIsArray($messages);

            $errors = [];
            foreach ($messages as $message) {
                self::assertIsArray($message);
                self::assertIsInt($message['line'] ?? null);
                self::assertIsString($message['message'] ?? null);
                $errors[] = ['line' => $message['line'], 'message' => $message['message']];
            }

            return $errors;
        } finally {
            unlink($fixture);
            rmdir($directory);
        }
    }
}

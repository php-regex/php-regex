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

namespace PHPRegex\Tests\Unit\TestUtils;

use PHPRegex\Tests\TestUtils\PropertyByteTablesWriter;
use PHPUnit\Framework\TestCase;

final class PropertyByteTablesWriterTest extends TestCase
{
    public function test_probes_use_a_fresh_private_directory_instead_of_planted_symlinks(): void
    {
        if ('Windows' === \PHP_OS_FAMILY) {
            $this->markTestSkipped('Requires POSIX executable files and symlinks.');
        }

        $planted = sys_get_temp_dir().'/property-bytes-'.getmypid();
        if (file_exists($planted) || is_link($planted)) {
            $this->markTestSkipped('The legacy temporary path is already in use.');
        }

        $fixture = sys_get_temp_dir().'/property-bytes-test-'.bin2hex(random_bytes(16));
        mkdir($fixture, 0o700);
        mkdir($planted, 0o700);
        try {
            file_put_contents($fixture.'/victim', 'unchanged');
            symlink($fixture.'/victim', $planted.'/input');
            symlink($fixture.'/victim', $planted.'/output');
            $script = <<<'PHP'
                <?php
                $directory = dirname($argv[2]);
                file_put_contents(__DIR__.'/observed', json_encode([
                    $directory,
                    fileperms($directory) & 0777,
                    file_get_contents($argv[2]),
                    dirname($argv[3]),
                ]));
                file_put_contents($argv[3], "/a/\n".str_repeat("No match\n", 256));
                PHP;
            file_put_contents($fixture.'/pcre2test', '#!'.\PHP_BINARY."\n".$script);
            chmod($fixture.'/pcre2test', 0o700);

            $matches = new \ReflectionMethod(PropertyByteTablesWriter::class, 'matches');
            $this->assertSame([''], $matches->invoke(null, $fixture.'/pcre2test', ['/a/']));
            [$directory, $permissions, $input, $outputDirectory] = json_decode((string) file_get_contents($fixture.'/observed'), true, 512, \JSON_THROW_ON_ERROR);
            $this->assertNotSame($planted, $directory);
            $this->assertSame(0o700, $permissions);
            $this->assertSame($directory, $outputDirectory);
            $this->assertStringStartsWith("/a/\n\\x{00}\n", $input);
            $this->assertFileDoesNotExist($directory);
            $this->assertSame('unchanged', file_get_contents($fixture.'/victim'));
            $this->assertTrue(is_link($planted.'/input'));
            $this->assertTrue(is_link($planted.'/output'));
        } finally {
            foreach ([$planted.'/input', $planted.'/output', $fixture.'/victim', $fixture.'/pcre2test', $fixture.'/observed'] as $file) {
                if (file_exists($file) || is_link($file)) {
                    unlink($file);
                }
            }
            if (is_dir($planted)) {
                rmdir($planted);
            }
            rmdir($fixture);
        }
    }
}

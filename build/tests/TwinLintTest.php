<?php

declare(strict_types=1);

namespace PHPRegex\Build\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Every file of the twin compiles on the PHP that runs the suite: PHP 7.4
 * refuses the syntax the downgrade must remove ($object::class, 0o octal
 * literals, a trailing comma in a parameter list), and each later PHP
 * deprecates its own (an implicitly nullable parameter from 8.4).
 */
final class TwinLintTest extends TestCase
{
    public function test_every_file_of_the_twin_compiles_without_notice(): void
    {
        $failures = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__).'/php74/src', \FilesystemIterator::SKIP_DOTS));
        $count = 0;
        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $count++;
            $output = [];
            exec(escapeshellarg(\PHP_BINARY).' -d error_reporting=-1 -d display_errors=1 -l '.escapeshellarg($file->getPathname()).' 2>&1', $output, $status);
            if (0 !== $status || 1 !== \count($output)) {
                $failures[] = implode("\n", $output);
            }
        }

        $this->assertGreaterThan(200, $count);
        $this->assertSame([], $failures, implode("\n", $failures));
    }
}

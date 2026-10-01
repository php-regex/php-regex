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

namespace PhpRegex\Tests\Support;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * A throwaway project directory per test: files written into a fresh
 * temporary directory, the working directory moved into it on demand, and
 * both undone after the test whatever order the tests run in.
 */
trait TemporaryProject
{
    private string|false $originalWorkingDirectory = false;

    /**
     * @var list<string>
     */
    private array $temporaryProjects = [];

    #[Before]
    protected function rememberWorkingDirectory(): void
    {
        $this->originalWorkingDirectory = getcwd();
        $this->temporaryProjects = [];
    }

    #[After]
    protected function removeTemporaryProjects(): void
    {
        if (false !== $this->originalWorkingDirectory) {
            chdir($this->originalWorkingDirectory);
        }

        foreach ($this->temporaryProjects as $directory) {
            self::removeTree($directory);
        }
        $this->temporaryProjects = [];
    }

    /**
     * @param array<string, string> $files relative path => contents
     *
     * @return string the real path of the new directory
     */
    private function makeProject(array $files = []): string
    {
        $directory = sys_get_temp_dir().'/regex-parser-project-'.bin2hex(random_bytes(8));
        mkdir($directory, 0o700, true);
        $directory = (string) realpath($directory);
        $this->temporaryProjects[] = $directory;

        foreach ($files as $path => $contents) {
            $target = $directory.'/'.$path;
            if (!is_dir(\dirname($target))) {
                mkdir(\dirname($target), 0o700, true);
            }
            file_put_contents($target, $contents);
        }

        return $directory;
    }

    /**
     * @param array<string, string> $files relative path => contents
     */
    private function enterProject(array $files = []): string
    {
        $directory = $this->makeProject($files);
        chdir($directory);

        return $directory;
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::removeTree($path.'/'.$entry);
        }
        @rmdir($path);
    }
}

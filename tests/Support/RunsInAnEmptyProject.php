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

namespace PHPRegex\Tests\Support;

use PHPRegex\Parser\Text\Path;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;

/**
 * Every test of the class runs in a fresh, empty temporary directory: a
 * command that reads one pattern finds its target where lint finds it, in
 * the working directory, and this repository's composer.json would judge
 * it for PHP 8.1 and later. In an empty directory it is judged for the
 * running PHP, whatever directory PHPUnit was started from. A process the
 * test starts without a working directory of its own inherits this one.
 *
 * Self-contained, so that it does not depend on the order PHPUnit runs the
 * hooks of TemporaryProject in: the directory it leaves is the one it
 * found, and the directory it made is removed.
 */
trait RunsInAnEmptyProject
{
    private string|false $directoryBeforeTheEmptyProject = false;

    private ?string $emptyProject = null;

    #[Before]
    protected function enterAnEmptyProject(): void
    {
        $this->directoryBeforeTheEmptyProject = getcwd();

        $directory = sys_get_temp_dir().'/regex-parser-empty-project-'.bin2hex(random_bytes(8));
        mkdir($directory, 0o700, true);
        // Spelled with "/" on every host, as the library spells the paths it reports.
        $this->emptyProject = Path::fromHost((string) realpath($directory));
        chdir($this->emptyProject);
    }

    #[After]
    protected function leaveTheEmptyProject(): void
    {
        if (false !== $this->directoryBeforeTheEmptyProject) {
            chdir($this->directoryBeforeTheEmptyProject);
        }

        if (null !== $this->emptyProject) {
            self::removeEmptyProject($this->emptyProject);
            $this->emptyProject = null;
        }
    }

    private static function removeEmptyProject(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::removeEmptyProject($path.'/'.$entry);
        }
        @rmdir($path);
    }
}

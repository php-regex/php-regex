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

namespace PHPRegex\Tests\Unit\Bridge\Infection;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The script that runs Infection on the fixture project writes the fixture
 * into the project it is given: only into a project that holds none of it.
 */
final class SelfCheckScriptTest extends TestCase
{
    #[Test]
    #[DataProvider('provideFilesOfTheProject')]
    public function test_the_script_leaves_a_project_that_already_holds_a_file_it_writes(string $file): void
    {
        $project = self::project();
        file_put_contents($project.'/'.$file, 'the project\'s own');

        try {
            [$status, $errors] = self::check($project);

            $this->assertSame(2, $status, $errors);
            $this->assertStringContainsString($file, $errors);
            $this->assertSame('the project\'s own', file_get_contents($project.'/'.$file));
            $this->assertFileDoesNotExist($project.'/src/Slug.php');
        } finally {
            self::remove($project);
        }
    }

    #[Test]
    public function test_the_script_leaves_a_project_whose_file_is_a_link_to_nothing(): void
    {
        // A copy would write through the link, wherever it points.
        $project = self::project();
        $this->assertTrue(symlink($project.'/elsewhere.xml', $project.'/phpunit.xml.dist'));

        try {
            [$status, $errors] = self::check($project);

            $this->assertSame(2, $status, $errors);
            $this->assertStringContainsString('phpunit.xml.dist', $errors);
            $this->assertFileDoesNotExist($project.'/elsewhere.xml');
            $this->assertTrue(is_link($project.'/phpunit.xml.dist'));
        } finally {
            self::remove($project);
        }
    }

    /**
     * @return iterable<string, array{file: string}>
     */
    public static function provideFilesOfTheProject(): iterable
    {
        yield 'its configuration' => ['file' => 'phpunit.xml.dist'];
        // The report each run writes, cleared before it.
        yield 'its report' => ['file' => 'infection-log.json'];
    }

    /**
     * A project with an autoloader and an Infection binary, both empty.
     */
    private static function project(): string
    {
        $project = sys_get_temp_dir().'/infection-selfcheck-'.bin2hex(random_bytes(4));
        mkdir($project.'/vendor', 0o777, true);
        file_put_contents($project.'/vendor/autoload.php', '<?php');
        file_put_contents($project.'/infection', '<?php');

        return $project;
    }

    /**
     * @return array{int, string} the exit status and what went to standard error
     */
    private static function check(string $project): array
    {
        $process = proc_open([\PHP_BINARY, '-d', 'auto_prepend_file=', \dirname(__DIR__, 4).'/tests/Tools/infection_bridge_selfcheck.php', $project, $project.'/infection'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $errors];
    }

    /**
     * Removes the project, with whatever a run may have written into it.
     */
    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                self::remove($path.'/'.$entry);
            }
        }
        rmdir($path);
    }
}

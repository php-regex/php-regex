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

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The script that runs Infection on the fixture project writes the fixture
 * into the project it is given: only into a project that holds none of it.
 */
final class SelfCheckScriptTest extends TestCase
{
    #[Test]
    public function test_the_script_leaves_a_project_that_already_holds_a_fixture_file(): void
    {
        $project = sys_get_temp_dir().'/infection-selfcheck-'.bin2hex(random_bytes(4));
        mkdir($project.'/vendor', 0o777, true);
        file_put_contents($project.'/vendor/autoload.php', '<?php');
        file_put_contents($project.'/infection', '<?php');
        file_put_contents($project.'/phpunit.xml.dist', 'the project\'s own');

        try {
            $process = proc_open([\PHP_BINARY, '-d', 'auto_prepend_file=', \dirname(__DIR__, 4).'/tests/Tools/infection_bridge_selfcheck.php', $project, $project.'/infection'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $this->assertIsResource($process);
            stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);

            $this->assertSame(2, $status, $errors);
            $this->assertStringContainsString('phpunit.xml.dist', $errors);
            $this->assertSame('the project\'s own', file_get_contents($project.'/phpunit.xml.dist'));
            $this->assertFileDoesNotExist($project.'/infection.json5');
        } finally {
            foreach (['vendor/autoload.php', 'infection', 'phpunit.xml.dist'] as $file) {
                @unlink($project.'/'.$file);
            }
            @rmdir($project.'/vendor');
            @rmdir($project);
        }
    }
}

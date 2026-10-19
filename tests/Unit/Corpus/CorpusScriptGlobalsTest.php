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

namespace PHPRegex\Tests\Unit\Corpus;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rector\Config\RectorConfig;

/**
 * bin/corpus declares its functions in the global namespace, where the
 * tests load it beside every other package of the suite: Rector's scoped
 * build declares global functions too, each when no function of that name
 * exists yet. The script loads whichever of the two comes first.
 */
final class CorpusScriptGlobalsTest extends TestCase
{
    #[Test]
    public function test_the_corpus_script_loads_after_the_global_functions_of_rector(): void
    {
        $scoperAutoload = \dirname((string) (new \ReflectionClass(RectorConfig::class))->getFileName(), 3).'/vendor/scoper-autoload.php';
        $this->assertFileExists($scoperAutoload);

        $script = \sprintf(
            'require %s; define("CORPUS_INCLUDED", true); require %s; echo "loaded";',
            var_export($scoperAutoload, true),
            var_export(\dirname(__DIR__, 3).'/bin/corpus', true),
        );
        $process = proc_open([\PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'auto_prepend_file=', '-d', 'display_errors=stderr', '-r', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $this->assertSame('loaded', $stdout, $stderr);
    }
}

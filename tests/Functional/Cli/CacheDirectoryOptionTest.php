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

namespace PHPRegex\Tests\Functional\Cli;

use PHPRegex\Cli\Application;
use PHPRegex\Cli\Command\CommandInterface;
use PHPRegex\Cli\GlobalOptionsParser;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Parser\Hir\ClassSetProvider;
use PHPRegex\Parser\Hir\ClassSetStore;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPRegex\Tests\TestUtils\OutputFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "--cache-dir <dir>" keeps what the engine answered about Unicode classes
 * in the directory, for the next run to read instead of scanning again:
 * the second run reports what the first did. Without the option the binary
 * keeps nothing between runs.
 */
final class CacheDirectoryOptionTest extends TestCase
{
    use TemporaryProject;

    private const PHP_FILE = "<?php\n\npreg_match('/^(\\p{Han}|\\p{Hiragana}|[\\w-]+)+\$/u', \$subject);\npreg_match('/\\P{Lu}*\\S/u', \$subject);\n";

    #[Test]
    public function test_lint_keeps_the_class_scans_in_the_cache_directory(): void
    {
        $project = $this->makeProject(['src/a.php' => self::PHP_FILE]);

        $first = self::lint($project, ['--cache-dir', 'var/regex']);
        $kept = glob($project.'/var/regex/class-sets-*/*.set') ?: [];
        $this->assertNotSame([], $kept, $first);

        $second = self::lint($project, ['--cache-dir=var/regex']);
        $this->assertSame($first, $second);
        $this->assertSame($kept, glob($project.'/var/regex/class-sets-*/*.set') ?: []);
        $this->assertStringContainsString('regex.lint.redos', $first);
    }

    /**
     * The option sets where the run keeps the answers, process-wide; a run
     * without it keeps them nowhere, whatever ran before in the process.
     */
    #[Test]
    public function test_each_run_keeps_the_answers_where_its_option_says(): void
    {
        $project = $this->makeProject();
        // Records where the answers are kept while it runs.
        $command = new class implements CommandInterface {
            /**
             * @var list<ClassSetStore|null>
             */
            public array $stores = [];

            public function getName(): string
            {
                return 'lint';
            }

            public function getAliases(): array
            {
                return [];
            }

            public function getDescription(): string
            {
                return 'records the class set store';
            }

            public function run(Input $input, Output $output): int
            {
                $store = (new \ReflectionClass(ClassSetProvider::class))->getStaticPropertyValue('store');
                $this->stores[] = $store instanceof ClassSetStore ? $store : null;

                return 0;
            }
        };
        $app = new Application(new GlobalOptionsParser(), OutputFactory::create(), $command);
        $app->register($command);

        ob_start();

        try {
            $app->run(['regex', 'lint', '--cache-dir', $project.'/cache']);
            $app->run(['regex', 'lint']);
        } finally {
            ob_end_clean();
            ClassSetProvider::persistIn(null);
        }

        $this->assertCount(2, $command->stores);
        $this->assertSame((new ClassSetStore($project.'/cache'))->directory(), $command->stores[0]?->directory());
        $this->assertNull($command->stores[1]);
    }

    #[Test]
    public function test_lint_keeps_nothing_without_the_option(): void
    {
        $project = $this->makeProject(['src/a.php' => self::PHP_FILE]);

        self::lint($project, []);

        $this->assertSame(['.', '..', 'src'], scandir($project));
    }

    /**
     * The JSON report of a run, ReDoS on, in a fresh process.
     *
     * @param list<string> $options
     */
    private static function lint(string $project, array $options): string
    {
        $command = [\PHP_BINARY, '-d', 'xdebug.mode=off', \dirname(__DIR__, 3).'/src/Cli/bin/regex', ...$options, 'lint', 'src/', '--redos', '--redos-threshold=low', '--format=json', '--no-visuals'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertNotSame('', $stdout, $stderr);

        // The time a run took is no part of what it found.
        return (string) preg_replace('/"(?:time|duration|elapsed|memory)[A-Za-z]*":\s*[0-9.]+/', '"time": 0', $stdout);
    }
}

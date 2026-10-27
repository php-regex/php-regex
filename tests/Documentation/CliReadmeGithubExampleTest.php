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

namespace PHPRegex\Tests\Documentation;

use PHPRegex\Tests\Support\PcreRule;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The GitHub-format example of the CLI package README is the output of a
 * real run: "regex lint src/ --format=github" on a project requiring PHP
 * ^8.2 whose src/demo.php holds a nested quantifier on its third line.
 */
final class CliReadmeGithubExampleTest extends TestCase
{
    use PcreRule;
    use TemporaryProject;

    private const COMMAND = 'vendor/bin/regex lint src/ --format=github --no-visuals';

    #[Test]
    public function test_the_readme_github_example_is_the_output_of_a_real_run(): void
    {
        $this->requiresBundledPcre2(80200, __FUNCTION__);
        $project = $this->makeProject([
            'composer.json' => '{"name": "acme/demo", "require": {"php": "^8.2"}}',
            'src/demo.php' => "<?php\n\npreg_match('/(a+)+b/', \$subject);\n",
        ]);

        $command = [\PHP_BINARY, \dirname(__DIR__, 2).'/src/Cli/bin/regex', 'lint', 'src/', '--format=github', '--no-visuals'];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $project);
        $this->assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $printed = array_values(array_filter(explode("\n", $stderr.$stdout), static fn (string $line): bool => '' !== $line));

        $this->assertSame(self::readmeExample(), $printed);
    }

    /**
     * The lines the README prints under the command, without their "# ".
     *
     * @return list<string>
     */
    private static function readmeExample(): array
    {
        $readme = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Cli/README.md');
        $start = strpos($readme, self::COMMAND."\n");
        self::assertIsInt($start, 'The README no longer shows "'.self::COMMAND.'".');

        $lines = [];
        foreach (\array_slice(explode("\n", substr($readme, $start)), 1) as $line) {
            if (!str_starts_with($line, '# ')) {
                break;
            }
            $lines[] = substr($line, 2);
        }

        return $lines;
    }
}

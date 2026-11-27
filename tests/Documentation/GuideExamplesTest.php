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

use PHPRegex\Tests\Support\DocumentationPages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The pages whose every printed line is checked against the engine. A
 * ```php fence of one of them is a whole program: it runs to a zero exit
 * code, and every line it prints stands in a comment. The comment closes
 * the statement that prints:
 *
 *     echo preg_replace('/\d+/', 'N', 'a1b22c'); // aNbNc
 *
 * A `\n` in the comment is a line break (what var_dump() prints, one
 * trailing break removed). A fence that printed a line no comment gives
 * fails the page: what a reader sees as the result is what PHP printed.
 */
#[Group('documentation')]
final class GuideExamplesTest extends TestCase
{
    /**
     * A page is listed here once its fences follow the contract.
     */
    private const PAGES = [
        'docs/guides/preg-replace.md',
        'docs/guides/php-regex-modifiers.md',
        'docs/cheat-sheet.md',
    ];

    /**
     * The statements whose comment gives what they print.
     */
    private const STATEMENT = '/^(?P<indent>[ \t]*)(?P<statement>(?:echo|print|var_dump|var_export|print_r)\b.*;)[ \t]*\/\/[ \t]?(?P<expected>.*)$/';

    #[Test]
    #[DataProvider('provideFences')]
    public function test_a_fence_runs_and_prints_what_its_comments_say(string $page, int $line, string $code): void
    {
        [$exit, $stdout, $stderr] = self::runProgram(self::instrumented($code));
        $at = $page.' line '.$line;

        $this->assertSame(0, $exit, $at.': the fence exits with code '.$exit.' — '.trim($stderr));
        $this->assertStringNotContainsString('MISMATCH', $stdout, $at.': a comment does not give what its statement printed.');
        $this->assertSame([], array_values(array_filter(self::lines($stdout), static fn (string $printed): bool => 'ok' !== $printed)), $at.': every printed line stands in the comment of the statement that prints it.');
    }

    #[Test]
    public function test_each_page_holds_at_least_one_checked_statement(): void
    {
        foreach (self::PAGES as $page) {
            $statements = 0;
            foreach (DocumentationPages::phpFences($page) as $fence) {
                $statements += preg_match_all(self::STATEMENT, $fence['code']);
            }

            $this->assertGreaterThan(0, $statements, $page.' checks what its examples print.');
        }
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function provideFences(): iterable
    {
        foreach (self::PAGES as $page) {
            foreach (DocumentationPages::phpFences($page) as $index => $fence) {
                yield basename($page, '.md').' fence '.$index => [$page, $fence['line'], $fence['code']];
            }
        }
    }

    /**
     * The fence as a program: a statement that carries its result in a
     * comment keeps its place and its variables, between a start of the
     * capture and the comparison of what was printed. The checker prints
     * "ok", or the difference.
     */
    private static function instrumented(string $code): string
    {
        $lines = [];
        foreach (preg_split('/\R/', $code) ?: [] as $line) {
            if (1 === preg_match(self::STATEMENT, $line, $statement)) {
                $expected = str_replace('\\n', "\n", $statement['expected']);
                $lines[] = $statement['indent'].'__start(); '.$statement['statement'].' __end('.var_export($expected, true).');';

                continue;
            }

            $lines[] = $line;
        }

        return "<?php\n\nfunction __start(): void\n{\n    ob_start();\n}\n\n"
            ."function __end(string \$expected): void\n{\n"
            ."    \$printed = (string) ob_get_clean();\n"
            ."    if (rtrim(\$printed, \"\\n\") === rtrim(\$expected, \"\\n\")) {\n"
            ."        echo \"ok\\n\";\n\n"
            ."        return;\n"
            ."    }\n"
            ."    echo 'MISMATCH: expected ', json_encode(\$expected), ', printed ', json_encode(\$printed), \"\\n\";\n"
            ."}\n\n".implode("\n", $lines)."\n";
    }

    /**
     * The program under the interpreter that runs the suite, without an ini
     * file: the same on every machine. The deadline stops an endless loop.
     *
     * @return array{int, string, string}
     */
    private static function runProgram(string $source): array
    {
        $file = tempnam(sys_get_temp_dir(), 'phpregex-guide-fence');
        \assert(\is_string($file));
        file_put_contents($file, $source);
        $stdoutFile = $file.'.out';
        $stderrFile = $file.'.err';

        $process = proc_open(
            [\PHP_BINARY, '-n', '-d', 'display_errors=0', '-d', 'error_reporting=E_ALL', '-d', 'pcre.jit=1', $file],
            [1 => ['file', $stdoutFile, 'w'], 2 => ['file', $stderrFile, 'w']],
            $pipes,
        );
        \assert(\is_resource($process));

        $deadline = microtime(true) + 10.0;
        do {
            $status = proc_get_status($process);
            if ($status['running']) {
                usleep(5000);
            }
        } while ($status['running'] && microtime(true) < $deadline);

        if ($status['running']) {
            proc_terminate($process);
            proc_close($process);
            unlink($stdoutFile);
            unlink($stderrFile);
            unlink($file);

            return [124, '', ''];
        }

        $exitCode = $status['exitcode'];
        proc_close($process);
        $stdout = (string) file_get_contents($stdoutFile);
        $stderr = (string) file_get_contents($stderrFile);
        unlink($stdoutFile);
        unlink($stderrFile);
        unlink($file);

        return [$exitCode, $stdout, $stderr];
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        $lines = preg_split('/\R/', $text) ?: [];

        return array_values(array_filter($lines, static fn (string $line): bool => '' !== trim($line)));
    }
}

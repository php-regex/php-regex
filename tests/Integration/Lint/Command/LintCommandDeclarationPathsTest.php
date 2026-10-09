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

namespace PHPRegex\Tests\Integration\Lint\Command;

use PHPRegex\Cli\Command\HelpCommand;
use PHPRegex\Cli\Command\LintCommand;
use PHPRegex\Cli\Command\LintOutputRenderer;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\Config\LintArgumentParser;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Linter\Config\LintExtractorFactory;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * regex lint reads the functions marked #[RegexPattern] in the configured
 * paths and in vendor/ of the working directory, whatever paths it lints
 * and however many jobs share the work.
 */
final class LintCommandDeclarationPathsTest extends TestCase
{
    use TemporaryProject;

    private const PROJECT_HELPER = <<<'CODE'
        <?php

        namespace App;

        use PHPRegex\Parser\Attribute\RegexPattern;

        function grep(#[RegexPattern] string $regex, string $subject): void
        {
        }
        CODE;

    private const VENDOR_HELPER = <<<'CODE'
        <?php

        namespace Acme;

        use JetBrains\PhpStorm\Language;

        function search(string $subject, #[Language('RegExp')] string $regex): void
        {
        }
        CODE;

    private const CALLER = "<?php\n\n\\App\\grep('/b02(/', 'x');\n\\Acme\\search('x', '/c03(/');\n";

    /**
     * @param list<string> $args
     */
    #[Test]
    #[DataProvider('provideRuns')]
    public function test_lint_reads_declarations_of_the_project_and_of_vendor(array $args): void
    {
        $this->enterProject([
            'regex.json' => '{"paths": ["lib"]}',
            'lib/Support/helper.php' => self::PROJECT_HELPER,
            'lib/caller.php' => self::CALLER,
            'vendor/acme/search/helper.php' => self::VENDOR_HELPER,
        ]);

        [$exitCode, $document] = $this->lintJson([...$args, '--no-redos', '--format=json']);

        $this->assertSame(1, $exitCode);
        $patterns = [];
        foreach (JsonContract::asArray($document['results'] ?? null) as $result) {
            $patterns[] = JsonContract::asArray($result)['pattern'] ?? null;
        }
        sort($patterns);
        $this->assertSame(['/b02(/', '/c03(/'], $patterns);
    }

    /**
     * @return iterable<string, array{args: list<string>}>
     */
    public static function provideRuns(): iterable
    {
        yield 'the configured paths, jobs left to the machine' => ['args' => []];
        yield 'the configured paths, four jobs' => ['args' => ['--jobs=4']];
        yield 'one file of them, one job' => ['args' => ['lib/caller.php', '--jobs=1']];
    }

    /**
     * With no paths configured, the project is the working directory.
     */
    #[Test]
    public function test_lint_reads_declarations_of_the_working_directory_without_configuration(): void
    {
        $this->enterProject([
            'lib/Support/helper.php' => self::PROJECT_HELPER,
            'lib/caller.php' => self::CALLER,
            'vendor/acme/search/helper.php' => self::VENDOR_HELPER,
        ]);

        [$exitCode, $document] = $this->lintJson(['lib/caller.php', '--no-redos', '--format=json', '--jobs=1']);

        $this->assertSame(1, $exitCode);
        $this->assertCount(2, JsonContract::asArray($document['results'] ?? null));
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, array<mixed>}
     */
    private function lintJson(array $args): array
    {
        $command = new LintCommand(
            new HelpCommand(),
            new LintConfigLoader(),
            new LintDefaultsBuilder(),
            new LintArgumentParser(),
            new LintExtractorFactory(),
            new LintOutputRenderer(),
        );
        $errors = fopen('php://memory', 'w+');
        $this->assertIsResource($errors);
        $output = new Output(false, false, '#', '-', $errors);
        $input = new Input('lint', $args, new GlobalOptions(false, false, false, true, null, null), []);

        ob_start();

        try {
            $exitCode = $command->run($input, $output);
        } finally {
            $stdout = (string) ob_get_clean();
        }
        fclose($errors);

        return [$exitCode, JsonContract::decodeDocument($stdout)];
    }
}

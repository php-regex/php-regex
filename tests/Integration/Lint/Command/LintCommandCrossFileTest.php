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
use PHPRegex\Cli\Command\LintArgumentParser;
use PHPRegex\Cli\Command\LintCommand;
use PHPRegex\Cli\Command\LintOutputRenderer;
use PHPRegex\Cli\GlobalOptions;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Output;
use PHPRegex\Linter\Config\LintConfigLoader;
use PHPRegex\Linter\Config\LintDefaultsBuilder;
use PHPRegex\Linter\Config\LintExtractorFactory;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * regex lint reads a constant of another file of the project, and
 * reports its pattern once, at the file that writes it.
 */
final class LintCommandCrossFileTest extends TestCase
{
    use TemporaryProject;

    #[Test]
    public function test_lint_reports_a_constant_of_another_file_at_its_literal_once(): void
    {
        $this->enterProject([
            'regex.json' => '{"paths": ["src"]}',
            'src/Patterns.php' => "<?php\n\nnamespace App;\n\nfinal class Patterns\n{\n    public const EMAIL = '/[^@]+@[^@]+/i';\n    public const BROKEN = '/[a-z/';\n}\n",
            'src/Service/Reader.php' => "<?php\n\nnamespace App\\Service;\n\nuse App\\Patterns;\n\npreg_match(Patterns::BROKEN, 'x');\n",
            'src/other.php' => "<?php\n\npreg_match(\\App\\Patterns::BROKEN, 'y');\n",
        ]);
        [$exitCode, $document] = $this->lintJson(['--no-redos', '--format=json', '--jobs=1']);

        $this->assertSame(1, $exitCode);
        $results = JsonContract::asArray($document['results'] ?? null);
        $this->assertCount(1, $results);
        $result = JsonContract::asArray($results[0]);
        $this->assertSame('/[a-z/', $result['pattern']);
        $this->assertSame('src/Patterns.php', $result['file']);
        $this->assertSame(8, $result['line']);
        $this->assertSame(27, $result['column']);
        $this->assertSame('via Patterns::BROKEN', $result['location']);
        $this->assertSame('preg_match()', $result['source']);
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

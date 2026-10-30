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
 * regex lint reads the "matches" operator of the Twig templates under the
 * linted paths, as any pattern of the project, unless --no-twig.
 */
final class LintCommandTwigTest extends TestCase
{
    use TemporaryProject;

    private const TEMPLATE = "<h1>{{ title }}</h1>\n{% if slug matches '/[a-z/' %}ok{% endif %}\n";

    #[Test]
    public function test_lint_reports_a_template_pattern_at_its_file_line_and_column(): void
    {
        $this->enterProject([
            'regex.json' => '{"paths": ["src", "templates"]}',
            'src/Noop.php' => "<?php\n",
            'templates/page.html.twig' => self::TEMPLATE,
        ]);
        [$exitCode, $document] = $this->lintJson(['--no-redos', '--format=json', '--jobs=1']);

        $this->assertSame(1, $exitCode);
        $results = JsonContract::asArray($document['results'] ?? null);
        $this->assertCount(1, $results);
        $result = JsonContract::asArray($results[0]);
        $this->assertSame('/[a-z/', $result['pattern']);
        $this->assertSame('twig:matches', $result['source']);
        $this->assertSame('templates/page.html.twig', $result['file']);
        $this->assertSame(2, $result['line']);
        $this->assertSame(20, $result['column']);
        $this->assertSame(1, JsonContract::asArray($document['stats'] ?? null)['errors'] ?? null);
    }

    #[Test]
    public function test_no_twig_leaves_the_templates_out(): void
    {
        $this->enterProject([
            'regex.json' => '{"paths": ["templates"]}',
            'templates/page.html.twig' => self::TEMPLATE,
        ]);
        [$exitCode, $document] = $this->lintJson(['--no-redos', '--no-twig', '--format=json', '--jobs=1']);

        $this->assertSame(0, $exitCode);
        $this->assertSame([], JsonContract::asArray($document['results'] ?? null));
    }

    #[Test]
    public function test_a_marker_in_the_template_silences_the_pattern(): void
    {
        $this->enterProject([
            'regex.json' => '{"paths": ["templates"]}',
            'templates/page.html.twig' => "{# @regex-ignore-next-line #}\n{% if slug matches '/[a-z/' %}ok{% endif %}\n",
        ]);
        [$exitCode, $document] = $this->lintJson(['--no-redos', '--format=json', '--jobs=1']);

        $this->assertSame(0, $exitCode);
        $this->assertSame([], JsonContract::asArray($document['results'] ?? null));
    }

    #[Test]
    public function test_a_marker_that_silences_nothing_is_reported_at_its_line(): void
    {
        $this->enterProject([
            'regex.json' => '{"paths": ["templates"]}',
            'templates/page.html.twig' => "{# @regex-ignore #}\n{% if slug matches '/[a-z]+/' %}ok{% endif %}\n",
        ]);
        [$exitCode, $document] = $this->lintJson(['--no-redos', '--format=json', '--jobs=1']);

        $this->assertSame(1, $exitCode);
        $results = JsonContract::asArray($document['results'] ?? null);
        $this->assertCount(1, $results);
        $issue = JsonContract::asArray(JsonContract::asArray(JsonContract::asArray($results[0])['issues'])[0]);
        $this->assertSame('regex.ignore.unmatched', $issue['issue_id']);
        $this->assertSame('templates/page.html.twig', $issue['file']);
        $this->assertSame(1, $issue['line']);
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

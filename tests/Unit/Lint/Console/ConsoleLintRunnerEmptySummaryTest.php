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

namespace PHPRegex\Tests\Unit\Lint\Console;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Console\ConsoleLintRequest;
use PHPRegex\Linter\Console\ConsoleLintRunner;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\LintSetup;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Tests\Support\ArgumentCarriers;
use PHPRegex\Tests\Support\Newlines;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * The end of a run of the bridges' regex:lint that found no pattern, in
 * a project whose pattern arguments are all built at run time: the line
 * that counts them stands right under "No regex patterns found.",
 * indented as it is, and in gray on a terminal that shows colors.
 */
final class ConsoleLintRunnerEmptySummaryTest extends TestCase
{
    private const LINE = 'Not verifiable: 3 pattern arguments (2 dynamic, 1 around a dynamic part)';

    #[Test]
    public function test_the_arguments_not_verified_are_counted_on_an_indented_line_under_the_summary(): void
    {
        $standard = $this->lint(decorated: false);

        $this->assertStringEndsWith("\n\n   PASS  No regex patterns found.\n  ".self::LINE."\n\n", $standard);
    }

    #[Test]
    public function test_a_terminal_that_shows_colors_writes_the_line_in_gray(): void
    {
        $standard = $this->lint(decorated: true);

        $this->assertStringContainsString("No regex patterns found.\e[39m\n  \e[90m".self::LINE."\e[39m\n\n", $standard);
        $this->assertSame(1, substr_count($standard, self::LINE));
    }

    /**
     * The gray ends with the line: under -v, the indent of the footer
     * that follows is written in no color, and the footer in its own.
     */
    #[Test]
    public function test_the_gray_of_the_line_ends_with_it(): void
    {
        $standard = $this->lint(decorated: true, verbosity: OutputInterface::VERBOSITY_VERBOSE);

        $this->assertStringEndsWith(
            "  \e[90m".self::LINE."\e[39m\n\n  \e[90mIf PHPRegex helps, a GitHub star is appreciated: https://github.com/php-regex/php-regex\e[39m\n\n",
            $standard,
        );
    }

    /**
     * What a run that reads three pattern arguments and no pattern
     * writes on its standard output.
     */
    private function lint(bool $decorated, int $verbosity = OutputInterface::VERBOSITY_NORMAL): string
    {
        $standard = self::memory();
        $error = self::memory();
        $source = new class(ArgumentCarriers::of(['raw_shell' => 1, 'opaque' => 2], 'other.php')) implements PatternSourceInterface {
            /**
             * @param list<PatternOccurrence> $arguments
             */
            public function __construct(private readonly array $arguments) {}

            public function getName(): string
            {
                return 'custom';
            }

            public function isSupported(): bool
            {
                return true;
            }

            public function extract(PatternSourceContext $context): array
            {
                return $this->arguments;
            }
        };

        $request = new ConsoleLintRequest(
            format: 'console',
            paths: [__DIR__],
            pathsSetting: null,
            exclude: ['vendor'],
            excludeBase: null,
            minSavings: 1,
            jobs: '1',
            disabledSources: ['routes'],
            optimizations: new OptimizerOptions(verifyWithAutomata: true),
            checkRedos: false,
            checkComplexity: false,
            reportUnmatchedIgnores: true,
            declarationPaths: [],
            vendorPaths: [],
            editorUrl: null,
            displayBase: null,
            setup: static function () use ($source): LintSetup {
                $analysis = new AnalysisService(RegexParser::create());

                return LintSetup::forTarget(ProjectTarget::fromSources([], [], null, []), [], $analysis, new LintService($analysis, new PatternSourceCollection([$source])), []);
            },
            invalidOptionPrefix: 'Invalid option: ',
            version: '9.9.9',
            unread: static fn (): array => [],
        );

        try {
            $status = (new ConsoleLintRunner(forking: false))->run($request, self::console($standard, new StreamOutput($error, $verbosity, $decorated), $verbosity, $decorated));
            $this->assertSame(0, $status);
            rewind($standard);

            return Newlines::lf((string) stream_get_contents($standard));
        } finally {
            fclose($standard);
            fclose($error);
        }
    }

    /**
     * A console whose standard and error outputs are kept apart in memory.
     *
     * @param resource $stream
     */
    private static function console($stream, OutputInterface $errorOutput, int $verbosity, bool $decorated): ConsoleOutputInterface
    {
        return new class($stream, $errorOutput, $verbosity, $decorated) extends StreamOutput implements ConsoleOutputInterface {
            /**
             * @param resource $stream
             */
            public function __construct(
                $stream,
                private OutputInterface $errorOutput,
                int $verbosity,
                bool $decorated
            ) {
                parent::__construct($stream, $verbosity, $decorated);
            }

            public function getErrorOutput(): OutputInterface
            {
                return $this->errorOutput;
            }

            public function setErrorOutput(OutputInterface $error): void
            {
                $this->errorOutput = $error;
            }

            public function section(): ConsoleSectionOutput
            {
                throw new \LogicException('No section in this test.');
            }
        };
    }

    /**
     * @return resource
     */
    private static function memory()
    {
        $stream = fopen('php://memory', 'w+');
        if (!\is_resource($stream)) {
            throw new \RuntimeException('No memory stream.');
        }

        return $stream;
    }
}

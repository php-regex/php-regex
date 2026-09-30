<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\Cli\Command;

use RegexParser\Cli\ConsoleStyle;
use RegexParser\Cli\Input;
use RegexParser\Cli\Output;
use RegexParser\Exception\ParserException;
use RegexParser\Internal\PatternParser;

final readonly class HelpCommand implements CommandInterface
{
    /**
     * The commands help documents on its own, used when it is asked for help
     * outside of an application — in a test, or by a bridge reusing it.
     */
    private const DOCUMENTED_COMMANDS = [
        'parse', 'analyze', 'compare', 'explain', 'debug', 'redos', 'diagram',
        'graph', 'highlight', 'validate', 'transpile', 'lint', 'clear-cache',
        'version', 'self-update', 'help',
    ];

    /**
     * @param array<int, CommandInterface> $commands the commands the
     *                                               application knows, so
     *                                               that the summary cannot
     *                                               drift from what it runs
     */
    public function __construct(private array $commands = []) {}

    /**
     * @param array<int, CommandInterface> $commands
     */
    public function withCommands(array $commands): self
    {
        return new self($commands);
    }

    public function getName(): string
    {
        return 'help';
    }

    public function getAliases(): array
    {
        return ['--help', '-h'];
    }

    public function getDescription(): string
    {
        return 'Display this help message';
    }

    public function run(Input $input, Output $output): int
    {
        $binary = $this->resolveInvocation();
        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $meta = [];
        if (null !== $input->globalOptions->phpVersion) {
            $meta['Target PHP'] = $output->warning('PHP '.$input->globalOptions->phpVersion);
        }
        if (null !== $input->globalOptions->pcreVersion) {
            $meta['Target PCRE2'] = $output->warning('PCRE2 '.$input->globalOptions->pcreVersion);
        }
        $this->renderHeader($style, $meta);

        $specificCommand = $input->args[0] ?? null;
        if (null !== $specificCommand) {
            return $this->renderCommandHelp($output, $binary, $specificCommand);
        }

        $this->renderTextSection($output, 'Description', [
            'CLI for regex parsing, validation, analysis, and linting',
        ]);

        $this->renderTextSection($output, 'Usage', [
            $this->formatUsage($output, $binary),
        ]);

        $this->renderTableSection($output, 'Commands', $this->commandSummary(), fn (string $value): string => $this->formatCommand($output, $value));

        $globalOptions = [
            ['--ansi', 'Force ANSI output'],
            ['--no-ansi', 'Disable ANSI output'],
            ['-q, --quiet', 'Suppress output'],
            ['--silent', 'Same as --quiet'],
            ['--no-visuals', 'Disable banner and section visuals'],
            ['--php-version <ver>', 'Target PHP version for validation'],
            ['--pcre-version <ver>', 'Target PCRE2 release for validation'],
            ['--help', 'Display this help message'],
        ];
        $this->renderTableSection($output, 'Global Options', $globalOptions, fn (string $value): string => $this->formatOption($output, $value));

        $lintOptions = [
            ['--exclude <path>', 'Paths to exclude (repeatable)'],
            ['--min-savings <n>', 'Minimum optimization savings'],
            ['--jobs <n>', 'Parallel workers for analysis'],
            ['--format <format>', 'Output format (console, json, github, checkstyle, junit)'],
            ['--output <file>', 'Write output to file'],
            ['--no-redos', 'Skip ReDoS risk analysis'],
            ['--redos-mode <mode>', 'ReDoS mode (theoretical, confirmed)'],
            ['--redos-threshold <sev>', 'Minimum ReDoS severity (low, medium, high, critical)'],
            ['--no-validate', 'Skip validation errors (structural lint only)'],
            ['--no-optimize', 'Disable optimization suggestions'],
            ['--interop <presets>', 'Wrapper libraries to read patterns from (composer-pcre, nette-utils, spatie-regex, laravel-str, none)'],
            ['--no-interop', 'Read patterns from native preg_* calls only'],
            ['--pattern-function <spec>', 'Extra call carrying a pattern, e.g. App\\Str::matches#1 (repeatable)'],
            ['-v, --verbose', 'Show detailed output'],
            ['--debug', 'Show debug information'],
        ];
        $this->renderTableSection($output, 'Lint Options', $lintOptions, fn (string $value): string => $this->formatOption($output, $value));
        $output->write($output->dim('  Config: regex.json or regex.dist.json in the working directory sets lint defaults.')."\n");
        $output->write($output->dim('  Target: --php-version, else regex.json phpVersion, else the lowest PHP composer.json allows, else the running PHP.')."\n");
        $output->write($output->dim('  Inline ignore: // @regex-ignore-next-line or // @regex-ignore')."\n\n");

        $diagramOptions = [
            ['--format <format>', 'Output format (text, svg)'],
            ['--output <file>', 'Write output to file'],
        ];
        $this->renderTableSection($output, 'Diagram Options', $diagramOptions, fn (string $value): string => $this->formatOption($output, $value));

        $transpileOptions = [
            ['--target <dialect>', 'Target dialect (js, python)'],
            ['--format <format>', 'Output format (console, json)'],
        ];
        $this->renderTableSection($output, 'Transpile Options', $transpileOptions, fn (string $value): string => $this->formatOption($output, $value));

        $analyzeOptions = [
            ['--format <format>', 'Output format (console, json)'],
            ['--redos-mode <mode>', 'ReDoS mode (off, theoretical, confirmed)'],
            ['--redos-threshold <sev>', 'Minimum ReDoS severity (low, medium, high, critical)'],
        ];
        $this->renderTableSection($output, 'Analyze Options', $analyzeOptions, fn (string $value): string => $this->formatOption($output, $value));

        $debugOptions = [
            ['--input <string>', 'Input string to test against the pattern'],
            ['--format <format>', 'Output format (console, json)'],
            ['--redos-mode <mode>', 'ReDoS mode (off, theoretical, confirmed)'],
            ['--redos-threshold <sev>', 'Minimum ReDoS severity (low, medium, high, critical)'],
        ];
        $this->renderTableSection($output, 'Debug Options', $debugOptions, fn (string $value): string => $this->formatOption($output, $value));

        $redosOptions = [
            ['--safe <pattern>', 'Safe pattern to compare against'],
            ['--input <string>', 'Input string to benchmark (auto-generated when omitted)'],
            ['--input-file <path>', 'Read input from a file'],
            ['--repeat <n>', 'Repeat input N times (default: 1)'],
            ['--prefix <string>', 'Prefix for the input'],
            ['--suffix <string>', 'Suffix for the input'],
            ['--iterations <n>', 'Number of iterations (default: 1)'],
            ['--warmup <n>', 'Warmup iterations (default: 0)'],
            ['--jit <0|1>', 'Override pcre.jit'],
            ['--backtrack-limit <n>', 'Override pcre.backtrack_limit'],
            ['--recursion-limit <n>', 'Override pcre.recursion_limit'],
            ['--time-limit <n>', 'Set max_execution_time in seconds'],
            ['--format <format>', 'Output format (console, json)'],
            ['--show-input', 'Print full input string'],
        ];
        $this->renderTableSection($output, 'ReDoS Benchmark Options', $redosOptions, fn (string $value): string => $this->formatOption($output, $value));

        $examples = [
            [[$binary, "'/a+/'"], 'Quick highlight'],
            [[$binary, 'parse', "'/a+/'", '--validate'], 'Parse with validation'],
            [[$binary, 'analyze', "'/a+/'"], 'Full analysis'],
            [[$binary, 'compare', "'/[a-z]+/'", "'/edit/'"], 'Compare two patterns'],
            [[$binary, 'explain', "'/a+/'"], 'Explain a pattern'],
            [[$binary, 'diagram', "'/^a+$/'"], 'Text diagram'],
            [[$binary, 'diagram', "'/^a+$/'", '--format=svg'], 'SVG diagram'],
            [[$binary, 'transpile', "'/^a+$/'", '--target=python'], 'Transpile to Python'],
            [[$binary, 'debug', "'/(a+)+$/'"], 'Heatmap + ReDoS details'],
            [[$binary, 'redos', "'/(a+)+$/'", '--safe', "'/a+$/'", '--input', "'a'", '--repeat=50000', "--suffix='!'"], 'Benchmark vulnerable vs safe'],
            [[$binary, 'highlight', "'/a+/'", '--format=html'], 'HTML highlight'],
            [[$binary, 'lint', 'src/', '--exclude=vendor'], 'Lint a codebase'],
            [[$binary, 'lint', '--format=json', 'src/'], 'JSON output'],
            [[$binary, 'lint', '--verbose', 'src/'], 'Verbose output'],
            [[$binary, 'self-update'], 'Update the installed phar'],
        ];
        $this->renderExamplesSection($output, $examples);

        return 0;
    }

    /**
     * What a command says about itself, when the application handed it over:
     * the page and the summary then cannot describe it differently.
     */
    private function describe(string $name, string $fallback): string
    {
        foreach ($this->commands as $command) {
            if ($command->getName() === $name) {
                return $command->getDescription();
            }
        }

        return $fallback;
    }

    /**
     * Name and description of every command, taken from the commands
     * themselves when the application handed them over.
     *
     * @return list<array{string, string}>
     */
    private function commandSummary(): array
    {
        if ([] === $this->commands) {
            return array_map(
                fn (string $name): array => [$name, (string) ($this->getCommandData($name)['description'] ?? '')],
                self::DOCUMENTED_COMMANDS,
            );
        }

        return array_map(
            static fn (CommandInterface $command): array => [$command->getName(), $command->getDescription()],
            array_values($this->commands),
        );
    }

    /**
     * @return list<string>
     */
    private function commandNames(): array
    {
        if ([] === $this->commands) {
            return self::DOCUMENTED_COMMANDS;
        }

        return array_map(static fn (CommandInterface $command): string => $command->getName(), array_values($this->commands));
    }

    private function renderCommandHelp(Output $output, string $binary, string $command): int
    {
        $commandData = $this->getCommandData($command);
        if (null === $commandData) {
            $output->write($output->error("Unknown command: {$command}\n\n"));
            $this->renderTextSection($output, 'Available Commands', $this->commandNames());

            return 1;
        }

        $this->renderTextSection($output, 'Description', [$this->describe($command, $commandData['description'])]);
        $this->renderTextSection($output, 'Usage', [$this->formatCommandUsage($output, $binary, $command, $commandData)]);

        if (!empty($commandData['options'])) {
            $this->renderTableSection($output, 'Options', $commandData['options'], fn (string $value): string => $this->formatOption($output, $value));
        }

        if (!empty($commandData['notes'])) {
            foreach ($commandData['notes'] as $note) {
                $output->write($output->dim('  '.$note)."\n");
            }
            $output->write("\n");
        }

        if (!empty($commandData['examples'])) {
            $this->renderExamplesSection($output, $commandData['examples']);
        }

        return 0;
    }

    /**
     * @return array{description: string, options: list<array{string, string}>, notes: list<string>, examples: list<array{list<string>, string}>}|null
     */
    private function getCommandData(string $command): ?array
    {
        /** @var array{description: string, options: list<array{string, string}>, notes: list<string>, examples: list<array{list<string>, string}>}|null $data */
        // @phpstan-ignore varTag.nativeType (PHPStan generalizes this large constant array; each match arm follows the documented shape.)
        $data = match ($command) {
            'parse' => [
                'description' => 'Parse and recompile a regex pattern',
                'options' => [
                    ['--validate', 'Validate the pattern after parsing'],
                    ['--php-version <ver>', 'Target PHP version for validation'],
                    ['--pcre-version <ver>', 'Target PCRE2 release for validation'],
                ],
                'notes' => [],
                'examples' => [
                    [[$this->resolveInvocation(), 'parse', "'/a+/'"], 'Basic parsing'],
                    [[$this->resolveInvocation(), 'parse', "'/a+/'", '--validate'], 'Parse with validation'],
                    [[$this->resolveInvocation(), 'parse', "'/a+/'", '--php-version=8.1'], 'Parse for specific PHP version'],
                ],
            ],
            'analyze' => [
                'description' => 'Parse, validate, and analyze ReDoS risk',
                'options' => [
                    ['--format <format>', 'Output format (console, json)'],
                    ['--php-version <ver>', 'Target PHP version for validation'],
                    ['--pcre-version <ver>', 'Target PCRE2 release for validation'],
                    ['--redos-mode <mode>', 'ReDoS mode (off, theoretical, confirmed)'],
                    ['--redos-threshold <sev>', 'Minimum ReDoS severity (low, medium, high, critical)'],
                ],
                'notes' => [],
                'examples' => [
                    [[$this->resolveInvocation(), 'analyze', "'/a+/'"], 'Analyze a simple pattern'],
                    [[$this->resolveInvocation(), 'analyze', "'/(a+)+$/'"], 'Analyze a potentially risky pattern'],
                    [[$this->resolveInvocation(), 'analyze', "'/(a+)+$/'", '--format=json'], 'Analyze with JSON output'],
                ],
            ],
            'compare' => [
                'description' => 'Compare two regex patterns using automata logic',
                'options' => [
                    ['--method <method>', 'intersection (default), subset, or equivalence'],
                    ['--php-version <ver>', 'Target PHP version for validation'],
                    ['--pcre-version <ver>', 'Target PCRE2 release for validation'],
                ],
                'notes' => ['Supports the regular subset only (no lookarounds or backreferences).'],
                'examples' => [
                    [[$this->resolveInvocation(), 'compare', "'/[a-z]+/'", "'/edit/'"], 'Check intersection'],
                    [[$this->resolveInvocation(), 'compare', "'/edit/'", "'/[a-z]+/'", '--method=subset'], 'Check subset'],
                    [[$this->resolveInvocation(), 'compare', "'/(a|b)c/'", "'/ac|bc/'", '--method=equivalence'], 'Check equivalence'],
                ],
            ],
            'explain' => [
                'description' => 'Explain a regex pattern in plain language',
                'options' => [
                    ['--format <format>', 'Output format (text, html)'],
                ],
                'notes' => [],
                'examples' => [
                    [[$this->resolveInvocation(), 'explain', "'/a+/'"], 'Explain a simple pattern'],
                    [[$this->resolveInvocation(), 'explain', "'/\\d{4}-\\d{2}-\\d{2}/'"], 'Explain a date pattern'],
                ],
            ],
            'debug' => [
                'description' => 'Deep ReDoS analysis with heatmap output',
                'options' => [
                    ['--input <string>', 'Input string to test against the pattern'],
                    ['--format <format>', 'Output format (console, json)'],
                    ['--redos-mode <mode>', 'ReDoS mode (off, theoretical, confirmed)'],
                    ['--redos-threshold <sev>', 'Minimum ReDoS severity (low, medium, high, critical)'],
                    ['--php-version <ver>', 'Target PHP version for validation'],
                    ['--pcre-version <ver>', 'Target PCRE2 release for validation'],
                ],
                'notes' => ['Provides detailed ReDoS analysis including attack vectors and complexity heatmaps.'],
                'examples' => [
                    [[$this->resolveInvocation(), 'debug', "'/(a+)+$/'"], 'Debug a pattern with potential ReDoS risk'],
                    [[$this->resolveInvocation(), 'debug', "'/(a+)+$/'", '--input=aaaaaaaa'], 'Debug with specific input'],
                ],
            ],
            'redos' => [
                'description' => 'Benchmark regex patterns for ReDoS behavior',
                'options' => [
                    ['--safe <pattern>', 'Safe pattern to compare against'],
                    ['--input <string>', 'Input string to benchmark (auto-generated when omitted)'],
                    ['--input-file <path>', 'Read input from a file'],
                    ['--repeat <n>', 'Repeat input N times'],
                    ['--prefix <string>', 'Prefix for the input'],
                    ['--suffix <string>', 'Suffix for the input'],
                    ['--iterations <n>', 'Number of iterations'],
                    ['--warmup <n>', 'Warmup iterations'],
                    ['--jit <0|1>', 'Override pcre.jit'],
                    ['--backtrack-limit <n>', 'Override pcre.backtrack_limit'],
                    ['--recursion-limit <n>', 'Override pcre.recursion_limit'],
                    ['--time-limit <n>', 'Set max_execution_time in seconds'],
                    ['--format <format>', 'Output format (console, json)'],
                    ['--show-input', 'Print full input string'],
                    ['--php-version <ver>', 'Target PHP version for validation'],
                    ['--pcre-version <ver>', 'Target PCRE2 release for validation'],
                ],
                'notes' => ['Use the same input for both patterns to compare timing and resource usage.'],
                'examples' => [
                    [[$this->resolveInvocation(), 'redos', "'/(a+)+$/'", '--safe', "'/a+$/'", '--input', "'a'", '--repeat=50000', "--suffix='!'"], 'Benchmark vulnerable vs safe'],
                    [[$this->resolveInvocation(), 'redos', "'/(a+)+$/'", '--input', "'aaaaa!'"], 'Benchmark a single pattern'],
                    [[$this->resolveInvocation(), 'redos', "'/(a+)+$/'", '--format=json', '--input', "'a!'"], 'JSON output'],
                ],
            ],
            'diagram' => [
                'description' => 'Render an ASCII diagram of the AST',
                'options' => [
                    ['--format <format>', 'Output format (ascii)'],
                    ['--php-version <ver>', 'Target PHP version for validation'],
                    ['--pcre-version <ver>', 'Target PCRE2 release for validation'],
                ],
                'notes' => [],
                'examples' => [
                    [[$this->resolveInvocation(), 'diagram', "'/^a+$/'"], 'Basic diagram'],
                    [[$this->resolveInvocation(), 'diagram', "'/(a|b)*c/'"], 'Diagram with alternation'],
                ],
            ],
            'highlight' => [
                'description' => 'Highlight a regex for display',
                'options' => [
                    ['--format <format>', 'Output format (console, html)'],
                    ['--php-version <ver>', 'Target PHP version for validation'],
                    ['--pcre-version <ver>', 'Target PCRE2 release for validation'],
                ],
                'notes' => [],
                'examples' => [
                    [[$this->resolveInvocation(), 'highlight', "'/a+/'"], 'Console highlighting'],
                    [[$this->resolveInvocation(), 'highlight', "'/a+/'", '--format=html'], 'HTML highlighting'],
                ],
            ],
            'validate' => [
                'description' => 'Validate a regex pattern',
                'options' => [
                    ['--php-version <ver>', 'Target PHP version for validation'],
                    ['--pcre-version <ver>', 'Target PCRE2 release for validation'],
                ],
                'notes' => [],
                'examples' => [
                    [[$this->resolveInvocation(), 'validate', "'/a+/'"], 'Validate a pattern'],
                    [[$this->resolveInvocation(), 'validate', "'/a+/'", '--php-version=8.0'], 'Validate for PHP 8.0'],
                ],
            ],
            'transpile' => [
                'description' => 'Transpile PCRE regex to other dialects',
                'options' => [
                    ['--target <dialect>', 'Target dialect (js, python)'],
                    ['--format <format>', 'Output format (console, json)'],
                ],
                'notes' => [],
                'examples' => [
                    [[$this->resolveInvocation(), 'transpile', "'/a+/'", '--target=js'], 'Transpile to JavaScript'],
                    [[$this->resolveInvocation(), 'transpile', "'/a+/'", '--target=python'], 'Transpile to Python'],
                ],
            ],
            'lint' => [
                'description' => 'Lint regex patterns in PHP source code',
                'options' => [
                    ['--exclude <path>', 'Paths to exclude (repeatable)'],
                    ['--min-savings <n>', 'Minimum optimization savings'],
                    ['--jobs <n>', 'Parallel workers for analysis'],
                    ['--format <format>', 'Output format (console, json, github, checkstyle, junit)'],
                    ['--no-redos', 'Skip ReDoS risk analysis'],
                    ['--redos-mode <mode>', 'ReDoS mode (theoretical, confirmed)'],
                    ['--redos-threshold <sev>', 'Minimum ReDoS severity (low, medium, high, critical)'],
                    ['--no-validate', 'Skip validation errors (structural lint only)'],
                    ['--no-optimize', 'Disable optimization suggestions'],
                    ['-v, --verbose', 'Show detailed output'],
                    ['--debug', 'Show debug information'],
                ],
                'notes' => [
                    'Config: regex.json or regex.dist.json in the working directory sets lint defaults.',
                    'Target: --php-version, else regex.json phpVersion, else the lowest PHP composer.json allows, else the running PHP.',
                    'Exit codes: 0 clean, 1 errors found, 2 unusable configuration or command line.',
                    'Inline ignore: // @regex-ignore-next-line or // @regex-ignore',
                ],
                'examples' => [
                    [[$this->resolveInvocation(), 'lint', 'src/'], 'Lint the src/ directory'],
                    [[$this->resolveInvocation(), 'lint', 'src/', '--exclude=vendor'], 'Lint excluding vendor/'],
                    [[$this->resolveInvocation(), 'lint', 'src/', '--format=json'], 'JSON output format'],
                    [[$this->resolveInvocation(), 'lint', 'src/', '--verbose'], 'Verbose linting'],
                    [[$this->resolveInvocation(), 'lint', 'src/', '--no-redos'], 'Skip ReDoS analysis'],
                    [[$this->resolveInvocation(), 'lint', 'src/', '--jobs=4'], 'Use 4 parallel workers'],
                    [[$this->resolveInvocation(), 'lint', 'src/', '--min-savings=10'], 'Only show optimizations saving 10+ chars'],
                    [[$this->resolveInvocation(), 'lint', 'file.php'], 'Lint a single file'],
                ],
            ],
            'graph' => [
                'description' => 'Generate a graph diagram (DOT/Mermaid) of the NFA',
                'options' => [
                    ['--format <format>', 'Output format (dot, mermaid)'],
                    ['--output <file>', 'Write the diagram to a file'],
                ],
                'notes' => [],
                'examples' => [
                    [[$this->resolveInvocation(), 'graph', "'/a+b/'"], 'Render the automaton as DOT'],
                    [[$this->resolveInvocation(), 'graph', "'/a+b/'", '--format=mermaid'], 'Render it as Mermaid'],
                ],
            ],
            'clear-cache' => [
                'description' => 'Clear the regex parser cache',
                'options' => [],
                'notes' => ['Removes the cached ASTs; the patterns are parsed again on the next run.'],
                'examples' => [
                    [[$this->resolveInvocation(), 'clear-cache'], 'Empty the cache directory'],
                ],
            ],
            'version' => [
                'description' => 'Display version information',
                'options' => [],
                'notes' => [],
                'examples' => [
                    [[$this->resolveInvocation(), 'version'], 'Show the installed version'],
                ],
            ],
            'self-update' => [
                'description' => 'Update the CLI phar to the latest release',
                'options' => [],
                'notes' => ['Updates the installed phar file to the latest version.'],
                'examples' => [
                    [[$this->resolveInvocation(), 'self-update'], 'Update to latest version'],
                ],
            ],
            'help' => [
                'description' => 'Display this help message',
                'options' => [
                    ['<command>', 'Show help for specific command'],
                ],
                'notes' => [],
                'examples' => [
                    [[$this->resolveInvocation(), '--help'], 'Show general help'],
                    [[$this->resolveInvocation(), 'lint', '--help'], 'Show lint command help'],
                ],
            ],
            default => null,
        };

        return $data;
    }

    /**
     * @param array{description: string, options: array<int, array{0: string, 1: string}>, notes: array<int, string>, examples: array<int, array{0: array<int, string>, 1: string}>} $commandData
     */
    private function formatCommandUsage(Output $output, string $binary, string $command, array $commandData): string
    {
        $usage = $output->color($binary, Output::BLUE).' '.$output->color($command, Output::YELLOW.Output::BOLD);

        if ('lint' === $command) {
            $usage .= ' '.$output->color('[options]', Output::CYAN).' '.$output->color('<path>', Output::GREEN);
        } elseif (\in_array($command, ['parse', 'analyze', 'debug', 'redos', 'diagram', 'graph', 'highlight', 'transpile', 'validate', 'explain'], true)) {
            $usage .= ' '.$output->color('[options]', Output::CYAN).' '.$output->color('<pattern>', Output::GREEN);
        } elseif ('compare' === $command) {
            $usage .= ' '.$output->color('[options]', Output::CYAN)
                .' '.$output->color('<pattern1>', Output::GREEN)
                .' '.$output->color('<pattern2>', Output::GREEN);
        } elseif ('help' === $command) {
            $usage .= ' '.$output->color('[command]', Output::GREEN);
        }

        return $usage;
    }

    /**
     * @param array<string, string> $meta
     */
    private function renderHeader(ConsoleStyle $style, array $meta): void
    {
        $style->renderBanner('help', $meta, 'Treat Regular Expressions as Code.');
    }

    /**
     * @param array<int, string> $lines
     */
    private function renderTextSection(Output $output, string $title, array $lines): void
    {
        $output->write($output->color($title.':', Output::MAGENTA)."\n");

        foreach ($lines as $line) {
            $output->write('  '.$line."\n");
        }

        $output->write("\n");
    }

    /**
     * @param array<int, array{0: string, 1: string}> $rows
     * @param callable(string): string                $formatLeft
     */
    private function renderTableSection(Output $output, string $title, array $rows, callable $formatLeft): void
    {
        $output->write($output->color($title.':', Output::MAGENTA)."\n");
        $this->renderTable($output, $rows, $formatLeft);
        $output->write("\n");
    }

    /**
     * @param array<int, array{0: string, 1: string}> $rows
     * @param callable(string): string                $formatLeft
     */
    private function renderTable(Output $output, array $rows, callable $formatLeft): void
    {
        $maxWidth = 0;
        foreach ($rows as $row) {
            $maxWidth = max($maxWidth, \strlen($row[0]));
        }

        foreach ($rows as [$left, $right]) {
            $padding = max(0, $maxWidth - \strlen($left));
            $output->write('  '.$formatLeft($left).str_repeat(' ', $padding + 2).$right."\n");
        }
    }

    /**
     * @param array<int, array{0: array<int, string>, 1: string}> $examples
     */
    private function renderExamplesSection(Output $output, array $examples): void
    {
        $output->write($output->color('Examples:', Output::MAGENTA)."\n");

        $maxWidth = 0;
        foreach ($examples as [$tokens]) {
            $command = implode(' ', $tokens);
            $maxWidth = max($maxWidth, \strlen($command));
        }

        foreach ($examples as [$tokens, $description]) {
            $command = implode(' ', $tokens);
            $padding = max(0, $maxWidth - \strlen($command));
            $output->write('  '.$this->formatExampleCommand($output, $tokens).str_repeat(' ', $padding + 2).$output->dim('# '.$description)."\n");
        }

        $output->write("\n");
    }

    private function resolveInvocation(): string
    {
        $argv = $_SERVER['argv'] ?? null;
        if (\is_array($argv) && isset($argv[0]) && \is_string($argv[0]) && '' !== $argv[0]) {
            return $argv[0];
        }

        return 'regex';
    }

    private function formatUsage(Output $output, string $binary): string
    {
        return $output->color($binary, Output::BLUE)
            .' '.$output->color('<command>', Output::YELLOW)
            .' '.$output->color('[options]', Output::CYAN)
            .' '.$output->color('<pattern>', Output::GREEN);
    }

    private function formatCommand(Output $output, string $command): string
    {
        return $output->color($command, Output::GREEN.Output::BOLD);
    }

    private function formatOption(Output $output, string $option): string
    {
        if (!$output->isAnsi()) {
            return $option;
        }

        $parts = preg_split('/(<[^>]+>)/', $option, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts) {
            return $option;
        }

        $formatted = '';
        foreach ($parts as $part) {
            if ('' === $part) {
                continue;
            }

            $partText = \is_array($part) ? $part[0] : $part;

            if ($this->isPlaceholder($partText)) {
                $formatted .= $output->color($partText, Output::YELLOW.Output::BOLD);

                continue;
            }

            $formatted .= $output->color($partText, Output::CYAN);
        }

        return $formatted;
    }

    /**
     * @param array<int, string> $tokens
     */
    private function formatExampleCommand(Output $output, array $tokens): string
    {
        $formatted = [];
        foreach ($tokens as $index => $token) {
            $formatted[] = $this->formatExampleToken($output, $token, $index);
        }

        return implode(' ', $formatted);
    }

    private function formatExampleToken(Output $output, string $token, int $index): string
    {
        if (0 === $index) {
            return $output->color($token, Output::BLUE.Output::BOLD);
        }

        if (str_starts_with($token, '-')) {
            return $output->color($token, Output::CYAN);
        }

        if ($this->isPatternToken($token)) {
            return $output->color($token, Output::GREEN);
        }

        if (\in_array($token, ['parse', 'analyze', 'compare', 'explain', 'debug', 'redos', 'diagram', 'highlight', 'validate', 'transpile', 'lint', 'self-update', 'help'], true)) {
            return $output->color($token, Output::YELLOW.Output::BOLD);
        }

        return $token;
    }

    private function isPlaceholder(string $value): bool
    {
        return str_starts_with($value, '<') && str_ends_with($value, '>');
    }

    private function isPatternToken(string $token): bool
    {
        $candidate = $token;

        if (
            (str_starts_with($candidate, "'") && str_ends_with($candidate, "'"))
            || (str_starts_with($candidate, '"') && str_ends_with($candidate, '"'))
        ) {
            $candidate = substr($candidate, 1, -1);
        }

        try {
            PatternParser::extractPatternAndFlags($candidate);

            return true;
        } catch (ParserException) {
            return false;
        }
    }
}

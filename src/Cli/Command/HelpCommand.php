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

namespace PHPRegex\Cli\Command;

use PHPRegex\Cli\ConsoleStyle;
use PHPRegex\Cli\GlobalOptionsParser;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Option\Arguments;
use PHPRegex\Cli\Option\Options;
use PHPRegex\Cli\Output;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Syntax\PatternParser;
use PHPRegex\Parser\Text\NearMiss;

/**
 * @internal
 *
 * @phpstan-type CommandHelp array{description: string, declaration: Options, options: list<array{string, string}>, notes: list<string>, examples: list<array{list<string>, string}>}
 */
final class HelpCommand extends AbstractCommand
{
    /**
     * The commands help documents on its own, used when it is asked for help
     * outside of an application — in a test, or by a bridge reusing it.
     */
    private const DOCUMENTED_COMMANDS = [
        'parse', 'analyze', 'compare', 'explain', 'debug', 'redos', 'diagram',
        'graph', 'highlight', 'validate', 'transpile', 'format', 'lint', 'lsp',
        'version', 'self-update', 'help',
    ];

    /**
     * @param array<int, CommandInterface> $commands the commands the
     *                                               application knows, so
     *                                               that the summary cannot
     *                                               drift from what it runs
     */
    public function __construct(private readonly array $commands = []) {}

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

    /**
     * The one argument help takes is a command name.
     */
    public static function options(): Options
    {
        return new Options(Arguments::Command, [], "Usage: regex help [command]\n");
    }

    public function run(Input $input, Output $output): int
    {
        $error = self::options()->parse($input->args)->error;
        if (null !== $error) {
            return $this->usageError($output, $error, self::options()->usage);
        }

        $binary = $this->resolveInvocation();
        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $meta = [];
        if (null !== $input->globalOptions->phpVersion) {
            $meta['Target PHP'] = $output->warning('PHP '.$input->globalOptions->phpVersion);
        }
        if (null !== $input->globalOptions->pcreVersion) {
            $meta['Target PCRE2'] = $output->warning('PCRE2 '.$input->globalOptions->pcreVersion);
        }
        if (null !== $input->globalOptions->locale) {
            $meta['Target locale'] = $output->warning($input->globalOptions->locale);
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

        $this->renderTableSection($output, 'Global Options', GlobalOptionsParser::options()->help(), fn (string $value): string => $this->formatOption($output, $value));

        // Each command's options are on its own page.
        $output->write('  Run '.$output->color($binary, Output::BLUE).' '.$this->formatCommand($output, 'help').' '.$output->color('<command>', Output::YELLOW)." for the options of a command.\n\n");

        $examples = [
            [[$binary, "'/a+/'"], 'Quick highlight'],
            [[$binary, 'parse', "'/a+/'", '--validate'], 'Parse with validation'],
            [[$binary, 'analyze', "'/a+/'"], 'Full analysis'],
            [[$binary, 'compare', "'/[a-z]+/'", "'/edit/'"], 'Compare two patterns'],
            [[$binary, 'explain', "'/a+/'"], 'Explain a pattern'],
            [[$binary, 'diagram', "'/^a+$/'"], 'Text diagram'],
            [[$binary, 'diagram', "'/^a+$/'", '--format=svg'], 'SVG diagram'],
            [[$binary, 'transpile', "'/^a+$/'", '--target=python'], 'Transpile to Python'],
            [[$binary, 'format', "'/^(?<year>\\d{4})-(?<month>\\d{2})$/'", '--width=24'], 'Lay a pattern out over lines'],
            [[$binary, 'debug', "'/(a+)+$/'"], 'Heatmap + ReDoS details'],
            [[$binary, 'redos', "'/(a+)+$/'", '--safe', "'/a+$/'", '--input', "'a'", '--repeat=50000', "--suffix='!'"], 'Benchmark vulnerable vs safe'],
            [[$binary, 'highlight', "'/a+/'", '--format=html'], 'HTML highlight'],
            [[$binary, 'lint', 'src/', '--exclude=vendor'], 'Lint a codebase'],
            [[$binary, 'lint', '--format=json', 'src/'], 'JSON output'],
            [[$binary, 'lint', '--verbose', 'src/'], 'Verbose output'],
            [[$binary, 'self-update'], 'Update the installed phar'],
        ];
        $this->renderExamplesSection($output, $examples);

        return self::SUCCESS;
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
            $suggestion = NearMiss::closest($command, $this->commandNames());
            $output->writeError($output->error(null === $suggestion ? "Unknown command: {$command}\n\n" : \sprintf("Unknown command: %s. Did you mean \"%s\"?\n\n", $command, $suggestion)));
            $this->renderTextSection($output, 'Available Commands', $this->commandNames());

            return self::INVALID;
        }

        $this->renderTextSection($output, 'Description', [$this->describe($command, $commandData['description'])]);
        $this->renderTextSection($output, 'Usage', [$this->formatCommandUsage($output, $binary, $command, $commandData['declaration'])]);

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

        return self::SUCCESS;
    }

    /**
     * @return CommandHelp|null
     */
    private function getCommandData(string $command): ?array
    {
        return match ($command) {
            'parse' => self::commandHelp(
                description: 'Parse and recompile a regex pattern',
                declaration: ParseCommand::options(),
                notes: [],
                examples: [
                    [[$this->resolveInvocation(), 'parse', "'/a+/'"], 'Basic parsing'],
                    [[$this->resolveInvocation(), 'parse', "'/a+/'", '--validate'], 'Parse with validation'],
                    [[$this->resolveInvocation(), 'parse', "'/a+/'", '--php-version=8.1'], 'Parse for specific PHP version'],
                ],
            ),
            'analyze' => self::commandHelp(
                description: 'Parse, validate, and analyze ReDoS risk',
                declaration: AnalyzeCommand::options(),
                notes: [],
                examples: [
                    [[$this->resolveInvocation(), 'analyze', "'/a+/'"], 'Analyze a simple pattern'],
                    [[$this->resolveInvocation(), 'analyze', "'/(a+)+$/'"], 'Analyze a potentially risky pattern'],
                    [[$this->resolveInvocation(), 'analyze', "'/(a+)+$/'", '--format=json'], 'Analyze with JSON output'],
                ],
            ),
            'compare' => self::commandHelp(
                description: 'Compare two regex patterns using automata logic',
                declaration: CompareCommand::options(),
                notes: ['Compares the regular subset: lookarounds, \b and anchors are read; backreferences, recursion and atomic groups are refused.'],
                examples: [
                    [[$this->resolveInvocation(), 'compare', "'/[a-z]+/'", "'/edit/'"], 'Check intersection'],
                    [[$this->resolveInvocation(), 'compare', "'/edit/'", "'/[a-z]+/'", '--method=subset'], 'Check subset'],
                    [[$this->resolveInvocation(), 'compare', "'/(a|b)c/'", "'/ac|bc/'", '--method=equivalence'], 'Check equivalence'],
                ],
            ),
            'explain' => self::commandHelp(
                description: 'Explain a regex pattern in plain language',
                declaration: ExplainCommand::options(),
                notes: [],
                examples: [
                    [[$this->resolveInvocation(), 'explain', "'/a+/'"], 'Explain a simple pattern'],
                    [[$this->resolveInvocation(), 'explain', "'/\\d{4}-\\d{2}-\\d{2}/'"], 'Explain a date pattern'],
                ],
            ),
            'debug' => self::commandHelp(
                description: 'Deep ReDoS analysis with heatmap output',
                declaration: DebugCommand::options(),
                notes: ['Provides detailed ReDoS analysis including attack vectors and complexity heatmaps.'],
                examples: [
                    [[$this->resolveInvocation(), 'debug', "'/(a+)+$/'"], 'Debug a pattern with potential ReDoS risk'],
                    [[$this->resolveInvocation(), 'debug', "'/(a+)+$/'", '--input=aaaaaaaa'], 'Debug with specific input'],
                ],
            ),
            'redos' => self::commandHelp(
                description: 'Benchmark regex patterns for ReDoS behavior',
                declaration: RedosCommand::options(),
                notes: ['Use the same input for both patterns to compare timing and resource usage.'],
                examples: [
                    [[$this->resolveInvocation(), 'redos', "'/(a+)+$/'", '--safe', "'/a+$/'", '--input', "'a'", '--repeat=50000', "--suffix='!'"], 'Benchmark vulnerable vs safe'],
                    [[$this->resolveInvocation(), 'redos', "'/(a+)+$/'", '--input', "'aaaaa!'"], 'Benchmark a single pattern'],
                    [[$this->resolveInvocation(), 'redos', "'/(a+)+$/'", '--format=json', '--input', "'a!'"], 'JSON output'],
                ],
            ),
            'diagram' => self::commandHelp(
                description: 'Render a diagram of the AST (text or SVG)',
                declaration: DiagramCommand::options(),
                notes: [],
                examples: [
                    [[$this->resolveInvocation(), 'diagram', "'/^a+$/'"], 'Basic diagram'],
                    [[$this->resolveInvocation(), 'diagram', "'/(a|b)*c/'"], 'Diagram with alternation'],
                ],
            ),
            'highlight' => self::commandHelp(
                description: 'Highlight a regex for display',
                declaration: HighlightCommand::options(),
                notes: [],
                examples: [
                    [[$this->resolveInvocation(), 'highlight', "'/a+/'"], 'Console highlighting'],
                    [[$this->resolveInvocation(), 'highlight', "'/a+/'", '--format=html'], 'HTML highlighting'],
                ],
            ),
            'validate' => self::commandHelp(
                description: 'Validate a regex pattern',
                declaration: ValidateCommand::options(),
                notes: [],
                examples: [
                    [[$this->resolveInvocation(), 'validate', "'/a+/'"], 'Validate a pattern'],
                    [[$this->resolveInvocation(), 'validate', "'/a+/'", '--php-version=8.0'], 'Validate for PHP 8.0'],
                ],
            ),
            'transpile' => self::commandHelp(
                description: 'Transpile PCRE regex to other dialects',
                declaration: TranspileCommand::options(),
                notes: [],
                examples: [
                    [[$this->resolveInvocation(), 'transpile', "'/a+/'", '--target=js'], 'Transpile to JavaScript'],
                    [[$this->resolveInvocation(), 'transpile', "'/a+/'", '--target=python'], 'Transpile to Python'],
                ],
            ),
            'format' => self::commandHelp(
                description: 'Lay a pattern out over lines, or compact it to one',
                declaration: FormatCommand::options(),
                notes: [
                    'Prints the pattern alone, so that it can be piped or pasted back. A pattern that fits the width comes back unchanged.',
                    'The output matches exactly what the input matches. A pattern it cannot lay out safely comes back as written, with one note on stderr.',
                ],
                examples: [
                    [[$this->resolveInvocation(), 'format', "'/^(?<year>\\d{4})-(?<month>\\d{2})$/'", '--width=24'], 'Lay a pattern out over lines'],
                    [[$this->resolveInvocation(), 'format', '"$(cat pattern.txt)"', '--compact'], 'Compact a laid-out pattern to one line'],
                    [[$this->resolveInvocation(), 'format', "'/^(?<year>\\d{4})-(?<month>\\d{2})$/'", '--json'], 'JSON output'],
                ],
            ),
            'lint' => self::commandHelp(
                description: 'Lint regex patterns in PHP source code',
                declaration: LintCommand::options(),
                notes: [
                    'Config: regex.json or regex.dist.json in the working directory sets lint defaults.',
                    'Target: --php-version, else regex.json phpVersion, else the lowest PHP composer.json allows, else the running PHP.',
                    'Exit codes: 0 clean, 1 errors found, 2 unusable configuration or command line.',
                    'Inline ignore: // @regex-ignore-next-line or // @regex-ignore',
                ],
                examples: [
                    [[$this->resolveInvocation(), 'lint', 'src/'], 'Lint the src/ directory'],
                    [[$this->resolveInvocation(), 'lint', 'src/', '--exclude=vendor'], 'Lint excluding vendor/'],
                    [[$this->resolveInvocation(), 'lint', 'src/', '--format=json'], 'JSON output format'],
                    [[$this->resolveInvocation(), 'lint', 'src/', '--verbose'], 'Verbose linting'],
                    [[$this->resolveInvocation(), 'lint', 'src/', '--redos'], 'Lint with the ReDoS risk analysis'],
                    [[$this->resolveInvocation(), 'lint', 'src/', '--jobs=4'], 'Use 4 parallel workers'],
                    [[$this->resolveInvocation(), 'lint', 'src/', '--min-savings=10'], 'Only show optimizations saving 10+ chars'],
                    [[$this->resolveInvocation(), 'lint', 'file.php'], 'Lint a single file'],
                ],
            ),
            'graph' => self::commandHelp(
                description: 'Generate a graph diagram (DOT/Mermaid) of the NFA',
                declaration: GraphCommand::options(),
                notes: [],
                examples: [
                    [[$this->resolveInvocation(), 'graph', "'/a+b/'"], 'Render the automaton as DOT'],
                    [[$this->resolveInvocation(), 'graph', "'/a+b/'", '--format=mermaid'], 'Render it as Mermaid'],
                ],
            ),
            'lsp' => self::commandHelp(
                description: 'Run the language server over stdio',
                declaration: LspCommand::options(),
                notes: [
                    'Speaks the Language Server Protocol on stdin and stdout, as vendor/bin/regex-lsp does.',
                    'The target is read from the editor\'s initializationOptions, regex.json or composer.json.',
                ],
                examples: [
                    [[$this->resolveInvocation(), 'lsp'], 'Start the server, as an editor does'],
                ],
            ),
            'version' => self::commandHelp(
                description: 'Display version information',
                declaration: VersionCommand::options(),
                notes: [],
                examples: [
                    [[$this->resolveInvocation(), 'version'], 'Show the installed version'],
                ],
            ),
            'self-update' => self::commandHelp(
                description: 'Update the CLI phar to the latest release',
                declaration: SelfUpdateCommand::options(),
                notes: ['Updates the installed phar file to the latest version.'],
                examples: [
                    [[$this->resolveInvocation(), 'self-update'], 'Update to latest version'],
                ],
            ),
            'help' => self::commandHelp(
                description: 'Display this help message',
                declaration: self::options(),
                rows: [
                    ['<command>', 'Show help for specific command'],
                ],
                notes: [],
                examples: [
                    [[$this->resolveInvocation(), '--help'], 'Show general help'],
                    [[$this->resolveInvocation(), 'lint', '--help'], 'Show lint command help'],
                ],
            ),
            default => null,
        };
    }

    /**
     * @param Options                           $declaration the options the command reads, the rows of its table
     * @param list<string>                      $notes
     * @param list<array{list<string>, string}> $examples
     * @param ?list<array{string, string}>      $rows        the rows of the table, when they are not the options
     *
     * @return CommandHelp
     */
    private static function commandHelp(string $description, Options $declaration, array $notes, array $examples, ?array $rows = null): array
    {
        return ['description' => $description, 'declaration' => $declaration, 'options' => $rows ?? $declaration->help(), 'notes' => $notes, 'examples' => $examples];
    }

    /**
     * The usage line of the command: "[options]" when it has any to show,
     * then the arguments it takes.
     */
    private function formatCommandUsage(Output $output, string $binary, string $command, Options $declaration): string
    {
        $usage = $output->color($binary, Output::BLUE).' '.$output->color($command, Output::YELLOW.Output::BOLD);

        if ([] !== $declaration->help()) {
            $usage .= ' '.$output->color('[options]', Output::CYAN);
        }

        foreach ($declaration->arguments() as $argument) {
            $usage .= ' '.$output->color($argument, Output::GREEN);
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

        $parts = LibraryPcre::split('/(<[^>]+>)/', $option, -1, \PREG_SPLIT_DELIM_CAPTURE);
        if (false === $parts) {
            return $option;
        }

        $formatted = '';
        foreach ($parts as $part) {
            if ('' === $part) {
                continue;
            }

            if ($this->isPlaceholder($part)) {
                $formatted .= $output->color($part, Output::YELLOW.Output::BOLD);

                continue;
            }

            $formatted .= $output->color($part, Output::CYAN);
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

        if (\in_array($token, self::DOCUMENTED_COMMANDS, true)) {
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

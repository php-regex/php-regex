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

namespace PHPRegex\Symfony\Command;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Console\ConsoleLintRequest;
use PHPRegex\Linter\Console\ConsoleLintRunner;
use PHPRegex\Linter\Formatter\FormatterRegistry;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\LintSetup;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Cache\CacheInterface;
use PHPRegex\Toolkit\Regex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lint regex patterns in PHP source code.
 *
 * The command reads its command line and the bundle's configuration; the
 * run itself is the one the Laravel package's regex:lint makes.
 *
 * The options patterns are read with are RegexOptions: those of
 * Regex::create() but the target, which is the project's, and the runtime
 * validation, which only the running PHP can do.
 *
 * @phpstan-type RegexOptions array{
 *     max_pattern_length?: int,
 *     max_lookbehind_length?: int,
 *     cache?: CacheInterface|string|null,
 *     redos_ignored_patterns?: array<string>,
 *     max_recursion_depth?: int,
 * }
 *
 * @internal
 */
#[AsCommand(
    name: 'regex:lint',
    description: 'Lints, validates, and optimizes regex patterns in your PHP code.',
)]
final class LintCommand extends Command
{
    /**
     * @var list<string>
     */
    private readonly array $defaultPaths;

    /**
     * @var list<string>
     */
    private readonly array $defaultExcludePaths;

    private readonly OptimizerOptions $defaultOptimizations;

    /**
     * @param array<string>           $defaultPaths
     * @param array<string>           $defaultExcludePaths
     * @param array<string, bool|int> $defaultOptimizations   the bundle's optimizations, in snake_case
     * @param RegexOptions            $regexOptions           what Regex::create() takes, but the target and the
     *                                                        runtime validation: the settings patterns are read with
     * @param string|int|null         $phpVersion             the bundle's php_version
     * @param string|null             $pcreVersion            the bundle's pcre_version
     * @param string|null             $projectDir             where composer.json is read; null reads none
     * @param array<string>           $warnings               what the bundle's configuration holds that the run
     *                                                        ignores, printed before the report
     * @param bool                    $reportUnmatchedIgnores the bundle's report_unmatched_ignores
     * @param string|null             $locale                 the bundle's locale
     */
    public function __construct(
        private readonly LintService $lint,
        private readonly AnalysisService $analysis,
        private readonly FormatterRegistry $formatterRegistry = new FormatterRegistry(),
        array $defaultPaths = ['src'],
        array $defaultExcludePaths = ['vendor'],
        array $defaultOptimizations = [],
        private readonly ?string $editorUrl = null,
        private readonly array $regexOptions = [],
        private readonly string|int|null $phpVersion = null,
        private readonly ?string $pcreVersion = null,
        private readonly ?string $projectDir = null,
        private readonly bool $checkRedos = false,
        private readonly bool $checkComplexity = false,
        private readonly array $warnings = [],
        private readonly bool $reportUnmatchedIgnores = true,
        private readonly ?string $locale = null,
    ) {
        $paths = self::stringList($defaultPaths);
        $exclude = self::stringList($defaultExcludePaths);
        $this->defaultPaths = [] === $paths ? ['src'] : $paths;
        $this->defaultExcludePaths = [] === $exclude ? ['vendor'] : $exclude;
        // Lint checks every rewrite with the automata unless told otherwise.
        $this->defaultOptimizations = OptimizerOptions::fromArray($defaultOptimizations + ['verify_with_automata' => true]);

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, \sprintf('The paths to analyze, read from the working directory (default: %s, read from the project directory)', implode(', ', $this->defaultPaths)))
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, 'Paths to exclude', $this->defaultExcludePaths)
            ->addOption('min-savings', null, InputOption::VALUE_OPTIONAL, 'Minimum optimization savings in characters', 1)
            ->addOption('jobs', 'j', InputOption::VALUE_OPTIONAL, 'Parallel workers for analysis (auto-detected if not specified)')
            ->addOption('no-routes', null, InputOption::VALUE_NONE, 'Skip route validation')
            ->addOption('no-validators', null, InputOption::VALUE_NONE, 'Skip validator validation')
            ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format (console, json, github, checkstyle, junit)', 'console')
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command scans your PHP code for regex patterns and provides:

                * Validation of regex syntax
                * Performance and security warnings
                * Optimization suggestions
                * Integration with Symfony routes and validators

                <info>php %command.full_name%</info>

                Analyze specific directories:
                <info>php %command.full_name% src/ lib/</info>

                Exclude directories:
                <info>php %command.full_name% --exclude=tests --exclude=vendor</info>

                Show only significant optimizations:
                <info>php %command.full_name% --min-savings=10</info>

                Run analysis in parallel (auto-detected by default):
                <info>php %command.full_name% --jobs=4</info>

                Skip specific validations:
                <info>php %command.full_name% --no-routes --no-validators</info>

                Output format for CI/CD:
                <info>php %command.full_name% --format=json</info>
                <info>php %command.full_name% --format=github</info>
                <info>php %command.full_name% --format=checkstyle</info>
                <info>php %command.full_name% --format=junit</info>
                EOF
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // The configured paths are the project's, read from its directory
        // whatever directory the command runs from; the paths given on the
        // command line are read from the working directory, as lint:yaml
        // reads them.
        $givenPaths = self::stringList($input->getArgument('paths'));
        $configured = [] === $givenPaths;
        $format = $input->getOption('format');
        $minSavings = $input->getOption('min-savings');
        $jobs = $input->getOption('jobs');

        return (new ConsoleLintRunner($this->formatterRegistry))->run(new ConsoleLintRequest(
            format: \is_string($format) ? $format : 'console',
            paths: $configured ? $this->fromProject($this->defaultPaths) : $givenPaths,
            pathsSetting: $configured ? 'php_regex.paths' : null,
            exclude: self::stringList($input->getOption('exclude')),
            // An exclude names a directory of the project: one of the same
            // name above it, where the project lives, excludes nothing.
            excludeBase: $configured ? $this->projectDir : null,
            minSavings: is_numeric($minSavings) ? (int) $minSavings : 1,
            // --jobs with no value is no count either.
            jobs: $input->hasParameterOption(['--jobs', '-j'], true) ? (\is_string($jobs) ? $jobs : '') : null,
            disabledSources: array_keys(array_filter(['routes' => (bool) $input->getOption('no-routes'), 'validators' => (bool) $input->getOption('no-validators')])),
            optimizations: $this->defaultOptimizations,
            checkRedos: $this->checkRedos,
            checkComplexity: $this->checkComplexity,
            reportUnmatchedIgnores: $this->reportUnmatchedIgnores,
            // The functions marked #[RegexPattern] are read in the
            // configured paths and in vendor/, whatever paths are linted.
            declarationPaths: $this->fromProject($this->defaultPaths),
            vendorPaths: [($this->projectDir ?? (getcwd() ?: '.')).'/vendor'],
            editorUrl: $this->editorUrl,
            displayBase: null,
            // The runtime service judges for the running PHP; the lint judges
            // for the project's target, and never compiles with the running PHP.
            setup: fn (): LintSetup => LintSetup::forTarget(
                ProjectTarget::fromSources(
                    ['php_regex.php_version' => $this->phpVersion],
                    ['php_regex.pcre_version' => $this->pcreVersion],
                    $this->projectDir,
                    getenv(),
                    ['php_regex.locale' => $this->locale],
                ),
                $this->regexOptions,
                $this->analysis,
                $this->lint,
                self::stringList($this->warnings),
            ),
            invalidOptionPrefix: 'Invalid option: ',
            version: Regex::VERSION,
        ), $output);
    }

    /**
     * The configured paths read from the project directory; as they are
     * written when the bundle knows of none.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function fromProject(array $paths): array
    {
        $projectDir = $this->projectDir;
        if (null === $projectDir) {
            return $paths;
        }

        return array_map(static fn (string $path): string => RelativePathHelper::absolutize($path, $projectDir), $paths);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn ($item): bool => \is_string($item) && '' !== $item));
    }
}

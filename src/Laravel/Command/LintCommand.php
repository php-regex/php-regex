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

namespace PHPRegex\Laravel\Command;

use Illuminate\Console\Command;
use PHPRegex\Laravel\Extractor\ValidationRulePatternSource;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Config\ProjectTarget;
use PHPRegex\Linter\Console\ConsoleLintRequest;
use PHPRegex\Linter\Console\ConsoleLintRunner;
use PHPRegex\Linter\Formatter\FormatterRegistry;
use PHPRegex\Linter\Formatter\RelativePathHelper;
use PHPRegex\Linter\LintIssue;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\LintSetup;
use PHPRegex\Linter\LintSeverity;
use PHPRegex\Optimizer\OptimizerOptions;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Toolkit\Regex;

/**
 * Lint regex patterns in PHP source code.
 *
 * The command reads its command line and config/php-regex.php; the run
 * itself is the one the Symfony bundle's regex:lint makes.
 *
 * @phpstan-import-type LintResult from LintReport
 *
 * @internal
 */
final class LintCommand extends Command
{
    use ReadsStringInput;

    /**
     * The identifier of a source file an extractor could not read.
     */
    private const ISSUE_ID_SOURCE_UNREADABLE = 'regex.lint.source.unreadable';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'regex:lint
        {paths?* : The paths to analyze, read from the working directory (default: the configured paths, read from the base path)}
        {--exclude=* : Paths to exclude}
        {--min-savings=1 : Minimum optimization savings in characters}
        {--j|jobs= : Parallel workers for analysis (auto-detected if not specified)}
        {--no-routes : Skip route validation}
        {--no-validators : Skip validator validation}
        {--format=console : Output format (console, json, github, checkstyle, junit)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Lints, validates, and optimizes regex patterns in your PHP code';

    /**
     * The lint and analysis services are resolved when the command runs,
     * not when artisan lists its commands: a setting they cannot use then
     * stops this command only.
     */
    public function __construct(private readonly FormatterRegistry $formatterRegistry)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // A null or empty php-regex.paths reads app/, the published default.
        // The configured paths are the application's, read from its base
        // path whatever directory the command runs from; the paths given on
        // the command line are read from the working directory.
        // The strings of an argument, an option or a setting, the empty ones
        // left out.
        $strings = static fn ($value): array => array_values(array_filter(array_filter((array) $value, \is_string(...)), static fn (string $item): bool => '' !== $item));
        $givenPaths = $strings($this->argument('paths'));
        $configured = [] === $givenPaths;
        $defaultPaths = $strings(config('php-regex.paths', ['app']));
        $exclude = $strings($this->option('exclude'));
        $skipValidators = (bool) $this->option('no-validators');
        $editorUrl = config('php-regex.ide');
        $jobs = $this->option('jobs');

        return (new ConsoleLintRunner($this->formatterRegistry))->run(new ConsoleLintRequest(
            format: $this->stringOption('format'),
            paths: $configured ? self::fromBasePath([] === $defaultPaths ? ['app'] : $defaultPaths) : $givenPaths,
            pathsSetting: $configured ? 'php-regex.paths' : null,
            exclude: [] !== $exclude ? $exclude : $strings(config('php-regex.exclude', ['vendor', 'node_modules', 'storage'])),
            // An exclude names a directory of the application: one of the
            // same name above it, where the application lives (Testbench's
            // skeleton lives under vendor/), excludes nothing.
            excludeBase: $configured ? base_path() : null,
            minSavings: (int) $this->option('min-savings'),
            jobs: null === $jobs ? null : (\is_string($jobs) ? $jobs : ''),
            disabledSources: array_keys(array_filter(['routes' => (bool) $this->option('no-routes'), 'validators' => $skipValidators])),
            optimizations: self::normalizeOptimizations(config('php-regex.optimizations', [])),
            checkRedos: true === config('php-regex.redos.enabled'),
            checkComplexity: true === config('php-regex.analysis.complexity'),
            reportUnmatchedIgnores: false !== config('php-regex.report_unmatched_ignores'),
            // The functions marked #[RegexPattern] are read in the
            // configured paths, the linted ones when none are, as
            // `regex lint` reads them, and in vendor/, whatever paths are
            // linted. A setting that is no list (one path) is read as one.
            declarationPaths: [] === $defaultPaths ? ($configured ? self::fromBasePath(['app']) : $givenPaths) : self::fromBasePath($defaultPaths),
            vendorPaths: null === ($vendorPaths = config('php-regex.vendor_paths'))
                ? [base_path('vendor')]
                : self::fromBasePath($strings($vendorPaths)),
            editorUrl: \is_string($editorUrl) ? $editorUrl : null,
            displayBase: base_path(),
            // The Regex service judges for the running PHP; the lint judges
            // for the project's target, and never compiles with the running
            // PHP. The services are resolved here, so that a setting they
            // cannot use is reported as one.
            setup: function (): LintSetup {
                $target = ProjectTarget::fromSources(
                    ['config php-regex.php_version' => $this->configVersion('php_version')],
                    ['config php-regex.pcre_version' => $this->configRelease('pcre_version')],
                    base_path(),
                    getenv(),
                    ['config php-regex.locale' => $this->configLocale()],
                );
                /** @var AnalysisService $analysis */
                $analysis = $this->laravel->make('php-regex.analysis');
                /** @var LintService $lint */
                $lint = $this->laravel->make('php-regex.lint');

                // What config/php-regex.php holds that the run ignores: one
                // warning each, and the run goes on.
                return LintSetup::forTarget($target, PHPRegexServiceProvider::regexOptions($this->laravel), $analysis, $lint, PHPRegexServiceProvider::extraction($this->laravel)['warnings']);
            },
            invalidOptionPrefix: 'Invalid config/php-regex.php: ',
            version: Regex::VERSION,
            unread: fn (): array => $skipValidators ? [] : $this->unreadFileResults(),
        ), $this->output->getOutput());
    }

    /**
     * A file the validation rule extractor could not read fails the run:
     * one error on its first line, named after the file and identified as
     * regex.lint.source.unreadable, instead of the rules it holds.
     *
     * @phpstan-return list<LintResult>
     */
    private function unreadFileResults(): array
    {
        /** @var ValidationRulePatternSource $validators */
        $validators = $this->laravel->make(ValidationRulePatternSource::class);

        $results = [];
        foreach ($validators->failures() as $failure) {
            $results[] = [
                'file' => $failure['file'],
                'line' => 1,
                'column' => 1,
                'fileOffset' => null,
                'source' => 'validation',
                'pattern' => null,
                'location' => 'Laravel validation rule',
                'issues' => [new LintIssue(LintSeverity::Error, $failure['message'], $failure['file'], 1, issueId: self::ISSUE_ID_SOURCE_UNREADABLE, source: 'validation')],
                'optimizations' => [],
            ];
        }

        return $results;
    }

    /**
     * A version of config/php-regex.php, as written: an int or a string.
     */
    private function configVersion(string $key): string|int|null
    {
        $value = config('php-regex.'.$key);
        if (null === $value || \is_string($value) || \is_int($value)) {
            return $value;
        }

        throw new InvalidRegexOptionException(\sprintf('"%s" must be a version string like "8.2" or a PHP_VERSION_ID like 80200, not a %s.', $key, get_debug_type($value)));
    }

    private function configRelease(string $key): ?string
    {
        $value = config('php-regex.'.$key);
        if (null === $value || \is_string($value)) {
            return $value;
        }

        throw new InvalidRegexOptionException(\sprintf('"%s" must be a PCRE2 release string like "10.42", not a %s.', $key, get_debug_type($value)));
    }

    private function configLocale(): ?string
    {
        $value = config('php-regex.locale');
        if (null === $value || \is_string($value)) {
            return $value;
        }

        throw new InvalidRegexOptionException(\sprintf('"locale" must be a locale name like "tr_TR.UTF-8", not a %s.', get_debug_type($value)));
    }

    /**
     * The configured paths read from the application's base path.
     *
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private static function fromBasePath(array $paths): array
    {
        $base = base_path();

        return array_map(static fn (string $path): string => RelativePathHelper::absolutize($path, $base), $paths);
    }

    /**
     * The optimizations of config/php-regex.php, keyed in snake_case as
     * the optimizer reads them. Lint checks every rewrite with the automata
     * unless verify_with_automata says otherwise, a config published before
     * it was listed included.
     */
    private static function normalizeOptimizations(mixed $optimizations): OptimizerOptions
    {
        return OptimizerOptions::fromArray((\is_array($optimizations) ? $optimizations : []) + ['verify_with_automata' => true]);
    }
}

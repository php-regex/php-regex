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

namespace PHPRegex\Linter;

use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\MemoryBudget;
use PHPRegex\Linter\Extraction\PatternAttributeScanner;
use PHPRegex\Linter\Extraction\PatternFunctionAwareInterface;
use PHPRegex\Linter\Internal\ForkedWorkerPool;

/**
 * Extracts regex patterns from PHP source files using configured extractor.
 *
 * This class is responsible for discovering and filtering PHP files,
 * then delegating pattern extraction to configured strategy.
 *
 * @internal
 */
final readonly class PatternExtractor
{
    private const WORKER_ALLOWED_CLASSES = [
        PatternOccurrence::class,
    ];

    /**
     * How many files found make one advance of the progress.
     */
    private const FOUND_BATCH = 1000;

    private const SUPPRESSION_MARKERS = [
        '@regex-ignore-next-line' => 'next',
        '@regex-ignore' => 'same',
        '@regex-suppress' => 'same',
    ];
    /**
     * Template file suffixes to exclude by default.
     * These files often contain template syntax that can be confused with regex quantifiers.
     */
    private const TEMPLATE_SUFFIXES = [
        '.tpl.php',
        '.blade.php',
        '.twig.php',
    ];

    public function __construct(private ExtractorInterface $extractor) {}

    public static function supportsParallel(): bool
    {
        return ForkedWorkerPool::canFork();
    }

    /**
     * Extract regex patterns from the given paths.
     *
     * The progress goes through the steps of the collection: the files found
     * (no total), the files read for declarations when the extractor is told
     * about pattern functions, the namespaced functions when that pass runs,
     * then the files read for their patterns.
     *
     * @param array<string>      $paths            Paths to scan for PHP files
     * @param array<string>|null $excludePaths     Optional paths to exclude (falls back to ['vendor'])
     * @param int                $workers          Number of worker processes to use when supported
     * @param array<string>      $declarationPaths Project paths read, besides the linted files, for the
     *                                             functions a parameter marked #[RegexPattern] makes pattern
     *                                             functions; what they hold is not linted
     * @param array<string>      $vendorPaths      Library paths (vendor/) read the same way; a project
     *                                             declaration wins over a copy found there
     *
     * @return array<PatternOccurrence>
     */
    public function extract(array $paths, ?array $excludePaths = null, ?LintProgress $progress = null, int $workers = 1, array $declarationPaths = [], array $vendorPaths = []): array
    {
        $excludePaths ??= ['vendor'];
        $progress?->step(LintProgress::STEP_FILES, null);
        $phpFiles = $this->collectPhpFiles($paths, $excludePaths, $progress);

        if ([] === $phpFiles) {
            $progress?->finish();

            return [];
        }

        // The declarations are read once, before the files are shared out:
        // a call and the declaration it needs may land in two chunks, or the
        // declaration in a file the run does not lint.
        $extractor = $this->extractor;
        if ($extractor instanceof PatternFunctionAwareInterface) {
            $projectFiles = $this->collectDeclarationFiles($paths, $phpFiles, $declarationPaths);
            $vendorFiles = $this->walkDeclarationPaths($vendorPaths);
            $progress?->finish();
            $extractor = $this->withDeclarations($extractor, $projectFiles, $vendorFiles, $workers, $progress);
        } else {
            $progress?->finish();
        }

        // The inline markers are read where the file is extracted: a file
        // never spans two chunks.
        $progress?->step(LintProgress::STEP_PATTERNS, \count($phpFiles));
        $occurrences = [];
        foreach ($this->readEach($phpFiles, $workers, fn (string $file): array => $this->applyInlineIgnores($extractor->extract([$file])), $progress) as $fileOccurrences) {
            array_push($occurrences, ...$fileOccurrences);
        }
        $progress?->finish();

        return $occurrences;
    }

    /**
     * The extractor, handed the pattern functions the files declare.
     *
     * Precedence, when several declarations name one function: a configured
     * spec (--pattern-function, extraction.functions) always wins over a
     * scanned declaration, the registry keeping it as configured; a project
     * declaration (the linted files and the declaration paths) wins over a
     * copy in vendor/; conflicting project declarations read the union of
     * the parameters they mark. Neither the order of the paths nor the
     * number of workers changes the result.
     *
     * @param list<string> $projectFiles
     * @param list<string> $vendorFiles
     */
    private function withDeclarations(ExtractorInterface&PatternFunctionAwareInterface $extractor, array $projectFiles, array $vendorFiles, int $workers, ?LintProgress $progress): ExtractorInterface
    {
        $files = [...$projectFiles, ...$vendorFiles];
        $progress?->step(LintProgress::STEP_DECLARATIONS, \count($files));
        $read = $this->readEach($files, $workers, static fn (string $file): array => PatternAttributeScanner::specs([$file]), $progress);
        $progress?->finish();

        $projectCount = \count($projectFiles);
        $specs = array_values(array_unique(array_merge([], ...\array_slice($read, 0, $projectCount))));
        $declared = array_fill_keys(array_map(self::specName(...), $specs), true);
        foreach (array_unique(array_merge([], ...\array_slice($read, $projectCount))) as $spec) {
            if (!isset($declared[self::specName($spec)])) {
                $specs[] = $spec;
            }
        }
        sort($specs);

        // An unqualified call in a namespace reaches that namespace's own
        // function, marked or not, before a global one: the namespaced
        // functions named as a configured or declared global one are read
        // too.
        $declaredGlobals = array_filter(array_map(self::specName(...), $specs), static fn (string $name): bool => !str_contains($name, '\\') && !str_contains($name, ':'));
        $globals = array_values(array_unique([...$extractor->customGlobalFunctions(), ...$declaredGlobals]));
        $plain = [];
        if ([] !== $globals) {
            $progress?->step(LintProgress::STEP_FUNCTIONS, \count($files));
            // A name read twice is kept once: the registry keys them.
            $plain = array_merge([], ...$this->readEach(
                $files,
                $workers,
                static fn (string $file): array => PatternAttributeScanner::namespacedFunctions([$file], $globals),
                $progress,
            ));
            $progress?->finish();
        }
        sort($plain);

        return $extractor->withPatternFunctions($specs, $plain);
    }

    /**
     * The lowercase function or method a spec names, without its argument.
     */
    private static function specName(string $spec): string
    {
        $hash = strrpos($spec, '#');

        return strtolower(false === $hash ? $spec : substr($spec, 0, $hash));
    }

    /**
     * What the read gives for each file, in the order of the files, run on
     * the workers when there are several; each file read advances the
     * progress.
     *
     * @template R
     *
     * @param list<string>        $files
     * @param \Closure(string): R $read
     *
     * @throws LintException when a worker fails
     *
     * @return list<R>
     */
    private function readEach(array $files, int $workers, \Closure $read, ?LintProgress $progress): array
    {
        $work = static function (array $chunk, ?\Closure $done) use ($read): array {
            $results = [];
            foreach ($chunk as $file) {
                $results[] = $read($file);
                if (null !== $done) {
                    $done(1);
                }
            }

            return $results;
        };

        if ($workers > 1 && \count($files) > 1 && ForkedWorkerPool::canFork()) {
            $results = (new ForkedWorkerPool())->run(
                $files,
                $workers,
                $work,
                $progress,
                'Parallel collection failed',
                self::WORKER_ALLOWED_CLASSES,
                static fn (string $file): string => $file,
            );
            if (null !== $results) {
                return $results;
            }
        }

        return $work($files, $progress instanceof LintProgress ? $progress->advance(...) : null);
    }

    /**
     * The project files read for declarations: the linted files, and the PHP
     * files under each declaration path. Below a linted path, the
     * declarations are read as the lint reads the files, so what the run
     * excludes there is not read, and a declaration path at or below a
     * linted one is not walked again. A declaration path the run does not
     * lint is read whatever the run excludes. vendor/ is left to its own
     * walk.
     *
     * @param array<string> $paths            the linted paths
     * @param array<string> $phpFiles         the linted files
     * @param array<string> $declarationPaths
     *
     * @return list<string>
     */
    private function collectDeclarationFiles(array $paths, array $phpFiles, array $declarationPaths): array
    {
        $linted = [];
        foreach ($paths as $path) {
            $real = '' !== $path && is_dir($path) ? realpath($path) : false;
            if (false !== $real) {
                $linted[$real] = true;
            }
        }

        $walk = [];
        foreach ($declarationPaths as $root) {
            if (!self::isBelow($root, $linted)) {
                $walk[] = $root;
            }
        }

        // Keys dedupe in one pass, without a call to the filesystem per file.
        return array_keys(array_flip([...$phpFiles, ...$this->walkDeclarationPaths($walk, $linted)]));
    }

    /**
     * Whether a directory is at or below one of the linted directories.
     *
     * @param array<string, true> $linted the real path of each linted directory
     */
    private static function isBelow(string $directory, array $linted): bool
    {
        $real = is_dir($directory) ? realpath($directory) : false;
        if (false === $real) {
            return false;
        }

        foreach (array_keys($linted) as $lintedReal) {
            if ($real === $lintedReal || str_starts_with($real, $lintedReal.\DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The PHP files under the paths, read for declarations only: a directory
     * that cannot be read is passed over, and a symlinked directory (a
     * package Composer links from a path repository) is followed, each real
     * directory once, so a symlink loop ends. A linted directory is not
     * entered: the lint's own walk listed its files.
     *
     * @param array<string>       $roots
     * @param array<string, true> $linted the real path of each linted directory
     *
     * @return list<string>
     */
    private function walkDeclarationPaths(array $roots, array $linted = []): array
    {
        $files = [];
        $visited = $linted;
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                array_push($files, ...$this->collectPhpFiles([$root], []));

                continue;
            }

            $pending = [rtrim($root, '/\\')];
            while ([] !== $pending) {
                $directory = array_pop($pending);
                $real = realpath($directory);
                $entries = false === $real || isset($visited[$real]) ? false : @scandir($directory);
                if (false === $entries) {
                    continue;
                }

                $visited[(string) $real] = true;
                foreach ($entries as $entry) {
                    $path = $directory.\DIRECTORY_SEPARATOR.$entry;
                    if ('.' === $entry || '..' === $entry) {
                        continue;
                    }
                    if (is_dir($path)) {
                        $pending[] = $path;
                    } elseif (str_ends_with($entry, '.php') && !$this->isTemplateFile($entry) && is_file($path)) {
                        $files[] = $path;
                    }
                }
            }
        }

        return $files;
    }

    /**
     * The progress, when given, advances by batches of files found, and by
     * the rest at the end.
     *
     * @param array<string> $paths
     * @param array<string> $excludePaths
     *
     * @return list<string>
     */
    private function collectPhpFiles(array $paths, array $excludePaths, ?LintProgress $progress = null): array
    {
        $normalizedExcludePaths = [];
        foreach ($excludePaths as $excludePath) {
            $excludePath = trim($excludePath, '/\\');
            if ('' !== $excludePath) {
                $normalizedExcludePaths[] = $excludePath;
            }
        }

        $files = [];
        foreach ($paths as $path) {
            if ('' === $path) {
                continue;
            }

            if (is_file($path) && str_ends_with($path, '.php') && !$this->isTemplateFile($path)) {
                $files[] = $path;
                self::reportFound($progress, \count($files));

                continue;
            }

            if (!is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            );

            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || 'php' !== $file->getExtension()) {
                    continue;
                }

                $filePath = $file->getPathname();

                // Skip template files by default
                if ($this->isTemplateFile($filePath)) {
                    continue;
                }

                // Skip excluded directories
                $excluded = false;
                foreach ($normalizedExcludePaths as $excludePath) {
                    if (str_contains($filePath, \DIRECTORY_SEPARATOR.$excludePath.\DIRECTORY_SEPARATOR) || str_starts_with($filePath, $excludePath.\DIRECTORY_SEPARATOR)) {
                        $excluded = true;

                        break;
                    }
                }

                if (!$excluded) {
                    $files[] = $filePath;
                    self::reportFound($progress, \count($files));
                }
            }
        }

        $rest = \count($files) % self::FOUND_BATCH;
        if (null !== $progress && 0 !== $rest) {
            $progress->advance($rest);
        }

        return $files;
    }

    private static function reportFound(?LintProgress $progress, int $found): void
    {
        if (null !== $progress && 0 === $found % self::FOUND_BATCH) {
            $progress->advance(self::FOUND_BATCH);
        }
    }

    /**
     * @param array<PatternOccurrence> $occurrences
     *
     * @return array<PatternOccurrence>
     */
    private function applyInlineIgnores(array $occurrences): array
    {
        if ([] === $occurrences) {
            return [];
        }

        $suppressedCache = [];
        $result = [];
        foreach ($occurrences as $occurrence) {
            // A file read with the tokenizer, or not read at all, holds no
            // pattern a marker could silence.
            if (!$occurrence instanceof PatternOccurrence || null !== $occurrence->unread || null !== $occurrence->parserFallback) {
                $result[] = $occurrence;

                continue;
            }

            $file = $occurrence->file;
            if (!\array_key_exists($file, $suppressedCache)) {
                $suppressedCache[$file] = $this->collectSuppressedLines($file);
            }

            if (isset($suppressedCache[$file][$occurrence->line])) {
                $result[] = new PatternOccurrence(
                    $occurrence->pattern,
                    $occurrence->file,
                    $occurrence->line,
                    $occurrence->source,
                    $occurrence->displayPattern,
                    $occurrence->location,
                    true,
                    $occurrence->column,
                    $occurrence->fileOffset,
                );
            } else {
                $result[] = $occurrence;
            }
        }

        return $result;
    }

    /**
     * @return array<int, bool>
     */
    private function collectSuppressedLines(string $file): array
    {
        $content = @file_get_contents($file);
        if (false === $content || '' === $content) {
            return [];
        }

        // Reading a huge generated file into tokens can exhaust the memory
        // limit, and a fatal error here would lose every result collected so
        // far. Such a file simply keeps no suppression markers.
        if (!MemoryBudget::allows($content, MemoryBudget::TOKENIZE_FACTOR)) {
            return [];
        }

        $tokens = token_get_all($content);
        $suppressed = [];

        foreach ($tokens as $token) {
            if (!\is_array($token)) {
                continue;
            }

            if (\T_COMMENT !== $token[0] && \T_DOC_COMMENT !== $token[0]) {
                continue;
            }

            $mode = $this->suppressionMode($token[1]);
            if (null === $mode) {
                continue;
            }

            $startLine = (int) $token[2];
            $endLine = $startLine + substr_count($token[1], "\n");
            $targetLine = 'next' === $mode ? $endLine + 1 : $endLine;

            $suppressed[$targetLine] = true;
        }

        return $suppressed;
    }

    private function suppressionMode(string $comment): ?string
    {
        foreach (self::SUPPRESSION_MARKERS as $marker => $mode) {
            if (str_contains($comment, $marker)) {
                return $mode;
            }
        }

        return null;
    }

    /**
     * Check if a file is a template file that should be excluded.
     */
    private function isTemplateFile(string $filePath): bool
    {
        foreach (self::TEMPLATE_SUFFIXES as $suffix) {
            if (str_ends_with($filePath, $suffix)) {
                return true;
            }
        }

        return false;
    }
}

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
        return \PHP_SAPI === 'cli'
            && \function_exists('pcntl_fork')
            && \function_exists('pcntl_waitpid');
    }

    /**
     * Extract regex patterns from the given paths.
     *
     * @param array<string>                 $paths            Paths to scan for PHP files
     * @param array<string>|null            $excludePaths     Optional paths to exclude (falls back to ['vendor'])
     * @param callable(int, int): void|null $progress         Reports collection progress as (current, total)
     * @param int                           $workers          Number of worker processes to use when supported
     * @param array<string>                 $declarationPaths Project paths read, besides the linted files, for the
     *                                                        functions a parameter marked #[RegexPattern] makes pattern
     *                                                        functions; what they hold is not linted
     * @param array<string>                 $vendorPaths      Library paths (vendor/) read the same way; a project
     *                                                        declaration wins over a copy found there
     *
     * @return array<PatternOccurrence>
     */
    public function extract(array $paths, ?array $excludePaths = null, ?callable $progress = null, int $workers = 1, array $declarationPaths = [], array $vendorPaths = []): array
    {
        $excludePaths ??= ['vendor'];
        $phpFiles = $this->collectPhpFiles($paths, $excludePaths);

        $total = \count($phpFiles);
        if (0 === $total) {
            if (null !== $progress) {
                $progress(0, 0);
            }

            return [];
        }

        // The progress starts before the declarations are read, which a
        // large vendor/ makes long.
        if (null !== $progress) {
            $progress(0, $total);
        }

        $parallel = $workers > 1 && self::supportsParallel();

        // The declarations are read once, before the files are shared out:
        // a call and the declaration it needs may land in two chunks, or the
        // declaration in a file the run does not lint.
        $extractor = $this->extractor;
        if ($extractor instanceof PatternFunctionAwareInterface) {
            $projectFiles = $this->collectDeclarationFiles($paths, $phpFiles, $declarationPaths);
            $extractor = $this->withDeclarations($extractor, $projectFiles, $this->walkDeclarationPaths($vendorPaths), $parallel ? $workers : 1);
        }

        if ($parallel && $total > 1) {
            return $this->applyInlineIgnores($this->extractParallel($phpFiles, $workers, $progress, $extractor));
        }

        return $this->applyInlineIgnores($this->extractSerial($phpFiles, $progress, $extractor));
    }

    /**
     * @param array<string>                 $phpFiles
     * @param callable(int, int): void|null $progress
     *
     * @return array<PatternOccurrence>
     */
    private function extractSerial(array $phpFiles, ?callable $progress, ExtractorInterface $extractor): array
    {
        if (null === $progress) {
            return $extractor->extract($phpFiles);
        }

        $total = \count($phpFiles);

        $occurrences = [];
        $current = 0;

        foreach ($phpFiles as $file) {
            foreach ($extractor->extract([$file]) as $occurrence) {
                $occurrences[] = $occurrence;
            }
            $current++;
            $progress($current, $total);
        }

        return $occurrences;
    }

    /**
     * @param array<string>                 $phpFiles
     * @param callable(int, int): void|null $progress
     *
     * @return array<PatternOccurrence>
     */
    private function extractParallel(array $phpFiles, int $workers, ?callable $progress, ExtractorInterface $extractor): array
    {
        $total = \count($phpFiles);
        $processed = 0;
        $resultsByIndex = $this->runInWorkers(
            $phpFiles,
            $workers,
            static fn (array $chunk): array => $extractor->extract($chunk),
            static function (int $count) use (&$processed, $total, $progress): void {
                if (null !== $progress) {
                    $processed += $count;
                    $progress($processed, $total);
                }
            },
        );

        if (null === $resultsByIndex) {
            return $this->extractSerial($phpFiles, $progress, $extractor);
        }

        /** @var array<PatternOccurrence> $results */
        $results = [];
        foreach ($resultsByIndex as $chunkResults) {
            /** @var PatternOccurrence $item */
            foreach (\is_array($chunkResults) ? $chunkResults : [] as $item) {
                $results[] = $item;
            }
        }

        return $results;
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
     * @param array<string> $projectFiles
     * @param array<string> $vendorFiles
     */
    private function withDeclarations(ExtractorInterface&PatternFunctionAwareInterface $extractor, array $projectFiles, array $vendorFiles, int $workers): ExtractorInterface
    {
        $scan = static fn (array $chunk): array => PatternAttributeScanner::specs($chunk);
        $specs = $this->scanInWorkers($projectFiles, $workers, $scan);
        $declared = array_fill_keys(array_map(self::specName(...), $specs), true);
        foreach ($this->scanInWorkers($vendorFiles, $workers, $scan) as $spec) {
            if (!isset($declared[self::specName($spec)])) {
                $specs[] = $spec;
            }
        }
        sort($specs);

        // An unqualified call in a namespace reaches that namespace's own
        // function, marked or not, before a global one: the namespaced
        // functions named as a declared global one are read too.
        $globals = array_values(array_filter(array_map(self::specName(...), $specs), static fn (string $name): bool => !str_contains($name, '\\') && !str_contains($name, ':')));
        $plain = [] === $globals ? [] : $this->scanInWorkers(
            [...$projectFiles, ...$vendorFiles],
            $workers,
            static fn (array $chunk): array => PatternAttributeScanner::namespacedFunctions($chunk, $globals),
        );
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
     * What the scan gives for the files, run on the workers when there are
     * several.
     *
     * @param array<string>                         $files
     * @param \Closure(array<string>): list<string> $scan
     *
     * @return list<string>
     */
    private function scanInWorkers(array $files, int $workers, \Closure $scan): array
    {
        $resultsByIndex = $workers > 1 && \count($files) > 1 ? $this->runInWorkers($files, $workers, $scan) : null;

        if (null === $resultsByIndex) {
            return array_values(array_unique($scan($files)));
        }

        $found = [];
        foreach ($resultsByIndex as $chunkResults) {
            foreach (\is_array($chunkResults) ? $chunkResults : [] as $item) {
                if (\is_string($item)) {
                    $found[] = $item;
                }
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Shares the files out between forked children, each running the work
     * on its chunk.
     *
     * @param array<string>                  $files
     * @param \Closure(array<string>): mixed $work
     * @param (\Closure(int): void)|null     $chunkDone called with the number of files of each chunk read back
     *
     * @return array<int, mixed>|null what the work gave for each chunk, in order; null when a child could not be started
     */
    private function runInWorkers(array $files, int $workers, \Closure $work, ?\Closure $chunkDone = null): ?array
    {
        $total = \count($files);
        $workerCount = max(1, min($workers, $total));
        $chunkSize = max(1, (int) ceil($total / $workerCount));
        $chunks = array_chunk($files, $chunkSize);
        $children = [];
        $failed = false;

        foreach ($chunks as $index => $chunk) {
            $tmpFile = tempnam(sys_get_temp_dir(), 'regexparser_extract_');
            if (false === $tmpFile) {
                $failed = true;

                break;
            }

            $pid = (new ForkedWorkerPool())->fork(
                static fn (): mixed => $work($chunk),
                $tmpFile,
            );
            if (-1 === $pid) {
                @unlink($tmpFile);
                $failed = true;

                break;
            }

            $children[$pid] = [
                'file' => $tmpFile,
                'index' => $index,
                'count' => \count($chunk),
            ];
        }

        if ($failed) {
            foreach ($children as $pid => $meta) {
                pcntl_waitpid($pid, $status);
                @unlink($meta['file']);
            }

            return null;
        }

        $resultsByIndex = [];
        $failure = null;

        // Every child is waited for and its payload file removed before a
        // failure is reported.
        foreach ($children as $pid => $meta) {
            pcntl_waitpid($pid, $status);
            $payload = $this->readWorkerPayload($meta['file']);
            @unlink($meta['file']);

            if (!($payload['ok'] ?? false)) {
                $error = $payload['error'] ?? ['message' => 'Unknown worker failure.', 'class' => \RuntimeException::class];
                $errorClass = isset($error['class']) && \is_string($error['class']) ? $error['class'] : \RuntimeException::class;
                $errorMessage = isset($error['message']) && \is_string($error['message']) ? $error['message'] : 'Unknown worker failure.';
                $failure ??= new LintException(\sprintf('Parallel collection failed: %s: %s', $errorClass, $errorMessage));

                continue;
            }

            $resultsByIndex[$meta['index']] = $payload['result'] ?? [];
            if (null !== $chunkDone) {
                $chunkDone($meta['count']);
            }
        }

        if (null !== $failure) {
            throw $failure;
        }

        ksort($resultsByIndex);

        return $resultsByIndex;
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
     * @param array<string> $paths
     * @param array<string> $excludePaths
     *
     * @return array<string>
     */
    private function collectPhpFiles(array $paths, array $excludePaths): array
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
                }
            }
        }

        return $files;
    }

    /**
     * @return array{ok: bool, result?: mixed, error?: array{message: string, class: string}}
     */
    private function readWorkerPayload(string $path): array
    {
        $data = @file_get_contents($path);
        if (false === $data) {
            return [
                'ok' => false,
                'error' => [
                    'message' => 'Failed to read worker output.',
                    'class' => \RuntimeException::class,
                ],
            ];
        }

        $payload = @unserialize($data, ['allowed_classes' => self::WORKER_ALLOWED_CLASSES]);
        if (!\is_array($payload) || !\array_key_exists('ok', $payload) || !\is_bool($payload['ok'])) {
            return [
                'ok' => false,
                'error' => [
                    'message' => 'Invalid worker output.',
                    'class' => \RuntimeException::class,
                ],
            ];
        }

        if (false === $payload['ok']) {
            $error = $payload['error'] ?? null;
            if (!\is_array($error) || !isset($error['message'], $error['class']) || !\is_string($error['message']) || !\is_string($error['class'])) {
                return [
                    'ok' => false,
                    'error' => [
                        'message' => 'Invalid worker error payload.',
                        'class' => \RuntimeException::class,
                    ],
                ];
            }

            return [
                'ok' => false,
                'error' => [
                    'message' => $error['message'],
                    'class' => $error['class'],
                ],
            ];
        }

        return [
            'ok' => true,
            'result' => $payload['result'] ?? null,
        ];
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

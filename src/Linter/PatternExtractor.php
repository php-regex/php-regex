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
     * @param array<string>                 $declarationPaths Paths read, besides the linted files, for the functions a
     *                                                        parameter marked #[RegexPattern] makes pattern functions (the
     *                                                        project's paths, its vendor/); what they hold is not linted
     *
     * @return array<PatternOccurrence>
     */
    public function extract(array $paths, ?array $excludePaths = null, ?callable $progress = null, int $workers = 1, array $declarationPaths = []): array
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

        $parallel = $workers > 1 && self::supportsParallel();

        // The declarations are read once, before the files are shared out:
        // a call and the declaration it needs may land in two chunks, or the
        // declaration in a file the run does not lint.
        $extractor = $this->extractor;
        if ($extractor instanceof PatternFunctionAwareInterface) {
            $declarationFiles = $this->collectDeclarationFiles($phpFiles, $declarationPaths, $excludePaths);
            $extractor = $extractor->withPatternFunctions($this->declaredPatternFunctions($declarationFiles, $parallel ? $workers : 1));
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
    private function extractSerial(array $phpFiles, ?callable $progress = null, ?ExtractorInterface $extractor = null): array
    {
        $extractor ??= $this->extractor;
        if (null === $progress) {
            return $extractor->extract($phpFiles);
        }

        $total = \count($phpFiles);
        $progress(0, $total);

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
    private function extractParallel(array $phpFiles, int $workers, ?callable $progress = null, ?ExtractorInterface $extractor = null): array
    {
        $extractor ??= $this->extractor;
        $total = \count($phpFiles);
        if (null !== $progress) {
            $progress(0, $total);
        }

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
     * The pattern function specs the files declare, read on the workers
     * when there are several.
     *
     * @param array<string> $files
     *
     * @return list<string>
     */
    private function declaredPatternFunctions(array $files, int $workers): array
    {
        $resultsByIndex = $workers > 1 && \count($files) > 1
            ? $this->runInWorkers($files, $workers, static fn (array $chunk): array => PatternAttributeScanner::specs($chunk))
            : null;

        if (null === $resultsByIndex) {
            return PatternAttributeScanner::specs($files);
        }

        $specs = [];
        foreach ($resultsByIndex as $chunkSpecs) {
            foreach (\is_array($chunkSpecs) ? $chunkSpecs : [] as $spec) {
                if (\is_string($spec)) {
                    $specs[] = $spec;
                }
            }
        }

        return array_values(array_unique($specs));
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

        foreach ($children as $pid => $meta) {
            pcntl_waitpid($pid, $status);
            $payload = $this->readWorkerPayload($meta['file']);
            @unlink($meta['file']);

            if (!($payload['ok'] ?? false)) {
                $error = $payload['error'] ?? ['message' => 'Unknown worker failure.', 'class' => \RuntimeException::class];
                $errorClass = isset($error['class']) && \is_string($error['class']) ? $error['class'] : \RuntimeException::class;
                $errorMessage = isset($error['message']) && \is_string($error['message']) ? $error['message'] : 'Unknown worker failure.';

                throw new LintException(\sprintf('Parallel collection failed: %s: %s', $errorClass, $errorMessage));
            }

            $resultsByIndex[$meta['index']] = $payload['result'] ?? [];
            if (null !== $chunkDone) {
                $chunkDone($meta['count']);
            }
        }

        ksort($resultsByIndex);

        return $resultsByIndex;
    }

    /**
     * The files read for declarations: the linted files, and the PHP files
     * under each declaration path. An excluded directory is read as the run
     * reads it, below the declaration path: vendor/ named as a declaration
     * path is read, though "vendor" is excluded.
     *
     * @param array<string> $phpFiles
     * @param array<string> $declarationPaths
     * @param array<string> $excludePaths
     *
     * @return list<string>
     */
    private function collectDeclarationFiles(array $phpFiles, array $declarationPaths, array $excludePaths): array
    {
        $files = [];
        $add = static function (string $file) use (&$files): void {
            $files[realpath($file) ?: $file] ??= $file;
        };

        foreach ($phpFiles as $file) {
            $add($file);
        }

        foreach ($declarationPaths as $root) {
            // A file is read as it is and a missing path passed over; below
            // a directory, what the run excludes is skipped.
            $root = is_dir($root) ? rtrim($root, '/\\') : $root;
            $below = is_dir($root) ? \strlen($root) : null;
            foreach ($this->collectPhpFiles([$root], []) as $file) {
                if (null === $below || !$this->isExcluded(substr($file, $below), $excludePaths)) {
                    $add($file);
                }
            }
        }

        return array_values($files);
    }

    /**
     * @param array<string> $paths
     * @param array<string> $excludePaths
     *
     * @return array<string>
     */
    private function collectPhpFiles(array $paths, array $excludePaths): array
    {
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
                if (!$this->isExcluded($filePath, $excludePaths)) {
                    $files[] = $filePath;
                }
            }
        }

        return $files;
    }

    /**
     * @param array<string> $excludePaths
     */
    private function isExcluded(string $filePath, array $excludePaths): bool
    {
        foreach ($excludePaths as $excludePath) {
            $excludePath = trim($excludePath, '/\\');
            if ('' !== $excludePath
                && (str_contains($filePath, \DIRECTORY_SEPARATOR.$excludePath.\DIRECTORY_SEPARATOR) || str_starts_with($filePath, $excludePath.\DIRECTORY_SEPARATOR))) {
                return true;
            }
        }

        return false;
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

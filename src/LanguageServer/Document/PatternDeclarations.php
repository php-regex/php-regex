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

namespace PHPRegex\LanguageServer\Document;

use PHPRegex\Linter\Extraction\PatternAttributeScanner;

/**
 * The functions and static methods that declare a parameter with
 * #[RegexPattern], or PhpStorm's #[Language('RegExp')]: their calls hold a
 * pattern at that argument.
 *
 * They are read from the PHP files of the workspace, under its "paths" and
 * out of its "exclude" entries, as `regex lint` reads them, and from the
 * open documents: an open document stands for its file until it is closed,
 * saved or not.
 *
 * @internal
 */
final class PatternDeclarations
{
    /**
     * The PHP files the workspace scan reads at most.
     */
    public const FILE_LIMIT = 20000;

    /**
     * A file naming neither namespace declares nothing: it is not tokenized.
     */
    private const NEEDLES = ['PHPRegex\Parser\Attribute', 'JetBrains\PhpStorm\Language'];

    private const TEMPLATE_SUFFIXES = ['.tpl.php', '.blade.php', '.twig.php'];

    /**
     * @var array<string, list<string>> path => specs, from the disk
     */
    private array $files = [];

    /**
     * @var array<string, list<string>> path (or URI, for a document with no file) => specs
     */
    private array $documents = [];

    private ?string $root = null;

    /**
     * @var list<string>
     */
    private array $bases = [];

    /**
     * @var list<string>
     */
    private array $exclude = [];

    /**
     * @var array{functions: array<string, int>, methods: array<string, int>}|null
     */
    private ?array $index = null;

    public function __construct(private readonly int $fileLimit = self::FILE_LIMIT) {}

    /**
     * Reads the PHP files under $paths (relative to $root, or absolute) for
     * declarations, but those under an $exclude entry (relative to $root) or
     * a template. False when the scan stopped at the file limit.
     *
     * @param list<string> $paths
     * @param list<string> $exclude
     */
    public function scanWorkspace(string $root, array $paths, array $exclude): bool
    {
        $this->root = rtrim(self::normalize($root), '/');
        $this->exclude = array_values(array_filter(array_map(static fn (string $entry): string => trim(self::normalize($entry), '/'), $exclude), static fn (string $entry): bool => '' !== $entry));
        $this->bases = [];
        foreach ($paths as $path) {
            $path = self::normalize($path);
            // "." and "./src" name the root and its src/ as the editor's URIs do.
            $base = (self::isAbsolute($path) ? $path : $this->root.'/'.$path).'/';
            while (str_contains($base, '/./')) {
                $base = str_replace('/./', '/', $base);
            }
            $this->bases[] = rtrim($base, '/');
        }

        $this->files = [];
        $this->index = null;
        $read = 0;
        foreach ($this->bases as $base) {
            foreach ($this->phpFiles($base) as $file) {
                if (++$read > $this->fileLimit) {
                    return false;
                }

                $specs = PatternAttributeScanner::specs([$file]);
                if ([] !== $specs) {
                    $this->files[$file] = $specs;
                }
            }
        }

        return true;
    }

    /**
     * Reads one file of the workspace again, from $content when given (the
     * text an editor saved), else from the disk. A file outside the
     * workspace is passed over. Whether the declarations changed.
     */
    public function readFile(string $path, ?string $content = null): bool
    {
        $path = self::normalize($path);
        if (!$this->inWorkspace($path)) {
            return false;
        }

        $before = $this->current();
        $specs = null === $content ? PatternAttributeScanner::specs([$path]) : self::scan($content);
        if ([] === $specs) {
            unset($this->files[$path]);
        } else {
            $this->files[$path] = $specs;
        }

        return $this->changedSince($before);
    }

    /**
     * Forgets a file deleted from the workspace. Whether the declarations
     * changed.
     */
    public function forgetFile(string $path): bool
    {
        $before = $this->current();
        unset($this->files[self::normalize($path)]);

        return $this->changedSince($before);
    }

    /**
     * Reads an open document, which stands for its file. Whether the
     * declarations changed.
     */
    public function readDocument(string $uri, string $content): bool
    {
        $before = $this->current();
        $this->documents[self::keyOf($uri)] = self::scan($content);

        return $this->changedSince($before);
    }

    /**
     * Hands a closed document back to its file on the disk. Whether the
     * declarations changed.
     */
    public function closeDocument(string $uri): bool
    {
        $before = $this->current();
        unset($this->documents[self::keyOf($uri)]);

        return $this->changedSince($before);
    }

    public function isEmpty(): bool
    {
        $index = $this->index();

        return [] === $index['functions'] && [] === $index['methods'];
    }

    /**
     * The zero-based position of the pattern argument of a function, by its
     * fully qualified name; null when it declares none.
     */
    public function functionArgument(string $name): ?int
    {
        return $this->index()['functions'][strtolower(ltrim($name, '\\'))] ?? null;
    }

    /**
     * The zero-based position of the pattern argument of a static method, by
     * its fully qualified class; null when it declares none.
     */
    public function methodArgument(string $class, string $method): ?int
    {
        return $this->index()['methods'][strtolower(ltrim($class, '\\').'::'.$method)] ?? null;
    }

    /**
     * The local path a file:// URI names; null for another scheme.
     */
    public static function pathOf(string $uri): ?string
    {
        if (!str_starts_with($uri, 'file://')) {
            return null;
        }

        // parse_url() reads file:///C:/project as C:/project.
        return rawurldecode((string) parse_url($uri, \PHP_URL_PATH));
    }

    /**
     * @return list<string>
     */
    private static function scan(string $content): array
    {
        foreach (self::NEEDLES as $needle) {
            if (false !== stripos($content, $needle)) {
                return array_values(array_unique(PatternAttributeScanner::scan($content)));
            }
        }

        return [];
    }

    /**
     * @return iterable<string>
     */
    private function phpFiles(string $base): iterable
    {
        if (is_file($base)) {
            if ($this->isPhpFile($base)) {
                yield $base;
            }

            return;
        }

        if (!is_dir($base)) {
            return;
        }

        $directories = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
            fn (\SplFileInfo $entry): bool => !$entry->isDir() || !$this->isExcluded(self::normalize($entry->getPathname()).'/'),
        );

        foreach (new \RecursiveIteratorIterator($directories, \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD) as $entry) {
            if ($entry instanceof \SplFileInfo && $entry->isFile()) {
                $path = self::normalize($entry->getPathname());
                if ($this->isPhpFile($path) && !$this->isExcluded($path)) {
                    yield $path;
                }
            }
        }
    }

    private function inWorkspace(string $path): bool
    {
        if (null === $this->root || !$this->isPhpFile($path) || $this->isExcluded($path)) {
            return false;
        }

        foreach ($this->bases as $base) {
            if ($path === $base || str_starts_with($path, $base.'/')) {
                return true;
            }
        }

        return false;
    }

    private function isPhpFile(string $path): bool
    {
        if (!str_ends_with(strtolower($path), '.php')) {
            return false;
        }

        foreach (self::TEMPLATE_SUFFIXES as $suffix) {
            if (str_ends_with(strtolower($path), $suffix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a path lies under an "exclude" entry, matched on whole
     * segments of its path from the root, as `regex lint` matches them.
     */
    private function isExcluded(string $path): bool
    {
        $relative = null !== $this->root && str_starts_with($path, $this->root.'/') ? substr($path, \strlen($this->root)) : $path;
        foreach ($this->exclude as $entry) {
            if (str_contains($relative, '/'.$entry.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{functions: array<string, int>, methods: array<string, int>}
     */
    private function index(): array
    {
        if (null !== $this->index) {
            return $this->index;
        }

        $index = ['functions' => [], 'methods' => []];
        foreach ($this->current() as $spec) {
            // The scanner writes every spec "name#index".
            $hash = (int) strrpos($spec, '#');
            $name = strtolower(substr($spec, 0, $hash));
            $index[str_contains($name, '::') ? 'methods' : 'functions'][$name] = (int) substr($spec, $hash + 1);
        }

        return $this->index = $index;
    }

    /**
     * The specs in force: the open documents', and the files' that no open
     * document stands for.
     *
     * @return list<string>
     */
    private function current(): array
    {
        $specs = [];
        foreach (array_diff_key($this->files, $this->documents) + $this->documents as $declared) {
            array_push($specs, ...$declared);
        }
        $specs = array_values(array_unique($specs));
        sort($specs);

        return $specs;
    }

    /**
     * @param list<string> $before
     */
    private function changedSince(array $before): bool
    {
        if ($before === $this->current()) {
            return false;
        }

        $this->index = null;

        return true;
    }

    private static function keyOf(string $uri): string
    {
        $path = self::pathOf($uri);

        return null === $path ? $uri : self::normalize($path);
    }

    private static function normalize(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || (\strlen($path) > 2 && ':' === $path[1] && '/' === $path[2]);
    }
}

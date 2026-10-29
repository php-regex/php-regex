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

use PHPRegex\Linter\Extraction\MemoryBudget;
use PHPRegex\Linter\Extraction\PatternAttributeScanner;
use PHPRegex\Parser\Text\Ascii;

/**
 * The functions and static methods that declare a parameter with
 * #[RegexPattern], or PhpStorm's #[Language('RegExp')]: their calls hold a
 * pattern at that argument.
 *
 * They are read from the PHP files of the workspace, under its "paths" and
 * out of its "exclude" entries, as `regex lint` reads them, from the
 * libraries of vendor/, whatever "exclude" says, and from the open
 * documents: an open document stands for its file until it is closed, saved
 * or not. A declaration of the project wins over a library's of the same
 * function. The libraries are read once, and again when a file of theirs
 * changes or Composer installs others.
 *
 * The same sources tell which namespaced functions are named as a global
 * pattern function: an unqualified call in their namespace reaches them,
 * and is not read.
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
     * The manifest of the packages Composer installed, under the library
     * directory: when it changes, the libraries are read again.
     */
    public const MANIFEST_SUFFIX = '/composer/installed.json';

    private const TEMPLATE_SUFFIXES = ['.tpl.php', '.blade.php', '.twig.php'];

    /**
     * @var array<string, list<string>> path => specs, from the disk
     */
    private array $files = [];

    /**
     * @var array<string, list<string>> path => specs, from the libraries on the disk
     */
    private array $libraries = [];

    /**
     * @var array<string, list<string>> path (or URI, for a document with no file) => specs
     */
    private array $documents = [];

    /**
     * The libraries' directory, vendor/, once read.
     */
    private ?string $libraryDirectory = null;

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

    /**
     * @var list<string>|null
     */
    private ?array $specs = null;

    /**
     * @var array<string, true> every PHP file of the workspace scan
     */
    private array $workspacePaths = [];

    /**
     * @var array<string, true> every PHP file of the libraries' scan
     */
    private array $libraryPaths = [];

    /**
     * @var array<string, string> path (or URI) => text, of the open documents
     */
    private array $texts = [];

    /**
     * The global function names the files were last read for, the
     * namespaced functions of those names shadowing them; null until read.
     *
     * @var list<string>|null
     */
    private ?array $plainNames = null;

    /**
     * The namespaced functions named as the global ones read for, by path,
     * from the disk; a file declaring none has no entry.
     *
     * @var array<string, list<string>>
     */
    private array $plainFiles = [];

    /**
     * The namespaced functions named as the global ones read for, by path
     * (or URI), of the open documents.
     *
     * @var array<string, list<string>>
     */
    private array $plainDocuments = [];

    /**
     * @var list<string> the configured global pattern functions, lowercase
     */
    private array $customGlobals = [];

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
        $this->workspacePaths = [];
        $this->forgetIndex();
        $this->plainNames = null;
        $read = 0;
        foreach ($this->bases as $base) {
            foreach ($this->phpFiles($base) as $file) {
                if (++$read > $this->fileLimit) {
                    return false;
                }

                $this->workspacePaths[$file] = true;
                $specs = self::scanFile($file);
                if ([] !== $specs) {
                    $this->files[$file] = $specs;
                }
            }
        }

        return true;
    }

    /**
     * Reads the PHP files under the libraries' directory, vendor/, for
     * declarations, as `regex lint` reads them: a symlinked package is
     * followed, each real directory once. What they hold is never
     * diagnosed. False when the scan stopped at the file limit.
     */
    public function scanLibraries(string $directory): bool
    {
        $directory = rtrim(self::normalize($directory), '/');
        $this->libraryDirectory = $directory;
        $this->libraries = [];
        $this->libraryPaths = [];
        $this->forgetIndex();
        $this->plainNames = null;

        $read = 0;
        $visited = [];
        $pending = [$directory];
        while ([] !== $pending) {
            $current = array_pop($pending);
            $real = realpath($current);
            $entries = false === $real || isset($visited[$real]) ? false : @scandir($current);
            if (false === $entries) {
                continue;
            }

            $visited[$real] = true;
            foreach ($entries as $entry) {
                $path = $current.'/'.$entry;
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }

                if (is_dir($path)) {
                    $pending[] = $path;
                } elseif ($this->isPhpFile($path)) {
                    if (++$read > $this->fileLimit) {
                        return false;
                    }

                    $this->libraryPaths[$path] = true;
                    $specs = self::scanFile($path);
                    if ([] !== $specs) {
                        $this->libraries[$path] = $specs;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Whether a path lies in the libraries' directory: a document there is
     * a library's, which the server reads declarations from and never
     * diagnoses.
     */
    public function isLibrary(string $path): bool
    {
        return null !== $this->libraryDirectory && str_starts_with(self::normalize($path), $this->libraryDirectory.'/');
    }

    /**
     * Reads one file of the workspace again, from $content when given (the
     * text an editor saved), else from the disk. A file outside the
     * workspace is passed over. Whether the declarations changed.
     */
    public function readFile(string $path, ?string $content = null): bool
    {
        $path = self::normalize($path);
        if ($this->isLibraryManifest($path)) {
            return $this->rescanLibraries();
        }

        $library = $this->isLibrary($path);
        $workspace = $this->inWorkspace($path);
        if (!$library && !$workspace) {
            return false;
        }

        if ($library && !$this->isPhpFile($path)) {
            return false;
        }

        $before = $this->snapshot();
        $specs = null === $content ? self::scanFile($path) : self::scan($content);
        if ($library) {
            $this->libraryPaths[$path] = true;
            $this->libraries[$path] = $specs;
            if ([] === $specs) {
                unset($this->libraries[$path]);
            }
        }

        // A vendor subtree the workspace's regex.json names among its
        // paths is workspace as well as library: both stores carry it,
        // and both must hear the change.
        if ($workspace) {
            $this->workspacePaths[$path] = true;
            $this->files[$path] = $specs;
            if ([] === $specs) {
                unset($this->files[$path]);
            }
        }

        if (null !== $this->plainNames && [] !== $this->plainNames) {
            $this->plainFiles[$path] = null === $content
                ? PatternAttributeScanner::namespacedFunctions([$path], $this->plainNames)
                : PatternAttributeScanner::namespacedFunctionsIn($content, $this->plainNames);
            if ([] === $this->plainFiles[$path]) {
                unset($this->plainFiles[$path]);
            }
        }

        return $this->changedSince($before);
    }

    /**
     * Forgets a file deleted from the workspace. Whether the declarations
     * changed.
     */
    public function forgetFile(string $path): bool
    {
        $path = self::normalize($path);
        if ($this->isLibraryManifest($path)) {
            return $this->rescanLibraries();
        }

        $before = $this->snapshot();
        unset($this->files[$path], $this->libraries[$path], $this->workspacePaths[$path], $this->libraryPaths[$path], $this->plainFiles[$path]);

        return $this->changedSince($before);
    }

    /**
     * Reads an open document, which stands for its file. Whether the
     * declarations changed.
     */
    public function readDocument(string $uri, string $content): bool
    {
        $before = $this->snapshot();
        $key = self::keyOf($uri);
        $this->documents[$key] = self::scan($content);
        $this->texts[$key] = $content;
        if (null !== $this->plainNames && [] !== $this->plainNames) {
            $this->plainDocuments[$key] = PatternAttributeScanner::namespacedFunctionsIn($content, $this->plainNames);
        }

        return $this->changedSince($before);
    }

    /**
     * Hands a closed document back to its file on the disk. Whether the
     * declarations changed.
     */
    public function closeDocument(string $uri): bool
    {
        $before = $this->snapshot();
        $key = self::keyOf($uri);
        unset($this->documents[$key], $this->texts[$key], $this->plainDocuments[$key]);

        return $this->changedSince($before);
    }

    /**
     * The namespaced functions named as a global pattern function, a
     * configured one or one the declarations mark: an unqualified call in
     * their namespace reaches them, not the global one, as `regex lint`
     * reads it. The files are read for them when the names change; an open
     * document stands for its file.
     *
     * @param array<string> $customGlobals the configured global pattern functions
     *
     * @return list<string>
     */
    public function plainFunctions(array $customGlobals): array
    {
        $this->customGlobals = self::lowercaseSorted($customGlobals);
        $names = $this->globalNames();
        if ([] === $names) {
            return [];
        }

        if ($names !== $this->plainNames) {
            $this->plainNames = $names;
            $this->plainFiles = [];
            foreach (array_keys($this->workspacePaths + $this->libraryPaths) as $path) {
                $functions = PatternAttributeScanner::namespacedFunctions([$path], $names);
                if ([] !== $functions) {
                    $this->plainFiles[$path] = $functions;
                }
            }
            $this->plainDocuments = [];
            foreach ($this->texts as $key => $text) {
                $this->plainDocuments[$key] = PatternAttributeScanner::namespacedFunctionsIn($text, $names);
            }
        }

        return $this->mergedPlain() ?? [];
    }

    public function isEmpty(): bool
    {
        $index = $this->index();

        return [] === $index['functions'] && [] === $index['methods'];
    }

    /**
     * The declarations in force, as #[RegexPattern] specs naming the
     * parameter each marks ("App\grep#1$regex"), sorted.
     *
     * @return list<string>
     */
    public function specs(): array
    {
        return $this->specs ??= $this->current();
    }

    /**
     * The zero-based position of the pattern argument of a function, by its
     * fully qualified name; null when it declares none.
     */
    public function functionArgument(string $name): ?int
    {
        return $this->index()['functions'][Ascii::lower(ltrim($name, '\\'))] ?? null;
    }

    /**
     * The zero-based position of the pattern argument of a static method, by
     * its fully qualified class; null when it declares none.
     */
    public function methodArgument(string $class, string $method): ?int
    {
        return $this->index()['methods'][Ascii::lower(ltrim($class, '\\').'::'.$method)] ?? null;
    }

    /**
     * The local path a file:// URI names; null for another scheme.
     */
    public static function pathOf(string $uri): ?string
    {
        if (!str_starts_with($uri, 'file://')) {
            return null;
        }

        // parse_url() reads file:///C:/project as C:/project, and the
        // file:///c%3A/project an editor sends for a Windows path as
        // /c:/project once decoded: the slash before the drive goes.
        $path = rawurldecode((string) parse_url($uri, \PHP_URL_PATH));

        return \strlen($path) >= 4 && '/' === $path[0] && ':' === $path[2] && '/' === $path[3] && Ascii::isAlpha($path[1]) ? substr($path, 1) : $path;
    }

    /**
     * Whether a path is one of the workspace's configured PHP files — the
     * ones the editor's events keep fresh even under vendor/, where a
     * subtree the workspace's regex.json names among its paths is
     * workspace, not library.
     */
    public function readsWorkspacePath(string $path): bool
    {
        return $this->inWorkspace($path);
    }

    /**
     * @return list<string>
     */
    private static function scan(string $content): array
    {
        // The workspace scan's filter: a text holding neither "RegexPattern"
        // nor PhpStorm's "RegExp", whatever its imports, declares nothing;
        // nor does one without an attribute. A library's dictionary of
        // function names may say "regexp" a thousand times.
        if (!str_contains($content, '#[') || !str_contains(Ascii::lower($content), 'regexp')) {
            return [];
        }

        return array_values(array_unique(PatternAttributeScanner::scan($content)));
    }

    /**
     * The declarations of a file on the disk; one too large to tokenize in
     * the memory left, or that cannot be read, declares none.
     *
     * @return list<string>
     */
    private static function scanFile(string $path): array
    {
        $size = @filesize($path);
        $content = false !== $size && MemoryBudget::allowsSize($size, MemoryBudget::TOKENIZE_FACTOR) ? @file_get_contents($path) : false;

        return \is_string($content) ? self::scan($content) : [];
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
        if (!str_ends_with(Ascii::lower($path), '.php')) {
            return false;
        }

        foreach (self::TEMPLATE_SUFFIXES as $suffix) {
            if (str_ends_with(Ascii::lower($path), $suffix)) {
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
        foreach ($this->specs() as $spec) {
            // The scanner writes every spec "name#index$parameter".
            $hash = (int) strrpos($spec, '#');
            $name = Ascii::lower(substr($spec, 0, $hash));
            $index[str_contains($name, '::') ? 'methods' : 'functions'][$name] = (int) substr($spec, $hash + 1);
        }

        return $this->index = $index;
    }

    /**
     * The specs in force: the open documents', the files' that no open
     * document stands for, then the libraries' for the functions the
     * project does not declare.
     *
     * @return list<string>
     */
    private function current(): array
    {
        $specs = [];
        foreach (array_diff_key($this->files, $this->documents) + $this->documents as $declared) {
            array_push($specs, ...$declared);
        }

        if ([] !== $this->libraries) {
            $declared = array_fill_keys(array_map(self::specName(...), $specs), true);
            foreach (array_diff_key($this->libraries, $this->documents) as $library) {
                foreach ($library as $spec) {
                    if (!isset($declared[self::specName($spec)])) {
                        $specs[] = $spec;
                    }
                }
            }
        }

        $specs = array_values(array_unique($specs));
        sort($specs);

        return $specs;
    }

    /**
     * The lowercase function or method a spec names.
     */
    private static function specName(string $spec): string
    {
        return Ascii::lower(substr($spec, 0, (int) strrpos($spec, '#')));
    }

    /**
     * What the documents are read with: the specs, and the shadowing
     * namespaced functions once read.
     *
     * @return array{list<string>, list<string>|null}
     */
    private function snapshot(): array
    {
        return [$this->current(), $this->mergedPlain()];
    }

    /**
     * @param array{list<string>, list<string>|null} $before
     */
    private function changedSince(array $before): bool
    {
        if ($before === $this->snapshot()) {
            return false;
        }

        $this->forgetIndex();

        return true;
    }

    /**
     * The shadowing namespaced functions read so far, the open documents'
     * standing for their files; null before the files were read for them.
     *
     * @return list<string>|null
     */
    private function mergedPlain(): ?array
    {
        if (null === $this->plainNames) {
            return null;
        }

        $plain = [];
        foreach (array_diff_key($this->plainFiles, $this->plainDocuments) + $this->plainDocuments as $functions) {
            array_push($plain, ...$functions);
        }

        return self::lowercaseSorted($plain);
    }

    /**
     * The global pattern functions an unqualified call may reach: the
     * configured ones and the ones the declarations mark.
     *
     * @return list<string>
     */
    private function globalNames(): array
    {
        $names = $this->customGlobals;
        foreach ($this->specs() as $spec) {
            $name = self::specName($spec);
            if (!str_contains($name, '\\') && !str_contains($name, ':')) {
                $names[] = $name;
            }
        }

        return self::lowercaseSorted($names);
    }

    /**
     * @param array<string> $names
     *
     * @return list<string>
     */
    private static function lowercaseSorted(array $names): array
    {
        $names = array_unique(array_map(Ascii::lower(...), $names));
        sort($names);

        return $names;
    }

    private function forgetIndex(): void
    {
        $this->index = null;
        $this->specs = null;
    }

    /**
     * Whether the path is the list of the packages Composer installed: when
     * it changes, the libraries are read again.
     */
    private function isLibraryManifest(string $path): bool
    {
        return null !== $this->libraryDirectory && $this->libraryDirectory.self::MANIFEST_SUFFIX === $path;
    }

    /**
     * Whether the declarations changed.
     */
    private function rescanLibraries(): bool
    {
        $before = $this->snapshot();
        $this->scanLibraries((string) $this->libraryDirectory);

        return $this->changedSince($before);
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

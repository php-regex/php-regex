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

/**
 * Manages open documents and their cached regex patterns.
 *
 * @internal
 */
final class DocumentManager
{
    /**
     * @var array<string, string> URI => content
     */
    private array $documents = [];

    /**
     * @var array<string, int> URI => version, as the client numbered it
     */
    private array $versions = [];

    /**
     * @var array<string, list<RegexOccurrence>> URI => occurrences
     */
    private array $occurrences = [];

    public function __construct(private RegexFinder $finder, private readonly PatternDeclarations $declarations = new PatternDeclarations()) {}

    /**
     * Reads the open documents again with another finder, the one the
     * workspace's configuration builds.
     *
     * @return list<string> the open documents
     */
    public function readWith(RegexFinder $finder): array
    {
        $this->finder = $finder;

        return $this->refresh();
    }

    /**
     * Open a document, at the version the client numbers it with.
     *
     * @return list<string> the other open documents whose patterns changed,
     *                      as the declarations of this one count now
     */
    public function open(string $uri, string $content, ?int $version = null): array
    {
        return $this->update($uri, $content, $version);
    }

    /**
     * Update a document's content, at the version the client numbers it
     * with: none sent names no state, and none is remembered of the
     * states before.
     *
     * @return list<string> the other open documents whose patterns changed
     *                      with the declarations of this one
     */
    public function update(string $uri, string $content, ?int $version = null): array
    {
        $this->documents[$uri] = $content;
        if (null === $version) {
            unset($this->versions[$uri]);
        } else {
            $this->versions[$uri] = $version;
        }

        if ($this->declarations->readDocument($uri, $content)) {
            return array_values(array_diff($this->refresh(), [$uri]));
        }

        $this->occurrences[$uri] = $this->finder->find($content, $this->declarations);

        return [];
    }

    /**
     * Close a document.
     *
     * @return list<string> the open documents whose patterns changed, as its
     *                      file on the disk declares otherwise
     */
    public function close(string $uri): array
    {
        unset($this->documents[$uri], $this->versions[$uri], $this->occurrences[$uri]);

        return $this->declarations->closeDocument($uri) ? $this->refresh() : [];
    }

    /**
     * The declarations the documents are read with.
     */
    public function declarations(): PatternDeclarations
    {
        return $this->declarations;
    }

    /**
     * Read every open document again, after the declarations changed.
     *
     * @return list<string> the open documents
     */
    public function refresh(): array
    {
        foreach ($this->documents as $uri => $content) {
            $this->occurrences[$uri] = $this->finder->find($content, $this->declarations);
        }

        return array_map(strval(...), array_keys($this->documents));
    }

    /**
     * Get document content.
     */
    public function getContent(string $uri): ?string
    {
        return $this->documents[$uri] ?? null;
    }

    /**
     * The version the client last numbered the document with, none when
     * it sends none.
     */
    public function getVersion(string $uri): ?int
    {
        return $this->versions[$uri] ?? null;
    }

    /**
     * Get all regex occurrences in a document.
     *
     * @return list<RegexOccurrence>
     */
    public function getOccurrences(string $uri): array
    {
        return $this->occurrences[$uri] ?? [];
    }

    /**
     * Get the regex occurrence at a specific position.
     */
    public function getOccurrenceAtPosition(string $uri, int $line, int $character): ?RegexOccurrence
    {
        foreach ($this->getOccurrences($uri) as $occurrence) {
            if ($occurrence->containsPosition($line, $character)) {
                return $occurrence;
            }
        }

        return null;
    }

    /**
     * Whether a document is a library's, in vendor/: its declarations are
     * read, its patterns are not diagnosed.
     */
    public function isLibrary(string $uri): bool
    {
        $path = PatternDeclarations::pathOf($uri);

        return null !== $path && $this->declarations->isLibrary($path);
    }

    /**
     * Whether a change the editor reports for a file outside the open
     * documents is read for its declarations: a library's file is not —
     * composer's manifest alone is, as reading it reads every library
     * again, each file of theirs with them.
     */
    public function readsWatchedChange(string $uri): bool
    {
        if (!$this->isLibrary($uri)) {
            return true;
        }

        $path = PatternDeclarations::pathOf($uri);

        return null !== $path
            && (str_ends_with($path, PatternDeclarations::MANIFEST_SUFFIX) || $this->declarations->readsWorkspacePath($path));
    }

    /**
     * Check if a document is open.
     */
    public function isOpen(string $uri): bool
    {
        return isset($this->documents[$uri]);
    }
}

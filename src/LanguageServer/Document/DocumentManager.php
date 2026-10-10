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
     * @var array<string, list<RegexOccurrence>> URI => occurrences
     */
    private array $occurrences = [];

    public function __construct(private readonly RegexFinder $finder, private readonly PatternDeclarations $declarations = new PatternDeclarations()) {}

    /**
     * Open a document.
     *
     * @return list<string> the other open documents whose patterns changed,
     *                      as the declarations of this one count now
     */
    public function open(string $uri, string $content): array
    {
        return $this->update($uri, $content);
    }

    /**
     * Update a document's content.
     *
     * @return list<string> the other open documents whose patterns changed
     *                      with the declarations of this one
     */
    public function update(string $uri, string $content): array
    {
        $this->documents[$uri] = $content;
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
        unset($this->documents[$uri], $this->occurrences[$uri]);

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
     * Check if a document is open.
     */
    public function isOpen(string $uri): bool
    {
        return isset($this->documents[$uri]);
    }
}

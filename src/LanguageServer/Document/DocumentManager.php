<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\LanguageServer\Document;

/**
 * Manages open documents and their cached regex patterns.
 */
final class DocumentManager
{
    /**
     * @var array<string, string> URI => content
     */
    private array $documents = [];

    /**
     * @var array<string, array<\PhpRegex\LanguageServer\Document\RegexOccurrence>> URI => occurrences
     */
    private array $occurrences = [];

    public function __construct(private readonly RegexFinder $finder) {}

    /**
     * Open a document.
     */
    public function open(string $uri, string $content): void
    {
        $this->documents[$uri] = $content;
        $this->occurrences[$uri] = $this->finder->find($content);
    }

    /**
     * Update a document's content.
     */
    public function update(string $uri, string $content): void
    {
        $this->documents[$uri] = $content;
        $this->occurrences[$uri] = $this->finder->find($content);
    }

    /**
     * Close a document.
     */
    public function close(string $uri): void
    {
        unset($this->documents[$uri], $this->occurrences[$uri]);
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
     * @return array<\PhpRegex\LanguageServer\Document\RegexOccurrence>
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

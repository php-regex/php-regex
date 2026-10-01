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

namespace PHPRegex\Laravel\Extractor;

use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;

/**
 * Extracts regex patterns from Laravel validation rules.
 *
 * This extractor parses PHP files looking for Laravel validation rules
 * that use regex patterns, such as 'regex:/pattern/' in validation arrays.
 *
 * @internal
 */
final readonly class ValidationRulePatternSource implements PatternSourceInterface
{
    public function getName(): string
    {
        return 'validators';
    }

    public function isSupported(): bool
    {
        return true;
    }

    /**
     * @return array<\PHPRegex\Linter\PatternOccurrence>
     */
    public function extract(PatternSourceContext $context): array
    {
        $patterns = [];

        foreach ($context->paths as $path) {
            if (!is_dir($path)) {
                continue;
            }

            $patterns = [...$patterns, ...$this->extractFromDirectory($path, $context->excludePaths)];
        }

        return $patterns;
    }

    /**
     * @param array<string> $excludePaths
     *
     * @return array<\PHPRegex\Linter\PatternOccurrence>
     */
    private function extractFromDirectory(string $directory, array $excludePaths): array
    {
        $patterns = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $filePath = $file->getPathname();

            // Check exclusions
            foreach ($excludePaths as $excludePath) {
                if (str_contains($filePath, \DIRECTORY_SEPARATOR.$excludePath.\DIRECTORY_SEPARATOR)) {
                    continue 2;
                }
            }

            $patterns = [...$patterns, ...$this->extractFromFile($filePath)];
        }

        return $patterns;
    }

    /**
     * @return array<\PHPRegex\Linter\PatternOccurrence>
     */
    private function extractFromFile(string $filePath): array
    {
        $content = @file_get_contents($filePath);
        if (false === $content) {
            return [];
        }

        $patterns = [];

        // Match Laravel validation regex rules: 'regex:/pattern/' or "regex:/pattern/"
        // Also matches not_regex variant. The pattern runs until the quote that
        // opened the string literal, so the other quote character (and escaped
        // quotes) may appear inside the pattern, e.g. 'regex:/^[^"]+$/'.
        $regexPattern = '/([\'"])(?:not_)?regex:((?:\\\\.|(?!\1).)+)\1/';

        if (preg_match_all($regexPattern, $content, $matches, \PREG_OFFSET_CAPTURE)) {
            foreach ($matches[2] as $index => $match) {
                $pattern = $this->unescapeStringLiteral($match[0], $matches[1][$index][0]);
                $offset = $match[1];

                // Calculate line number from offset
                $lineNumber = substr_count(substr($content, 0, $offset), "\n") + 1;

                // Normalize pattern (ensure it has delimiters)
                $normalized = $this->normalizePattern($pattern);
                if (null === $normalized) {
                    continue;
                }

                // Get the full rule match for context
                $fullMatch = $matches[0][$index][0];
                $isNotRegex = str_starts_with($fullMatch, "'not_regex:") || str_starts_with($fullMatch, '"not_regex:');
                $ruleType = $isNotRegex ? 'not_regex' : 'regex';

                $patterns[] = new PatternOccurrence(
                    $normalized,
                    $filePath,
                    $lineNumber,
                    'validation:'.$ruleType,
                    $pattern,
                    'Laravel validation rule',
                );
            }
        }

        return $patterns;
    }

    /**
     * Normalize a validation regex pattern.
     */
    /**
     * Undo the PHP string-literal escaping of the quote that delimited the
     * rule, so the extracted pattern matches what the validator receives.
     */
    private function unescapeStringLiteral(string $pattern, string $quote): string
    {
        return str_replace(['\\'.$quote, '\\\\'], [$quote, '\\'], $pattern);
    }

    private function normalizePattern(string $pattern): ?string
    {
        $pattern = trim($pattern);

        if ('' === $pattern) {
            return null;
        }

        // Laravel validation regex rules typically include delimiters
        if ($this->hasDelimiters($pattern)) {
            return $pattern;
        }

        // Wrap in delimiters if missing
        return '/'.addcslashes($pattern, '/').'/';
    }

    /**
     * Check if a pattern has regex delimiters.
     */
    private function hasDelimiters(string $pattern): bool
    {
        if (\strlen($pattern) < 2) {
            return false;
        }

        $firstChar = $pattern[0];
        $validDelimiters = ['/', '#', '~', '!', '@', '%', '`'];

        if (!\in_array($firstChar, $validDelimiters, true)) {
            return false;
        }

        // Check if it ends with the same delimiter (possibly with flags)
        $lastDelimiterPos = strrpos($pattern, $firstChar);

        return false !== $lastDelimiterPos && $lastDelimiterPos > 0;
    }
}

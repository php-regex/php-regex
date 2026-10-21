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
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\InternalFailureException;

/**
 * Extracts regex patterns from Laravel validation rules.
 *
 * This extractor parses PHP files looking for Laravel validation rules
 * that use regex patterns, such as 'regex:/pattern/' in validation arrays.
 *
 * @internal
 */
final class ValidationRulePatternSource implements PatternSourceInterface
{
    /**
     * A single- or double-quoted literal on one line that holds a 'regex:'
     * or 'not_regex:' rule, at its start or after a "|", up to the quote
     * that opened it. Group 1 is the quote, group 2 the literal as written.
     * An escaped character (a backslash and the character after it) and any
     * other character but a backslash, the quote and a newline are
     * disjoint, so a run of backslashes is read in one pass.
     */
    private const RULE_PATTERN = '/(?|(\')(?=(?:(?:\\\\.|[^\'\\\\\n|])*+\|)*(?:not_)?regex:)((?:\\\\.|[^\'\\\\\n])++)\'|(")(?=(?:(?:\\\\.|[^"\\\\\n|])*+\|)*(?:not_)?regex:)((?:\\\\.|[^"\\\\\n])++)")/';

    /**
     * A regex rule as Laravel's ValidationRuleParser reads one rule: the
     * name, then all the text after the first ":".
     */
    private const RULE = '/^(not_)?regex:/';

    /**
     * The files the last extract() could not read, each with why.
     *
     * @var list<array{file: string, message: string}>
     */
    private array $failures = [];

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
        $this->failures = [];
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
     * The files the last extract() could not read: the rule regex gave up
     * on them, so the rules they hold were not linted.
     *
     * @return list<array{file: string, message: string}>
     */
    public function failures(): array
    {
        return $this->failures;
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
            $this->failures[] = [
                'file' => $filePath,
                'message' => 'Validation rules not read: the file cannot be read.',
            ];

            return [];
        }

        $patterns = [];

        // The pattern runs until the quote that opened the string literal,
        // so the other quote character (and escaped quotes) may appear
        // inside it, e.g. 'regex:/^[^"]+$/'.
        try {
            LibraryPcre::matchAll(self::RULE_PATTERN, $content, $matches, \PREG_OFFSET_CAPTURE);

            foreach ($matches[2] as $index => $match) {
                $literal = $this->unescapeStringLiteral($match[0], $matches[1][$index][0]);
                $offset = $match[1];
                $lineNumber = substr_count(substr($content, 0, $offset), "\n") + 1;

                // Laravel splits a rules string on every "|", one inside a
                // pattern included; an element of a rules array is one rule.
                $rules = $this->isRulesString($literal, substr($content, 0, $offset - 1)) ? explode('|', $literal) : [$literal];

                foreach ($rules as $rule) {
                    if (1 !== LibraryPcre::match(self::RULE, $rule, $name)) {
                        continue;
                    }

                    // preg_match() gets the parameter as it is, delimiters and
                    // all: it is linted as written.
                    $pattern = substr($rule, \strlen($name[0]));
                    $trimmed = trim($pattern, " \t\n\r\0\x0B");
                    if ('' === $trimmed) {
                        continue;
                    }

                    $patterns[] = new PatternOccurrence(
                        $trimmed,
                        $filePath,
                        $lineNumber,
                        'validation:'.('' === ($name[1] ?? '') ? 'regex' : 'not_regex'),
                        $pattern,
                        'Laravel validation rule',
                    );
                }
            }
        } catch (InternalFailureException $failure) {
            $this->failures[] = [
                'file' => $filePath,
                'message' => \sprintf(
                    'Validation rules not read: the rule regex gave up on this file (%s).',
                    \PREG_BACKTRACK_LIMIT_ERROR === $failure->pcreError ? 'backtrack limit reached' : 'PCRE error '.$failure->pcreError,
                ),
            ];

            return [];
        }

        return $patterns;
    }

    /**
     * Whether the literal is a rules string, which Laravel splits on "|",
     * rather than one element of a rules array: a rule before the regex one,
     * as "nullable|regex:/a/", or a literal given to a key ('a' => '…') or
     * assigned ($rules['a'] = '…'), where $rules['a'][] = '…' pushes one
     * element.
     */
    private function isRulesString(string $literal, string $before): bool
    {
        if (1 !== LibraryPcre::match(self::RULE, $literal)) {
            return true;
        }

        $before = rtrim($before, " \t\n\r\0\x0B");
        if (str_ends_with($before, '=>')) {
            return true;
        }

        // "==", "!=", "<=" and ">=" compare; "=", ".=" and "??=" assign.
        if (!str_ends_with($before, '=') || 1 === LibraryPcre::match('/[=!<>]=$/', $before)) {
            return false;
        }

        return !str_ends_with(rtrim(substr($before, 0, -1), " \t\n\r\0\x0B"), '[]');
    }

    /**
     * Undo the PHP string-literal escaping of the quote that delimited the
     * rule, so the extracted pattern matches what the validator receives.
     */
    private function unescapeStringLiteral(string $pattern, string $quote): string
    {
        return str_replace(['\\'.$quote, '\\\\'], [$quote, '\\'], $pattern);
    }
}

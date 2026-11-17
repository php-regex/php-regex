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

use PHPRegex\Linter\Extraction\PhpStringLiteral;
use PHPRegex\Linter\Extraction\PhpTokens;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\InternalFailureException;
use PHPRegex\Parser\Text\Path;

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
     * A single- or double-quoted literal on one line that holds a 'regex:',
     * 'not_regex:' or 'notregex:' rule, in any case and blanks around its
     * name, at its start or after a "|", up to the quote that opened it.
     * Group 1 is the quote, group 2 the literal as written.
     * An escaped character (a backslash and the character after it) and any
     * other character but a backslash, the quote and a newline are
     * disjoint, so a run of backslashes is read in one pass.
     */
    private const RULE_PATTERN = '/(?|(\')(?=(?:(?:\\\\.|[^\'\\\\\n|])*+\|)*[ \t\r\0\x0B]*+(?i:(?:not_?)?regex)[ \t\r\0\x0B]*+:)((?:\\\\.|[^\'\\\\\n])++)\'|(")(?=(?:(?:\\\\.|[^"\\\\\n|])*+\|)*[ \t\r\0\x0B]*+(?i:(?:not_?)?regex)[ \t\r\0\x0B]*+:)((?:\\\\.|[^"\\\\\n])++)")/';

    /**
     * A regex rule as Laravel's ValidationRuleParser reads one rule: the
     * name, then all the text after the first ":". parseStringRule() trims
     * the name before Str::studly() picks the rule, ruleIsRegex() reads it
     * without case, and "notregex" as "not_regex". Groups 1 and 3 are the
     * blanks around the name, group 2 the negation.
     */
    private const RULE = '/^([ \t\n\r\0\x0B]*+)(not_?)?regex([ \t\n\r\0\x0B]*+):/i';

    /**
     * The files the last extract() could not read, each with why.
     *
     * @var list<array{file: string, message: string}>
     */
    private array $failures = [];

    /**
     * The offsets of the string literals of the file read that are an
     * argument of a call, read once a literal asks.
     *
     * @var array<int, true>|null
     */
    private ?array $callArguments = null;

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

            $patterns = [...$patterns, ...$this->extractFromDirectory($path, $context)];
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
     * @return array<\PHPRegex\Linter\PatternOccurrence>
     */
    private function extractFromDirectory(string $directory, PatternSourceContext $context): array
    {
        $patterns = [];
        // Walked with "/" on every host; the excludes leave a file out as
        // they leave out its PHP patterns.
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(Path::fromHost($directory), \RecursiveDirectoryIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || 'php' !== $file->getExtension()) {
                continue;
            }

            $filePath = $file->getPathname();
            if ($context->excludes($filePath)) {
                continue;
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
        $this->callArguments = null;

        // The pattern runs until the quote that opened the string literal,
        // so the other quote character (and escaped quotes) may appear
        // inside it, e.g. 'regex:/^[^"]+$/'.
        try {
            LibraryPcre::matchAll(self::RULE_PATTERN, $content, $matches, \PREG_OFFSET_CAPTURE);

            $previousOffset = 0;
            $lineNumber = 1;
            foreach ($matches[2] as $index => $match) {
                $quote = $matches[1][$index][0];
                $literal = PhpStringLiteral::decode($quote.$match[0].$quote) ?? $match[0];
                $offset = $match[1];
                // Match offsets increase: count each intervening byte only once.
                $lineNumber += substr_count($content, "\n", $previousOffset, $offset - $previousOffset);
                $previousOffset = $offset;

                // Laravel splits a rules string on every "|", one inside a
                // pattern included; an element of a rules array is one rule.
                $rules = $this->isRulesString($literal, $content, $offset - 1) ? explode('|', $literal) : [$literal];

                foreach ($rules as $rule) {
                    if (1 !== LibraryPcre::match(self::RULE, $rule, $name)) {
                        continue;
                    }

                    // preg_match() gets the parameter as it is, delimiters and
                    // all: it is linted as written. A name with blanks around
                    // it is no regex name to ruleIsRegex(), which then reads
                    // the parameter as CSV: the rule runs on its first field.
                    $pattern = substr($rule, \strlen($name[0]));
                    if ('' !== $name[1].$name[3]) {
                        $pattern = (string) (str_getcsv($pattern, ',', '"', '\\')[0] ?? '');
                    }
                    $trimmed = trim($pattern, " \t\n\r\0\x0B");
                    if ('' === $trimmed) {
                        continue;
                    }

                    $patterns[] = new PatternOccurrence(
                        $trimmed,
                        $filePath,
                        $lineNumber,
                        'validation:'.('' === $name[2] ? 'regex' : 'not_regex'),
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
     * as "nullable|regex:/a/", a literal given to a key ('a' => '…') or
     * assigned ($rules['a'] = '…'), where $rules['a'][] = '…' pushes one
     * element, or an argument of a call: Validator::sometimes() explodes
     * [$key => $rules], and ConditionalRules::rules() a string, as
     * Rule::when() keeps it.
     *
     * @param int $start the offset of the quote that opens the literal
     */
    private function isRulesString(string $literal, string $content, int $start): bool
    {
        if (1 !== LibraryPcre::match(self::RULE, $literal)) {
            return true;
        }

        $before = $this->previousNonWhitespace($content, $start - 1);
        if ($before >= 1 && '=>' === substr($content, $before - 1, 2)) {
            return true;
        }

        // "==", "!=", "<=" and ">=" compare; "=", ".=" and "??=" assign.
        if ($before < 0 || '=' !== $content[$before] || ($before > 0 && str_contains('=!<>', $content[$before - 1]))) {
            return isset($this->callArguments($content)[$start]);
        }

        $before = $this->previousNonWhitespace($content, $before - 1);

        return $before < 1 || '[]' !== substr($content, $before - 1, 2);
    }

    private function previousNonWhitespace(string $content, int $offset): int
    {
        // Only the whitespace immediately before this literal (or its assignment)
        // is visited; earlier literals stop the walk without copying a prefix.
        while ($offset >= 0 && str_contains(" \t\n\r\0\x0B", $content[$offset])) {
            $offset--;
        }

        return $offset;
    }

    /**
     * The offsets of the string literals of the file whose innermost
     * bracket is the parenthesis of a call: a name, a variable or a closing
     * bracket before it. array(), isset() or a control structure is none.
     *
     * @return array<int, true>
     */
    private function callArguments(string $content): array
    {
        if (null !== $this->callArguments) {
            return $this->callArguments;
        }

        $tokens = token_get_all($content);
        $offsets = PhpTokens::offsets($tokens);
        $calls = [];
        $this->callArguments = [];
        foreach ($tokens as $index => $token) {
            if (PhpTokens::opensNesting($token)) {
                $previous = PhpTokens::previousSignificant($tokens, $index - 1);
                $callee = null === $previous ? null : $tokens[$previous];
                $calls[] = '(' === $token && null !== $callee && (null !== PhpTokens::name($callee) || \in_array($callee, [')', ']'], true) || (\is_array($callee) && \T_VARIABLE === $callee[0]));
            } elseif (')' === $token || ']' === $token || '}' === $token) {
                array_pop($calls);
            } elseif (\is_array($token) && \T_CONSTANT_ENCAPSED_STRING === $token[0] && true === end($calls)) {
                $this->callArguments[$offsets[$index]] = true;
            }
        }

        return $this->callArguments;
    }
}

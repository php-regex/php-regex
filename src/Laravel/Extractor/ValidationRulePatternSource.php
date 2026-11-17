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
     * A double-quoted one may spell that name and its blanks with escapes,
     * as "\tregex:" or "\x72egex:": a name of letters, digits, braces,
     * blanks and escapes, one escape at least, is taken too, and the rule
     * is read once PHP has decoded the literal.
     * Group 1 is the quote, group 2 the literal as written.
     * An escaped character (a backslash and the character after it) and any
     * other character but a backslash, the quote and a newline are
     * disjoint, so a run of backslashes is read in one pass.
     */
    private const RULE_PATTERN = '/(?|(\')(?=(?:(?:\\\\.|[^\'\\\\\n|])*+\|)*[ \t\r\0\x0B]*+(?i:(?:not_?)?regex)[ \t\r\0\x0B]*+:)((?:\\\\.|[^\'\\\\\n])++)\'|(")(?=(?:(?:\\\\.|[^"\\\\\n|])*+\|)*(?:[ \t\r\0\x0B]*+(?i:(?:not_?)?regex)[ \t\r\0\x0B]*+:|(?=[ \t\r\0\x0B0-9A-Za-z_{}]*+\\\\)(?:[ \t\r\0\x0B0-9A-Za-z_{}]|\\\\[^\n:])++:))((?:\\\\.|[^"\\\\\n])++)")/';

    /**
     * The calls that take a rules string, which Laravel splits on "|", by
     * the name of the method, or of the class built, in lower case: the
     * arguments that are one, by position from 0 and by name.
     * Validator::sometimes() explodes [$key => $rules], and
     * ConditionalRules::rules() and defaultRules() a string, as
     * Rule::when() and Rule::unless() keep it.
     */
    private const RULES_ARGUMENTS = [
        'sometimes' => [1 => 'rules'],
        'when' => [1 => 'rules', 2 => 'defaultRules'],
        'unless' => [1 => 'rules', 2 => 'defaultRules'],
        'conditionalrules' => [1 => 'rules', 2 => 'defaultRules'],
    ];

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
     * The offsets of the string literals of the file read that a call takes
     * as rules, and of the parentheses that only group what they hold, read
     * once a literal asks.
     *
     * @var array{rules: array<int, true>, grouping: array<int, true>}|null
     */
    private ?array $tokenReading = null;

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
        $this->tokenReading = null;

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
     * element, or the rules a call takes (RULES_ARGUMENTS), where a string
     * another call takes, as array_push() appends it, is one element.
     * Parentheses that only group the literal change nothing.
     *
     * @param int $start the offset of the quote that opens the literal
     */
    private function isRulesString(string $literal, string $content, int $start): bool
    {
        if (1 !== LibraryPcre::match(self::RULE, $literal)) {
            return true;
        }

        $before = $this->previousNonWhitespace($content, $start - 1);
        while ($before >= 0 && '(' === $content[$before] && isset($this->tokenReading($content)['grouping'][$before])) {
            $before = $this->previousNonWhitespace($content, $before - 1);
        }

        if ($before >= 1 && '=>' === substr($content, $before - 1, 2)) {
            return true;
        }

        // "==", "!=", "<=" and ">=" compare; "=", ".=" and "??=" assign.
        if ($before < 0 || '=' !== $content[$before] || ($before > 0 && str_contains('=!<>', $content[$before - 1]))) {
            return isset($this->tokenReading($content)['rules'][$start]);
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
     * The offsets of the string literals of the file that a call takes as
     * rules, by position or by name, and of the parentheses that only group
     * what they hold. A literal is an argument of the innermost bracket
     * around it that does not only group.
     *
     * @return array{rules: array<int, true>, grouping: array<int, true>}
     */
    private function tokenReading(string $content): array
    {
        if (null !== $this->tokenReading) {
            return $this->tokenReading;
        }

        $tokens = token_get_all($content);
        $offsets = PhpTokens::offsets($tokens);
        $rules = [];
        $grouping = [];
        // Each open bracket, null where it only groups: the arguments it
        // takes as rules, the position of the one read and its name.
        $brackets = [];
        foreach ($tokens as $index => $token) {
            if (PhpTokens::opensNesting($token)) {
                $previous = PhpTokens::previousSignificant($tokens, $index - 1);
                // An open tag stands before any bracket.
                if ('(' === $token && null !== $previous && self::groups($tokens[$previous])) {
                    $grouping[$offsets[$index]] = true;
                    $brackets[] = null;
                } else {
                    $brackets[] = ['rules' => '(' === $token && null !== $previous ? self::rulesArgumentsOf($tokens, $previous) : [], 'position' => 0, 'name' => null];
                }

                continue;
            }

            if (')' === $token || ']' === $token || '}' === $token) {
                array_pop($brackets);

                continue;
            }

            $top = \count($brackets) - 1;
            if (',' === $token || ':' === $token) {
                $bracket = $brackets[$top] ?? null;
                if (null === $bracket || [] === $bracket['rules']) {
                    continue;
                }

                if (',' === $token) {
                    $bracket['position']++;
                    $bracket['name'] = null;
                } else {
                    // "rules:" names the argument after a "(" or a ",";
                    // a ternary's ":" follows no such label.
                    $label = PhpTokens::previousSignificant($tokens, $index - 1);
                    $opener = null === $label ? null : PhpTokens::previousSignificant($tokens, $label - 1);
                    if (null !== $label && null !== $opener && \in_array($tokens[$opener], ['(', ','], true)) {
                        $bracket['name'] = PhpTokens::identifier($tokens[$label]);
                    }
                }
                $brackets[$top] = $bracket;

                continue;
            }

            if (!\is_array($token) || \T_CONSTANT_ENCAPSED_STRING !== $token[0]) {
                continue;
            }

            while ($top >= 0 && null === $brackets[$top]) {
                $top--;
            }
            $bracket = $brackets[$top] ?? null;
            if (null !== $bracket && (null === $bracket['name'] ? isset($bracket['rules'][$bracket['position']]) : \in_array($bracket['name'], $bracket['rules'], true))) {
                $rules[$offsets[$index]] = true;
            }
        }

        return $this->tokenReading = ['rules' => $rules, 'grouping' => $grouping];
    }

    /**
     * Whether a parenthesis after this token only groups what it holds:
     * after an operator, a cast or a punctuation mark. After a name, a
     * variable, a string, a ")" or a "]" it opens a call, after a keyword a
     * control structure, array(), isset() or a closure.
     *
     * @param array{0: int, 1: string, 2?: int}|string $token
     */
    private static function groups(array|string $token): bool
    {
        if (!\is_array($token)) {
            return ')' !== $token && ']' !== $token;
        }

        return \T_VARIABLE !== $token[0] && \T_CONSTANT_ENCAPSED_STRING !== $token[0] && null === PhpTokens::name($token) && null === PhpTokens::identifier($token);
    }

    /**
     * The arguments the call after this name takes as rules, by position
     * and by name: the method sometimes(), when() or unless(), or the
     * ConditionalRules built. A function of these names takes none.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array<int, string>
     */
    private static function rulesArgumentsOf(array $tokens, int $callee): array
    {
        $name = PhpTokens::name($tokens[$callee]);
        if (null === $name) {
            return [];
        }

        // The last segment of a qualified name: the "\" put before the name
        // stands one byte before the last one of the name itself.
        $short = strtolower(substr($name, (int) strrpos('\\'.$name, '\\')));
        $arguments = self::RULES_ARGUMENTS[$short] ?? [];
        if ([] === $arguments || 'conditionalrules' === $short) {
            return $arguments;
        }

        $operator = PhpTokens::previousSignificant($tokens, $callee - 1);

        return null !== $operator && PhpTokens::isObjectOrStaticOperator($tokens[$operator]) ? $arguments : [];
    }
}

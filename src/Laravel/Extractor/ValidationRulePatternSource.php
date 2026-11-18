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

use PHPRegex\Linter\Extraction\NameResolutionContext;
use PHPRegex\Linter\Extraction\PhpStringLiteral;
use PHPRegex\Linter\Extraction\PhpTokens;
use PHPRegex\Linter\Extraction\TokenNameResolver;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PatternSourceInterface;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Exception\InternalFailureException;
use PHPRegex\Parser\Text\Path;

/**
 * Extracts regex patterns from Laravel validation rules.
 *
 * This extractor reads the string literals of the PHP code, as PHP decodes
 * them, looking for Laravel validation rules that use regex patterns, such
 * as 'regex:/pattern/' in validation arrays.
 *
 * @internal
 */
final class ValidationRulePatternSource implements PatternSourceInterface
{
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
     * The calls that take an array of rule sets, which validate each
     * element alone: Rule::anyOf() and the AnyOf rule it builds hand each
     * one to Validator::make(), which splits a string on "|". By the name
     * of the method, or of the class built, in lower case: the argument
     * that is one, by position from 0 and by name.
     */
    private const RULE_SETS_ARGUMENTS = ['anyof' => [0 => 'rules']];

    /**
     * Livewire's attributes that hand their rule, given first or named
     * "rule", to Laravel as the rules of the property they mark, in lower
     * case (Livewire 3.0 to 4.x). Livewire merges the rules of all the
     * attributes of one property with array_merge_recursive(): two of them
     * make one list, each string one rule.
     */
    private const LIVEWIRE_RULE_ATTRIBUTES = ['livewire\attributes\validate', 'livewire\attributes\rule'];

    /**
     * A regex rule as Laravel's ValidationRuleParser reads one rule: the
     * name, then all the text after the first ":". parseStringRule() trims
     * the name before Str::studly() picks the rule, ruleIsRegex() reads it
     * without case, and "notregex" as "not_regex". Groups 1 and 3 are the
     * blanks around the name, group 2 the negation.
     */
    private const RULE = '/^([ \t\n\r\0\x0B]*+)(not_?)?regex([ \t\n\r\0\x0B]*+):/i';

    /**
     * What a file writes of a regex rule: its name and the colon after it,
     * with blanks or their escapes between, or an escape, hexadecimal,
     * octal or a code point, that may write one of their characters. A file
     * that holds neither holds no rule.
     */
    private const RULE_TEXT = '/regex(?:[ \t\n\r\0\x0B]|\\\\[tnrv])*+:|\\\\(?:[0-7xX]|u\{)/i';

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
        $reading = null;

        try {
            // A file that writes no regex rule is not tokenized.
            if (1 !== LibraryPcre::match(self::RULE_TEXT, $content)) {
                return [];
            }

            // PHP reads the literals of its code alone: a quote inside
            // another literal, in a comment or around the PHP tags opens
            // none. What PHP warns about the file, an octal escape above
            // \377, is no word of the run's.
            $tokens = @token_get_all($content);
            $offsets = PhpTokens::offsets($tokens);
            for ($index = 0, $count = \count($tokens); $index < $count; $index++) {
                $token = $tokens[$index];
                $opening = $index;
                if (\is_array($token) && \T_CONSTANT_ENCAPSED_STRING === $token[0]) {
                    $pieces = [PhpTokens::stringValue($token[1])];
                } elseif (\is_string($token) && str_ends_with($token, '"')) {
                    [$pieces, $index] = self::interpolatedPieces($tokens, $index);
                } else {
                    continue;
                }

                // Every name of a regex rule holds "regex" once PHP has
                // decoded it.
                if (false === stripos(implode('', $pieces), 'regex')) {
                    continue;
                }

                // Laravel splits a rules string on every "|", one inside a
                // pattern included; an element of a rules array is one rule,
                // not known when an expression is part of it.
                $literal = $pieces[0] ?? '';
                $reading ??= self::tokenReading($tokens, $offsets);
                if ($this->isRulesString($literal, $content, $offsets[$opening], $reading)) {
                    $rules = self::rulesOf($pieces);
                } else {
                    $rules = \in_array(null, $pieces, true) ? [] : [$literal];
                }

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
                        PhpTokens::lineOf($tokens, $opening),
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
     * rather than one element of a rules array: a literal given to a key
     * ('a' => '…') or assigned ($rules['a'] = '…'), where $rules['a'][] = '…'
     * pushes one element, the rules a call takes (RULES_ARGUMENTS), or an
     * element of the array of rule sets a call takes (RULE_SETS_ARGUMENTS),
     * where a string another call takes, as array_push() appends it, is one
     * element. Anywhere else, a rule before the regex one, as
     * "nullable|regex:/a/", makes a rules string, unless array_push() or
     * array_unshift() appends it: Laravel then reads one rule named
     * "nullable|regex", and runs no pattern. Parentheses that only group the
     * literal change nothing.
     *
     * @param string                                                                                 $literal the value of the literal, up to its first expression when it interpolates
     * @param int                                                                                    $start   the offset of the token that opens the literal
     * @param array{rules: array<int, true>, grouping: array<int, true>, appended: array<int, true>} $reading what the tokens of the file say, from tokenReading()
     */
    private function isRulesString(string $literal, string $content, int $start, array $reading): bool
    {
        $before = $this->previousNonWhitespace($content, $start - 1);
        while ($before >= 0 && '(' === $content[$before] && isset($reading['grouping'][$before])) {
            $before = $this->previousNonWhitespace($content, $before - 1);
        }

        if ($before >= 1 && '=>' === substr($content, $before - 1, 2)) {
            return true;
        }

        // "==", "!=", "<=" and ">=" compare; "=", ".=" and "??=" assign.
        if ($before < 0 || '=' !== $content[$before] || ($before > 0 && str_contains('=!<>', $content[$before - 1]))) {
            if (isset($reading['rules'][$start])) {
                return true;
            }

            return 1 !== LibraryPcre::match(self::RULE, $literal) && !isset($reading['appended'][$start]);
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
     * rules, by position or by name, or that the array of rule sets a call
     * takes holds as elements, of those array_push() or array_unshift()
     * appends as elements, and of the parentheses that only group what they
     * hold. A literal is an argument of the innermost bracket around it
     * that does not only group. A double-quoted string that interpolates is
     * placed by its opening quote. The rule of a Livewire attribute is a
     * rules string where it is the only one of its declaration.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $offsets
     *
     * @return array{rules: array<int, true>, grouping: array<int, true>, appended: array<int, true>}
     */
    private static function tokenReading(array $tokens, array $offsets): array
    {
        $rules = [];
        $grouping = [];
        $appended = [];
        $count = \count($tokens);
        $closers = PhpTokens::closers($tokens);
        $context = new NameResolutionContext();
        // The closing bracket of the last attribute group of the run being
        // read, and how many of Livewire's rule attributes the run holds.
        $run = [-1, 0];
        // Each open bracket, null where it only groups: the arguments it
        // takes as rules and as an array of rule sets, whether it appends
        // its arguments after the first, whether it is an array of rule
        // sets, whether it opens an attribute group, the position of the
        // argument read and its name.
        $brackets = [];
        foreach ($tokens as $index => $token) {
            if (\is_array($token) && \T_NAMESPACE === $token[0]) {
                TokenNameResolver::readNamespace($tokens, $index, $count, $context);
            } elseif (\is_array($token) && \T_USE === $token[0]) {
                TokenNameResolver::readUse($tokens, $index, $count, $context);
            }

            if (PhpTokens::opensNesting($token)) {
                $previous = PhpTokens::previousSignificant($tokens, $index - 1);
                $attribute = \is_array($token) && \T_ATTRIBUTE === $token[0];
                if ($attribute && $index > $run[0]) {
                    $run = self::livewireRun($tokens, $index, $closers, $context);
                }

                // An open tag stands before any bracket.
                if ('(' === $token && null !== $previous && self::groups($tokens[$previous])) {
                    $grouping[$offsets[$index]] = true;
                    $brackets[] = null;
                } else {
                    $call = '(' === $token && null !== $previous;
                    // An array literal: a "[" after no operand, or array().
                    $array = null !== $previous && ('[' === $token ? self::groups($tokens[$previous]) : '(' === $token && \is_array($tokens[$previous]) && \T_ARRAY === $tokens[$previous][0]);
                    $outer = null;
                    for ($level = \count($brackets) - 1; $level >= 0 && null === $outer; $level--) {
                        $outer = $brackets[$level];
                    }
                    $inAttribute = null !== $outer && $outer['attribute'];
                    $brackets[] = [
                        'rules' => match (true) {
                            !$call => [],
                            $inAttribute => 1 === $run[1] && self::isLivewireRuleAttribute($tokens[$previous], $context) ? [0 => 'rule'] : [],
                            default => self::rulesArgumentsOf($tokens, $previous),
                        },
                        'sets' => $call ? self::ruleSetsArgumentsOf($tokens, $previous) : [],
                        'appends' => $call && self::appendsElements($tokens, $previous),
                        'holdsSets' => $array && null !== $outer && self::isOneOf($outer['sets'], $outer['position'], $outer['name']),
                        'attribute' => $attribute,
                        'position' => 0,
                        'name' => null,
                    ];
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
                if (null === $bracket || ([] === $bracket['rules'] && [] === $bracket['sets'] && !$bracket['appends'])) {
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

            if (\is_array($token) ? \T_CONSTANT_ENCAPSED_STRING !== $token[0] : !str_ends_with($token, '"')) {
                continue;
            }

            while ($top >= 0 && null === $brackets[$top]) {
                $top--;
            }
            $bracket = $brackets[$top] ?? null;
            if (null !== $bracket && ($bracket['holdsSets'] || self::isOneOf($bracket['rules'], $bracket['position'], $bracket['name']))) {
                $rules[$offsets[$index]] = true;
            }
            if (null !== $bracket && $bracket['appends'] && $bracket['position'] > 0) {
                $appended[$offsets[$index]] = true;
            }
        }

        return ['rules' => $rules, 'grouping' => $grouping, 'appended' => $appended];
    }

    /**
     * The closing bracket of the last attribute group of the run that opens
     * at $start, groups that follow one another, and how many of Livewire's
     * rule attributes with arguments the run holds: the run marks one
     * declaration.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $closers
     *
     * @return array{int, int}
     */
    private static function livewireRun(array $tokens, int $start, array $closers, NameResolutionContext $context): array
    {
        $attributes = 0;
        $count = \count($tokens);
        for ($group = $start; ; $group = $next) {
            $end = $closers[$group] ?? $count - 1;
            // A name of the group, its arguments between parentheses that
            // are stepped over.
            for ($index = $group + 1; $index < $end; $index++) {
                $next = PhpTokens::nextSignificant($tokens, $index + 1, $end);
                if (null !== $next && '(' === $tokens[$next] && self::isLivewireRuleAttribute($tokens[$index], $context)) {
                    $attributes++;
                }

                $index = $closers[$index] ?? $index;
            }

            $next = PhpTokens::nextSignificant($tokens, $end + 1, $count);
            if (null === $next || !\is_array($tokens[$next]) || \T_ATTRIBUTE !== $tokens[$next][0]) {
                return [$end, $attributes];
            }
        }
    }

    /**
     * Whether this name is one of Livewire's rule attributes, as the file
     * imports it.
     *
     * @param array{int, string, int}|string $token
     */
    private static function isLivewireRuleAttribute(array|string $token, NameResolutionContext $context): bool
    {
        $name = PhpTokens::name($token);

        return null !== $name && \in_array(strtolower($context->resolveClass($name)), self::LIVEWIRE_RULE_ATTRIBUTES, true);
    }

    /**
     * Whether the argument at this position, or of this name when it is
     * given by name, is one of these.
     *
     * @param array<int, string> $arguments
     */
    private static function isOneOf(array $arguments, int $position, ?string $name): bool
    {
        return null === $name ? isset($arguments[$position]) : \in_array($name, $arguments, true);
    }

    /**
     * The texts of the double-quoted string that interpolates from the
     * quote at $open, as PHP decodes them, with null for each expression
     * between them, and the index of the quote that closes it.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array{list<string|null>, int}
     */
    private static function interpolatedPieces(array $tokens, int $open): array
    {
        $pieces = [];
        // An expression between braces may hold strings of its own.
        $depth = 0;
        for ($index = $open + 1, $count = \count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (0 === $depth && '"' === $token) {
                break;
            }

            if (0 === $depth && \is_array($token) && \T_ENCAPSED_AND_WHITESPACE === $token[0]) {
                $pieces[] = PhpStringLiteral::decodeInterpolated($token[1]);

                continue;
            }

            if (PhpTokens::opensNesting($token)) {
                $depth++;
            } elseif (')' === $token || ']' === $token || '}' === $token) {
                $depth--;
            }

            if ([] === $pieces || null !== $pieces[array_key_last($pieces)]) {
                $pieces[] = null;
            }
        }

        return [$pieces, $index];
    }

    /**
     * The rules of a rules string that its text holds whole: each between
     * two "|" its text writes, or the start or the end of the string, with
     * no expression inside. The value of an expression may hold a "|" of
     * its own, so the rule it is part of is not known.
     *
     * @param list<string|null> $pieces the texts of the string, null for each expression between them
     *
     * @return list<string>
     */
    private static function rulesOf(array $pieces): array
    {
        $rules = [];
        $rule = '';
        foreach ($pieces as $piece) {
            if (null === $piece) {
                $rule = null;

                continue;
            }

            foreach (explode('|', $piece) as $position => $part) {
                if ($position > 0) {
                    if (null !== $rule) {
                        $rules[] = $rule;
                    }
                    $rule = '';
                }

                if (null !== $rule) {
                    $rule .= $part;
                }
            }
        }

        if (null !== $rule) {
            $rules[] = $rule;
        }

        return $rules;
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
     * Whether the call after this name is the function array_push() or
     * array_unshift(), which add each argument after the first as one
     * element.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     */
    private static function appendsElements(array $tokens, int $callee): bool
    {
        $name = PhpTokens::name($tokens[$callee]);
        if (null === $name) {
            return false;
        }

        $short = strtolower(substr($name, (int) strrpos('\\'.$name, '\\')));
        $operator = PhpTokens::previousSignificant($tokens, $callee - 1);

        return \in_array($short, ['array_push', 'array_unshift'], true) && (null === $operator || !PhpTokens::isObjectOrStaticOperator($tokens[$operator]));
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

    /**
     * The arguments the call after this name takes as an array of rule
     * sets, by position and by name: the method anyOf(), or the AnyOf rule
     * built. A function of this name takes none.
     *
     * @param array<int, array{int, string, int}|string> $tokens
     *
     * @return array<int, string>
     */
    private static function ruleSetsArgumentsOf(array $tokens, int $callee): array
    {
        $name = PhpTokens::name($tokens[$callee]);
        if (null === $name) {
            return [];
        }

        $arguments = self::RULE_SETS_ARGUMENTS[strtolower(substr($name, (int) strrpos('\\'.$name, '\\')))] ?? [];
        $before = PhpTokens::previousSignificant($tokens, $callee - 1);

        return null !== $before && (PhpTokens::isObjectOrStaticOperator($tokens[$before]) || (\is_array($tokens[$before]) && \T_NEW === $tokens[$before][0])) ? $arguments : [];
    }
}

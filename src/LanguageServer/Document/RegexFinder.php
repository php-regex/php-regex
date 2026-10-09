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

use PHPRegex\LanguageServer\Converter\PositionConverter;
use PHPRegex\Linter\Extraction\NameResolutionContext;
use PHPRegex\Linter\Extraction\PhpStringLiteral;
use PHPRegex\Parser\Internal\LibraryPcre;

/**
 * Finds regex patterns in PHP source code: the first argument of the
 * preg_* functions and of the wrapper methods, and the argument a function
 * or static method declares with #[RegexPattern] (see PatternDeclarations).
 *
 * @internal
 */
final class RegexFinder
{
    /**
     * Known preg_* functions that take regex patterns.
     */
    private const PREG_FUNCTIONS = [
        'preg_match',
        'preg_match_all',
        'preg_replace',
        'preg_replace_callback',
        'preg_replace_callback_array',
        'preg_filter',
        'preg_grep',
        'preg_split',
    ];

    /**
     * Wrapper methods whose first argument is a regex pattern.
     *
     * Codebases that route patterns through composer/pcre never call preg_*
     * directly. Matching on the method name alone is enough here: a string
     * that does not look like a pattern is dropped by the delimiter check.
     */
    private const WRAPPER_METHODS = [
        'match',
        'matchall',
        'matchstrictgroups',
        'matchallstrictgroups',
        'matchwithoffsets',
        'matchallwithoffsets',
        'ismatch',
        'ismatchall',
        'ismatchstrictgroups',
        'ismatchallstrictgroups',
        'ismatchwithoffsets',
        'ismatchallwithoffsets',
        'replace',
        'replacecallback',
        'replacecallbackstrictgroups',
        'replacecallbackarray',
        'split',
        'splitwithoffsets',
        'grep',
    ];

    /**
     * Matches an identifier, so a reserved word used as a method name, the
     * "match" of Str::match(), is read as one.
     */
    private const IDENTIFIER = '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/';

    /**
     * Find all regex patterns in PHP content.
     *
     * @return array<RegexOccurrence>
     */
    public function find(string $content, ?PatternDeclarations $declarations = null): array
    {
        $occurrences = $this->findKnownCalls($content);
        if (null === $declarations || $declarations->isEmpty()) {
            return $occurrences;
        }

        // A literal both readings find, Str::match('/x/') declared at its
        // first argument, is one occurrence.
        $byOffset = [];
        foreach ([...$occurrences, ...$this->findDeclaredCalls($content, $declarations)] as $occurrence) {
            $byOffset[$occurrence->byteOffset] ??= $occurrence;
        }
        ksort($byOffset);

        return array_values($byOffset);
    }

    /**
     * The patterns of the preg_* calls and of the wrapper methods.
     *
     * @return array<RegexOccurrence>
     */
    private function findKnownCalls(string $content): array
    {
        $occurrences = [];
        $tokens = @token_get_all($content);
        if ([] === $tokens) {
            return [];
        }

        $positionConverter = new PositionConverter($content);
        $expectingPattern = false;
        $afterDoubleColon = false;

        foreach ($tokens as $index => $token) {
            if (!\is_array($token)) {
                // Single character token
                if ('(' === $token && $expectingPattern) {
                    // Next string token should be the pattern
                    $expectingPattern = 'next_arg';
                } elseif (')' === $token || ',' === $token) {
                    $expectingPattern = false;
                }

                continue;
            }

            [$tokenType, $tokenValue] = $token;

            if (\T_DOUBLE_COLON === $tokenType) {
                $afterDoubleColon = true;

                continue;
            }

            $isStaticMember = $afterDoubleColon;
            if (\T_WHITESPACE !== $tokenType && \T_COMMENT !== $tokenType && \T_DOC_COMMENT !== $tokenType) {
                $afterDoubleColon = false;
            }

            // Track function calls
            if (\T_STRING === $tokenType && \in_array($tokenValue, self::PREG_FUNCTIONS, true)) {
                $expectingPattern = true;

                continue;
            }

            // Track wrapper calls such as Preg::match(). Reserved words are
            // valid method names, so any identifier token is considered.
            if ($isStaticMember && \in_array(strtolower($tokenValue), self::WRAPPER_METHODS, true)) {
                $expectingPattern = true;

                continue;
            }

            // Find string patterns after preg_* function calls
            if ('next_arg' === $expectingPattern && \in_array($tokenType, [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE], true)) {
                $pattern = $this->extractPattern($tokenValue);
                if (null !== $pattern && $this->isValidRegexDelimiter($pattern)) {
                    $byteOffset = $this->findByteOffset($tokens, $index);
                    $startPos = $positionConverter->offsetToPosition($byteOffset);
                    $endPos = $positionConverter->offsetToPosition($byteOffset + \strlen($tokenValue));

                    $occurrences[] = new RegexOccurrence(
                        pattern: $pattern,
                        start: $startPos,
                        end: $endPos,
                        byteOffset: $byteOffset,
                    );
                }
                $expectingPattern = false;
            }
        }

        return $occurrences;
    }

    /**
     * The patterns of the calls to the functions and static methods that
     * declare a pattern parameter, their names resolved through the
     * namespace and the "use" imports, as `regex lint` resolves them. An
     * instance call names no class that can be known, and is not read; an
     * argument is read when it is one string literal.
     *
     * @return list<RegexOccurrence>
     */
    private function findDeclaredCalls(string $content, PatternDeclarations $declarations): array
    {
        $tokens = array_values(array_filter(
            @\PhpToken::tokenize($content),
            static fn (\PhpToken $token): bool => !$token->isIgnorable(),
        ));

        $positions = new PositionConverter($content);
        $context = new NameResolutionContext();
        $occurrences = [];
        $depth = 0;
        // The brace depth "use" imports are read at: in a braced namespace,
        // its body; deeper, a "use" imports a trait.
        $importDepth = 0;
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->is(\T_NAMESPACE)) {
                $named = isset($tokens[$i + 1]) && $tokens[$i + 1]->is([\T_STRING, \T_NAME_QUALIFIED]);
                $context->enterNamespace($named ? $tokens[$i + 1]->text : '');
                $importDepth = '{' === ($tokens[$i + ($named ? 2 : 1)]->text ?? null) ? $depth + 1 : $depth;
            } elseif ($token->is(\T_USE) && $depth === $importDepth && '(' !== ($tokens[$i + 1]->text ?? null)) {
                $i = self::readUse($tokens, $i + 1, $context);
            } elseif ('{' === $token->text || $token->is([\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ('}' === $token->text) {
                $depth--;
            } elseif ($token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE])) {
                [$argument, $open] = $this->declaredArgument($tokens, $i, $context, $declarations);
                $literal = null === $argument ? null : self::argumentLiteral($tokens, $open, $argument);
                $pattern = null === $literal ? null : $this->extractPattern($literal->text);
                if (null !== $literal && null !== $pattern && $this->isValidRegexDelimiter($pattern)) {
                    $occurrences[] = new RegexOccurrence(
                        pattern: $pattern,
                        start: $positions->offsetToPosition($literal->pos),
                        end: $positions->offsetToPosition($literal->pos + \strlen($literal->text)),
                        byteOffset: $literal->pos,
                    );
                }
            }
        }

        return $occurrences;
    }

    /**
     * The pattern argument of the call a name at $i opens, if it calls a
     * declared function or static method, with the index of its "(".
     *
     * @param list<\PhpToken> $tokens
     *
     * @return array{int|null, int}
     */
    private function declaredArgument(array $tokens, int $i, NameResolutionContext $context, PatternDeclarations $declarations): array
    {
        $name = $tokens[$i]->text;
        $next = $tokens[$i + 1] ?? null;
        $previous = $tokens[$i - 1] ?? null;
        if (null !== $previous && $previous->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_NEW])) {
            return [null, 0];
        }

        if (null !== $next && $next->is(\T_DOUBLE_COLON)) {
            $method = $tokens[$i + 2] ?? null;
            if (null === $method || '(' !== ($tokens[$i + 3]->text ?? null) || 1 !== LibraryPcre::match(self::IDENTIFIER, $method->text)) {
                return [null, 0];
            }

            return [$declarations->methodArgument($context->resolveClass($name), $method->text), $i + 3];
        }

        // A declaration, "function grep(" or "function &grep(", is no call.
        $declaring = null !== $previous && ($previous->is(\T_FUNCTION) || ('&' === $previous->text && ($tokens[$i - 2] ?? null)?->is(\T_FUNCTION)));
        if (null === $next || '(' !== $next->text || $declaring) {
            return [null, 0];
        }

        // PHP calls the namespace's function first, the global one when
        // there is none.
        $namespaced = $context->namespacedFunction($name);

        return [(null === $namespaced ? null : $declarations->functionArgument($namespaced)) ?? $declarations->functionArgument($context->resolveFunction($name)), $i + 1];
    }

    /**
     * The argument at $index of the list that opens at $open, when it is one
     * string literal.
     *
     * @param list<\PhpToken> $tokens
     */
    private static function argumentLiteral(array $tokens, int $open, int $index): ?\PhpToken
    {
        $position = 0;
        $nesting = 0;
        $argument = [];
        $count = \count($tokens);
        for ($i = $open + 1; $i < $count; $i++) {
            $token = $tokens[$i];
            if (0 === $nesting && (')' === $token->text || ',' === $token->text)) {
                if ($position === $index) {
                    break;
                }
                if (')' === $token->text) {
                    return null;
                }
                $position++;

                continue;
            }

            if (\in_array($token->text, ['(', '[', '{'], true) || $token->is([\T_ATTRIBUTE, \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                $nesting++;
            } elseif (\in_array($token->text, [')', ']', '}'], true)) {
                $nesting--;
            }

            if ($position === $index) {
                $argument[] = $token;
            }
        }

        return 1 === \count($argument) && $argument[0]->is(\T_CONSTANT_ENCAPSED_STRING) ? $argument[0] : null;
    }

    /**
     * Reads "use A\B as C, D;", "use A\{B, C as D};" and their "use
     * function" forms into the context; "use const" is passed over.
     *
     * @param list<\PhpToken> $tokens
     *
     * @return int the index of the token that ends the statement
     */
    private static function readUse(array $tokens, int $i, NameResolutionContext $context): int
    {
        $function = isset($tokens[$i]) && $tokens[$i]->is(\T_FUNCTION);
        $constant = isset($tokens[$i]) && $tokens[$i]->is(\T_CONST);
        if ($function || $constant) {
            $i++;
        }

        $prefix = '';
        $name = null;
        $alias = null;
        $count = \count($tokens);
        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                if (null !== $name && $tokens[$i - 1]->is(\T_AS)) {
                    $alias = $token->text;
                } else {
                    $name = ltrim($token->text, '\\');
                }
            } elseif ('{' === $token->text) {
                $prefix = rtrim((string) $name, '\\');
                $name = null;
            } elseif (\in_array($token->text, [',', ';', '}'], true)) {
                if (null !== $name && !$constant) {
                    $full = '' === $prefix ? $name : $prefix.'\\'.$name;
                    $alias ??= substr($full, (int) strrpos('\\'.$full, '\\'));
                    $function ? $context->importFunction($alias, $full) : $context->importClass($alias, $full);
                }
                $name = null;
                $alias = null;
                if (';' === $token->text) {
                    return $i;
                }
            }
        }

        return $i;
    }

    /**
     * Extract the actual pattern from a quoted string token.
     */
    private function extractPattern(string $tokenValue): ?string
    {
        // As PHP reads it: "/\d+/" keeps its \d.
        return PhpStringLiteral::decode($tokenValue);
    }

    /**
     * Check if a string looks like a regex with valid delimiter.
     */
    private function isValidRegexDelimiter(string $pattern): bool
    {
        if (\strlen($pattern) < 2) {
            return false;
        }

        $delimiter = $pattern[0];

        // Common regex delimiters
        if (LibraryPcre::match('/^[\/~#@!%]/', $delimiter)) {
            return true;
        }

        // Paired delimiters
        $pairs = ['(' => ')', '[' => ']', '{' => '}', '<' => '>'];
        if (isset($pairs[$delimiter])) {
            return true;
        }

        return false;
    }

    /**
     * Calculate byte offset for a token.
     *
     * @param array<int|string|array{int, string, int}> $tokens
     */
    private function findByteOffset(array $tokens, int $targetIndex): int
    {
        $offset = 0;

        for ($i = 0; $i < $targetIndex; $i++) {
            $token = $tokens[$i];
            if (\is_array($token)) {
                $offset += \strlen($token[1]);
            } else {
                $offset += \strlen((string) $token);
            }
        }

        return $offset;
    }
}

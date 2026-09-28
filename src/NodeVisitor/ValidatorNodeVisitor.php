<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace RegexParser\NodeVisitor;

use RegexParser\Exception\ParserException;
use RegexParser\Exception\SemanticErrorException;
use RegexParser\GroupNumbering;
use RegexParser\GroupNumberingCollector;
use RegexParser\Node\AlternationNode;
use RegexParser\Node\AnchorNode;
use RegexParser\Node\AssertionNode;
use RegexParser\Node\BackrefNode;
use RegexParser\Node\CalloutNode;
use RegexParser\Node\CharClassNode;
use RegexParser\Node\CharLiteralNode;
use RegexParser\Node\CharLiteralType;
use RegexParser\Node\CharTypeNode;
use RegexParser\Node\ClassOperationNode;
use RegexParser\Node\CommentNode;
use RegexParser\Node\ConditionalNode;
use RegexParser\Node\ControlCharNode;
use RegexParser\Node\DefineNode;
use RegexParser\Node\DotNode;
use RegexParser\Node\GroupNode;
use RegexParser\Node\GroupType;
use RegexParser\Node\KeepNode;
use RegexParser\Node\LimitMatchNode;
use RegexParser\Node\LiteralNode;
use RegexParser\Node\NodeInterface;
use RegexParser\Node\PcreVerbNode;
use RegexParser\Node\PosixClassNode;
use RegexParser\Node\QuantifierBounds;
use RegexParser\Node\QuantifierNode;
use RegexParser\Node\RangeNode;
use RegexParser\Node\RegexNode;
use RegexParser\Node\SequenceNode;
use RegexParser\Node\SubroutineNode;
use RegexParser\Node\UnicodePropNode;
use RegexParser\Node\VersionConditionNode;
use RegexParser\Regex;

/**
 * Validator for regex Abstract Syntax Trees with caching and optimization.
 *
 * This validator provides semantic validation while minimizing
 * computational overhead through caching and streamlined validation logic.
 *
 * @extends AbstractNodeVisitor<void>
 */
final class ValidatorNodeVisitor extends AbstractNodeVisitor
{
    // Maximum cache size to prevent memory leaks in long-running processes
    private const MAX_CACHE_SIZE = 1000;

    // Precomputed validation sets for maximum performance
    private const VALID_ASSERTIONS = [
        'A' => true, 'z' => true, 'Z' => true,
        'G' => true, 'b' => true, 'B' => true,
        'b{g}' => true, 'B{g}' => true, // Grapheme boundary assertions (PCRE2)
    ];

    private const VALID_PCRE_VERBS = [
        // Backtracking control verbs
        'FAIL' => true, 'F' => true, 'ACCEPT' => true, 'COMMIT' => true,
        'PRUNE' => true, 'SKIP' => true, 'THEN' => true,
        // Definition verb
        'DEFINE' => true,
        // Mark verb (with optional :NAME argument)
        'MARK' => true,
        // Mode setting verbs
        'UTF8' => true, 'UTF' => true, 'UCP' => true,
        // Newline conventions
        'CR' => true, 'LF' => true, 'CRLF' => true, 'ANYCRLF' => true, 'ANY' => true, 'NUL' => true,
        // BSR (backslash-R) conventions
        'BSR_ANYCRLF' => true, 'BSR_UNICODE' => true,
        // Optimization control
        'NO_AUTO_POSSESS' => true, 'NO_START_OPT' => true, 'NO_DOTSTAR_ANCHOR' => true,
        // Limit verbs
        'LIMIT_MATCH' => true, 'LIMIT_RECURSION' => true,
        'LIMIT_DEPTH' => true, 'LIMIT_HEAP' => true,
        'LIMIT_LOOKBEHIND' => true,
        // Script run verbs (also as lowercase aliases)
        'script_run' => true, 'sr' => true,
        'atomic_script_run' => true, 'asr' => true,
        // Empty match control
        'NOTEMPTY' => true, 'NOTEMPTY_ATSTART' => true,
        // First line anchor
        'FIRSTLINE' => true,
        // JIT control (PCRE2)
        'NO_JIT' => true,
    ];

    private const VALID_POSIX_CLASSES = [
        'alnum' => true, 'alpha' => true, 'ascii' => true,
        'blank' => true, 'cntrl' => true, 'digit' => true,
        'graph' => true, 'lower' => true, 'print' => true,
        'punct' => true, 'space' => true, 'upper' => true,
        'word' => true, 'xdigit' => true,
    ];

    /**
     * Escaped letters PCRE2 gives no meaning to.
     */
    private const UNRECOGNIZED_ESCAPES = [
        'I' => true, 'J' => true, 'M' => true, 'O' => true, 'T' => true, 'Y' => true,
        'i' => true, 'j' => true, 'm' => true, 'q' => true, 'y' => true,
    ];

    /**
     * Perl case-changing escapes, which PCRE2 refuses rather than ignores.
     */
    private const UNSUPPORTED_ESCAPES = [
        'F' => true, 'L' => true, 'U' => true, 'l' => true, 'u' => true,
    ];

    /**
     * Escapes that match a position or more than one character: a class,
     * which matches one character, cannot hold them.
     */
    private const CLASS_INVALID_ESCAPES = [
        'A' => true, 'B' => true, 'C' => true, 'G' => true, 'K' => true,
        'R' => true, 'X' => true, 'Z' => true, 'z' => true,
    ];

    private const HEX_DIGITS = '0123456789abcdefABCDEF';

    private const OCTAL_DIGITS = '01234567';

    /**
     * What PCRE2 skips around the digits of "\x{...}" and "\o{...}".
     */
    private const BRACE_PADDING = " \t";

    /**
     * A repeat count after "\N", which makes it "\N" repeated rather than a
     * "\N{...}" name.
     */
    private const REPEAT_COUNT = '/\G\{[ \t]*+(?:\d++[ \t]*+(?:,[ \t]*+\d*+[ \t]*+)?|,[ \t]*+\d++[ \t]*+)\}/';

    /**
     * The start-of-pattern settings, "(*UTF)" among them, that PCRE2 reads
     * before the pattern itself.
     */
    private const LEADING_UTF_VERB = '/\A(?:\(\*[A-Z_]++(?:=\d++)?\))*?\(\*UTF8?\)/';

    // Optimized state management with minimal memory footprint

    private bool $unicodeMode = false;

    /**
     * The pattern body the tree was parsed from, for what no node records:
     * whether a letter was escaped, and what follows an escape the lexer did
     * not recognize. Null where positions do not point into it.
     */
    private ?string $source = null;

    private int $charClassDepth = 0;

    private GroupNumbering $groupNumbering;

    /**
     * @var array<int>
     */
    private array $captureSequence = [];

    private int $captureIndex = 0;

    private int $lookbehindLimit = 0;

    private ?NodeInterface $previousNode = null;

    private ?NodeInterface $nextNode = null;

    /**
     * @var array<string, bool>
     */
    private static array $unicodePropCache = [];

    /**
     * @var array<string, array{0: int, 1: int}>
     */
    private static array $quantifierBoundsCache = [];

    public function __construct(
        private readonly int $maxLookbehindLength = Regex::DEFAULT_MAX_LOOKBEHIND_LENGTH,
        private readonly ?string $pattern = null,
        private readonly int $phpVersionId = \PHP_VERSION_ID,
    ) {}

    /**
     * Clears static caches. Useful for long-running processes or testing.
     */
    public static function clearCaches(): void
    {
        self::$unicodePropCache = [];
        self::$quantifierBoundsCache = [];
    }

    #[\Override]
    public function visitRegex(RegexNode $node): void
    {
        $this->source = $node->source;
        $this->charClassDepth = 0;
        $this->unicodeMode = str_contains($node->flags, 'u')
            || (null !== $node->source && 1 === preg_match(self::LEADING_UTF_VERB, $node->source));
        $this->groupNumbering = (new GroupNumberingCollector())->collect($node);
        $this->captureSequence = $this->groupNumbering->captureSequence;
        $this->captureIndex = 0;
        $this->lookbehindLimit = $this->extractLookbehindLimit($node->pattern) ?? $this->maxLookbehindLength;
        $this->previousNode = null;
        $this->nextNode = null;

        $node->pattern->accept($this);
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node): void
    {
        $previous = $this->previousNode;
        $next = $this->nextNode;

        foreach ($node->alternatives as $alt) {
            $this->previousNode = null;
            $this->nextNode = null;
            $alt->accept($this);
        }

        $this->previousNode = $previous;
        $this->nextNode = $next;
    }

    #[\Override]
    public function visitSequence(SequenceNode $node): void
    {
        $previous = $this->previousNode;
        $next = $this->nextNode;
        $total = \count($node->children);
        $last = null;

        foreach ($node->children as $index => $child) {
            $this->previousNode = $last;
            $this->nextNode = $index + 1 < $total ? $node->children[$index + 1] : null;
            $child->accept($this);
            $last = $child;
        }

        $this->previousNode = $previous;
        $this->nextNode = $next;
    }

    #[\Override]
    public function visitGroup(GroupNode $node): void
    {
        $this->ensureGroupNumberingInitialized();

        $previous = $this->previousNode;
        $next = $this->nextNode;

        if (\in_array(
            $node->type,
            [GroupType::T_GROUP_LOOKBEHIND_POSITIVE, GroupType::T_GROUP_LOOKBEHIND_NEGATIVE],
            true,
        )) {
            $this->validateLookbehindLength($node);
        }

        if (GroupType::T_GROUP_CAPTURING === $node->type || GroupType::T_GROUP_NAMED === $node->type) {
            $this->captureIndex++;
        }

        $this->previousNode = null;
        $this->nextNode = null;

        // "(*pla:...)" and its kin are parsed apart, with positions counted
        // from their own payload, so the source cannot be read under them.
        $source = $this->source;
        if ($node->child->getStartPosition() <= $node->startPosition) {
            $this->source = null;
        }

        try {
            $node->child->accept($this);
        } finally {
            $this->source = $source;
        }

        $this->previousNode = $previous;
        $this->nextNode = $next;
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node): void
    {
        // Fast cached quantifier bounds parsing
        [$min, $max] = $this->getQuantifierBounds($node->quantifier);

        // Early validation with clear error messages
        if (-1 !== $max && $min > $max) {
            $this->raiseSemanticError(
                \sprintf('Invalid quantifier range "%s": min > max.', $node->quantifier),
                $node->startPosition,
                'regex.quantifier.invalid_range',
            );
        }

        // PCRE caps repetition counts at 65535.
        if ($min > 65535 || $max > 65535) {
            $this->raiseSemanticError(
                \sprintf('Number too big in "%s" quantifier: PCRE allows at most 65535 repetitions.', $node->quantifier),
                $node->startPosition,
                'regex.quantifier.too_big',
            );
        }

        $node->node->accept($this);
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node): void
    {
        if (null === $this->source) {
            return;
        }

        if ($this->charClassDepth > 0 && '[' === $node->value && $this->isUnquotedClassBracket($this->source, $node)) {
            $this->validateBracketInClass($this->source, $node->startPosition);

            return;
        }

        $letter = $node->value;
        $start = $node->startPosition;
        if (1 !== \strlen($letter) || !ctype_alpha($letter)
            || '\\'.$letter !== substr($this->source, $start, $node->endPosition - $start)) {
            return;
        }

        $this->validateEscapedLetter($this->source, $letter, $start);
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node): void
    {
        if (0 === $this->charClassDepth) {
            return;
        }

        if ('N' === $node->value) {
            // "[\N{U+41 }]" with padding the lexer does not read, or a
            // malformed "\N{U+...}", reaches here as "\N": judge the braces
            // as outside a class.
            if (null !== $this->source && $this->startsNamedCodePoint($this->source, $node->getEndPosition())) {
                $this->validateNamedCharacterBraces($this->source, $node->getEndPosition());

                return;
            }

            $this->raiseSemanticError(
                '\N is not supported in a character class.',
                $node->getEndPosition(),
                'regex.charclass.invalid_escape',
            );
        }

        if (isset(self::CLASS_INVALID_ESCAPES[$node->value])) {
            $this->raiseSemanticError(
                \sprintf('Escape sequence \%s is invalid in a character class.', $node->value),
                $node->getEndPosition(),
                'regex.charclass.invalid_escape',
            );
        }
    }

    #[\Override]
    public function visitDot(DotNode $node): void
    {
        // No semantic validation needed for dot
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node): void
    {
        // No semantic validation needed for anchors
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node): void
    {
        // Fast array lookup with early return
        if (!isset(self::VALID_ASSERTIONS[$node->value])) {
            $this->raiseSemanticError(
                \sprintf('Invalid assertion: \\%s.', $node->value),
                $node->startPosition,
                'regex.assertion.invalid',
            );
        }
    }

    /**
     * `\K` is valid anywhere, lookarounds included: PHP compiles every pattern
     * with PCRE2_EXTRA_ALLOW_LOOKAROUND_BSK, and PCRE2 allowed it there by
     * default before 10.38.
     */
    #[\Override]
    public function visitKeep(KeepNode $node): void {}

    #[\Override]
    public function visitCharClass(CharClassNode $node): void
    {
        if (0 === $this->charClassDepth && null !== $this->source) {
            $start = $node->startPosition;

            // "[[:<:]]" and "[[:>:]]" are the old POSIX word boundaries,
            // which PCRE reads as such outside a class.
            if (\in_array(substr($this->source, $start, 7), ['[[:<:]]', '[[:>:]]'], true)) {
                return;
            }

            $this->validatePosixOutsideClass($this->source, $start);
        }

        $parts = $node->expression instanceof AlternationNode ? $node->expression->alternatives : [$node->expression];

        $this->charClassDepth++;

        try {
            foreach ($parts as $part) {
                $part->accept($this);
            }
        } finally {
            $this->charClassDepth--;
        }
    }

    #[\Override]
    public function visitClassOperation(ClassOperationNode $node): void
    {
        $node->left->accept($this);
        $node->right->accept($this);
    }

    #[\Override]
    public function visitRange(RangeNode $node): void
    {
        // 1. Validation: Ensure start and end nodes represent a single character.
        // We allow LiteralNode, but also CharLiteralNode and friends.
        if (!$this->isSingleCharNode($node->start) || !$this->isSingleCharNode($node->end)) {
            $this->raiseSemanticError(
                \sprintf(
                    'Invalid range: ranges must be between literal characters or single escape sequences. Found %s and %s.',
                    $node->start::class,
                    $node->end::class,
                ),
                $node->startPosition,
                'regex.range.invalid_bounds',
            );
        }

        // 2. Validation: Ensure characters are single codepoint (for LiteralNodes).
        // Use mb_strlen for proper Unicode character counting.
        if ($node->start instanceof LiteralNode && mb_strlen($node->start->value, 'UTF-8') > 1) {
            $this->raiseSemanticError(
                'Invalid range: start char must be a single character.',
                $node->startPosition,
                'regex.range.invalid_start',
            );
        }
        if ($node->end instanceof LiteralNode && mb_strlen($node->end->value, 'UTF-8') > 1) {
            $this->raiseSemanticError(
                'Invalid range: end char must be a single character.',
                $node->startPosition,
                'regex.range.invalid_end',
            );
        }

        $node->start->accept($this);
        $node->end->accept($this);

        // 3. Validation: order check, on the code points of both endpoints.
        // PCRE reports it where the range ends; a range between two plain
        // characters keeps the offset it has always been reported at.
        $startCodePoint = $this->rangeEndpointCodePoint($node->start, true);
        $endCodePoint = $this->rangeEndpointCodePoint($node->end, false);
        if (null !== $startCodePoint && null !== $endCodePoint && $startCodePoint > $endCodePoint) {
            $this->raiseSemanticError(
                \sprintf('Invalid range "%s": start character comes after end character.', $this->describeRange($node)),
                $node->start instanceof LiteralNode && $node->end instanceof LiteralNode
                    ? $node->startPosition
                    : $node->getEndPosition(),
                'regex.range.reversed',
            );
        }
    }

    #[\Override]
    public function visitBackref(BackrefNode $node): void
    {
        $this->ensureGroupNumberingInitialized();

        $ref = $node->ref;

        $suggestions = $this->getNameSuggestions($ref);

        // Fast path for numeric backreferences
        if (preg_match('/^\\\\(\d++)$/', $ref, $matches)) {
            $num = (int) $matches[1];
            if (0 === $num) {
                $this->raiseSemanticError(
                    'Backreference \\0 is not valid.',
                    $node->startPosition,
                    'regex.backref.zero',
                    'Use \\g<0> for recursion to the whole pattern, or remove the reference.',
                );
            }
            if ($num > $this->groupNumbering->maxGroupNumber) {
                // PCRE disambiguation: \NN with NN >= 10 and no such group is
                // read as an octal escape (up to three octal digits, value
                // <= \377) followed by literal digits, e.g. (a)\11 == (a)\x09.
                if ($num >= 10 && $this->isValidOctalFallback($matches[1])) {
                    return;
                }

                $this->raiseSemanticError(
                    \sprintf('Backreference to non-existent group: \\%d.', $num),
                    $node->startPosition,
                    'regex.backref.missing_group',
                );
            }

            return;
        }

        // Numeric conditionals without a leading backslash (e.g., (?(2)...))
        if (preg_match('/^(\d++)$/', $ref, $matches)) {
            $num = (int) $matches[1];
            if (0 === $num) {
                $this->raiseSemanticError(
                    'Backreference 0 is not valid.',
                    $node->startPosition,
                    'regex.backref.zero',
                    'Use \\g<0> for recursion to the whole pattern, or remove the reference.',
                );
            }
            if ($num > $this->groupNumbering->maxGroupNumber) {
                $this->raiseSemanticError(
                    \sprintf('Backreference to non-existent group: %d.', $num),
                    $node->startPosition,
                    'regex.backref.missing_group',
                );
            }

            return;
        }

        // Optimized named backreference validation
        if (preg_match('/^\\\\k[<{\'](?<name>\w++)[>}\']$/', $ref, $matches)) {
            $name = $matches['name'];
            if (!$this->groupNumbering->hasNamedGroup($name)) {
                $suggestions = $this->getNameSuggestions($name);
                $this->raiseSemanticError(
                    \sprintf('Backreference to non-existent named group: "%s".', $name).$suggestions,
                    $node->startPosition,
                    'regex.backref.missing_named_group',
                );
            }

            return;
        }

        // Bare name validation (conditionals)
        if ($this->isBareNamedBackref($ref)) {
            if (!$this->groupNumbering->hasNamedGroup($ref)) {
                $suggestions = $this->getNameSuggestions($ref);
                $this->raiseSemanticError(
                    \sprintf('Backreference to non-existent named group: "%s".', $ref).$suggestions,
                    $node->startPosition,
                    'regex.backref.missing_named_group',
                );
            }

            return;
        }

        // \g backreference with optimized validation (\g1, \g{1}, \g'1')
        if (preg_match('/^\\\\g(?:\{([0-9+-]++)\}|\'([0-9+-]++)\'|([0-9+-]++))$/', $ref, $matches)) {
            $numStr = ('' !== $matches[1]) ? $matches[1] : (('' !== ($matches[2] ?? '')) ? $matches[2] : ($matches[3] ?? ''));
            if ('0' === $numStr || '+0' === $numStr || '-0' === $numStr) {
                $this->raiseSemanticError(
                    'Backreference \\g{0} is not valid.',
                    $node->startPosition,
                    'regex.backref.zero',
                    'Use \\g<0> for recursion to the whole pattern.',
                );
            }

            if (str_starts_with($numStr, '+') || str_starts_with($numStr, '-')) {
                $offset = (int) $numStr;
                $this->assertRelativeReferenceExists($offset, $node->startPosition, 'regex.backref.relative', 'Backreference');

                return;
            }

            $num = (int) $numStr;
            if ($num > $this->groupNumbering->maxGroupNumber) {
                $this->raiseSemanticError(
                    \sprintf('Backreference to non-existent group: \\g{%d}.', $num),
                    $node->startPosition,
                    'regex.backref.missing_group',
                );
            }

            return;
        }

        $this->raiseSemanticError(
            \sprintf('Invalid backreference syntax: "%s".', $ref),
            $node->startPosition,
            'regex.backref.invalid_syntax',
        );
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node): void
    {
        // The Lexer/Parser combination already ensures these are
        // syntactically valid. We validate the *value*.
        match ($node->type) {
            CharLiteralType::UNICODE => $this->validateUnicode($node),
            CharLiteralType::OCTAL => $this->validateOctal($node),
            CharLiteralType::OCTAL_LEGACY => $this->validateOctalLegacy($node),
            CharLiteralType::UNICODE_NAMED => $this->validateUnicodeNamed($node),
        };

        // UTF-8 cannot encode the UTF-16 surrogates. PCRE reports it at the
        // closing brace.
        if ($this->unicodeMode && $node->codePoint >= 0xD800 && $node->codePoint <= 0xDFFF) {
            $this->raiseSemanticError(
                \sprintf('Code point "%s" is a surrogate, which is not allowed in Unicode mode.', $node->originalRepresentation),
                $node->getEndPosition() - 1,
                'regex.unicode.surrogate',
            );
        }
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node): void
    {
        $prop = $node->prop;
        $key = $node->hasBraces
            ? 'p'.$prop
            : ((\strlen($prop) > 1 || str_starts_with($prop, '^'))
                ? 'p{'.$prop.'}'
                : 'p'.$prop);

        // Intelligent caching with lazy validation and size limit
        if (!isset(self::$unicodePropCache[$key])) {
            // Prevent unbounded cache growth in long-running processes
            if (\count(self::$unicodePropCache) >= self::MAX_CACHE_SIZE) {
                self::$unicodePropCache = \array_slice(self::$unicodePropCache, -((int) (self::MAX_CACHE_SIZE / 2)), null, true);
            }
            self::$unicodePropCache[$key] = $this->validateUnicodeProperty($key);
        }

        if (false === self::$unicodePropCache[$key]) {
            $propertyKey = $this->extractUnicodePropertyKey($key);
            $suggestion = $this->suggestUnicodeProperty($propertyKey);
            $message = \sprintf('Invalid or unsupported Unicode property: \\%s.', $key);
            if (null !== $suggestion) {
                $message .= " Did you mean \\{$suggestion}?";
            }
            $this->raiseSemanticError(
                $message,
                $node->startPosition,
                'regex.unicode.property_invalid',
            );
        }
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node): void
    {
        if ($node->codePoint < 0 || $node->codePoint > 0xFF) {
            $this->raiseSemanticError(
                \sprintf('Invalid control character "\\c%s".', $node->char),
                $node->startPosition,
                'regex.control_char.invalid',
            );
        }
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node): void
    {
        // PCRE matches the name case-sensitively: "[[:ALPHA:]]" is unknown.
        if (!$this->isPosixClassName($node->class)) {
            $this->raiseSemanticError(
                \sprintf('Invalid POSIX class: "%s".', $node->class),
                $node->getEndPosition(),
                'regex.posix.invalid',
            );
        }
    }

    /**
     * Validates a `CommentNode`.
     */
    #[\Override]
    public function visitComment(CommentNode $node): void
    {
        // Comments are ignored in validation
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node): void
    {
        $this->ensureGroupNumberingInitialized();

        // Check if the condition is a valid *type* of condition first
        // (e.g., a backreference, a subroutine call, or a lookaround)
        if ($node->condition instanceof BackrefNode) {
            // This is (?(1)...) or (?(<name>)...) or (?(name)...)
            // For bare names, check if the group exists before calling accept
            $ref = $node->condition->ref;
            if ($this->isBareNamedBackref($ref) && !$this->groupNumbering->hasNamedGroup($ref)) {
                // Bare name that doesn't exist - this is an invalid conditional
                $this->raiseSemanticError(
                    'Invalid conditional construct. Condition must be a group reference, lookaround, or (DEFINE).',
                    $node->condition->getStartPosition(),
                    'regex.conditional.invalid',
                );
            }
            // Now validate the backreference itself
            $node->condition->accept($this);
        } elseif ($node->condition instanceof SubroutineNode) {
            $ref = $node->condition->reference;
            if ('R' === $ref || '0' === $ref) {
                // Always valid recursion condition to entire pattern.
            } elseif (preg_match('/^R-?\d++$/', $ref)) {
                $num = (int) substr($ref, 1);
                $this->assertSubroutineReferenceExists($num, $node->condition->startPosition, 'regex.subroutine.recursion', 'Recursion condition');
            } else {
                $node->condition->accept($this);
            }
        } elseif ($node->condition instanceof GroupNode && \in_array($node->condition->type, [
            GroupType::T_GROUP_LOOKAHEAD_POSITIVE,
            GroupType::T_GROUP_LOOKAHEAD_NEGATIVE,
            GroupType::T_GROUP_LOOKBEHIND_POSITIVE,
            GroupType::T_GROUP_LOOKBEHIND_NEGATIVE,
        ], true)) {
            // This is (?(?=...)...) etc. This is valid.
            $node->condition->accept($this);
        } elseif ($node->condition instanceof AssertionNode && 'DEFINE' === $node->condition->value) {
            // (?(DEFINE)...) This is valid.
            $node->condition->accept($this);
        } elseif ($node->condition instanceof VersionConditionNode) {
            // (?(VERSION>=10.4)...) asks about the library reading the
            // pattern; whether the comparison is one PCRE makes is checked
            // where the condition is visited.
            $node->condition->accept($this);
        } else {
            // Any other atom is not a valid condition
            $this->raiseSemanticError(
                'Invalid conditional construct. Condition must be a group reference, lookaround, or (DEFINE).',
                $node->condition->getStartPosition(),
                'regex.conditional.invalid',
            );
        }

        $node->yes->accept($this);
        $node->no->accept($this);
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node): void
    {
        $this->ensureGroupNumberingInitialized();

        $ref = $node->reference;

        if ('R' === $ref || '0' === $ref) {
            return; // (?R) or (?0) is always valid.
        }

        if (str_starts_with($ref, 'R')) {
            $numPart = substr($ref, 1);
            if ('' === $numPart) {
                return;
            }

            if (ctype_digit($numPart)) {
                $num = (int) $numPart;
                $this->assertAbsoluteReferenceExists($num, $node->startPosition, 'regex.subroutine.recursion', 'Recursion condition');

                return;
            }

            if (str_starts_with($numPart, '-') && ctype_digit(substr($numPart, 1))) {
                $num = (int) $numPart;
                $this->assertRelativeReferenceExists($num, $node->startPosition, 'regex.subroutine.recursion', 'Recursion condition');

                return;
            }
        }

        // Numeric reference: (?1), (?-1), (?+1), \g<-1>, \g<+1>
        if (1 === preg_match('/^[+-]?\d+$/', $ref)) {
            $num = (int) $ref;
            if (0 === $num) {
                return; // (?0) is an alias for (?R)
            }
            if (str_starts_with($ref, '+') || str_starts_with($ref, '-')) {
                $this->assertRelativeReferenceExists($num, $node->startPosition, 'regex.subroutine.relative_missing', 'Subroutine call');
            } else {
                $this->assertAbsoluteReferenceExists($num, $node->startPosition, 'regex.subroutine.missing_group', 'Subroutine call');
            }

            return;
        }

        // Named reference: (?&name), (?P>name), \g<name>
        if (!$this->groupNumbering->hasNamedGroup($ref)) {
            $this->raiseSemanticError(
                \sprintf('Subroutine call to non-existent named group: "%s".', $ref),
                $node->startPosition,
                'regex.subroutine.missing_named_group',
            );
        }
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node): void
    {
        $verbName = preg_split('/[:=]/', $node->verb, 2)[0] ?? $node->verb;
        if ('LIMIT_LOOKBEHIND' === $verbName && preg_match('/^LIMIT_LOOKBEHIND=(\d++)$/', $node->verb, $matches)) {
            $this->lookbehindLimit = (int) $matches[1];
        }

        if (!isset(self::VALID_PCRE_VERBS[$verbName])) {
            $this->raiseSemanticError(
                \sprintf('Invalid or unsupported PCRE verb: "%s".', $verbName),
                $node->startPosition,
                'regex.verb.invalid',
            );
        }
    }

    #[\Override]
    public function visitDefine(DefineNode $node): void
    {
        $node->content->accept($this);
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node): void
    {
        // No specific validation needed for this node.
    }

    /**
     * PCRE compares the version two ways and no more.
     *
     * The parser reads the others so that a pattern using one still produces
     * a tree to look at; saying they will not compile is this visitor's job.
     */
    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node): void
    {
        if (\in_array($node->operator, ['=', '>='], true)) {
            return;
        }

        $this->raiseSemanticError(
            \sprintf('Version condition "%s" is not supported: PCRE compares with "=" or ">=".', $node->operator),
            $node->startPosition,
            'regex.condition.version_operator',
        );
    }

    #[\Override]
    public function visitCallout(CalloutNode $node): void
    {
        $position = $node->startPosition + 4;

        if (null === $node->identifier) {
            return;
        }

        if (\is_int($node->identifier)) {
            if ($node->identifier < 0 || $node->identifier > 255) {
                $this->raiseSemanticError(
                    \sprintf('Callout identifier must be between 0 and 255, got %d.', $node->identifier),
                    $position,
                    'regex.callout.out_of_range',
                );
            }
        } elseif (\is_string($node->identifier)) {
            // PCRE2 allows any string as an argument, but it's good to ensure it's not empty.
            if ('' === $node->identifier) {
                $this->raiseSemanticError(
                    'Callout string identifier cannot be empty.',
                    $position,
                    'regex.callout.empty_identifier',
                );
            }
        } else {
            // This case should ideally be caught by the Lexer/Parser, but as a safeguard.
            $this->raiseSemanticError(
                'Invalid callout identifier type.',
                $position,
                'regex.callout.invalid_type',
            );
        }
    }

    private function extractUnicodePropertyKey(string $key): string
    {
        // Strip \p or \P prefix
        if (str_starts_with($key, '\\p') || str_starts_with($key, '\\P')) {
            $key = substr($key, 2);
        }
        if (str_starts_with($key, '{') && str_ends_with($key, '}')) {
            return substr($key, 1, -1);
        }

        return $key;
    }

    private function suggestUnicodeProperty(string $key): ?string
    {
        $suggestions = [
            'Letter' => 'p{L}',
            'Number' => 'p{N}',
            'Punctuation' => 'p{P}',
            'Symbol' => 'p{S}',
            'Mark' => 'p{M}',
            'Separator' => 'p{Z}',
            'Other' => 'p{C}',
            'Control' => 'p{Cc}',
            'Format' => 'p{Cf}',
            'Surrogate' => 'p{Cs}',
            'Private_Use' => 'p{Co}',
            'Unassigned' => 'p{Cn}',
            'Lowercase_Letter' => 'p{Ll}',
            'Uppercase_Letter' => 'p{Lu}',
            'Titlecase_Letter' => 'p{Lt}',
            'Cased_Letter' => 'p{L&}',
            'Modifier_Letter' => 'p{Lm}',
            'Other_Letter' => 'p{Lo}',
            'Nonspacing_Mark' => 'p{Mn}',
            'Spacing_Mark' => 'p{Mc}',
            'Enclosing_Mark' => 'p{Me}',
            'Decimal_Number' => 'p{Nd}',
            'Letterlike_Number' => 'p{Nl}',
            'Other_Number' => 'p{No}',
            'Connector_Punctuation' => 'p{Pc}',
            'Dash_Punctuation' => 'p{Pd}',
            'Open_Punctuation' => 'p{Ps}',
            'Close_Punctuation' => 'p{Pe}',
            'Initial_Punctuation' => 'p{Pi}',
            'Final_Punctuation' => 'p{Pf}',
            'Other_Punctuation' => 'p{Po}',
            'Math_Symbol' => 'p{Sm}',
            'Currency_Symbol' => 'p{Sc}',
            'Modifier_Symbol' => 'p{Sk}',
            'Other_Symbol' => 'p{So}',
            'Space_Separator' => 'p{Zs}',
            'Line_Separator' => 'p{Zl}',
            'Paragraph_Separator' => 'p{Zp}',
            'Other_Separator' => 'p{Zo}',
        ];

        return $suggestions[$key] ?? null;
    }

    private function normalizeQuantifier(string $q): string
    {
        if (!str_starts_with($q, '{') || !str_ends_with($q, '}')) {
            return $q;
        }

        $inner = substr($q, 1, -1);
        $inner = preg_replace('/\\s+/', '', $inner) ?? $inner;

        return '{'.$inner.'}';
    }

    private function getNameSuggestions(string $name): string
    {
        $available = array_keys($this->groupNumbering->namedGroups);
        $suggestions = [];
        foreach ($available as $avail) {
            if (levenshtein($name, $avail) <= 2) {
                $suggestions[] = $avail;
            }
        }
        if (!empty($suggestions)) {
            return ' Did you mean: '.implode(', ', $suggestions).'?';
        }

        return '';
    }

    private function isBareNamedBackref(string $ref): bool
    {
        return 1 === preg_match('/^[A-Za-z_]\w*+$/', $ref);
    }

    private function validateUnicode(CharLiteralNode $node): void
    {
        // Parse codePoint from the escape string
        $rep = $node->originalRepresentation;

        if (preg_match('/^\\\\x([0-9a-fA-F]{1,2})$/', $rep, $m)) {
            $codePoint = (int) hexdec($m[1]);
        } elseif (preg_match('/^\\\\u([0-9a-fA-F]{4})$/', $rep, $m)) {
            $codePoint = (int) hexdec($m[1]);
        } elseif (preg_match('/^\\\\(x|u)\\{[ \t]*+([0-9a-fA-F]+)[ \t]*+\\}$/', $rep, $m)) {
            $codePoint = (int) hexdec($m[2]);
        } else {
            return; // Invalid format, skip
        }

        if ($codePoint > 0x10FFFF) {
            $this->raiseSemanticError(
                \sprintf('Invalid Unicode codepoint "%s" (out of range).', $node->originalRepresentation),
                $node->startPosition,
                'regex.unicode.out_of_range',
            );
        }

        // "\u0041" and "\u{41}" are JavaScript: PCRE2 refuses "\u" outright.
        // What was written is read from the source, so a node built by hand
        // is not judged on its spelling.
        if (null !== $this->source && '\\u' === substr($this->source, $node->startPosition, 2)) {
            $this->raiseUnsupportedEscape('u', $node->startPosition + 2);
        }
    }

    private function validateOctal(CharLiteralNode $node): void
    {
        // Without /u, PCRE limits \o{} to single-byte values (0-255); in
        // Unicode mode any valid codepoint is allowed.
        if ($this->unicodeMode) {
            if ($node->codePoint > 0x10FFFF) {
                $this->raiseSemanticError(
                    \sprintf('Invalid octal codepoint "%s" (out of Unicode range).', $node->originalRepresentation),
                    $node->startPosition,
                    'regex.octal.out_of_range',
                );
            }

            return;
        }

        if ($node->codePoint > 0xFF) {
            $this->raiseSemanticError(
                \sprintf('Invalid octal codepoint "%s".', $node->originalRepresentation),
                $node->startPosition,
                'regex.octal.out_of_range',
            );
        }
    }

    private function validateOctalLegacy(CharLiteralNode $node): void
    {
        // Without Unicode mode PCRE limits legacy octal to one byte, \377;
        // in Unicode mode "\400" to "\777" are code points like any other.
        if (!$this->unicodeMode && $node->codePoint > 0xFF) {
            $this->raiseSemanticError(
                \sprintf('Invalid legacy octal codepoint "%s" (out of range).', $node->originalRepresentation),
                $node->startPosition,
                'regex.octal.out_of_range',
            );
        }
    }

    private function validateUnicodeNamed(CharLiteralNode $node): void
    {
        // Extract the Unicode name from the representation
        if (!preg_match('/^\\\\N\\{(.+)}$/', $node->originalRepresentation, $matches)) {
            throw new ParserException("Invalid Unicode named character format: {$node->originalRepresentation}", $node->getStartPosition(), $this->pattern);
        }

        $name = $matches[1];

        // PCRE only supports \N{U+hhhh} in Unicode (/u) mode.
        if (!$this->unicodeMode && 1 === preg_match('/^[ \t]*+U\+[0-9A-Fa-f]+[ \t]*+$/', $name)) {
            $this->raiseSemanticError(
                \sprintf('\N{%s} is only supported in Unicode mode; add the "u" flag.', $name),
                // PCRE reads the escape to its closing brace before it looks
                // at the mode.
                $node->getEndPosition(),
                'regex.unicode_named.requires_utf',
            );
        }

        // If the codePoint is -1, the name could not be resolved
        if (-1 === $node->codePoint) {
            throw new ParserException("Invalid Unicode character name: {$name}", $node->getStartPosition(), $this->pattern);
        }
    }

    /**
     * Optimized Unicode property validation with error suppression.
     */
    private function validateUnicodeProperty(string $key): bool
    {
        if ($this->compileUnicodeProperty($key)) {
            return true;
        }

        if (null !== $mappedKey = $this->mapJavaUnicodeProperty($key)) {
            if ($this->compileUnicodeProperty($mappedKey)) {
                return true;
            }
        }

        // Fallback: map Block=/Blk= to In<block> alias which PCRE recognizes.
        if (preg_match('/^p\\{(\\^)?bl(?:ock|k)=([^}]+)\\}$/i', $key, $matches)) {
            $negation = (string) $matches[1];
            $block = $matches[2];
            $aliasKey = 'p{'.$negation.'In'.$block.'}';
            // Try to compile the alias; if the runtime lacks block-name support,
            // still treat the property as syntactically valid.
            $this->compileUnicodeProperty($aliasKey);

            return true;
        }

        return false;
    }

    private function mapJavaUnicodeProperty(string $key): ?string
    {
        if (!preg_match('/^p\\{(\\^)?([A-Za-z_][A-Za-z0-9_]*)\\}$/', $key, $matches)) {
            return null;
        }

        $negation = $matches[1];
        $property = strtolower($matches[2]);
        $aliases = [
            'javalowercase' => 'Ll',
            'javauppercase' => 'Lu',
            'javawhitespace' => 'White_Space',
            'javamirrored' => 'Bidi_Mirrored',
        ];

        if (!isset($aliases[$property])) {
            return null;
        }

        return 'p{'.$negation.$aliases[$property].'}';
    }

    private function compileUnicodeProperty(string $key): bool
    {
        // Use error suppression as preg_match warns on invalid properties
        $result = @preg_match("/^\\{$key}$/u", '');
        $error = preg_last_error();

        // PREG_NO_ERROR means it compiled successfully
        return false !== $result && \PREG_NO_ERROR === $error;
    }

    private function isSingleCharNode(NodeInterface $node): bool
    {
        return $node instanceof LiteralNode
            || $node instanceof CharLiteralNode
            || $node instanceof ControlCharNode;
        // CharTypeNode (e.g., \d) is technically invalid in a standard PCRE range start/end,
        // but we exclude it here to remain spec-compliant unless lenient mode is desired.
    }

    /**
     * Cached quantifier bounds parsing.
     *
     * @return array{0: int, 1: int}
     */
    private function getQuantifierBounds(string $q): array
    {
        $normalized = $this->normalizeQuantifier($q);
        // Return cached result if available
        if (isset(self::$quantifierBoundsCache[$normalized])) {
            return self::$quantifierBoundsCache[$normalized];
        }

        // Prevent unbounded cache growth in long-running processes
        if (\count(self::$quantifierBoundsCache) >= self::MAX_CACHE_SIZE) {
            self::$quantifierBoundsCache = \array_slice(self::$quantifierBoundsCache, -((int) (self::MAX_CACHE_SIZE / 2)), null, true);
        }

        // Compute and cache the result
        $bounds = $this->parseQuantifierBounds($normalized);
        self::$quantifierBoundsCache[$normalized] = $bounds;

        return $bounds;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseQuantifierBounds(string $q): array
    {
        $bounds = QuantifierBounds::parse($q);

        return null === $bounds ? [1, 1] : [$bounds->min, $bounds->max ?? -1];
    }

    private function calculateFixedLength(NodeInterface $node): ?int
    {
        return match (true) {
            $node instanceof LiteralNode => mb_strlen($node->value),
            $node instanceof CharTypeNode, $node instanceof DotNode => 1,
            $node instanceof AnchorNode, $node instanceof AssertionNode => 0,
            $node instanceof SequenceNode => $this->calculateSequenceLength($node),
            $node instanceof GroupNode => $this->calculateFixedLength($node->child),
            $node instanceof QuantifierNode => $this->calculateQuantifierLength($node),
            $node instanceof CharClassNode => 1,
            $node instanceof AlternationNode => null, // Handled separately
            default => null, // Unknown or variable
        };
    }

    private function calculateSequenceLength(SequenceNode $node): ?int
    {
        $total = 0;
        foreach ($node->children as $child) {
            $length = $this->calculateFixedLength($child);
            if (null === $length) {
                return null; // Variable length
            }
            $total += $length;
        }

        return $total;
    }

    private function calculateQuantifierLength(QuantifierNode $node): ?int
    {
        [$min, $max] = $this->parseQuantifierBounds($node->quantifier);

        // Only fixed if min == max (and both are not -1)
        if ($min !== $max || -1 === $max) {
            return null; // Variable length
        }

        $childLength = $this->calculateFixedLength($node->node);
        if (null === $childLength) {
            return null;
        }

        return $min * $childLength;
    }

    private function extractLookbehindLimit(NodeInterface $node): ?int
    {
        if ($node instanceof PcreVerbNode && preg_match('/^LIMIT_LOOKBEHIND=(\d++)$/', $node->verb, $matches)) {
            return (int) $matches[1];
        }

        if ($node instanceof GroupNode) {
            return $this->extractLookbehindLimit($node->child);
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alt) {
                $limit = $this->extractLookbehindLimit($alt);
                if (null !== $limit) {
                    return $limit;
                }
            }
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $limit = $this->extractLookbehindLimit($child);
                if (null !== $limit) {
                    return $limit;
                }
            }
        }

        if ($node instanceof QuantifierNode) {
            return $this->extractLookbehindLimit($node->node);
        }

        if ($node instanceof ConditionalNode) {
            return $this->extractLookbehindLimit($node->condition)
                ?? $this->extractLookbehindLimit($node->yes)
                ?? $this->extractLookbehindLimit($node->no);
        }

        if ($node instanceof DefineNode) {
            return $this->extractLookbehindLimit($node->content);
        }

        if ($node instanceof CharClassNode) {
            return $this->extractLookbehindLimit($node->expression);
        }

        if ($node instanceof ClassOperationNode) {
            return $this->extractLookbehindLimit($node->left) ?? $this->extractLookbehindLimit($node->right);
        }

        if ($node instanceof RangeNode) {
            return $this->extractLookbehindLimit($node->start) ?? $this->extractLookbehindLimit($node->end);
        }

        return null;
    }

    private function validateLookbehindLength(GroupNode $node): void
    {
        $lengthRange = $node->child->accept(new LengthRangeNodeVisitor());
        [$min, $max] = $lengthRange;

        if (null === $max) {
            $culprit = $this->findUnboundedLookbehindNode($node->child);
            $position = $culprit?->getStartPosition() ?? $node->startPosition;
            $detail = $culprit instanceof QuantifierNode ? $culprit->quantifier : null;
            $hint = null !== $detail
                ? \sprintf('Use a bounded quantifier instead of "%s".', $detail)
                : 'Ensure the lookbehind has a bounded maximum length.';

            $this->raiseSemanticError(
                'Lookbehind is unbounded. PCRE requires a bounded maximum length.',
                $position,
                'regex.lookbehind.unbounded',
                $hint,
            );
        }

        if (!$this->supportsVariableLengthLookbehind() && $min !== $max) {
            $this->raiseSemanticError(
                'Variable-length lookbehind is not supported before PHP 7.3.',
                $node->startPosition,
                'regex.lookbehind.variable_length_not_supported',
                'Use a fixed-length lookbehind or target PHP 7.3+.',
            );
        }

        if ($max > $this->lookbehindLimit) {
            $this->raiseSemanticError(
                \sprintf('Lookbehind exceeds the maximum length of %d (max=%d).', $this->lookbehindLimit, $max),
                $node->startPosition,
                'regex.lookbehind.too_long',
                \sprintf('Reduce lookbehind length or use (*LIMIT_LOOKBEHIND=%d).', $max),
            );
        }
    }

    private function supportsVariableLengthLookbehind(): bool
    {
        return $this->phpVersionId >= 70300;
    }

    private function findUnboundedLookbehindNode(NodeInterface $node): ?NodeInterface
    {
        if ($node instanceof BackrefNode || $node instanceof SubroutineNode) {
            return $node;
        }

        if ($node instanceof QuantifierNode) {
            [, $max] = $this->getQuantifierBounds($node->quantifier);
            if (-1 === $max) {
                return $node;
            }

            return $this->findUnboundedLookbehindNode($node->node);
        }

        if ($node instanceof GroupNode) {
            return $this->findUnboundedLookbehindNode($node->child);
        }

        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alt) {
                $culprit = $this->findUnboundedLookbehindNode($alt);
                if (null !== $culprit) {
                    return $culprit;
                }
            }
        }

        if ($node instanceof SequenceNode) {
            foreach ($node->children as $child) {
                $culprit = $this->findUnboundedLookbehindNode($child);
                if (null !== $culprit) {
                    return $culprit;
                }
            }
        }

        if ($node instanceof ConditionalNode) {
            return $this->findUnboundedLookbehindNode($node->condition)
                ?? $this->findUnboundedLookbehindNode($node->yes)
                ?? $this->findUnboundedLookbehindNode($node->no);
        }

        if ($node instanceof DefineNode) {
            return $this->findUnboundedLookbehindNode($node->content);
        }

        if ($node instanceof CharClassNode) {
            return $this->findUnboundedLookbehindNode($node->expression);
        }

        if ($node instanceof ClassOperationNode) {
            return $this->findUnboundedLookbehindNode($node->left) ?? $this->findUnboundedLookbehindNode($node->right);
        }

        if ($node instanceof RangeNode) {
            return $this->findUnboundedLookbehindNode($node->start) ?? $this->findUnboundedLookbehindNode($node->end);
        }

        return null;
    }

    private function assertAbsoluteReferenceExists(int $num, int $position, string $code, string $context): void
    {
        if ($num <= 0 || $num > $this->groupNumbering->maxGroupNumber) {
            $this->raiseSemanticError(
                \sprintf('%s to non-existent group: %d.', $context, $num),
                $position,
                $code,
            );
        }
    }

    private function assertRelativeReferenceExists(int $offset, int $position, string $code, string $context): void
    {
        if (0 === $offset) {
            $this->raiseSemanticError(
                \sprintf('%s relative reference cannot be zero.', $context),
                $position,
                $code,
            );
        }

        $index = $offset > 0 ? $this->captureIndex + $offset - 1 : $this->captureIndex + $offset;
        if ($index < 0 || $index >= \count($this->captureSequence)) {
            $this->raiseSemanticError(
                \sprintf('%s relative reference %d is outside the range of available capture groups.', $context, $offset),
                $position,
                $code,
                'Check group numbering or remove the relative reference.',
            );
        }
    }

    private function assertSubroutineReferenceExists(int $num, int $position, string $code, string $context): void
    {
        if ($num > 0) {
            $this->assertAbsoluteReferenceExists($num, $position, $code, $context);

            return;
        }

        $this->assertRelativeReferenceExists($num, $position, $code, $context);
    }

    /**
     * A letter written after a backslash that the lexer read as the letter
     * itself, because it names no escape it knows.
     */
    private function validateEscapedLetter(string $source, string $letter, int $start): void
    {
        $end = $start + 2;

        if (isset(self::UNRECOGNIZED_ESCAPES[$letter])) {
            $this->raiseSemanticError(
                \sprintf('Unrecognized escape sequence "\%s".', $letter),
                $end,
                'regex.escape.unrecognized',
            );
        }

        if (isset(self::UNSUPPORTED_ESCAPES[$letter])) {
            $this->raiseUnsupportedEscape($letter, $end);
        }

        if ($this->charClassDepth > 0 && isset(self::CLASS_INVALID_ESCAPES[$letter])) {
            $this->raiseSemanticError(
                \sprintf('Escape sequence \%s is invalid in a character class.', $letter),
                $end,
                'regex.charclass.invalid_escape',
            );
        }

        match ($letter) {
            'o' => $this->validateOctalBraces($source, $end),
            'x' => $this->validateHexBraces($source, $end),
            'N' => $this->validateNamedCharacterBraces($source, $end),
            default => null,
        };
    }

    /**
     * "\o" takes its digits in braces, always.
     */
    private function validateOctalBraces(string $source, int $position): void
    {
        if ('{' !== ($source[$position] ?? '')) {
            $this->raiseSemanticError(
                'Missing opening brace after \o.',
                $position,
                'regex.octal.missing_brace',
            );
        }

        $this->validateBracedDigits($source, $position + 1, self::OCTAL_DIGITS, true, 'regex.octal.invalid_digit', '\o{}');
    }

    /**
     * A bare "\x" is left alone: PCRE2 releases disagree on it.
     */
    private function validateHexBraces(string $source, int $position): void
    {
        if ('{' === ($source[$position] ?? '')) {
            $this->validateBracedDigits($source, $position + 1, self::HEX_DIGITS, true, 'regex.unicode.invalid_digit', '\x{}');
        }
    }

    /**
     * "\N{" outside a class that the lexer did not read as a named character:
     * a repeat count, a malformed "\N{U+...}", or a name PCRE2 refuses.
     */
    private function validateNamedCharacterBraces(string $source, int $position): void
    {
        // Unreachable: the lexer only leaves "\N" as an escaped letter when
        // a brace follows it, and inside a class "{U+" is checked first. It
        // guards a caller that did not check.
        if ('{' !== ($source[$position] ?? '')) {
            return;
        }

        if ($this->startsNamedCodePoint($source, $position)) {
            $digits = $position + 1 + strspn($source, self::BRACE_PADDING, $position + 1) + 2;

            if (!$this->unicodeMode) {
                // PCRE reads to the closing brace before it looks at the mode.
                $end = $digits + strspn($source, self::HEX_DIGITS.self::BRACE_PADDING, $digits);
                $this->raiseSemanticError(
                    '\N{U+...} is only supported in Unicode mode; add the "u" flag.',
                    '}' === ($source[$end] ?? '') ? $end + 1 : $end,
                    'regex.unicode_named.requires_utf',
                );
            }

            $this->validateBracedDigits($source, $digits, self::HEX_DIGITS, false, 'regex.unicode.invalid_digit', '\N{U+}');

            // Reached only when the digits are left unjudged, "\N{U+ }": a
            // well-formed "\N{U+...}" is a token of its own.
            return;
        }

        if (1 !== preg_match(self::REPEAT_COUNT, $source, $matches, 0, $position)) {
            $this->raiseUnsupportedEscape('N{', $position + 1);
        }
    }

    /**
     * Whether "{U+" follows, which PCRE2 10.48 also reads with spaces or tabs
     * after the brace: "\N{ U+41}".
     */
    private function startsNamedCodePoint(string $source, int $position): bool
    {
        return '{' === ($source[$position] ?? '')
            && 'U+' === substr($source, $position + 1 + strspn($source, self::BRACE_PADDING, $position + 1), 2);
    }

    /**
     * Read the digits of a braced escape the way PCRE2 10.48 does: spaces and
     * tabs may pad them, at least one digit is needed, and the first other
     * character must be the closing brace.
     *
     * @param bool $leadingPadding whether padding may precede the digits;
     *                             where PCRE2's reading of it is unsettled,
     *                             the escape is not judged
     */
    private function validateBracedDigits(
        string $source,
        int $position,
        string $digits,
        bool $leadingPadding,
        string $invalidDigitCode,
        string $escape,
    ): void {
        $length = \strlen($source);

        if ($leadingPadding) {
            $position += strspn($source, self::BRACE_PADDING, $position);
        } elseif ($position < $length && 1 === strspn($source, self::BRACE_PADDING, $position, 1)) {
            // "\N{U+ 41}": 10.48 refuses it but accepts "\N{U+ }", and how it
            // reads padding right after "U+" is not settled, so this is left
            // unjudged, a known false accept.
            return;
        }

        if ($position >= $length || '}' === $source[$position]) {
            $this->raiseSemanticError(
                \sprintf('Digits missing in %s.', $escape),
                $position,
                'regex.escape.digits_missing',
            );
        }

        $position += strspn($source, $digits, $position);
        $position += strspn($source, self::BRACE_PADDING, $position);

        // Unreachable from a parsed pattern: the lexer reads every braced
        // escape of this shape as a token of its own, so only a malformed one
        // gets here. It guards a caller that passes a well-formed one.
        if ($position < $length && '}' === $source[$position]) {
            return;
        }

        $this->raiseSemanticError(
            \sprintf('Invalid character in %s, or closing brace missing.', $escape),
            $position >= $length ? $length : $position + $this->characterLengthAt($source, $position),
            $invalidDigitCode,
        );
    }

    private function raiseUnsupportedEscape(string $escape, int $position): never
    {
        $this->raiseSemanticError(
            \sprintf('PCRE does not support the escape "\%s" (\F, \L, \l, \N{name}, \U and \u are not supported).', $escape),
            $position,
            'regex.escape.unsupported',
        );
    }

    /**
     * Whether a "[" read inside a class was written as is, rather than
     * quoted by a "\Q" that holds nothing else, or escaped.
     */
    private function isUnquotedClassBracket(string $source, LiteralNode $node): bool
    {
        $start = $node->startPosition;
        if (1 !== $node->endPosition - $start || '[' !== ($source[$start] ?? '')) {
            return false;
        }

        if ($start < 2 || '\Q' !== substr($source, $start - 2, 2)) {
            return true;
        }

        // "\\Q[" is an escaped backslash, a "Q" and a bracket.
        $backslashes = \strlen(substr($source, 0, $start - 1)) - \strlen(rtrim(substr($source, 0, $start - 1), '\\'));

        return 0 === $backslashes % 2;
    }

    /**
     * A "[" inside a class starts a POSIX item when one closes after it:
     * "[[:alpha:]]" is known, "[[:foo:]]" and "[[.ch.]]" are errors.
     */
    private function validateBracketInClass(string $source, int $start): void
    {
        $terminator = $this->findPosixTerminator($source, $start + 1);
        if (null === $terminator) {
            return;
        }

        if (':' !== $source[$start + 1]) {
            $this->raiseSemanticError(
                'POSIX collating elements are not supported.',
                $terminator + 2,
                'regex.posix.collating_element',
            );
        }

        $name = substr($source, $start + 2, $terminator - $start - 2);
        if (!$this->isPosixClassName($name)) {
            $this->raiseSemanticError(
                \sprintf('Invalid POSIX class: "%s".', $name),
                $terminator + 2,
                'regex.posix.invalid',
            );
        }
    }

    /**
     * "[:alpha:]" written as a class of its own is a POSIX item outside a
     * class, which PCRE refuses rather than reading as a set of characters.
     */
    private function validatePosixOutsideClass(string $source, int $start): void
    {
        $terminator = $this->findPosixTerminator($source, $start + 1);
        if (null === $terminator) {
            return;
        }

        if (':' === $source[$start + 1]) {
            $this->raiseSemanticError(
                'POSIX named classes are supported only within a class.',
                $terminator + 2,
                'regex.posix.outside_class',
            );
        }

        $this->raiseSemanticError(
            'POSIX collating elements are not supported.',
            $terminator + 2,
            'regex.posix.collating_element',
        );
    }

    /**
     * PCRE2's check_posix_syntax(): after "[:", "[." or "[=", find the
     * matching ":]", ".]" or "=]" before any "]" or a new "[:"-like opener.
     *
     * @return int|null the offset of the closing ":", "." or "="
     */
    private function findPosixTerminator(string $source, int $offset): ?int
    {
        $terminator = $source[$offset] ?? '';
        if (':' !== $terminator && '.' !== $terminator && '=' !== $terminator) {
            return null;
        }

        $length = \strlen($source);
        for ($position = $offset + 1; $length - $position >= 2; $position++) {
            $char = $source[$position];
            $next = $source[$position + 1];

            if ('\\' === $char && (']' === $next || '\\' === $next)) {
                $position++;

                continue;
            }

            if (('[' === $char && $terminator === $next) || ']' === $char) {
                return null;
            }

            if ($terminator === $char && ']' === $next) {
                return $position;
            }
        }

        return null;
    }

    private function isPosixClassName(string $name): bool
    {
        return isset(self::VALID_POSIX_CLASSES[str_starts_with($name, '^') ? substr($name, 1) : $name]);
    }

    /**
     * The value an endpoint gives the range. Without Unicode mode PCRE reads
     * the pattern byte by byte, so a multibyte character written as is ends
     * a range start with its last byte and begins a range end with its first:
     * "[\u{e9}-\xe0]" is the range from 0xA9 to 0xE0.
     *
     * @param bool $isStart whether the node is the start of the range
     */
    private function rangeEndpointCodePoint(NodeInterface $node, bool $isStart): ?int
    {
        if ($node instanceof LiteralNode) {
            // Unreachable from a parsed pattern: the parser never gives a
            // range an empty endpoint. It guards a tree built by hand.
            if ('' === $node->value) {
                return null;
            }

            if (!$this->unicodeMode) {
                return \ord($isStart ? $node->value[\strlen($node->value) - 1] : $node->value[0]);
            }

            $codePoint = mb_ord($node->value, 'UTF-8');

            return false === $codePoint ? \ord($node->value) : $codePoint;
        }

        if ($node instanceof CharLiteralNode || $node instanceof ControlCharNode) {
            return $node->codePoint >= 0 ? $node->codePoint : null;
        }

        // Unreachable from a parsed pattern: guardRangeEndpoint() and
        // isSingleCharNode() leave only the node types above as endpoints.
        return null;
    }

    private function describeRange(RangeNode $node): string
    {
        if (null !== $this->source) {
            return substr($this->source, $node->startPosition, $node->getEndPosition() - $node->startPosition);
        }

        return $this->describeRangeEndpoint($node->start).'-'.$this->describeRangeEndpoint($node->end);
    }

    private function describeRangeEndpoint(NodeInterface $node): string
    {
        return match (true) {
            $node instanceof LiteralNode => $node->value,
            $node instanceof CharLiteralNode => $node->originalRepresentation,
            $node instanceof ControlCharNode => '\c'.$node->char,
            default => '?',
        };
    }

    /**
     * The width of the character at an offset, which is how far PCRE steps
     * past it: one byte, or a whole UTF-8 sequence in Unicode mode.
     */
    private function characterLengthAt(string $source, int $position): int
    {
        if (!$this->unicodeMode) {
            return 1;
        }

        $byte = \ord($source[$position]);

        return match (true) {
            $byte >= 0xF0 => 4,
            $byte >= 0xE0 => 3,
            $byte >= 0xC0 => 2,
            default => 1,
        };
    }

    private function raiseSemanticError(string $message, int $position, string $code, ?string $hint = null): never
    {
        throw new SemanticErrorException(
            $message,
            $position,
            $this->pattern,
            null,
            $code,
            $hint,
        );
    }

    private function ensureGroupNumberingInitialized(): void
    {
        if (!isset($this->groupNumbering)) {
            $this->groupNumbering = new GroupNumbering(0, [], []);
            $this->captureSequence = [];
            $this->captureIndex = 0;
            $this->lookbehindLimit = $this->maxLookbehindLength;
        }
    }

    /**
     * Whether a digit string that does not resolve to a capture group is a
     * valid octal escape under PCRE rules: it must start with an octal digit,
     * and the leading run of up to three octal digits must encode <= \377
     * unless the pattern is in Unicode mode, where "\400" is U+0100.
     */
    private function isValidOctalFallback(string $digits): bool
    {
        if (1 !== preg_match('/^([0-7]{1,3})/', $digits, $octal)) {
            return false;
        }

        return $this->unicodeMode || octdec($octal[1]) <= 0xFF;
    }
}

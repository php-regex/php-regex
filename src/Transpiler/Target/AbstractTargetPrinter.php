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

namespace PHPRegex\Transpiler\Target;

use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\QuantifierType;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Transpiler\TranspileContext;
use PHPRegex\Transpiler\TranspileException;

/**
 * What every target's compiler needs, whatever dialect it writes.
 *
 * The dialects differ in almost everything they emit, so only what they
 * genuinely share lives here: the context they compile against, how they
 * refuse a construct, the options in force where the printer stands (a
 * pattern's flags, then each "(?flags)" up to the end of its group), the
 * swap of greedy and lazy under /U, a repeated assertion read once, and the
 * spaces PCRE reads under u.
 *
 * @extends AbstractNodeVisitor<string>
 *
 * @internal
 */
abstract class AbstractTargetPrinter extends AbstractNodeVisitor
{
    /**
     * What "\s" matches under u, which PHP reads with UCP: "\h" and "\v"
     * together, the same characters in every PCRE2, written for a class.
     */
    protected const UNICODE_SPACES = '\t-\r \x85\xA0\u1680\u180E\u2000-\u200A\u2028\u2029\u202F\u205F\u3000';

    /**
     * What "\h" matches, written for a class.
     */
    protected const HORIZONTAL_SPACES = '\x09\x20\xA0\u1680\u180E\u2000-\u200A\u202F\u205F\u3000';

    /**
     * What "\v" matches, written for a class.
     */
    protected const VERTICAL_SPACES = '\x0A-\x0D\x85\u2028\u2029';

    private const LOOKAROUNDS = [GroupType::LookaheadPositive, GroupType::LookaheadNegative, GroupType::LookbehindPositive, GroupType::LookbehindNegative];

    /**
     * Whether /i holds where the printer stands.
     */
    protected bool $caseless = false;

    /**
     * Whether /s holds where the printer stands: the dot takes "\n".
     */
    protected bool $dotAll = false;

    /**
     * Whether /m holds where the printer stands: "^" and "$" read lines.
     */
    protected bool $multiline = false;

    /**
     * Whether /U holds where the printer stands: greedy and lazy swap.
     */
    protected bool $ungreedy = false;

    /**
     * Whether /D is set: "$" reads the end of the subject alone.
     */
    protected bool $dollarEndOnly = false;

    /**
     * Whether the pattern has u, which PHP compiles with UCP: "\d", "\s",
     * "\w" and "\b" read Unicode.
     */
    protected bool $unicode = false;

    /**
     * The pattern's own flags.
     */
    protected string $patternFlags = '';

    public function __construct(protected readonly TranspileContext $context) {}

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node): string
    {
        return $this->unsupported('Perl extended character classes "(?[...])" are not supported by this target.', $node);
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node): string
    {
        return $this->unsupported('Perl extended character classes "(?[...])" are not supported by this target.', $node);
    }

    /**
     * Whether the group is a bare "(?flags)", which sets its flags for what
     * follows it up to the end of the enclosing group, the alternatives
     * after it included. "(?i:)" holds the same empty child one byte longer.
     */
    public static function isBareOptionSetting(NodeInterface $node): bool
    {
        return $node instanceof GroupNode
            && GroupType::InlineFlags === $node->type
            && $node->getEndPosition() - $node->getStartPosition() === \strlen((string) $node->flags) + 3;
    }

    /**
     * Refuse a construct the target has no way to express.
     *
     * @throws TranspileException
     */
    protected function unsupported(string $message, NodeInterface $node): string
    {
        throw new TranspileException(
            $message,
            $node->getStartPosition(),
            $this->context->sourcePattern,
        );
    }

    /**
     * Read the options the pattern sets for its whole length.
     */
    protected function enterPattern(RegexNode $node): void
    {
        $this->patternFlags = $node->flags;
        $this->caseless = str_contains($node->flags, 'i');
        $this->dotAll = str_contains($node->flags, 's');
        $this->multiline = str_contains($node->flags, 'm');
        $this->ungreedy = str_contains($node->flags, 'U');
        $this->dollarEndOnly = str_contains($node->flags, 'D');
        $this->unicode = str_contains($node->flags, 'u');
    }

    /**
     * The options in force, to be restored where a group ends.
     *
     * @return array{bool, bool, bool, bool}
     */
    protected function scope(): array
    {
        return [$this->caseless, $this->dotAll, $this->multiline, $this->ungreedy];
    }

    /**
     * @param array{bool, bool, bool, bool} $scope
     */
    protected function restoreScope(array $scope): void
    {
        [$this->caseless, $this->dotAll, $this->multiline, $this->ungreedy] = $scope;
    }

    /**
     * Apply an inline "(?flags)" and say whether the target carries every
     * letter it holds: a leading "^" unsets i, m and s, then the letters
     * before "-" are set and those after it unset. "x" is applied by the
     * parser, so a target that takes it has nothing left to do; "xx" only
     * goes through where the target says so, and a letter the target does
     * not list never does.
     *
     * @param string $carried the letters the target can carry
     */
    protected function applyInlineFlags(string $flags, string $carried, bool $doubleExtended = false): bool
    {
        if ('' !== trim($flags, '^-'.$carried) || (!$doubleExtended && str_contains($flags, 'xx'))) {
            return false;
        }

        if (str_starts_with($flags, '^')) {
            $this->caseless = $this->dotAll = $this->multiline = false;
        }

        [$set, $unset] = explode('-', ltrim($flags, '^').'-', 3);
        $this->caseless = (str_contains($set, 'i') || $this->caseless) && !str_contains($unset, 'i');
        $this->dotAll = (str_contains($set, 's') || $this->dotAll) && !str_contains($unset, 's');
        $this->multiline = (str_contains($set, 'm') || $this->multiline) && !str_contains($unset, 'm');
        $this->ungreedy = (str_contains($set, 'U') || $this->ungreedy) && !str_contains($unset, 'U');

        return true;
    }

    /**
     * A sequence's items one after the other. A numbered backreference
     * followed by a digit, as "\\1" before a "2" a dropped comment left
     * after it, is closed in a group: "\\12" would name group 12.
     *
     * @param list<NodeInterface> $children
     */
    protected function printSequence(array $children): string
    {
        $result = '';
        $reference = null;
        foreach ($children as $child) {
            $printed = $child->accept($this);
            if ('' === $printed) {
                continue;
            }

            if (null !== $reference && Ascii::isDigit($printed[0])) {
                $result = substr($result, 0, $reference).'(?:'.substr($result, $reference).')';
            }

            $reference = $child instanceof BackrefNode && 1 === LibraryPcre::match('/\\\\\d+$/', $printed) ? \strlen($result) : null;
            $result .= $printed;
        }

        return $result;
    }

    /**
     * The lazy mark of a quantifier: /U and "(?U)" swap greedy and lazy.
     */
    protected function lazySuffix(QuantifierNode $node): string
    {
        return (QuantifierType::Lazy === $node->type) !== $this->ungreedy ? '?' : '';
    }

    /**
     * A repeated assertion, or null for any other repeated item. PCRE reads
     * an assertion repeated as one that holds once, and one that may be
     * left out ("?", "*", "{0,n}") as none at all, but for the groups it
     * captures: those are refused, as the targets either reject a repeated
     * assertion or leave its groups unset.
     *
     * @throws TranspileException
     */
    protected function repeatedAssertion(QuantifierNode $node): ?string
    {
        $assertion = $node->node;
        $bounds = QuantifierBounds::parse($node->quantifier);
        if (!$assertion instanceof GroupNode || !\in_array($assertion->type, self::LOOKAROUNDS, true) || null === $bounds) {
            return null;
        }

        if ($bounds->min > 0) {
            return $assertion->accept($this);
        }

        if (self::holdsCapture($assertion->child)) {
            return $this->unsupported('A capturing group in an assertion that may be left out cannot be carried into this target: PCRE sets it whenever the assertion holds.', $node);
        }

        return '';
    }

    /**
     * Refuse a non-atomic assertion, "(*napla:...)" or "(?*...)": the
     * targets' assertions are atomic.
     *
     * @throws TranspileException
     */
    protected function refuseNonAtomic(GroupNode $node, string $target): void
    {
        if ('*' === $node->flags && \in_array($node->type, self::LOOKAROUNDS, true)) {
            $this->unsupported('Non-atomic assertions are not supported in '.$target.'.', $node);
        }
    }

    protected static function holdsCapture(NodeInterface $node): bool
    {
        if ($node instanceof GroupNode && (GroupType::Capturing === $node->type || GroupType::Named === $node->type)) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::holdsCapture($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A class with members that are the complement of a set, as "\S" under
     * u in "[\S\d]", where the target has no class operation to write one:
     * "[\S\d]" is "(?:[\d]|[^spaces])", "[^\S\d]" is the characters in
     * every complemented set and none of the others, "(?:(?![\d])[spaces])".
     *
     * @param string                 $members     the other members, as the target writes them in a class
     * @param non-empty-list<string> $complements the sets the complemented members leave out, as written in a class
     */
    protected static function composedClass(string $members, array $complements, bool $negated): string
    {
        if (!$negated) {
            $parts = '' === $members ? [] : ['['.$members.']'];
            foreach ($complements as $complement) {
                $parts[] = '[^'.$complement.']';
            }

            return '(?:'.implode('|', $parts).')';
        }

        $last = array_pop($complements);
        $class = '' === $members ? '' : '(?!['.$members.'])';
        foreach ($complements as $complement) {
            $class .= '(?=['.$complement.'])';
        }

        return '(?:'.$class.'['.$last.'])';
    }

    /**
     * Without u PCRE reads bytes, where the targets read characters: a
     * multibyte character written whole stays that character, the PHP
     * string holding it as UTF-8 text, but a byte above 0x7F on its own, as
     * an escape or as invalid UTF-8, has no equivalent there.
     *
     * @throws TranspileException
     */
    protected function refuseALoneByte(string $text, NodeInterface $node): void
    {
        if (!str_contains($this->context->sourceFlags, 'u') && 1 === LibraryPcre::match('/[\x80-\xFF]/', $text) && !mb_check_encoding($text, 'UTF-8')) {
            $this->refuseByte($node);
        }
    }

    /**
     * @throws TranspileException
     */
    protected function refuseAByteEscape(int $codePoint, NodeInterface $node): void
    {
        if ($codePoint > 0x7F && !str_contains($this->context->sourceFlags, 'u')) {
            $this->refuseByte($node);
        }
    }

    /**
     * Drop the whitespace /x allows inside a quantifier: "{1, 3}" is "{1,3}"
     * to every target, and none of them read /x the way PCRE does. "{,3}",
     * which PCRE2 reads as "{0,3}" from 10.43, is written so.
     */
    protected function normalizeQuantifier(string $quantifier): string
    {
        $quantifier = LibraryPcre::replace('/\s+/', '', $quantifier) ?? $quantifier;

        return str_starts_with($quantifier, '{,') ? '{0,'.substr($quantifier, 2) : $quantifier;
    }

    /**
     * @throws TranspileException
     */
    private function refuseByte(NodeInterface $node): void
    {
        $this->unsupported('A byte above 0x7F has no equivalent in this target, which reads characters, not bytes: without the u flag PCRE matches that byte alone. Add u, or write the character itself.', $node);
    }
}

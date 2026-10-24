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

namespace PHPRegex\Parser\Validation;

use PHPRegex\Parser\Analysis\GroupIndex;
use PHPRegex\Parser\Analysis\GroupNumbering;
use PHPRegex\Parser\Engine\LibraryPcre;
use PHPRegex\Parser\ErrorCode;
use PHPRegex\Parser\Exception\SemanticErrorException;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\PcreFeature;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Syntax\NameCharacters;
use PHPRegex\Parser\Syntax\PcreNumber;
use PHPRegex\Parser\Syntax\VersionCondition;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Parser\Text\NearMiss;

/**
 * The checks of what names a group: a back reference, a call, the
 * condition of a conditional, the groups a call returns or a substring scan
 * matches. Each is read as PCRE reads it, its name or its number, and the
 * group it names looked up.
 *
 * A reference is refused two ways, which is why these checks stand apart
 * from the validator: one that cannot be read is refused at once, but one
 * to a group the pattern does not have is resolved by PCRE once the whole
 * pattern is read, after errors written further on. Such an error goes to
 * the validator, which knows how far the walk has gone, and decides when it
 * is raised. Where PCRE reports it, an offset that moved between releases,
 * is worked out here.
 *
 * An instance reads one text, the pattern or the payload of a "(*pla:...)"
 * parsed apart, and reports offsets in the whole pattern.
 *
 * @internal
 */
final class ReferenceChecks
{
    /**
     * A group name in Unicode mode, and in any mode once read by the lexer.
     */
    private const GROUP_NAME = '[_\p{L}][_\p{L}\p{Nd}]*+';

    /**
     * @param GroupIndex                             $groups    the groups of the pattern
     * @param GroupNumbering                         $numbering their numbers and names
     * @param PcreTarget                             $target    the PHP and PCRE2 judged
     * @param \Closure(SemanticErrorException): void $missing   told of a reference to a group
     *                                                          the pattern does not have,
     *                                                          which PCRE refuses once the
     *                                                          whole pattern is read
     * @param string|null                            $pattern   the whole pattern, which the
     *                                                          errors quote
     * @param string|null                            $source    the text the positions of the
     *                                                          references point into; null
     *                                                          when nothing says what was
     *                                                          written
     * @param int                                    $offset    where that text starts in the
     *                                                          pattern
     * @param bool                                   $utf       whether PCRE reads the pattern
     *                                                          as UTF-8
     */
    public function __construct(
        private readonly GroupIndex $groups,
        private readonly GroupNumbering $numbering,
        private readonly PcreTarget $target,
        private readonly \Closure $missing,
        private readonly ?string $pattern = null,
        private readonly ?string $source = null,
        private readonly int $offset = 0,
        private readonly bool $utf = false,
    ) {}

    /**
     * The same checks on another text of the pattern, which starts at
     * $offset in it.
     */
    public function reading(?string $source, int $offset): self
    {
        return new self($this->groups, $this->numbering, $this->target, $this->missing, $this->pattern, $source, $offset, $this->utf);
    }

    /**
     * A back reference, "\1", "\g{-1}", "\k<name>", or the reference a
     * condition tests, "(?(1)", "(?(<name>)".
     */
    public function backref(BackrefNode $node): void
    {
        $this->validateReferenceName($node);

        $ref = $node->ref;

        // A reference by number, written with a backslash.
        if (1 === LibraryPcre::match('/^\\\\(\d++)$/', $ref, $matches)) {
            $num = (int) $matches[1];
            if (0 === $num) {
                $this->raise(
                    'Backreference \\0 is not valid.',
                    $node->startPosition,
                    ErrorCode::BackrefZero,
                    'Use \\g<0> for recursion to the whole pattern, or remove the reference.',
                );
            }
            $this->guardGroupNumberSize($node, '', $matches[1]);

            // "\NN" that PCRE reads as an octal escape is parsed as one: a
            // reference by number here names a group or none.
            if ($num > $this->numbering->maxGroupNumber) {
                $this->raiseMissingReference(
                    \sprintf('Backreference to non-existent group: \\%d.', $num),
                    $this->missingReferenceOffset($node),
                    ErrorCode::BackrefMissingGroup,
                );
            }

            return;
        }

        // Relative conditions, "(?(-1)...)" and "(?(+1)...)", count groups
        // from where they stand.
        if (1 === LibraryPcre::match('/^[+-]\d++$/', $ref)) {
            $this->assertRelativeReferenceExists((int) $ref, $this->missingReferenceOffset($node), ErrorCode::BackrefRelative, 'Condition', $node);

            return;
        }

        // A condition on a group by number, "(?(2)...)".
        if (1 === LibraryPcre::match('/^(\d++)$/', $ref, $matches)) {
            $num = (int) $matches[1];
            if (0 === $num) {
                $this->raise(
                    'Backreference 0 is not valid.',
                    $this->missingReferenceOffset($node),
                    ErrorCode::BackrefZero,
                    'Use \\g<0> for recursion to the whole pattern, or remove the reference.',
                );
            }
            if ($num > $this->numbering->maxGroupNumber) {
                $this->raiseMissingReference(
                    \sprintf('Backreference to non-existent group: %d.', $num),
                    $this->missingReferenceOffset($node),
                    ErrorCode::BackrefMissingGroup,
                );
            }

            return;
        }

        // A reference by name, "\k<name>", "\k{name}", "\k'name'".
        if (1 === LibraryPcre::match('/^\\\\k[<{\'](?<name>'.self::GROUP_NAME.')[>}\']$/u', $ref, $matches)) {
            $name = $matches['name'];
            $this->guardByteModeName($node, $name, ErrorCode::GroupNameUnterminated);
            if (!$this->numbering->hasNamedGroup($name)) {
                $suggestion = $this->nameSuggestions($name);
                $this->raiseMissingReference(
                    \sprintf('Backreference to non-existent named group: "%s".', $name).(null === $suggestion ? '' : ' '.$suggestion),
                    $this->missingReferenceOffset($node),
                    ErrorCode::BackrefMissingNamedGroup,
                    $suggestion,
                );
            }

            return;
        }

        // A condition on a group by its bare name, "(?(name)...)".
        if ($this->isBareName($ref)) {
            if (!$this->numbering->hasNamedGroup($ref)) {
                $suggestion = $this->nameSuggestions($ref);
                $this->raiseMissingReference(
                    \sprintf('Backreference to non-existent named group: "%s".', $ref).(null === $suggestion ? '' : ' '.$suggestion),
                    $node->startPosition,
                    ErrorCode::BackrefMissingNamedGroup,
                    $suggestion,
                );
            }

            return;
        }

        // "\g1", "\g{1}", "\g'1'", "\g{-1}".
        if (1 === LibraryPcre::match('/^\\\\g(?:\{([0-9+-]++)\}|\'([0-9+-]++)\'|([0-9+-]++))$/', $ref, $matches)) {
            $numStr = ('' !== $matches[1]) ? $matches[1] : (('' !== ($matches[2] ?? '')) ? $matches[2] : ($matches[3] ?? ''));

            // "\g-" or "\g+" with no digit is no reference at all: PCRE stops
            // on the sign.
            if ('' !== ($matches[3] ?? '') && '' === ltrim($numStr, '+-')) {
                $this->raise(
                    '\g is not followed by a braced, angle-bracketed or quoted name or number, or by a plain number.',
                    $node->startPosition + 2,
                    ErrorCode::BackrefInvalidSyntax,
                );
            }
            if (1 === LibraryPcre::match('/^[+-]?0++$/', $numStr)) {
                $this->raise(
                    'Backreference \\g{0} is not valid.',
                    $this->missingReferenceOffset($node),
                    ErrorCode::BackrefZero,
                    'Use \\g<0> for recursion to the whole pattern.',
                );
            }

            if (1 === LibraryPcre::match('/^([+-]?)(\d++)$/', $numStr, $number)) {
                $this->guardGroupNumberSize($node, $number[1], $number[2]);
            }

            if (str_starts_with($numStr, '+') || str_starts_with($numStr, '-')) {
                $offset = (int) $numStr;
                $this->assertRelativeReferenceExists($offset, $this->missingReferenceOffset($node), ErrorCode::BackrefRelative, 'Backreference', $node);

                return;
            }

            $num = (int) $numStr;
            if ($num > $this->numbering->maxGroupNumber) {
                $this->raiseMissingReference(
                    \sprintf('Backreference to non-existent group: \\g{%d}.', $num),
                    $this->missingReferenceOffset($node),
                    ErrorCode::BackrefMissingGroup,
                );
            }

            return;
        }

        // "\g{name}" is kept as "\k{name}": the message quotes the source.
        $written = null === $this->source ? $ref : substr($this->source, $node->startPosition, $node->getEndPosition() - $node->startPosition);

        $this->raise(
            \sprintf('Invalid backreference syntax: "%s".', '' === $written ? $ref : $written),
            $this->missingReferenceOffset($node),
            ErrorCode::BackrefInvalidSyntax,
        );
    }

    /**
     * A call, "(?1)", "(?-1)", "(?&name)", "\g<name>", and the groups it
     * returns, "(?1(2,<name>))".
     */
    public function subroutine(SubroutineNode $node): void
    {
        $this->validateSubroutineReference($node);

        if ([] !== $node->returnedGroups) {
            $this->listedGroups($node, $node->returnedGroups, $node->startPosition + 1);
        }
    }

    /**
     * A condition that tests a group by its bare name, "(?(name)...)": the
     * pattern has to name a group so.
     */
    public function conditionName(BackrefNode $condition): void
    {
        $ref = $condition->ref;
        if ($this->isBareName($ref) && !$this->numbering->hasNamedGroup($ref)) {
            $this->raiseMissingReference(
                \sprintf('Invalid conditional construct. Condition must be a group reference, lookaround, or (DEFINE). The pattern has no group named "%s".', $ref),
                $this->missingReferenceOffset($condition),
                ErrorCode::ConditionalMissingGroup,
                $this->nameSuggestions($ref),
            );
        }
    }

    /**
     * A recursion condition, "(?(R)", "(?(R2)", "(?(R&name)": whether the
     * condition is one, and so was judged here.
     */
    public function recursionCondition(SubroutineNode $condition): bool
    {
        $ref = $condition->reference;
        if ('R' === $ref || '0' === $ref) {
            // A recursion to the whole pattern is always a valid condition.
            return true;
        }

        if (1 === LibraryPcre::match('/^R-?\d++$/', $ref)) {
            $num = (int) substr($ref, 1);
            // PCRE reads "R2" as a name, then as a group number digit by
            // digit, and stops on the digit that takes it over 65535.
            $overflow = strspn($ref, 'R') + $this->digitsWithinGroupLimit(ltrim($ref, 'R'));
            if ($overflow < \strlen($ref)) {
                $this->raise(
                    \sprintf('Group number %s in a recursion condition is too big: PCRE takes at most 65535.', substr($ref, 1)),
                    $condition->startPosition + $overflow,
                    ErrorCode::GroupNumberTooBig,
                );
            }
            // Group 0 is the whole pattern: "R0" asks whether any recursion runs.
            if (0 !== $num) {
                $this->assertSubroutineReferenceExists($num, $this->missingReferenceOffset($condition), ErrorCode::SubroutineRecursion, 'Recursion condition');
            }

            return true;
        }

        if (str_starts_with($ref, 'R&')) {
            // "(?(R&name)...)": the group has to exist.
            if (!$this->numbering->hasNamedGroup(substr($ref, 2))) {
                $this->raiseMissingReference(
                    \sprintf('Recursion condition to non-existent named group: "%s".', substr($ref, 2)),
                    $this->missingReferenceOffset($condition),
                    ErrorCode::SubroutineMissingNamedGroup,
                    \sprintf('Name a group "%s" with (?<%1$s>...), or test a group the pattern names.', substr($ref, 2)),
                );
            }

            return true;
        }

        return false;
    }

    /**
     * Each group a call returns, or a substring scan matches, must exist; the
     * list opens at the first "(" from $from. PCRE reports a missing group
     * where it is named, a name past its "<" or quote.
     *
     * @param list<string> $groups
     */
    public function listedGroups(NodeInterface $node, array $groups, int $from): void
    {
        if (null === $this->source) {
            return;
        }

        $at = (int) strpos($this->source, '(', $from) + 1;
        foreach ($groups as $group) {
            $exists = match (true) {
                Ascii::isDigit($group) => (int) $group <= $this->numbering->maxGroupNumber,
                str_starts_with($group, '+') => ($this->groups->nextNumberAt($node) ?? 1) - 1 + (int) $group <= $this->numbering->maxGroupNumber,
                str_starts_with($group, '<'), str_starts_with($group, "'") => $this->numbering->hasNamedGroup(substr($group, 1, -1)),
                // A relative number back is refused as it is read.
                default => true,
            };

            if (!$exists) {
                $this->raiseMissingReference(
                    \sprintf('Group %s is listed but does not exist.', $group),
                    Ascii::isDigit($group) || str_starts_with($group, '+') ? $at : $at + 1,
                    ErrorCode::GroupListMissingGroup,
                );
            }

            $at += \strlen($group) + 1;
        }
    }

    private function validateSubroutineReference(SubroutineNode $node): void
    {
        $this->validateReferenceName($node);

        $ref = $node->reference;

        if ('R' === $ref || '0' === $ref) {
            return; // (?R) or (?0) is always valid.
        }

        if (str_starts_with($ref, 'R')) {
            $numPart = substr($ref, 1);

            if (Ascii::isDigit($numPart)) {
                $num = (int) $numPart;
                $this->assertAbsoluteReferenceExists($num, $this->missingReferenceOffset($node), ErrorCode::SubroutineRecursion, 'Recursion condition');

                return;
            }

            if (str_starts_with($numPart, '-') && Ascii::isDigit(substr($numPart, 1))) {
                $num = (int) $numPart;
                $this->assertRelativeReferenceExists($num, $node->startPosition, ErrorCode::SubroutineRecursion, 'Recursion condition');
            }
        }

        // "(?-0)" and "(?+0)" point nowhere: PCRE refuses a relative zero,
        // at the ")" of "(?-0)" and at the "<" of "\g<-0>".
        if ('-0' === $ref || '+0' === $ref) {
            $this->raise(
                \sprintf('Subroutine call relative reference cannot be zero: "%s".', $ref),
                'g' === $node->syntax ? $node->startPosition + 2 : max($node->startPosition, $node->getEndPosition() - 1),
                ErrorCode::SubroutineRelativeZero,
            );
        }

        // Numeric reference: (?1), (?-1), (?+1), \g<-1>, \g<+1>
        if (1 === LibraryPcre::match('/^([+-]?)(\d+)$/', $ref, $matches)) {
            $this->guardGroupNumberSize($node, $matches[1], $matches[2]);
            $num = (int) $ref;
            if (0 === $num) {
                return; // (?0) is an alias for (?R)
            }
            if (str_starts_with($ref, '+') || str_starts_with($ref, '-')) {
                $this->assertRelativeReferenceExists($num, $this->missingReferenceOffset($node), ErrorCode::SubroutineRelativeMissing, 'Subroutine call', $node);
            } else {
                $this->assertAbsoluteReferenceExists($num, $this->missingReferenceOffset($node), ErrorCode::SubroutineMissingGroup, 'Subroutine call');
            }

            return;
        }

        // Named reference: (?&name), (?P>name), \g<name>
        $this->guardByteModeName($node, $ref, ErrorCode::GroupUnclosed);
        if (!$this->numbering->hasNamedGroup($ref)) {
            $this->raiseMissingReference(
                \sprintf('Subroutine call to non-existent named group: "%s".', $ref),
                $this->missingReferenceOffset($node),
                ErrorCode::SubroutineMissingNamedGroup,
                \sprintf('Name a group "%s" with (?<%1$s>...), or call a group the pattern names.', $ref),
            );
        }
    }

    /**
     * "Did you mean: name?" for the group names near $name, or null.
     */
    private function nameSuggestions(string $name): ?string
    {
        $suggestions = NearMiss::within($name, array_map(strval(...), array_keys($this->numbering->namedGroups)));

        return [] === $suggestions ? null : 'Did you mean: '.implode(', ', $suggestions).'?';
    }

    private function isBareName(string $ref): bool
    {
        return 1 === LibraryPcre::match('/^'.self::GROUP_NAME.'$/u', $ref);
    }

    /**
     * The name of "\k{...}", "\k<...>" and "\k'...'", and of "\g{...}",
     * "\g<...>" and "\g'...'" where no number stands, as PCRE2's read_name()
     * reads it: past the spaces and tabs after "{" from 10.43, a digit first
     * is refused on it, past it from 10.47; a character no name holds first
     * leaves no name; else the name ends at the first character no name
     * holds, and what closes it must follow, past the spaces and tabs before
     * "}" from 10.43. A name holds ASCII letters, digits and underscores,
     * and under UTF from 10.33 any letter or decimal digit. Before 10.43 a
     * space after the number of "\g{" ends "\g", whose number PCRE reads
     * first.
     */
    private function validateReferenceName(BackrefNode|SubroutineNode $node): void
    {
        $source = $this->source ?? '';
        $start = $node->startPosition;
        $closer = ['{' => '}', '<' => '>', "'" => "'"][$source[$start + 2] ?? ''] ?? null;
        if (null === $closer || 1 !== LibraryPcre::match('/\G\\\\[gk]/', $source, $escape, 0, $start)) {
            return;
        }

        $letter = $source[$start + 1];
        $padded = '}' === $closer && $this->target->supports(PcreFeature::PaddedBracedEscapes);
        $nameStart = $start + 3 + ($padded ? strspn($source, " \t", $start + 3) : 0);
        $digits = [];
        if ('g' === $letter && 1 === LibraryPcre::match('/\G([+-]?)(\d++)/', $source, $digits, 0, $nameStart)) {
            // Only "\g{...}" is a back reference, whose number is read here.
            if ($node instanceof BackrefNode && '}' === $closer && !$padded && 1 === strspn($source, " \t", $nameStart + \strlen($digits[0]), 1)) {
                $this->raise(
                    'Spaces inside \g{} need PCRE2 10.43, which PHP bundles from 8.4.',
                    $start + 2,
                    $this->braceNumberError($node, $digits[1], $digits[2]) ?? ErrorCode::BackrefInvalidSyntax,
                    'Remove the spaces inside the braces, or target PHP 8.4+.',
                );
            }

            return;
        }

        if ($this->utf && $this->target->supports(PcreFeature::NonAsciiGroupNames)) {
            $digit = substr($source, $nameStart, NameCharacters::digitAt($source, $nameStart, $this->target));
            $read = '' === $digit ? substr($source, $nameStart, NameCharacters::end($source, $nameStart, $this->target) - $nameStart) : $digit;
        } else {
            LibraryPcre::match('/\G(?:([0-9])|[_A-Za-z0-9]*+)/', $source, $name, 0, $nameStart);
            $read = $name[0] ?? '';
            $digit = $name[1] ?? '';
        }

        $closeAt = $nameStart + \strlen($read);
        $closeAt += $padded ? strspn($source, " \t", $closeAt) : 0;

        if ('' === $digit && '' !== $read && $closer === ($source[$closeAt] ?? '')) {
            return;
        }

        $written = substr($source, $start, $node->getEndPosition() - $start);
        [$offset, $code, $message] = match (true) {
            '' !== $digit => [$this->target->faultOffset($nameStart + \strlen($digit), \strlen($digit)), ErrorCode::GroupNameInvalid, \sprintf('Invalid reference "%s": a group name must not start with a digit.', $written)],
            '' === $read => [$nameStart, ErrorCode::GroupNameExpected, \sprintf('Invalid reference "%s": a group name is expected at position %d.', $written, $nameStart)],
            default => [$closeAt, ErrorCode::GroupNameUnterminated, \sprintf('Invalid reference "%s": the group name ends at position %d, and no "%s" closes it there.', $written, $closeAt, $closer)],
        };

        // A space PCRE2 stops on inside the braces is padding it does not
        // read before 10.43.
        $unreadPadding = '}' === $closer && !$padded && 1 === strspn($source, " \t", $offset, 1);
        $this->raise(
            $unreadPadding ? \sprintf('Spaces inside \%s{} need PCRE2 10.43, which PHP bundles from 8.4.', $letter) : $message,
            $offset,
            $code,
            $unreadPadding ? 'Remove the spaces inside the braces, or target PHP 8.4+.' : null,
        );
    }

    /**
     * The error PCRE gives the number of "\g{...}" as it reads it, before
     * what follows: past 65535, a forward one counted from the groups opened
     * before it included; a relative zero; a backward one past the groups
     * opened. Null for a number it reads.
     */
    private function braceNumberError(BackrefNode $node, string $sign, string $digits): ?ErrorCode
    {
        $value = (int) $digits;
        $before = ($this->groups->nextNumberAt($node) ?? 1) - 1;

        return match (true) {
            PcreNumber::exceeds($digits) || ('+' === $sign && $value + $before > PcreNumber::MAX) => ErrorCode::GroupNumberTooBig,
            '' !== $sign && 0 === $value => ErrorCode::BackrefZero,
            '-' === $sign && $value > $before => ErrorCode::BackrefRelative,
            default => null,
        };
    }

    /**
     * Where PCRE reports a reference to a group that does not exist.
     *
     * A name is looked up where it starts. A number is checked once the
     * whole reference is read, except when it fails while being read: a
     * relative reference back past the first group, or a relative zero,
     * stops right after "\g" when bracketed and after its digits when not.
     * "(?1)" is reported at its ")", and a condition "(?(2)" two characters
     * before the end of its number, where PCRE records it.
     */
    private function missingReferenceOffset(BackrefNode|SubroutineNode $node): int
    {
        $start = $node->startPosition;
        $end = $node->getEndPosition();

        if (null === $this->source || $end <= $start) {
            return $start; // Unreachable from a parsed pattern: only "(?(R)" is empty, and it always exists.
        }

        $text = substr($this->source, $start, $end - $start);

        // "\g{2}", "\g-1", "\k<name>", "\g<name>", "\g'1'", ...
        if (1 === LibraryPcre::match('/^\\\\([gk])([{<\'])?\s*+([+-]?)(\d*)/', $text, $matches)) {
            // A name, past the spaces and tabs a brace takes from 10.43.
            if ('' === $matches[4]) {
                return $start + 3 + ('{' === $matches[2] && $this->target->supports(PcreFeature::PaddedBracedEscapes) ? strspn($text, " \t", 3) : 0);
            }

            // A number past 65535 is refused on the brace that opens it.
            $tooBig = $this->groupNumberTooBigOffset($start + \strlen($matches[0]) - \strlen($matches[4]));
            if (null !== $tooBig) {
                return '' === $matches[2] ? $tooBig : $start + 2;
            }

            // "\g'3gh'": the number read, what should close it is missing;
            // before PCRE2 10.47, "\g" itself is refused, past the "g".
            $closing = ['{' => '}', '<' => '>', "'" => "'", '' => ''][$matches[2]];
            $rest = ltrim(substr($text, \strlen($matches[0])), " \t\n\r\0\x0B");
            if ('' !== $closing && !str_starts_with($rest, $closing)) {
                return $this->target->supports(PcreFeature::GReferenceNumberReadBeforeClosing) ? $start + \strlen($matches[0]) : $start + 2;
            }

            $failsWhileRead = '-' === $matches[3] || ('' !== $matches[3] && 0 === (int) $matches[4]);
            if ($failsWhileRead && '' !== $matches[2]) {
                return $start + 2;
            }

            // Only a positive number that names no group moved in 10.47; one
            // past 65535, a forward one counted from the groups before it
            // included, is refused where it ends.
            $number = (int) $matches[4] + ('+' === $matches[3] ? ($this->groups->nextNumberAt($node) ?? 1) - 1 : 0);

            return $failsWhileRead || 0 === $number || $number > PcreNumber::MAX ? $end : $this->target->faultOffset($end);
        }

        // "\1", "\81"; a number past 65535 is refused where it ends.
        if ('\\' === $text[0]) {
            return (int) substr($text, 1) > PcreNumber::MAX ? $end : $this->target->faultOffset($end);
        }

        // "(?P=name)", "(?P>name)", "(?&name)", "(?1)", "(?-1)"
        if (str_starts_with($text, '(?')) {
            if (str_starts_with($text, '(?P')) {
                return $start + 4;
            }

            if ('&' === ($text[2] ?? '')) {
                return $start + 3;
            }

            // Past the number, before the groups "(?1(2))" returns.
            return $this->groupNumberTooBigOffset($start + 2 + strspn($text, '+-', 2)) ?? $start + 2 + \strlen($node instanceof SubroutineNode ? $node->reference : $node->ref);
        }

        // "(?(VERSION=10z)": PCRE reads a version condition, not a name.
        $versionError = VersionCondition::errorOffsetFor($this->source, $start, $this->target, $this->utf);
        if (null !== $versionError) {
            return $versionError;
        }

        // What follows "(?(": "<name>", "'name'", "R&name", "-1", "2", a bare name.
        if ('<' === $text[0] || '\'' === $text[0]) {
            return $start + 1;
        }

        if (str_starts_with($text, 'R&')) {
            return $start + 2;
        }

        if (1 === LibraryPcre::match('/^([+-]?)(\d++)$/', $text, $matches)) {
            return $this->groupNumberTooBigOffset($start + \strlen($matches[1]))
                ?? ('-' === $matches[1] || 0 === (int) $matches[2] ? $end : $end - 2);
        }

        return $start;
    }

    /**
     * Where PCRE refuses a group number past 65535 whose digits start at
     * $digitsStart: past the whole number from PCRE2 10.45, past the digit
     * that takes it over before. Null for a number within the limit.
     */
    private function groupNumberTooBigOffset(int $digitsStart): ?int
    {
        $source = (string) $this->source;
        $digits = substr($source, $digitsStart, strspn($source, '0123456789', $digitsStart));
        $read = $this->digitsWithinGroupLimit($digits);
        if ($read === \strlen($digits)) {
            return null;
        }

        return $digitsStart + ($this->target->supports(PcreFeature::NumberTooBigPastWholeNumber) ? \strlen($digits) : $read + 1);
    }

    /**
     * How many leading digits of $digits PCRE reads before the number goes
     * over 65535, the highest group number; all of them when it does not.
     */
    private function digitsWithinGroupLimit(string $digits): int
    {
        $number = 0;
        $length = \strlen($digits);
        for ($index = 0; $index < $length && Ascii::isDigit($digits[$index]); $index++) {
            $number = $number * 10 + (int) $digits[$index];
            if ($number > PcreNumber::MAX) {
                return $index;
            }
        }

        return $length;
    }

    /**
     * PCRE refuses a group number past 65535 as it reads the pattern, before
     * it looks up any group: a forward relative number counts the groups
     * before it too.
     *
     * @param string $sign   "+", "-" or "", as written
     * @param string $digits the number, as written
     */
    private function guardGroupNumberSize(BackrefNode|SubroutineNode $node, string $sign, string $digits): void
    {
        $number = $this->digitsWithinGroupLimit($digits) < \strlen($digits) ? 65536 : (int) $digits;
        if ('+' === $sign) {
            $number += ($this->groups->nextNumberAt($node) ?? 1) - 1;
        }

        if ($number > PcreNumber::MAX) {
            // A braced, angled or quoted number is refused on its bracket,
            // even when only its sum with the groups before it overflows.
            $text = null === $this->source ? '' : substr($this->source, $node->startPosition, 3);
            $bracketed = 1 === LibraryPcre::match('/^\\\\g[{<\']/', $text);

            $this->raise(
                \sprintf('Group number %s%s is too big: PCRE takes at most 65535.', $sign, $digits),
                $bracketed ? $node->startPosition + 2 : $this->missingReferenceOffset($node),
                ErrorCode::GroupNumberTooBig,
            );
        }
    }

    private function assertAbsoluteReferenceExists(int $num, int $position, ErrorCode $code, string $context): void
    {
        if ($num <= 0 || $num > $this->numbering->maxGroupNumber) {
            $this->raiseMissingReference(
                \sprintf('%s to non-existent group: %d.', $context, $num),
                $position,
                $code,
            );
        }
    }

    private function assertRelativeReferenceExists(int $offset, int $position, ErrorCode $code, string $context, BackrefNode|SubroutineNode|null $node = null): void
    {
        if (0 === $offset) {
            $this->raise(
                \sprintf('%s relative reference cannot be zero.', $context),
                $position,
                $code,
            );
        }

        if ($this->relativeTargetExists($node)) {
            return;
        }

        // A reference forward names a group PCRE has not read yet: it is
        // resolved with the references by number, once the pattern is read.
        if ($offset > 0) {
            $this->raiseMissingReference(
                \sprintf('%s relative reference %d is outside the range of available capture groups.', $context, $offset),
                $position,
                $code,
            );

            return;
        }

        $this->raise(
            \sprintf('%s relative reference %d is outside the range of available capture groups.', $context, $offset),
            $position,
            $code,
            'Check group numbering or remove the relative reference.',
        );
    }

    /**
     * Whether a group bears the number a relative reference counts to. The
     * index counts group numbers, as PCRE does: every branch of a branch
     * reset numbers its groups from the same number. A node of no indexed
     * tree names no group, nor does "(?(R-1)", which PCRE refuses to read.
     */
    private function relativeTargetExists(BackrefNode|SubroutineNode|null $node): bool
    {
        return match (true) {
            $node instanceof BackrefNode => [] !== $this->groups->groupsReferencedBy($node),
            $node instanceof SubroutineNode => [] !== $this->groups->groupsCalledBy($node),
            default => false,
        };
    }

    private function assertSubroutineReferenceExists(int $num, int $position, ErrorCode $code, string $context): void
    {
        if ($num > 0) {
            $this->assertAbsoluteReferenceExists($num, $position, $code, $context);

            return;
        }

        $this->assertRelativeReferenceExists($num, $position, $code, $context);
    }

    /**
     * Without UTF mode a name holds ASCII word characters only: PCRE stops
     * reading it on the first byte above 0x7F and refuses what follows there.
     */
    private function guardByteModeName(BackrefNode|SubroutineNode $node, string $name, ErrorCode $code): void
    {
        if ($this->utf || null === $this->source || 1 !== LibraryPcre::match('/[\x80-\xFF]/', $name)) {
            return;
        }

        $text = substr($this->source, $node->startPosition, $node->getEndPosition() - $node->startPosition);
        $position = $node->startPosition + strcspn($text, implode('', array_map(chr(...), range(0x80, 0xFF))));

        $this->raise(
            \sprintf('Group name "%s" holds a byte past ASCII, which ends a name without UTF mode.', $name),
            $position,
            $code,
        );
    }

    /**
     * A reference to a group the pattern does not have, which the validator
     * raises when PCRE would.
     */
    private function raiseMissingReference(string $message, int $position, ErrorCode $code, ?string $hint = null): void
    {
        ($this->missing)(new SemanticErrorException($message, $code, $position + $this->offset, $this->pattern, null, $hint));
    }

    private function raise(string $message, int $position, ErrorCode $code, ?string $hint = null): never
    {
        throw new SemanticErrorException($message, $code, $position + $this->offset, $this->pattern, null, $hint);
    }
}

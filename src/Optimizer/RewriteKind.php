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

namespace PHPRegex\Optimizer;

use PHPRegex\Parser\Exception\InternalFailureException;

/**
 * A kind of rewrite the optimizer makes, as OptimizationResult::$kinds lists
 * them; message() is the sentence OptimizationResult::$changes holds for it.
 *
 * @api
 */
enum RewriteKind: string
{
    case Digits = 'digits';
    case Word = 'word';
    case ClassMembers = 'class_members';
    case AlternationToClass = 'alternation_to_class';
    case MergedClasses = 'merged_classes';
    case DuplicateAlternative = 'duplicate_alternative';
    case FactoredPrefix = 'factored_prefix';
    case FactoredSuffix = 'factored_suffix';
    case GroupRemoved = 'group_removed';
    case RunCounted = 'run_counted';
    case ShortQuantifier = 'short_quantifier';
    case CountOfOne = 'count_of_one';
    case RepeatOfZero = 'repeat_of_zero';
    case Possessive = 'possessive';
    case UnusedFlag = 'unused_flag';
    case Respelled = 'respelled';

    /**
     * The kind a message of the rewriter names.
     *
     * @internal the optimizer reads the kinds of the rewrites the rewriter recorded
     *
     * @throws InternalFailureException when no kind has that message
     */
    public static function named(string $message): self
    {
        foreach (self::cases() as $kind) {
            if ($kind->message() === $message) {
                return $kind;
            }
        }

        throw InternalFailureException::unexpectedState(\sprintf('No kind of rewrite is named "%s".', $message));
    }

    /**
     * The sentence that names the rewrite, "Wrote [0-9] as \d." for Digits.
     */
    public function message(): string
    {
        return match ($this) {
            self::Digits => Rewriter::DIGITS,
            self::Word => Rewriter::WORD,
            self::ClassMembers => Rewriter::CLASS_MEMBERS,
            self::AlternationToClass => Rewriter::ALTERNATION_TO_CLASS,
            self::MergedClasses => Rewriter::MERGED_CLASSES,
            self::DuplicateAlternative => Rewriter::DUPLICATE_ALTERNATIVE,
            self::FactoredPrefix => Rewriter::FACTORED_PREFIX,
            self::FactoredSuffix => Rewriter::FACTORED_SUFFIX,
            self::GroupRemoved => Rewriter::GROUP_REMOVED,
            self::RunCounted => Rewriter::RUN_COUNTED,
            self::ShortQuantifier => Rewriter::SHORT_QUANTIFIER,
            self::CountOfOne => Rewriter::COUNT_OF_ONE,
            self::RepeatOfZero => Rewriter::REPEAT_OF_ZERO,
            self::Possessive => Rewriter::POSSESSIVE,
            self::UnusedFlag => Rewriter::UNUSED_FLAG,
            self::Respelled => Rewriter::RESPELLED,
        };
    }
}

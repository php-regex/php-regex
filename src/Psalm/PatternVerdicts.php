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

namespace PHPRegex\Psalm;

use PHPRegex\Parser\Analysis\CaptureShape;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Parser\Validation\ValidationResult;

/**
 * What the library says of a pattern for a target, read once per process:
 * the error the target reports, or the shape of what it captures and the
 * facts the types read from its tree.
 *
 * @internal
 *
 * @phpstan-type PatternVerdict array{error: ?string, shape: ?CaptureShape, noAutoCapture: bool, keepsOut: bool, fewStrings: bool}
 */
final class PatternVerdicts
{
    /**
     * What the library says of a pattern, read once per process.
     *
     * @var array<string, PatternVerdict> by target and pattern
     */
    private static array $verdicts = [];

    /**
     * @return PatternVerdict
     */
    public static function verdict(RegexParser $parser, string $pattern): array
    {
        return self::$verdicts[CallTarget::key($parser)."\0".$pattern] ??= self::judge($parser, $pattern);
    }

    /**
     * @return PatternVerdict
     */
    private static function judge(RegexParser $parser, string $pattern): array
    {
        try {
            $regex = $parser->parse($pattern);
        } catch (ExceptionInterface) {
            $regex = null;
        }

        $validation = $parser->validate($pattern);
        // The library's own limits, not PCRE's: it cannot judge the pattern, which PCRE may well accept.
        if (ValidationErrorCategory::Limit === $validation->category) {
            return ['error' => null, 'shape' => null, 'noAutoCapture' => false, 'keepsOut' => false, 'fewStrings' => false];
        }

        if (!$validation->isValid) {
            return ['error' => self::message($parser, $validation), 'shape' => null, 'noAutoCapture' => false, 'keepsOut' => false, 'fewStrings' => false];
        }

        // A pattern the running engine does not compile leaves $matches as it was: nothing is asserted on it.
        if (null === $regex || null !== (new PcreEngine())->compile($pattern)) {
            return ['error' => null, 'shape' => null, 'noAutoCapture' => false, 'keepsOut' => false, 'fewStrings' => false]; // Only on a running PCRE2 older than the target's, as the 10.40 and 10.42 CI images.
        }

        $shape = (new CaptureShapeAnalyzer())->analyze($regex);

        return [
            'error' => null,
            'shape' => $shape,
            'noAutoCapture' => str_contains($regex->flags, 'n'),
            'keepsOut' => self::keepsOut($regex),
            // Only a match of bounded length, from the start of the subject, may leave a few strings to read.
            'fewStrings' => null !== $shape->whole->maxLength && self::startsAtTheStart($regex->pattern) && (str_contains($regex->flags, 'u') || !self::readsTheLocale($regex)),
        ];
    }

    /**
     * Whether every match starts at the start of the subject, as far as the
     * tree shows it: ^ or \A first in each branch.
     */
    private static function startsAtTheStart(NodeInterface $node): bool
    {
        if ($node instanceof AlternationNode) {
            foreach ($node->alternatives as $alternative) {
                if (!self::startsAtTheStart($alternative)) {
                    return false;
                }
            }

            return [] !== $node->alternatives;
        }

        return match (true) {
            $node instanceof AnchorNode => '^' === $node->value,
            $node instanceof AssertionNode => 'A' === $node->value,
            $node instanceof SequenceNode => [] !== $node->children && self::startsAtTheStart($node->children[0]),
            $node instanceof GroupNode => \in_array($node->type, [GroupType::Capturing, GroupType::Named, GroupType::NonCapturing, GroupType::Atomic], true) && self::startsAtTheStart($node->child),
            default => false,
        };
    }

    /**
     * Whether, without /u, the pattern reads PCRE's character tables, which
     * PHP builds from the locale setlocale() sets: a caseless part, a
     * shorthand class, a POSIX class or a word boundary. After
     * setlocale(LC_CTYPE, 'fr_FR.ISO8859-1'), preg_match('/^\w$/', "\xE9")
     * returns 1, where it returns 0 under the C locale the solver reads.
     */
    private static function readsTheLocale(NodeInterface $node): bool
    {
        if ($node instanceof RegexNode && str_contains($node->flags, 'i')) {
            return true;
        }

        if ($node instanceof CharTypeNode || $node instanceof PosixClassNode
            || ($node instanceof AssertionNode && \in_array($node->value, ['b', 'B'], true))
            || ($node instanceof GroupNode && str_contains((string) $node->flags, 'i'))) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::readsTheLocale($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a \K stands anywhere in the tree.
     */
    private static function keepsOut(NodeInterface $node): bool
    {
        if ($node instanceof KeepNode) {
            return true;
        }

        foreach ($node->getChildren() as $child) {
            if (self::keepsOut($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Regex pattern is invalid for PHP 8.4 with PCRE2 10.44: <the
     * library's error> (offset 3).", as the PHPStan extension words it.
     */
    private static function message(RegexParser $parser, ValidationResult $validation): string
    {
        $target = $parser->target();
        $reason = rtrim(explode("\n", $validation->error ?? 'Invalid regex.')[0], '.');

        return \sprintf(
            'Regex pattern is invalid for PHP %d.%d with PCRE2 %s%s: %s%s.',
            intdiv($target->phpVersionId, 10000),
            intdiv($target->phpVersionId, 100) % 100,
            $target->pcreVersion,
            'C' === $target->locale ? '' : ' under the locale '.$target->locale,
            $reason,
            null === $validation->offset ? '' : ' (offset '.$validation->offset.')',
        );
    }
}

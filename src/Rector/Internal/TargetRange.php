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

namespace PHPRegex\Rector\Internal;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Scalar\String_;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Printer\NodeDumper;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\RangeDiff;

/**
 * What the rules that ready a pattern for the PHP versions a project runs
 * on share: the range they judge, from the PHP the code targets to the
 * newest the library knows, and the pattern literal of a preg_*() call.
 *
 * @internal
 */
final class TargetRange
{
    /**
     * The functions whose first argument is a pattern.
     */
    public const FUNCTIONS = ['preg_match', 'preg_match_all', 'preg_replace', 'preg_replace_callback', 'preg_split', 'preg_grep', 'preg_filter'];

    /**
     * @var array<int, RangeDiff>
     */
    private static array $ranges = [];

    private function __construct() {}

    /**
     * From the PHP the code targets to the newest the library knows,
     * through every PHP version a verdict may change at: never a single
     * target. One range per PHP version, kept for the run.
     */
    public static function from(int $phpVersion): RangeDiff
    {
        $boundaries = PcreTarget::phpVersionBoundaries();

        return self::$ranges[$phpVersion] ??= RangeDiff::between($phpVersion, max($phpVersion, $boundaries[\count($boundaries) - 1]));
    }

    /**
     * The first argument of the call when it is one string literal, given
     * by position: the pattern a rule may rewrite. Null for a first-class
     * callable, a named or unpacked argument, or any other expression.
     */
    public static function patternLiteral(FuncCall $call): ?String_
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }

        $argument = $call->getArgs()[0] ?? null;

        return null === $argument || !$argument->value instanceof String_ || null !== $argument->name || $argument->unpack ? null : $argument->value;
    }

    /**
     * The call with its pattern literal replaced by one holding $pattern.
     */
    public static function withPattern(FuncCall $call, string $pattern): FuncCall
    {
        $call->getArgs()[0]->value = PregCall::string($pattern);

        return $call;
    }

    /**
     * Whether the rewritten pattern is read alike by every PHP of the
     * range, and as $reader, which accepts both, read the pattern it
     * replaces: the same tree, or two trees the printer writes the same
     * once escapes are normalized, as a NUL byte and "\x00".
     */
    public static function keepsTheReading(RangeDiff $range, RegexParser $reader, string $pattern, string $rewritten): bool
    {
        $verdict = $range->diff($rewritten);
        if (!$verdict->from->validation->isValid || null !== $verdict->refusedBy() || null !== $verdict->readsOtherwise) {
            return false;
        }

        $before = $reader->parse($pattern);
        $after = $reader->parse($rewritten);

        return $after->accept(new NodeDumper()) === $before->accept(new NodeDumper())
            || $after->accept(new PatternPrinter(true, false, false)) === $before->accept(new PatternPrinter(true, false, false));
    }
}

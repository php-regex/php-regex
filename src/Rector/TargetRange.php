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

namespace PHPRegex\Rector;

use PhpParser\Node\Expr\CallLike;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\Printer\NodeDumper;
use PHPRegex\Parser\Printer\PatternPrinter;
use PHPRegex\Parser\RegexParser;
use PHPRegex\Parser\Validation\RangeDiff;
use Rector\Reflection\ReflectionResolver;

/**
 * What the rules that ready a pattern for the PHP versions a project runs
 * on share: the range they judge, from the PHP the code targets to the
 * newest the library knows, and the pattern literals a call hands to a
 * regex engine.
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
     * The call with each pattern literal it hands to a regex engine
     * replaced by its rewrite (see PatternLiterals); null when none is
     * rewritten.
     *
     * @template T of CallLike
     *
     * @param T                         $call
     * @param bool                      $pregCall      whether the call is a function call named in FUNCTIONS
     * @param bool                      $callbackArray whether the call is preg_replace_callback_array()
     * @param \Closure(string): ?string $rewrite       the rewritten pattern, or null to leave it
     *
     * @return T|null
     */
    public static function rewriteEach(CallLike $call, bool $pregCall, bool $callbackArray, ReflectionResolver $reflection, \Closure $rewrite): ?CallLike
    {
        $changed = false;
        foreach (PatternLiterals::of($call, $pregCall, $callbackArray, $reflection) as [$literal, $replace]) {
            $rewritten = $rewrite($literal->value);
            if (null !== $rewritten) {
                $replace(PregCall::string($rewritten));
                $changed = true;
            }
        }

        return $changed ? $call : null;
    }

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

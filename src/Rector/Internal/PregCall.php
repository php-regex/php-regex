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

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\ErrorSuppress;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PHPRegex\Parser\Engine\PcreEngine;
use PHPRegex\Parser\PcreTarget;
use PHPRegex\Parser\RegexParser;
use Rector\ValueObject\PhpVersion;

/**
 * What the three rules ask of a preg_*() call before the automata are asked
 * anything: its arguments, the pattern it was given, and the string literals
 * a rewrite is spelled with.
 *
 * @internal
 */
final class PregCall
{
    /**
     * The attribute a call under "@" carries: the rule meets the "@" before
     * the call it silences, and the call does not know its parent.
     */
    private const SILENCED = 'php_regex_silenced';

    /**
     * The bytes a single-quoted PHP string shows as they are.
     */
    private const PRINTABLE = ' !"#$%&\'()*+,-./0123456789:;<=>?@ABCDEFGHIJKLMNOPQRSTUVWXYZ[\\]^_`abcdefghijklmnopqrstuvwxyz{|}~';

    /**
     * The argument values of the call, by position, or null when they cannot
     * be read that way: a first-class callable, a named or unpacked argument,
     * or fewer or more arguments than asked.
     *
     * @return list<Expr>|null
     */
    public static function arguments(FuncCall $call, int $min, int $max): ?array
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }

        $values = [];
        foreach ($call->getArgs() as $arg) {
            if (null !== $arg->name || $arg->unpack) {
                return null;
            }
            $values[] = $arg->value;
        }

        $count = \count($values);

        return $count >= $min && $count <= $max ? $values : null;
    }

    /**
     * Marks the call the operator silences: a rewrite would silence another
     * function, and preg_*() reports through the warning it would no longer
     * raise.
     */
    public static function markSilenced(ErrorSuppress $node): void
    {
        if ($node->expr instanceof FuncCall) {
            $node->expr->setAttribute(self::SILENCED, true);
        }
    }

    public static function isSilenced(FuncCall $call): bool
    {
        return true === $call->getAttribute(self::SILENCED);
    }

    /**
     * Whether dropping the expression from the code changes nothing but the
     * value it held: a literal, a constant, or a concatenation of them.
     */
    public static function isConstantExpression(Expr $expr): bool
    {
        return match (true) {
            $expr instanceof String_, $expr instanceof Int_ => true,
            $expr instanceof UnaryMinus => self::isConstantExpression($expr->expr),
            $expr instanceof ConstFetch => true,
            $expr instanceof ClassConstFetch => $expr->class instanceof Name && $expr->name instanceof Identifier,
            $expr instanceof Concat => self::isConstantExpression($expr->left) && self::isConstantExpression($expr->right),
            default => false,
        };
    }

    /**
     * Whether the PHP the code targets compiles the pattern, as the library
     * judges it for that PHP and the PCRE2 its sources bundle: "n" arrived
     * in 8.2, "r" in 8.4. PHP also refuses a NUL byte in a pattern before
     * 8.2 ("Null byte in regex"), where a string function would answer.
     */
    public static function targetAccepts(string $pattern, RegexParser $target): bool
    {
        if ($target->target()->phpVersionId < PhpVersion::PHP_82 && str_contains($pattern, "\0")) {
            return false;
        }

        return $target->validate($pattern)->isValid;
    }

    /**
     * The parser that reads patterns as the PHP the code targets does,
     * with the PCRE2 its sources bundle.
     */
    public static function targetParser(int $phpVersion): RegexParser
    {
        return RegexParser::create(['php_version' => $phpVersion, 'pcre_version' => PcreTarget::bundledWith($phpVersion)->pcreVersion]);
    }

    /**
     * Whether the PCRE2 engine running the rule compiles the pattern.
     */
    public static function compiles(string $pattern): bool
    {
        return null === (new PcreEngine())->compile($pattern);
    }

    /**
     * Whether every literal can be written in the code the rewrite prints.
     * The printer escapes control bytes and invalid UTF-8 but writes DEL
     * (0x7F) as it is, invisible in the code; spelling it by hand would leave
     * a node whose value is not its bytes, which a later rule could read. A
     * literal holding one is not written: the call is left as it is.
     */
    public static function spellable(string ...$literals): bool
    {
        foreach ($literals as $literal) {
            if (str_contains($literal, "\x7f")) {
                return false;
            }
        }

        return true;
    }

    /**
     * A string literal holding the bytes: single-quoted when they are all
     * printable ASCII, double-quoted with escapes otherwise.
     */
    public static function string(string $value): String_
    {
        if (strspn($value, self::PRINTABLE) === \strlen($value)) {
            return new String_($value, ['kind' => String_::KIND_SINGLE_QUOTED]);
        }

        return new String_($value, ['kind' => String_::KIND_DOUBLE_QUOTED]);
    }

    /**
     * A call to a global function, fully qualified: in a namespace that
     * declares or imports a function of the same name, an unqualified call
     * would reach that one.
     *
     * @param list<Expr> $arguments
     */
    public static function call(string $function, array $arguments): FuncCall
    {
        return new FuncCall(new FullyQualified($function), array_map(static fn (Expr $value): Arg => new Arg($value), $arguments));
    }
}

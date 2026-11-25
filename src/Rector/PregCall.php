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
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\ParserOptions;
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
     * The PCRE2 release each composer.json read pins, by path: Rector reads
     * one project per run, and every call asks.
     *
     * @var array<string, string|null>
     */
    private static array $pinnedPcre = [];

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
     * judges it for that PHP and its PCRE2 (target()): "n" arrived
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
     * with its PCRE2 (target()), under the locale Rector runs with: Rector
     * names no other, and a rule rewrites no pattern whose answer moves
     * with the locale (see TrivialMatchClassifier).
     */
    public static function targetParser(int $phpVersion): RegexParser
    {
        $target = self::target($phpVersion);

        return RegexParser::create(['php_version' => $target->phpVersionId, 'pcre_version' => $target->pcreVersion]);
    }

    /**
     * The PHP the code targets with the PCRE2 it runs, as `regex lint`
     * resolves it: the release the project's composer.json pins in
     * config.platform.lib-pcre, else the running PCRE2 when the PHP is the
     * running one's minor version, else the one the PHP bundles. Rector
     * takes targets from PHP 5.3 to PHP 10.0, which the library judges from
     * PHP 7.0 to PHP 9.99.
     */
    public static function target(int $phpVersion): PcreTarget
    {
        $phpVersion = min(max($phpVersion, ParserOptions::OLDEST_PHP_VERSION_ID), ParserOptions::LATEST_PHP_VERSION_ID);

        return ParserOptions::resolveTarget($phpVersion, self::pinnedPcre(), null, true);
    }

    /**
     * The PCRE2 release config.platform.lib-pcre pins in the composer.json
     * Rector runs beside, or in the file the COMPOSER variable names: null
     * when the file cannot be read, pins none, or pins what names no
     * release, which is passed over as `regex lint` passes it over.
     */
    public static function pinnedPcre(): ?string
    {
        $name = getenv('COMPOSER');
        $name = \is_string($name) && '' !== $name ? $name : 'composer.json';

        if (!\array_key_exists($name, self::$pinnedPcre)) {
            $contents = is_file($name) ? file_get_contents($name) : false;
            $value = false === $contents ? null : json_decode($contents, true);
            foreach (['config', 'platform', 'lib-pcre'] as $key) {
                $value = \is_array($value) ? $value[$key] ?? null : null;
            }
            self::$pinnedPcre[$name] = \is_string($value) ? self::release($value) : null;
        }

        return self::$pinnedPcre[$name];
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

    /**
     * The PCRE2 release a platform version names: Composer spells one with
     * up to four numbers, "10.42.0", and a PCRE2 release has two. Null when
     * it names none.
     */
    private static function release(string $written): ?string
    {
        $parts = explode('.', $written, 3);
        $patch = $parts[2] ?? '';
        if (\count(explode('.', $patch)) <= 2 && strspn($patch, '0123456789.') === \strlen($patch)) {
            $written = $parts[0].'.'.($parts[1] ?? '');
        }

        try {
            return (new PcreTarget(\PHP_VERSION_ID, $written))->pcreVersion;
        } catch (InvalidRegexOptionException) {
            return null;
        }
    }
}

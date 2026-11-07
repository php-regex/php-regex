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

namespace PHPRegex\Infection\Site;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\BitwiseOr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;

/**
 * A call to a native preg_*() function whose pattern is written as a string
 * literal: the function, the pattern, and the arguments the call was given,
 * bound to their parameters as PHP binds them.
 *
 * The function is the global one: a name that resolves to another
 * namespace's function, through "use function" or a qualified name, is not
 * read; an unqualified name inside a namespace falls back to the global
 * function, as PHP does when the namespace defines none.
 *
 * @internal
 */
final class PatternCall
{
    /**
     * The parameters of each function read, in order. The pattern comes
     * first; preg_replace_callback_array() takes its patterns as array keys
     * and is not read.
     */
    private const PARAMETERS = [
        'preg_match' => ['pattern', 'subject', 'matches', 'flags', 'offset'],
        'preg_match_all' => ['pattern', 'subject', 'matches', 'flags', 'offset'],
        'preg_replace' => ['pattern', 'replacement', 'subject', 'limit', 'count'],
        'preg_replace_callback' => ['pattern', 'callback', 'subject', 'limit', 'count', 'flags'],
        'preg_split' => ['pattern', 'subject', 'limit', 'flags'],
        'preg_grep' => ['pattern', 'array', 'flags'],
        'preg_filter' => ['pattern', 'replacement', 'subject', 'limit', 'count'],
    ];

    /**
     * What a new node must not carry over: the original node Infection keeps
     * for the printer, which would print the original back, and the literal's
     * spelling as the file wrote it.
     */
    private const PRINTER_ATTRIBUTES = ['origNode' => true, 'rawValue' => true];

    /**
     * @param array<string, Expr> $arguments
     */
    private function __construct(
        public readonly FuncCall $node,
        public readonly string $function,
        public readonly string $pattern,
        private readonly array $arguments,
        private readonly int $patternPosition,
    ) {}

    public static function fromNode(Node $node): ?self
    {
        if (!$node instanceof FuncCall || !$node->name instanceof Name) {
            return null;
        }

        $function = self::functionName($node->name);
        if (!isset(self::PARAMETERS[$function])) {
            return null;
        }

        $parameters = self::PARAMETERS[$function];
        $arguments = [];
        $patternPosition = null;
        foreach (array_values($node->args) as $position => $arg) {
            if (!$arg instanceof Arg || $arg->unpack) {
                return null;
            }
            $name = $arg->name?->toString() ?? $parameters[$position] ?? null;
            if (null === $name || isset($arguments[$name])) {
                return null;
            }
            $arguments[$name] = $arg->value;
            if ('pattern' === $name) {
                $patternPosition = $position;
            }
        }

        $pattern = $arguments['pattern'] ?? null;
        if (!$pattern instanceof String_ || null === $patternPosition) {
            return null;
        }

        return new self($node, $function, $pattern->value, $arguments, $patternPosition);
    }

    public function observation(): Observation
    {
        $onlyPatternAndSubject = 2 === \count($this->arguments) && isset($this->arguments['subject']);

        return 'preg_grep' === $this->function || ('preg_match' === $this->function && $onlyPatternAndSubject)
            ? Observation::Match
            : Observation::Matches;
    }

    /**
     * The integer an argument is written as, a sum of PREG_* flags
     * included; the default when the call does not pass it; null when the
     * value is only known at run time.
     */
    public function integer(string $parameter, int $default): ?int
    {
        return isset($this->arguments[$parameter]) ? self::integerOf($this->arguments[$parameter]) : $default;
    }

    /**
     * Whether the call passes the argument.
     */
    public function passes(string $parameter): bool
    {
        return isset($this->arguments[$parameter]);
    }

    /**
     * Whether the argument is absent, its default then applying, or written
     * as a literal: an integer, a sum of PREG_* flags or a string.
     */
    public function literal(string $parameter): bool
    {
        $value = $this->arguments[$parameter] ?? null;

        return null === $value || $value instanceof String_ || null !== self::integerOf($value);
    }

    /**
     * The string an argument is written as; null when it is not a literal
     * or the call does not pass it.
     */
    public function string(string $parameter): ?string
    {
        $value = $this->arguments[$parameter] ?? null;

        return $value instanceof String_ ? $value->value : null;
    }

    /**
     * The call with another pattern literal in place of its own, spelled the
     * way the original was: quotes, heredoc or nowdoc.
     */
    public function withPattern(string $pattern): FuncCall
    {
        $args = array_values($this->node->args);
        $arg = $args[$this->patternPosition];
        \assert($arg instanceof Arg);
        $literal = new String_($pattern, array_diff_key($arg->value->getAttributes(), self::PRINTER_ATTRIBUTES));
        $args[$this->patternPosition] = new Arg($literal, $arg->byRef, $arg->unpack, array_diff_key($arg->getAttributes(), self::PRINTER_ATTRIBUTES), $arg->name);

        return new FuncCall($this->node->name, $args, array_diff_key($this->node->getAttributes(), self::PRINTER_ATTRIBUTES));
    }

    /**
     * The name the call resolves to, lower-cased, or the name as written
     * when nothing resolved it: a namespaced one, Safe\preg_match say, then
     * names no function of PARAMETERS.
     */
    private static function functionName(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return ($resolved instanceof Name ? $resolved : $name)->toLowerString();
    }

    private static function integerOf(Expr $value): ?int
    {
        if ($value instanceof Int_) {
            return $value->value;
        }
        if ($value instanceof UnaryMinus && $value->expr instanceof Int_) {
            return -$value->expr->value;
        }
        if ($value instanceof BitwiseOr) {
            $left = self::integerOf($value->left);
            $right = self::integerOf($value->right);

            return null === $left || null === $right ? null : $left | $right;
        }
        if ($value instanceof ConstFetch) {
            $name = $value->name->getAttribute('resolvedName') ?? $value->name;
            \assert($name instanceof Name);
            $constant = $name->toString();
            if (str_starts_with($constant, 'PREG_') && \defined($constant)) {
                $flag = \constant($constant);

                return \is_int($flag) ? $flag : null;
            }
        }

        return null;
    }
}

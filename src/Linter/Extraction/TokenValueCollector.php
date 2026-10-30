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

namespace PHPRegex\Linter\Extraction;

use PHPRegex\Parser\Text\Ascii;

/**
 * Reads, from the tokens of a file, what it proves about its constants,
 * properties and local variables, for InFileValueResolver; and turns a
 * range of tokens into the form that class reads.
 *
 * The token strategy hands it every token in order. It tracks the class
 * bodies and the function bodies the token is in (a closure and an arrow
 * function are bodies of their own; the "use" list of a closure belongs to
 * the body around it), the declarations, and every way a variable or a
 * property may be written. It reads what AstValueCollector reads from a
 * syntax tree, so both strategies prove the same values.
 *
 * @internal
 */
final class TokenValueCollector
{
    /**
     * The tokens visit() acts on: a caller may skip the others.
     */
    public const READ = [
        '$' => true, '=' => true, '(' => true,
        \T_CLASS => true, \T_INTERFACE => true, \T_TRAIT => true, \T_ENUM => true,
        \T_FUNCTION => true, \T_FN => true, \T_CONST => true, \T_GLOBAL => true, \T_STATIC => true,
        \T_CATCH => true, \T_UNSET => true, \T_FOREACH => true, \T_INC => true, \T_DEC => true,
        \T_FOR => true, \T_WHILE => true, \T_DO => true, \T_GOTO => true, \T_YIELD => true,
        \T_EVAL => true, \T_INCLUDE => true, \T_INCLUDE_ONCE => true, \T_REQUIRE => true, \T_REQUIRE_ONCE => true,
        \T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG => true, \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG => true,
        \T_PLUS_EQUAL => true, \T_MINUS_EQUAL => true, \T_MUL_EQUAL => true, \T_DIV_EQUAL => true,
        \T_CONCAT_EQUAL => true, \T_MOD_EQUAL => true, \T_AND_EQUAL => true, \T_OR_EQUAL => true,
        \T_XOR_EQUAL => true, \T_SL_EQUAL => true, \T_SR_EQUAL => true, \T_POW_EQUAL => true, \T_COALESCE_EQUAL => true,
    ];

    /**
     * Keywords whose parenthesis opens no argument list.
     */
    private const NOT_CALLS = [
        \T_IF, \T_ELSEIF, \T_WHILE, \T_FOR, \T_FOREACH, \T_SWITCH, \T_CATCH, \T_DECLARE,
        \T_FUNCTION, \T_FN, \T_USE, \T_MATCH, \T_LIST, \T_ISSET, \T_EMPTY, \T_UNSET, \T_EXIT, \T_EVAL,
    ];

    /**
     * The tokens of READ that declare, as opposed to those that write.
     */
    private const DECLARES = [
        '(' => true, \T_CLASS => true, \T_INTERFACE => true, \T_TRAIT => true, \T_ENUM => true,
        \T_FUNCTION => true, \T_FN => true, \T_CONST => true,
    ];

    private const MODIFIERS = [\T_PUBLIC, \T_PROTECTED, \T_PRIVATE, \T_STATIC, \T_FINAL, \T_ABSTRACT, \T_READONLY, \T_VAR];

    private readonly InFileValueResolver $values;

    /**
     * The bodies the current token may be in: a class (its lowercase name,
     * null for a trait or an anonymous class) or a function (its scope,
     * whether it is a closure or an arrow function, which Closure::bind()
     * may give another class, and whether it returns by reference).
     *
     * @var list<array{class: true, id: string|null, ranges: list<array{int, int}>, end: int}|array{class: false, id: int, ranges: list<array{int, int}>, end: int, bound: bool, reference: bool}>
     */
    private array $stack = [];

    private int $nextScope = 0;

    /**
     * Whether any write may matter: none does when the pattern arguments
     * name no variable and no property.
     */
    private readonly bool $watchesWrites;

    /**
     * @var array<int, int> the opening index of each closing bracket
     */
    private array $openers;

    /**
     * @var array<int, true> the first token of each argument a call reads its pattern from
     */
    private array $patternArguments = [];

    /**
     * @var array<int, true> the "const" tokens of class bodies
     */
    private array $memberConstants = [];

    /**
     * @var list<int>|null the offset of each newline, read on demand
     */
    private ?array $newlines = null;

    /**
     * @param array<int, array{int, string, int}|string> $tokens
     * @param array<int, int>                            $offsets           the byte offset of each token
     * @param array<int, int>                            $closers           the closing index of each bracket
     * @param array<string, true>                        $watchedVariables  the variables ("$re") whose writes
     *                                                                      matter: only those a pattern argument
     *                                                                      names can change a pattern
     * @param bool                                       $watchesProperties whether a pattern argument names a
     *                                                                      property
     */
    public function __construct(
        private readonly array $tokens,
        private readonly array $offsets,
        private readonly array $closers,
        private readonly string $content,
        private readonly array $watchedVariables,
        private readonly bool $watchesProperties,
    ) {
        $this->values = new InFileValueResolver();
        $this->openers = array_flip($closers);
        $this->watchesWrites = $watchesProperties || [] !== $watchedVariables;
    }

    public function values(): InFileValueResolver
    {
        return $this->values;
    }

    /**
     * Say that the argument whose value starts at the index carries the
     * pattern of its call: a variable passed there is read, not written.
     */
    public function markPatternArgument(int $index): void
    {
        $first = $this->next($index);
        if (null !== $first) {
            $this->patternArguments[$first] = true;
        }
    }

    /**
     * Read the token at the index, one of READ; the tokens are handed over
     * in order, the others left out: they declare and write nothing. A body
     * left behind is dropped at the next token read: no lookup counts on
     * it, it holds no later token.
     */
    public function visit(int $index, NameResolutionContext $context): void
    {
        $token = $this->tokens[$index];
        $id = \is_array($token) ? $token[0] : $token;

        while ([] !== $this->stack && $this->stack[\count($this->stack) - 1]['end'] < $index) {
            array_pop($this->stack);
        }

        // A write changes no pattern when no pattern argument names what it
        // writes: only the declarations are read then. A keyword after "::"
        // or "->" is a member name: Fixer::function($re) calls a method.
        $previous = \is_array($token) ? $this->previous($index - 1) : null;
        if ((!$this->watchesWrites && !isset(self::DECLARES[$id])) || (null !== $previous && PhpTokens::isObjectOrStaticOperator($this->tokens[$previous]))) {
            return;
        }

        switch ($id) {
            case '$':
                // $$name, ${'name'}: any variable of the body.
                $this->bailScope($index);

                break;
            case '=':
                $this->assignment($index, $context);

                break;
            case '(':
                $this->call($index, $context);

                break;
            case \T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG:
            case \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG:
                $this->reference($index);

                break;
            case \T_CLASS:
            case \T_INTERFACE:
            case \T_TRAIT:
            case \T_ENUM:
                $this->enterClass($index, $context);

                break;
            case \T_FUNCTION:
                $this->enterFunction($index);

                break;
            case \T_FN:
                $this->enterArrowFunction($index);

                break;
            case \T_CONST:
                if (!isset($this->memberConstants[$index])) {
                    $this->globalConstants($index, $context);
                }

                break;
            case \T_GLOBAL:
                $this->globals($index);

                break;
            case \T_STATIC:
                $this->staticVariables($index);

                break;
            case \T_CATCH:
                $this->catchVariable($index);

                break;
            case \T_UNSET:
                $this->unsetTargets($index);

                break;
            case \T_FOREACH:
                $this->foreachTargets($index);
                $this->loop($index);

                break;
            case \T_FOR:
            case \T_WHILE:
            case \T_DO:
                $this->loop($index);

                break;
            case \T_YIELD:
                $this->yielded($index);

                break;
            case \T_GOTO:
                // Anything in the body may run again after anything else.
                $scope = $this->scopeAt($index);
                if (null !== $scope) {
                    $this->values->jumps($scope);
                }

                break;
            case \T_INC:
            case \T_DEC:
                $this->increment($index);

                break;
            case \T_EVAL:
            case \T_INCLUDE:
            case \T_INCLUDE_ONCE:
            case \T_REQUIRE:
            case \T_REQUIRE_ONCE:
                // Code the file does not show runs in the body; h(include: 1)
                // only names an argument.
                $next = $this->next($index + 1);
                if (null === $next || ':' !== $this->tokens[$next]) {
                    $this->bailScope($index);
                }

                break;
            default:
                // The compound assignments left in READ: .=, +=, ??=, ...
                $this->writeBackward($previous, $this->scopeAt($index));
        }
    }

    /**
     * The expression of the tokens in [$start, $end) as InFileValueResolver
     * reads it, placed where php-parser places it: a concatenation at its
     * first token, its parenthesis included; a parenthesised expression at
     * the expression.
     */
    public function expression(int $start, int $end, NameResolutionContext $context): ValueExpr
    {
        $first = $this->next($start, $end);
        if (null === $first) {
            return ValueExpr::unknown();
        }

        // The walk down to $first stops at $first at the latest.
        $last = $this->previous($end - 1, $first) ?? $first;
        while ('(' === $this->tokens[$first] && ($this->closers[$first] ?? null) === $last) {
            $first = $this->next($first + 1, $last);
            if (null === $first) {
                return ValueExpr::unknown();
            }

            $last = $this->previous($last - 1, $first) ?? $first;
        }

        $operands = [];
        $operandStart = $first;
        // A heredoc holds a "." only in the "{$" and "${" it interpolates.
        for ($i = $first; $i <= $last; $i++) {
            $token = $this->tokens[$i];
            if (PhpTokens::opensNesting($token)) {
                $i = $this->closers[$i] ?? $last;
            } elseif ('.' === $token) {
                $operands[] = [$operandStart, $i];
                $operandStart = $i + 1;
            }
        }

        if ([] === $operands) {
            return $this->operand($first, $last, $context);
        }

        $operands[] = [$operandStart, $last + 1];
        $parts = [];
        $literal = '';
        $allLiteral = true;
        foreach ($operands as [$from, $to]) {
            $part = $this->expression($from, $to, $context);
            $parts[] = $part;
            $allLiteral = $allLiteral && ValueExpr::LITERAL === $part->kind;
            $literal .= $part->value;
        }

        $offset = $this->offsets[$first];

        return $allLiteral
            ? ValueExpr::literal($literal, $offset, $this->lineAt($offset))
            : ValueExpr::concat($parts, $offset, $this->lineAt($offset));
    }

    /**
     * The function whose body holds the token, or the class whose body
     * does, whichever is the innermost; null at file scope.
     *
     * @return array{class: true, id: string|null, ranges: list<array{int, int}>, end: int}|array{class: false, id: int, ranges: list<array{int, int}>, end: int, bound: bool, reference: bool}|null
     */
    private function bodyAt(int $index): ?array
    {
        for ($i = \count($this->stack) - 1; $i >= 0; $i--) {
            if (self::contains($this->stack[$i]['ranges'], $index)) {
                return $this->stack[$i];
            }
        }

        return null;
    }

    /**
     * Whether the token is in a closure or an arrow function, which
     * Closure::bind() may give another class and another $this.
     */
    private function isBoundAt(int $index): bool
    {
        $body = $this->bodyAt($index);

        return null !== $body && !$body['class'] && $body['bound'];
    }

    /**
     * The function body the token is in; null at file scope or directly in
     * a class body.
     */
    private function scopeAt(int $index): ?int
    {
        for ($i = \count($this->stack) - 1; $i >= 0; $i--) {
            $entry = $this->stack[$i];
            if (!self::contains($entry['ranges'], $index)) {
                continue;
            }

            return $entry['class'] ? null : $entry['id'];
        }

        return null;
    }

    /**
     * The lowercase name of the class whose body holds the token; null in
     * a trait, an anonymous class, or out of any class.
     */
    private function classAt(int $index): ?string
    {
        for ($i = \count($this->stack) - 1; $i >= 0; $i--) {
            $entry = $this->stack[$i];
            if ($entry['class'] && self::contains($entry['ranges'], $index)) {
                return $entry['id'];
            }
        }

        return null;
    }

    /**
     * One operand, [$first, $last] trimmed: a literal, a heredoc, a
     * constant, a variable, $this->name, an array or a number.
     */
    private function operand(int $first, int $last, NameResolutionContext $context): ValueExpr
    {
        $token = $this->tokens[$first];
        if ($first === $last) {
            return $this->singleToken($first, $context);
        }

        if (\is_array($token) && \T_START_HEREDOC === $token[0]) {
            return $this->heredoc($first, $last);
        }

        if ('[' === $token && ($this->closers[$first] ?? null) === $last) {
            return $this->arrayOf($first, $last, $context);
        }

        // $first < $last: a significant token follows the first.
        $second = $this->next($first + 1) ?? $last;

        if (\is_array($token) && \T_ARRAY === $token[0] && '(' === $this->tokens[$second] && ($this->closers[$second] ?? null) === $last) {
            return $this->arrayOf($second, $last, $context);
        }

        // -1, +2.5: a number, as a key.
        if (('-' === $token || '+' === $token) && $second === $last && \is_array($this->tokens[$last]) && \in_array($this->tokens[$last][0], [\T_LNUMBER, \T_DNUMBER], true)) {
            return ValueExpr::nonString();
        }

        $third = $this->next($second + 1, $last + 1);
        if (null === $third) {
            return ValueExpr::unknown();
        }

        // Kind::Re->value: the backing value of an enum case, read as the
        // case's fetch and then named by its "->value".
        if ($third !== $last) {
            $arrow = $this->next($third + 1);
            $value = null === $arrow ? null : $this->next($arrow + 1);
            if ($value !== $last || !$this->is($arrow, \T_OBJECT_OPERATOR) || 'value' !== PhpTokens::identifier($this->tokens[$last])) {
                return ValueExpr::unknown();
            }

            return ValueExpr::enumCaseValue($this->operand($first, $third, $context));
        }

        $name = PhpTokens::identifier($this->tokens[$last]);
        if (null === $name) {
            return ValueExpr::unknown();
        }

        $operator = $this->tokens[$second];
        if (\is_array($operator) && \T_DOUBLE_COLON === $operator[0]) {
            $class = \is_array($token) && \T_STATIC === $token[0] ? 'static' : PhpTokens::name($token);

            return null === $class || 'class' === Ascii::lower($name) ? ValueExpr::unknown() : $this->classConstant($class, $name, $first, $context);
        }

        if (\is_array($token) && \T_VARIABLE === $token[0] && '$this' === $token[1] && \is_array($operator) && \T_OBJECT_OPERATOR === $operator[0]
            && !$this->isBoundAt($first)) {
            return ValueExpr::property($this->classAt($first), $name);
        }

        return ValueExpr::unknown();
    }

    private function singleToken(int $index, NameResolutionContext $context): ValueExpr
    {
        $token = $this->tokens[$index];
        if (!\is_array($token)) {
            return ValueExpr::unknown();
        }

        if (\T_CONSTANT_ENCAPSED_STRING === $token[0]) {
            return ValueExpr::literal(PhpTokens::stringValue($token[1]), $this->offsets[$index], $token[2]);
        }

        if (\T_LNUMBER === $token[0] || \T_DNUMBER === $token[0]) {
            return ValueExpr::nonString();
        }

        if (\T_VARIABLE === $token[0]) {
            return '$this' === $token[1] ? ValueExpr::unknown() : ValueExpr::variable($this->scopeAt($index), substr($token[1], 1), $this->offsets[$index]);
        }

        $name = PhpTokens::name($token);
        if (null === $name) {
            return ValueExpr::unknown();
        }

        $lower = Ascii::lower(ltrim($name, '\\'));
        if ('true' === $lower || 'false' === $lower) {
            return ValueExpr::nonString();
        }

        if ('null' === $lower) {
            return ValueExpr::unknown();
        }

        return ValueExpr::constant(array_map(ValueExpr::constantKey(...), $context->resolveConstant($name)), $name);
    }

    private function heredoc(int $first, int $last): ValueExpr
    {
        $end = $this->tokens[$last];
        if (!\is_array($end) || \T_END_HEREDOC !== $end[0]) {
            return ValueExpr::unknown();
        }

        $body = '';
        for ($i = $first + 1; $i < $last; $i++) {
            $token = $this->tokens[$i];
            // Anything else between the markers is interpolation.
            if (!\is_array($token) || \T_ENCAPSED_AND_WHITESPACE !== $token[0]) {
                return ValueExpr::unknown();
            }

            $body .= $token[1];
        }

        $start = $this->tokens[$first];
        $value = \is_array($start) ? PhpStringLiteral::decodeHeredoc($start[1], $body, $end[1]) : null;

        return null === $value || !\is_array($start) ? ValueExpr::unknown() : ValueExpr::literal($value, $this->offsets[$first], $start[2]);
    }

    private function classConstant(string $class, string $name, int $index, NameResolutionContext $context): ValueExpr
    {
        $written = $class.'::'.$name;

        return match (Ascii::lower($class)) {
            // A closure may be bound to another class: self:: names none.
            'self' => ValueExpr::classConstant($this->isBoundAt($index) ? null : $this->classAt($index), $name, false, $written),
            'static' => ValueExpr::classConstant($this->isBoundAt($index) ? null : $this->classAt($index), $name, true, $written),
            'parent' => ValueExpr::unknown(),
            default => ValueExpr::classConstant(ValueExpr::classKey($context->resolveClass($class)), $name, false, $written),
        };
    }

    /**
     * The items of the array literal whose brackets are at $open and
     * $close.
     */
    private function arrayOf(int $open, int $close, NameResolutionContext $context): ValueExpr
    {
        $items = [];
        $ranges = $this->split($open + 1, $close);
        foreach ($ranges as $position => [$from, $to]) {
            $first = $this->next($from, $to);
            if (null === $first) {
                // A trailing comma leaves an empty slot; a hole is list() syntax.
                if ($position === \count($ranges) - 1) {
                    continue;
                }

                return ValueExpr::unknown();
            }

            $token = $this->tokens[$first];
            if (\is_array($token) && \in_array($token[0], [\T_ELLIPSIS, \T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG], true)) {
                return ValueExpr::unknown();
            }

            $arrow = $this->find($from, $to, static fn (array|string $token): bool => \is_array($token) && \T_DOUBLE_ARROW === $token[0]);
            $items[] = null === $arrow
                ? [null, $this->expression($from, $to, $context)]
                : [$this->expression($from, $arrow, $context), $this->expression($arrow + 1, $to, $context)];
        }

        return ValueExpr::arrayOf($items);
    }

    private function enterClass(int $index, NameResolutionContext $context): void
    {
        // Foo::class never reaches here: visit() passes over a keyword after "::".
        $previous = $this->previous($index - 1);
        $token = $this->tokens[$index];
        $kind = \is_array($token) ? $token[0] : \T_CLASS;
        $nameIndex = $this->next($index + 1);
        $named = null !== $nameIndex && $this->is($nameIndex, \T_STRING);

        // An anonymous class follows "new", its attributes between; any
        // other "class" is a name: an argument named class:, say.
        if (!$named) {
            $opener = null !== $previous && ']' === $this->tokens[$previous] ? $this->openers[$previous] ?? null : null;
            $new = null !== $opener && $this->is($opener, \T_ATTRIBUTE) ? $this->previous($opener - 1) : $previous;
            if (\T_CLASS !== (\is_array($token) ? $token[0] : null) || null === $new || !$this->is($new, \T_NEW)) {
                return;
            }
        }

        $final = \T_ENUM === $kind;
        for ($i = $previous; null !== $i && $this->is($i, \T_FINAL, \T_ABSTRACT, \T_READONLY); $i = $this->previous($i - 1)) {
            $final = $final || $this->is($i, \T_FINAL);
        }

        $open = null;
        for ($i = $index + 1, $count = \count($this->tokens); $i < $count; $i++) {
            if ('{' === $this->tokens[$i]) {
                $open = $i;

                break;
            }

            if (';' === $this->tokens[$i]) {
                return;
            }

            if (PhpTokens::opensNesting($this->tokens[$i])) {
                $i = $this->closers[$i] ?? $count;
            }
        }

        $close = null === $open ? null : $this->closers[$open] ?? null;
        if (null === $open || null === $close) {
            return;
        }

        $key = null;
        $owner = $named ? ValueExpr::classKey($context->declaredName((string) PhpTokens::name($this->tokens[$nameIndex]))) : null;
        if (null !== $owner && \T_TRAIT === $kind) {
            $this->values->declareTrait($owner);
        } elseif (null !== $owner) {
            $key = $owner;
            $this->values->declareClass($key, $final);
        }

        $extends = \T_CLASS === $kind && null !== $this->find($index + 1, $open, static fn (array|string $token): bool => \is_array($token) && \T_EXTENDS === $token[0]);
        $this->stack[] = ['class' => true, 'id' => $key, 'ranges' => [[$open, $close]], 'end' => $close];
        $this->readMembers($open, $close, $key, $final, $extends, $owner, $context);
    }

    /**
     * Declare the constants and properties of the class body in [$open,
     * $close], and the traits it uses under $owner; mark its "const"
     * tokens.
     */
    private function readMembers(int $open, int $close, ?string $class, bool $final, bool $extends, ?string $owner, NameResolutionContext $context): void
    {
        $modifiers = [];
        $i = $open + 1;
        while ($i < $close) {
            $token = $this->tokens[$i];
            if (PhpTokens::isIgnorable($token)) {
                $i++;

                continue;
            }

            $id = \is_array($token) ? $token[0] : null;
            if (\T_ATTRIBUTE === $id) {
                $i = ($this->closers[$i] ?? $close) + 1;

                continue;
            }

            if (null !== $id && \in_array($id, self::MODIFIERS, true)) {
                $modifiers[$id] = true;
                $i++;

                continue;
            }

            // A "case" among the members is an enum's, declared as its
            // constants are: backed, it declares its value; a pure enum's
            // case declares nothing.
            if (\T_CONST === $id || \T_CASE === $id) {
                if (\T_CONST === $id) {
                    $this->memberConstants[$i] = true;
                }

                $end = $this->statementEnd($i, $close);
                if (null !== $class) {
                    foreach ($this->constantItems($i + 1, $end, $context) as [$name, $value]) {
                        if (\T_CASE === $id) {
                            $this->values->declareEnumCase($class, $name, $value);
                        } else {
                            $this->values->declareClassConstant($class, $name, $value, isset($modifiers[\T_FINAL]));
                        }
                    }
                }

                $modifiers = [];
                $i = $end + 1;

                continue;
            }

            if (\T_FUNCTION === $id || \T_USE === $id) {
                $end = $this->memberEnd($i, $close);
                if (\T_USE === $id && null !== $owner) {
                    // use A, B { ... }: the names before the brace or the ";".
                    for ($j = $i + 1; $j < $end && '{' !== $this->tokens[$j]; $j++) {
                        $trait = PhpTokens::name($this->tokens[$j]);
                        if (null !== $trait) {
                            $this->values->useTrait($owner, ValueExpr::classKey($context->resolveClass($trait)));
                        }
                    }
                }

                $i = $end + 1;
                $modifiers = [];

                continue;
            }

            if (\T_VARIABLE === $id) {
                $i = $this->readProperties($i, $close, $class, $final, $extends, $modifiers, $context) + 1;
                $modifiers = [];

                continue;
            }

            $i = (PhpTokens::opensNesting($token) ? ($this->closers[$i] ?? $close) : $i) + 1;
        }
    }

    /**
     * Declare the properties of a declaration starting at its first
     * variable; the index of its ";" or of the brace closing its hooks.
     *
     * @param array<int, true> $modifiers
     */
    private function readProperties(int $index, int $close, ?string $class, bool $final, bool $extends, array $modifiers, NameResolutionContext $context): int
    {
        $items = [];
        $name = null;
        $equals = null;
        $end = $close;
        $hooks = false;
        for ($i = $index; $i < $close; $i++) {
            $token = $this->tokens[$i];
            if (';' === $token || ',' === $token || '{' === $token) {
                $items[] = [$name, $equals, $i];
                $name = null;
                $equals = null;
                if (',' !== $token) {
                    $hooks = '{' === $token;
                    $end = $hooks ? $this->closers[$i] ?? $close : $i;

                    break;
                }

                continue;
            }

            if (null === $name && \is_array($token) && \T_VARIABLE === $token[0]) {
                $name = substr($token[1], 1);
            } elseif ('=' === $token && null === $equals) {
                $equals = $i;
            } elseif (PhpTokens::opensNesting($token)) {
                $i = $this->closers[$i] ?? $close;
            }
        }

        if (null === $class) {
            return $end;
        }

        // Another file may write a public property, a subclass replace one
        // of an open class, a parent class write a protected one; a hook
        // computes its own value.
        $proves = (isset($modifiers[\T_PRIVATE]) ? $final || isset($modifiers[\T_FINAL]) : isset($modifiers[\T_PROTECTED]) && $final && !$extends)
            && !isset($modifiers[\T_STATIC]) && !$hooks;

        foreach ($items as [$property, $equals, $itemEnd]) {
            if (null !== $property) {
                $this->values->declareProperty($class, $property, $proves && null !== $equals ? $this->expression($equals + 1, $itemEnd, $context) : null);
            }
        }

        return $end;
    }

    /**
     * The end of a method, a trait use or an enum case: its ";" or the
     * brace closing its body.
     */
    private function memberEnd(int $index, int $close): int
    {
        for ($i = $index; $i < $close; $i++) {
            $token = $this->tokens[$i];
            if (';' === $token) {
                return $i;
            }

            if ('{' === $token) {
                return $this->closers[$i] ?? $close;
            }

            if (PhpTokens::opensNesting($token)) {
                $i = $this->closers[$i] ?? $close;
            }
        }

        return $close;
    }

    /**
     * The name and the value of each "NAME = value" of a constant
     * declaration in [$start, $end).
     *
     * @return list<array{string, ValueExpr}>
     */
    private function constantItems(int $start, int $end, NameResolutionContext $context): array
    {
        $items = [];
        foreach ($this->split($start, $end) as [$from, $to]) {
            $equals = $this->find($from, $to, static fn (array|string $token): bool => '=' === $token);
            $name = null === $equals ? null : $this->previous($equals - 1, $from);
            $identifier = null === $name ? null : PhpTokens::identifier($this->tokens[$name]);
            if (null !== $equals && null !== $identifier) {
                $items[] = [$identifier, $this->expression($equals + 1, $to, $context)];
            }
        }

        return $items;
    }

    private function globalConstants(int $index, NameResolutionContext $context): void
    {
        $end = $this->statementEnd($index, \count($this->tokens));
        foreach ($this->constantItems($index + 1, $end, $context) as [$name, $value]) {
            $this->values->declareConstant($context->declaredName($name), $value);
        }
    }

    /**
     * A function, a method or a closure: "use function a\b;" never comes
     * here, the import statements are read before the tokens are handed
     * over.
     */
    private function enterFunction(int $index): void
    {
        $open = $this->next($index + 1);
        $byReference = null !== $open && $this->isAmpersand($open);
        if ($byReference) {
            $open = $this->next($open + 1);
        }

        // A closure has no name: Closure::bind() may give it another class.
        $bound = true;
        if (null !== $open && null !== PhpTokens::identifier($this->tokens[$open])) {
            $open = $this->next($open + 1);
            $bound = false;
        }

        $close = null === $open || '(' !== $this->tokens[$open] ? null : $this->closers[$open] ?? null;
        if (null === $open || null === $close) {
            return;
        }

        $scope = $this->nextScope++;
        $ranges = [[$open, $close]];
        $uses = null;

        $i = $this->next($close + 1);
        if (null !== $i && $this->is($i, \T_USE)) {
            $useOpen = $this->next($i + 1);
            $useClose = null === $useOpen ? null : $this->closers[$useOpen] ?? null;
            if (null !== $useOpen && null !== $useClose) {
                $uses = [$useOpen, $useClose];
                $i = $this->next($useClose + 1);
            }
        }

        // The return type, then the body or the ";" of an abstract method.
        while (null !== $i && '{' !== $this->tokens[$i] && ';' !== $this->tokens[$i]) {
            $i = $this->next((PhpTokens::opensNesting($this->tokens[$i]) ? ($this->closers[$i] ?? $i) : $i) + 1);
        }

        if (null !== $i && '{' === $this->tokens[$i] && isset($this->closers[$i])) {
            $ranges[] = [$i, $this->closers[$i]];
        }

        $this->stack[] = ['class' => false, 'id' => $scope, 'ranges' => $ranges, 'end' => $ranges[\count($ranges) - 1][1], 'bound' => $bound, 'reference' => $byReference];
        $this->bailVariables($open, $close, $scope);

        if (null === $uses) {
            return;
        }

        for ($j = $uses[0] + 1; $j < $uses[1]; $j++) {
            $token = $this->tokens[$j];
            if (!\is_array($token) || \T_VARIABLE !== $token[0]) {
                continue;
            }

            // use (&$re) lets the closure write the variable of the body
            // around it: reference() bails that one, the "&" being visited.
            $this->values->bail($scope, substr($token[1], 1));
        }
    }

    private function enterArrowFunction(int $index): void
    {
        $open = $this->next($index + 1);
        $byReference = null !== $open && $this->isAmpersand($open);
        if ($byReference) {
            $open = $this->next($open + 1);
        }

        $close = null === $open || '(' !== $this->tokens[$open] ? null : $this->closers[$open] ?? null;
        if (null === $open || null === $close) {
            return;
        }

        $arrow = $this->next($close + 1);
        while (null !== $arrow && !$this->is($arrow, \T_DOUBLE_ARROW)) {
            $arrow = $this->next((PhpTokens::opensNesting($this->tokens[$arrow]) ? ($this->closers[$arrow] ?? $arrow) : $arrow) + 1);
        }

        if (null === $arrow) {
            return;
        }

        $scope = $this->nextScope++;
        $end = $this->arrowEnd($arrow + 1);
        $this->stack[] = ['class' => false, 'id' => $scope, 'ranges' => [[$open, $end]], 'end' => $end, 'bound' => true, 'reference' => $byReference];
        $this->bailVariables($open, $close, $scope);
    }

    /**
     * The last token of an arrow function's body, which runs to the first
     * token that closes the expression around it.
     */
    private function arrowEnd(int $start): int
    {
        $ternaries = 0;
        for ($i = $start, $count = \count($this->tokens); $i < $count; $i++) {
            $token = $this->tokens[$i];
            if (\in_array($token, [';', ',', ')', ']', '}'], true) || (\is_array($token) && \T_CLOSE_TAG === $token[0])) {
                return $i - 1;
            }

            if (':' === $token) {
                if (0 === $ternaries) {
                    return $i - 1;
                }

                $ternaries--;
            } elseif ('?' === $token) {
                $ternaries++;
            } elseif (PhpTokens::opensNesting($token)) {
                $i = $this->closers[$i] ?? $count;
            }
        }

        return \count($this->tokens) - 1;
    }

    private function globals(int $index): void
    {
        $scope = $this->scopeAt($index);
        $first = $this->next($index + 1);
        if (null === $first || !$this->is($first, \T_VARIABLE)) {
            return;
        }

        for ($i = $index + 1, $count = \count($this->tokens); $i < $count && ';' !== $this->tokens[$i]; $i++) {
            $token = $this->tokens[$i];
            if (null !== $scope && \is_array($token) && \T_VARIABLE === $token[0]) {
                $this->values->bail($scope, substr($token[1], 1));
            }
        }
    }

    /**
     * "static $a = 1, $b;" in a function body.
     */
    private function staticVariables(int $index): void
    {
        $scope = $this->scopeAt($index);
        $i = $this->next($index + 1);
        if (null === $scope || null === $i || !$this->is($i, \T_VARIABLE)) {
            return;
        }

        $count = \count($this->tokens);
        while (null !== $i && $this->is($i, \T_VARIABLE)) {
            $token = $this->tokens[$i];
            if (\is_array($token)) {
                $this->values->bail($scope, substr($token[1], 1));
            }

            for (; $i < $count && ',' !== $this->tokens[$i] && ';' !== $this->tokens[$i]; $i++) {
                if (PhpTokens::opensNesting($this->tokens[$i])) {
                    $i = $this->closers[$i] ?? $count;
                }
            }

            $i = $i < $count && ',' === $this->tokens[$i] ? $this->next($i + 1) : null;
        }
    }

    private function catchVariable(int $index): void
    {
        $open = $this->next($index + 1);
        $close = null === $open ? null : $this->closers[$open] ?? null;
        if (null === $open || null === $close) {
            return;
        }

        $this->bailVariables($open, $close, $this->scopeAt($index));
    }

    /**
     * unset($a, $this->b): each argument is written.
     */
    private function unsetTargets(int $index): void
    {
        $open = $this->next($index + 1);
        $close = null === $open || '(' !== $this->tokens[$open] ? null : $this->closers[$open] ?? null;
        if (null === $open || null === $close) {
            return;
        }

        $scope = $this->scopeAt($index);
        foreach ($this->split($open + 1, $close) as [$from, $to]) {
            $first = $this->next($from, $to);
            $this->writeBackward(null === $first ? null : $this->fetchArgument($first, $to), $scope);
        }
    }

    private function foreachTargets(int $index): void
    {
        $open = $this->next($index + 1);
        $close = null === $open ? null : $this->closers[$open] ?? null;
        if (null === $open || null === $close) {
            return;
        }

        $as = $this->find($open + 1, $close, static fn (array|string $token): bool => \is_array($token) && \T_AS === $token[0]);
        if (null === $as) {
            return;
        }

        $this->writeEverything($as + 1, $close, $this->scopeAt($index));

        $byReference = null !== $this->find($as + 1, $close, static fn (array|string $token): bool => \is_array($token)
            && (\T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG === $token[0] || \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG === $token[0]));
        if ($byReference && !$this->isArrayLiteral($open + 1, $as)) {
            $this->iterateByReference($index, $open + 1, $as);
        }
    }

    /**
     * A loop of a function body, from its keyword to its last byte: what
     * it holds may run again. One whose end is not known may hold anything.
     */
    private function loop(int $index): void
    {
        $scope = $this->scopeAt($index);
        if (null === $scope) {
            return;
        }

        $end = $this->loopEnd($index);
        if (null === $end) {
            $this->values->jumps($scope);
        } else {
            $this->values->loop($scope, $this->offsets[$index], $this->offsets[$end]);
        }
    }

    /**
     * The last token of the loop whose keyword is at $index, as php-parser
     * ends the statement: the brace closing its body, the ";" after its
     * endfor, endforeach or endwhile, the ";" of a single statement, or,
     * for do, the ";" after its condition, whatever statement its body is.
     * Null for another loop whose body is itself a control structure, as
     * the php-parser strategy reads it, or a loop the file leaves open.
     */
    private function loopEnd(int $index): ?int
    {
        $open = $this->next($index + 1);
        if ($this->is($index, \T_DO)) {
            // "do if (...) ...; while (...);": the while closing the do
            // comes after the whole statement its body is.
            $bodyEnd = null === $open ? null : $this->wholeStatementEnd($open);
            $while = null === $bodyEnd ? null : $this->next($bodyEnd + 1);
            $condition = null !== $while && $this->is($while, \T_WHILE) ? $this->next($while + 1) : null;
            $conditionEnd = null === $condition ? null : $this->closers[$condition] ?? null;

            return null === $conditionEnd ? null : $this->next($conditionEnd + 1) ?? $conditionEnd;
        }

        $close = null !== $open && '(' === $this->tokens[$open] ? $this->closers[$open] ?? null : null;
        $body = null === $close ? null : $this->next($close + 1);
        if (null !== $body && ':' === $this->tokens[$body]) {
            return $this->alternativeEnd($index, $body);
        }

        return null === $body ? null : $this->bodyEnd($body);
    }

    /**
     * The last token of the statement a loop runs, starting at $body: the
     * brace closing a block, the ";" of a single statement. Null for a
     * control structure, whose end is not looked for, or a statement the
     * file leaves open.
     */
    private function bodyEnd(int $body): ?int
    {
        if ('{' === $this->tokens[$body]) {
            return $this->closers[$body] ?? null;
        }

        if ($this->is($body, \T_IF, \T_FOR, \T_FOREACH, \T_WHILE, \T_DO, \T_SWITCH, \T_TRY, \T_DECLARE)) {
            return null;
        }

        $end = $this->statementEnd($body, \count($this->tokens));

        return isset($this->tokens[$end]) ? $end : null;
    }

    /**
     * The last token of the statement that starts at $index, a control
     * structure read to its end: a block's closing brace, the ";" of a
     * single statement, an if with its elseif and else branches, a loop or
     * a switch with its body, a try with its catch and finally blocks, the
     * ";" after an endif, endfor, endforeach, endwhile, endswitch or
     * enddeclare. Null for a statement the file leaves open.
     */
    private function wholeStatementEnd(int $index): ?int
    {
        if ('{' === $this->tokens[$index]) {
            return $this->closers[$index] ?? null;
        }

        if ($this->is($index, \T_IF)) {
            return $this->ifEnd($index);
        }

        if ($this->is($index, \T_TRY)) {
            return $this->tryEnd($index);
        }

        if ($this->is($index, \T_DO)) {
            return $this->loopEnd($index);
        }

        if (!$this->is($index, \T_FOR, \T_FOREACH, \T_WHILE, \T_SWITCH, \T_DECLARE)) {
            $end = $this->statementEnd($index, \count($this->tokens));

            return isset($this->tokens[$end]) ? $end : null;
        }

        $body = $this->afterCondition($index);
        if (null === $body) {
            return null;
        }

        if (':' === $this->tokens[$body]) {
            return $this->is($index, \T_SWITCH, \T_DECLARE)
                ? $this->alternativeBlockEnd($body, $this->is($index, \T_SWITCH) ? \T_SWITCH : \T_DECLARE, $this->is($index, \T_SWITCH) ? \T_ENDSWITCH : \T_ENDDECLARE)
                : $this->alternativeEnd($index, $body);
        }

        return $this->wholeStatementEnd($body);
    }

    /**
     * The first token after the parenthesised condition of the keyword at
     * $index, or null.
     */
    private function afterCondition(int $index): ?int
    {
        $open = $this->next($index + 1);
        $close = null !== $open && '(' === $this->tokens[$open] ? $this->closers[$open] ?? null : null;

        return null === $close ? null : $this->next($close + 1);
    }

    /**
     * The last token of the if at $index: its last branch, elseif and else
     * included, or the ";" after its endif.
     */
    private function ifEnd(int $index): ?int
    {
        for ($at = $index; ;) {
            $body = $this->afterCondition($at);
            if (null === $body) {
                return null;
            }

            if (':' === $this->tokens[$body]) {
                return $this->alternativeBlockEnd($body, \T_IF, \T_ENDIF);
            }

            $end = $this->wholeStatementEnd($body);
            $after = null === $end ? null : $this->next($end + 1);
            if (null !== $after && $this->is($after, \T_ELSEIF)) {
                $at = $after;

                continue;
            }

            if (null !== $after && $this->is($after, \T_ELSE)) {
                $else = $this->next($after + 1);

                return null === $else ? null : $this->wholeStatementEnd($else);
            }

            return $end;
        }
    }

    /**
     * The brace closing the last block of the try at $index: its catch and
     * finally blocks included.
     */
    private function tryEnd(int $index): ?int
    {
        $block = $this->next($index + 1);
        while (null !== $block && '{' === $this->tokens[$block]) {
            $end = $this->closers[$block] ?? null;
            $after = null === $end ? null : $this->next($end + 1);
            if (null === $after || !$this->is($after, \T_CATCH, \T_FINALLY)) {
                return $end;
            }

            $block = $this->is($after, \T_CATCH) ? $this->afterCondition($after) : $this->next($after + 1);
        }

        return null;
    }

    /**
     * The ";" after the $closing keyword (endif, endswitch, enddeclare) that
     * ends the block opened by the ":" at $colon; a block of the same
     * $keyword opened inside it, with its own ":", is stepped over.
     */
    private function alternativeBlockEnd(int $colon, int $keyword, int $closing): ?int
    {
        $depth = 0;
        for ($i = $colon + 1, $count = \count($this->tokens); $i < $count; $i++) {
            if ($this->is($i, $closing)) {
                if (0 === $depth) {
                    return $this->next($i + 1) ?? $i;
                }

                $depth--;
            } elseif ($this->is($i, $keyword)) {
                $body = $this->afterCondition($i);
                if (null !== $body && ':' === $this->tokens[$body]) {
                    $depth++;
                    $i = $body;
                }
            } elseif (PhpTokens::opensNesting($this->tokens[$i])) {
                $i = $this->closers[$i] ?? $count;
            }
        }

        return null;
    }

    /**
     * The ";" after the endfor, endforeach or endwhile closing the loop at
     * $index, whose body starts after the ":" at $colon; a loop of the same
     * keyword inside it is stepped over.
     */
    private function alternativeEnd(int $index, int $colon): ?int
    {
        $keyword = $this->tokens[$index];
        $id = \is_array($keyword) ? $keyword[0] : \T_WHILE;
        $closing = match ($id) {
            \T_FOR => \T_ENDFOR,
            \T_FOREACH => \T_ENDFOREACH,
            default => \T_ENDWHILE,
        };

        for ($i = $colon + 1, $count = \count($this->tokens); $i < $count; $i++) {
            if ($this->is($i, $closing)) {
                return $this->next($i + 1) ?? $i;
            }

            if ($this->is($i, $id)) {
                $inner = $this->loopEnd($i);
                if (null === $inner) {
                    return null;
                }

                $i = $inner;
            } elseif (PhpTokens::opensNesting($this->tokens[$i])) {
                $i = $this->closers[$i] ?? $count;
            }
        }

        return null;
    }

    /**
     * What a by-reference foreach over the tokens in [$start, $end) writes:
     * a named property, that property; anything else may be an object,
     * which has each property its scope sees written, under no name: in a
     * class body, the class's own.
     */
    private function iterateByReference(int $index, int $start, int $end): void
    {
        $first = $this->next($start, $end);
        $last = null === $first ? null : $this->fetchArgument($first, $end);
        $before = null === $last ? null : $this->previous($last - 1);
        if (null !== $last && null !== $before && $this->isObjectOperator($before) && null !== PhpTokens::identifier($this->tokens[$last])) {
            $this->writeBackward($last, $this->scopeAt($index));
        } elseif ($this->inClassBody($index)) {
            $this->values->writeAnyProperty($this->classAt($index));
        }
    }

    /**
     * Whether the tokens in [$start, $end) are an array literal, [...] or
     * array(...), in parentheses or not.
     */
    private function isArrayLiteral(int $start, int $end): bool
    {
        $first = $this->next($start, $end);
        if (null === $first) {
            return false;
        }

        $last = $this->previous($end - 1, $first) ?? $first;
        while ('(' === $this->tokens[$first] && ($this->closers[$first] ?? null) === $last) {
            $first = $this->next($first + 1, $last);
            if (null === $first) {
                return false;
            }

            $last = $this->previous($last - 1, $first) ?? $first;
        }

        $open = $this->is($first, \T_ARRAY) ? $this->next($first + 1, $last + 1) : $first;
        $opener = null === $open ? null : $this->tokens[$open];

        return (('[' === $opener && $open === $first) || ('(' === $opener && $open !== $first)) && ($this->closers[$open] ?? null) === $last;
    }

    /**
     * Whether the token is in the body of a class, an interface, a trait,
     * an enum or an anonymous class.
     */
    private function inClassBody(int $index): bool
    {
        foreach ($this->stack as $entry) {
            if ($entry['class'] && self::contains($entry['ranges'], $index)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A yield in a function declared to return by reference: the caller's
     * foreach may write what it yields, its key and its value.
     */
    private function yielded(int $index): void
    {
        $body = $this->bodyAt($index);
        if (null === $body || $body['class'] || !$body['reference']) {
            return;
        }

        $count = \count($this->tokens);
        $end = $this->find($index + 1, $count, static fn (array|string $token): bool => \in_array($token, [';', ')', ']', ',', '}'], true)
            || (\is_array($token) && \T_CLOSE_TAG === $token[0]));
        $this->writeEverything($index + 1, $end ?? $count, $body['id']);
    }

    private function increment(int $index): void
    {
        $previous = $this->previous($index - 1);
        if (null !== $previous && $this->endsOperand($previous)) {
            $this->writeBackward($previous, $this->scopeAt($index));

            return;
        }

        $next = $this->next($index + 1);
        $this->writeBackward(null === $next ? null : $this->chainEnd($next, \count($this->tokens)), $this->scopeAt($index));
    }

    /**
     * A reference taken: "= &$x", "[&$x]", "use (&$x)", "as &$x".
     */
    private function reference(int $index): void
    {
        // "<?php" stands before any "&".
        $previous = $this->previous($index - 1) ?? $index;
        $token = $this->tokens[$previous];
        if (!\in_array($token, ['=', '(', ',', '['], true) && !$this->is($previous, \T_DOUBLE_ARROW, \T_AS)) {
            return;
        }

        $next = $this->next($index + 1);
        $this->writeBackward(null === $next ? null : $this->chainEnd($next, \count($this->tokens)), $this->scopeAt($index));
    }

    private function assignment(int $index, NameResolutionContext $context): void
    {
        $scope = $this->scopeAt($index);
        // "<?php" stands before any "=".
        $target = $this->previous($index - 1) ?? $index;
        $value = $this->next($index + 1);
        $token = $this->tokens[$target];
        $before = $this->previous($target - 1);
        $plain = \is_array($token) && \T_VARIABLE === $token[0]
            && (null === $before || (!$this->isObjectOperator($before) && !$this->is($before, \T_DOUBLE_COLON) && '$' !== $this->tokens[$before]));

        // "$re = &$x" takes a reference: the variable may change with $x.
        if (!$plain || null === $value || $this->isAmpersand($value)) {
            $this->writeBackward($target, $scope);

            return;
        }

        if (null !== $scope && \is_array($token) && isset($this->watchedVariables[$token[1]])) {
            $this->values->assign($scope, substr($token[1], 1), $this->assignedValue($value, $context), $this->offsets[$target]);
        }
    }

    /**
     * The value of an assignment whose right side starts at $start, read
     * up to the token that closes it.
     */
    private function assignedValue(int $start, NameResolutionContext $context): ValueExpr
    {
        // Only a constant expression proves a value: anything else need
        // not be read.
        $token = $this->tokens[$start];
        $id = \is_array($token) ? $token[0] : null;
        // A local array of literals proves no pattern either: it is not
        // built.
        if (!\in_array($token, ['(', '-', '+'], true) && null === PhpTokens::name($token)
            && !\in_array($id, [\T_CONSTANT_ENCAPSED_STRING, \T_START_HEREDOC, \T_STATIC, \T_LNUMBER, \T_DNUMBER], true)) {
            return ValueExpr::unknown();
        }

        // A ":" ends the value, or cuts a ternary short: the "?" left in
        // the value proves nothing either way.
        $count = \count($this->tokens);
        for ($i = $start; $i < $count; $i++) {
            $token = $this->tokens[$i];
            if (\in_array($token, [';', ',', ')', ']', '}', ':'], true)
                || (\is_array($token) && \in_array($token[0], [\T_AS, \T_DOUBLE_ARROW, \T_LOGICAL_AND, \T_LOGICAL_OR, \T_LOGICAL_XOR, \T_CLOSE_TAG], true))) {
                break;
            }

            if (PhpTokens::opensNesting($token)) {
                $i = $this->closers[$i] ?? $count;
            }
        }

        return $this->expression($start, $i, $context);
    }

    /**
     * A parenthesis: when it opens the arguments of a call, the variables
     * passed there may be written, by reference; extract() and compact()
     * reach every variable; define() declares a constant.
     */
    private function call(int $index, NameResolutionContext $context): void
    {
        $close = $this->closers[$index] ?? null;
        $callee = $this->previous($index - 1);
        if (null === $close || null === $callee) {
            return;
        }

        $function = null;
        $token = $this->tokens[$callee];
        $before = $this->previous($callee - 1);
        if (\is_array($token) && \T_VARIABLE === $token[0]) {
            // $callback($re)
        } elseif (null !== $before && PhpTokens::isObjectOrStaticOperator($this->tokens[$before]) && null !== PhpTokens::identifier($token)) {
            // $object->method($re), Foo::method($re)
        } elseif (null !== PhpTokens::name($token)) {
            if (null !== $before && ($this->is($before, \T_FUNCTION) || ($this->isAmpersand($before) && $this->isPreceded($before, \T_FUNCTION)))) {
                return; // a declaration
            }

            if (null === $before || !$this->is($before, \T_NEW)) {
                $function = PhpTokens::name($token);
            }
        } elseif ('"' === $token || $this->is($callee, \T_CONSTANT_ENCAPSED_STRING, \T_END_HEREDOC)) {
            // 'normalize'($re): a string names the function.
        } elseif (\is_array($token) && \in_array($token[0], [\T_STATIC, \T_CLASS], true)) {
            // new static($re), new class($re) {}, new #[A] class($re) {}
            while (null !== $before && ']' === $this->tokens[$before] && $this->is($this->openers[$before] ?? $before, \T_ATTRIBUTE)) {
                $before = $this->previous(($this->openers[$before] ?? $before) - 1);
            }

            if (null === $before || !$this->is($before, \T_NEW)) {
                return;
            }
        } elseif (')' === $token || ']' === $token || '}' === $token) {
            $opener = $this->openers[$callee] ?? null;
            $beforeOpener = null === $opener ? null : $this->previous($opener - 1);
            $openerToken = null === $opener ? null : $this->tokens[$opener];
            if (null === $opener || (\is_array($openerToken) && \T_ATTRIBUTE === $openerToken[0])) {
                return;
            }

            if (')' === $token && null !== $beforeOpener && \is_array($this->tokens[$beforeOpener]) && \in_array($this->tokens[$beforeOpener][0], self::NOT_CALLS, true)) {
                return;
            }

            if ('}' === $token && (null === $beforeOpener || (!PhpTokens::isObjectOrStaticOperator($this->tokens[$beforeOpener]) && '$' !== $this->tokens[$beforeOpener]))) {
                return;
            }
        } else {
            return;
        }

        $scope = $this->scopeAt($index);
        if (null !== $function) {
            $resolved = Ascii::lower($context->resolveFunction($function));
            if ('extract' === $resolved || 'compact' === $resolved) {
                $this->bailScope($index);
            } elseif ('define' === $resolved) {
                $this->define($index, $close, $context);
            } elseif (('array_walk' === $resolved || 'array_walk_recursive' === $resolved) && !$this->walksLiteralArray($index, $close)) {
                // array_walk() over an object writes each of its properties,
                // private ones included, from any scope.
                $this->values->writeAnyProperty(null);
            }
        }

        if (!$this->watchesWrites) {
            return;
        }

        foreach ($this->split($index + 1, $close) as [$from, $to]) {
            $value = $this->argumentValue($from, $to);
            if (null === $value || isset($this->patternArguments[$value])) {
                continue;
            }

            // A variable no pattern argument names, with no property watched.
            $token = $this->tokens[$value];
            if (!$this->watchesProperties && \is_array($token) && \T_VARIABLE === $token[0] && !isset($this->watchedVariables[$token[1]])) {
                continue;
            }

            // $this passed: the callee may write any property of it, as
            // array_walk($this, ...) does, private ones included.
            if (\is_array($token) && '$this' === $token[1] && null === $this->next($value + 1, $to)) {
                $this->values->writeAnyProperty($this->classAt($index));

                continue;
            }

            $this->writeBackward($this->fetchArgument($value, $to), $scope);
        }
    }

    /**
     * Whether array_walk() walks a literal array: its first argument, or
     * the one named "array". A spread first may pass anything.
     */
    private function walksLiteralArray(int $open, int $close): bool
    {
        foreach ($this->split($open + 1, $close) as $position => [$from, $to]) {
            $first = $this->next($from, $to);
            $name = null === $first ? null : PhpTokens::identifier($this->tokens[$first]);
            $colon = null === $name || null === $first ? null : $this->next($first + 1, $to);
            if (null !== $colon && ':' === $this->tokens[$colon]) {
                if ('array' === $name) {
                    return $this->isArrayLiteral($colon + 1, $to);
                }
            } elseif (0 === $position) {
                return null !== $first && !$this->is($first, \T_ELLIPSIS) && $this->isArrayLiteral($from, $to);
            }
        }

        return false;
    }

    /**
     * define('NAME', value), the name a literal.
     */
    private function define(int $open, int $close, NameResolutionContext $context): void
    {
        $arguments = $this->split($open + 1, $close);
        if (2 !== \count($arguments)) {
            return;
        }

        $name = $this->next($arguments[0][0], $arguments[0][1]);
        $value = $this->argumentValue(...$arguments[1]);
        $nameToken = null === $name ? null : $this->tokens[$name];
        if (null === $name || null === $value || $value !== $this->next($arguments[1][0], $arguments[1][1]) || null !== $this->next($name + 1, $arguments[0][1])
            || !\is_array($nameToken) || \T_CONSTANT_ENCAPSED_STRING !== $nameToken[0]) {
            return;
        }

        $constant = PhpTokens::stringValue($nameToken[1]);
        if ('' !== $constant) {
            $this->values->declareConstant($constant, $this->expression($arguments[1][0], $arguments[1][1], $context));
        }
    }

    /**
     * Where the value of an argument in [$from, $to) starts, past its name
     * and its spread; null for an empty slot.
     */
    private function argumentValue(int $from, int $to): ?int
    {
        $first = $this->next($from, $to);
        if (null === $first) {
            return null;
        }

        if ($this->is($first, \T_ELLIPSIS)) {
            return $this->next($first + 1, $to);
        }

        $colon = null === PhpTokens::identifier($this->tokens[$first]) ? null : $this->next($first + 1, $to);
        if (null !== $colon && ':' === $this->tokens[$colon]) {
            return $this->next($colon + 1, $to);
        }

        return $first;
    }

    /**
     * The last token of the variable, property or offset chain starting at
     * $start ($a, $a->b['c'], $a->b()->c), or null when no chain starts
     * there or it ends on a call.
     */
    private function chainEnd(int $start, int $limit): ?int
    {
        if (!$this->is($start, \T_VARIABLE)) {
            return null;
        }

        $end = $start;
        $call = false;
        while (true) {
            $next = $this->next($end + 1, $limit);
            if (null === $next) {
                return $call ? null : $end;
            }

            // An offset, or a call on what precedes: $a->b()[0] is an offset again.
            if ('[' === $this->tokens[$next] || '(' === $this->tokens[$next]) {
                $call = '(' === $this->tokens[$next];
                $end = $this->closers[$next] ?? $limit;

                continue;
            }

            if (!$this->isObjectOperator($next)) {
                return $call ? null : $end;
            }

            $member = $this->next($next + 1, $limit);
            if (null === $member || ('{' !== $this->tokens[$member] && null === PhpTokens::identifier($this->tokens[$member]) && !$this->is($member, \T_VARIABLE))) {
                return null;
            }

            $end = '{' === $this->tokens[$member] ? $this->closers[$member] ?? $limit : $member;
            $call = false;
        }
    }

    /**
     * The last token of the argument starting at $first, up to $to, when
     * the argument is a variable, a property or an offset, whatever its
     * receiver: what a call may take by reference. Null otherwise.
     */
    private function fetchArgument(int $first, int $to): ?int
    {
        $last = $this->previous($to - 1, $first);

        // The parentheses around an argument are no part of it.
        while (null !== $last && '(' === $this->tokens[$first] && ($this->closers[$first] ?? null) === $last) {
            $first = $this->next($first + 1, $last) ?? $last;
            $last = $this->previous($last - 1, $first);
        }

        return null !== $last && $this->fetchStart($last) === $first ? $last : null;
    }

    /**
     * Where the variable, property or offset expression ending at $last
     * starts ($a, $a->b['c'], f()->d, ($x)->e, Foo::$p->q), or null when
     * no such expression ends there: a call, a literal, a constant.
     */
    private function fetchStart(int $last): ?int
    {
        $token = $this->tokens[$last];
        $before = $this->previous($last - 1);
        $opener = $this->openers[$last] ?? null;
        $beforeOpener = null === $opener ? null : $this->previous($opener - 1);
        $member = null !== $before && $this->isObjectOperator($before);
        $fetch = match (true) {
            // Foo::$p and $$p lead to no write: the walk finds that.
            \is_array($token) && \T_VARIABLE === $token[0] => true,
            ']' === $token => null !== $beforeOpener && $this->endsOperand($beforeOpener),
            '}' === $token => null !== $beforeOpener && $this->isObjectOperator($beforeOpener),
            default => $member && null !== PhpTokens::identifier($token),
        };

        $i = $last;
        while ($fetch && null !== $i) {
            $token = $this->tokens[$i];
            $before = $this->previous($i - 1);
            $opener = $this->openers[$i] ?? null;
            $beforeOpener = null === $opener ? null : $this->previous($opener - 1);

            if (\is_array($token) && \T_VARIABLE === $token[0] || null !== PhpTokens::identifier($token)) {
                // ->member, ->$member, Foo::$static, Foo::method(): the receiver comes before.
                if (null !== $before && PhpTokens::isObjectOrStaticOperator($this->tokens[$before])) {
                    $i = $this->previous($before - 1);

                    continue;
                }

                // $$name; new Foo()->bar
                return null !== $before && ('$' === $this->tokens[$before] || $this->is($before, \T_NEW)) ? $before : $i;
            }

            // "<?php" stands before any opener.
            if (null === $opener || null === $beforeOpener) {
                return $i;
            }

            // ->{...}, ${...}
            if ('}' === $token) {
                if ('$' === $this->tokens[$beforeOpener]) {
                    return $beforeOpener;
                }

                $i = $this->isObjectOperator($beforeOpener) ? $this->previous($beforeOpener - 1) : null;

                continue;
            }

            // An offset of an operand, or a call on it; else a parenthesised
            // expression or an array literal heads the chain.
            if ($this->endsOperand($beforeOpener)) {
                $i = $beforeOpener;

                continue;
            }

            return $opener;
        }

        return null;
    }

    /**
     * Mark what the expression ending at $index, written to, may change:
     * the variable at its root, the property names along it, everything a
     * destructuring names.
     */
    private function writeBackward(?int $index, ?int $scope): void
    {
        while (null !== $index) {
            $token = $this->tokens[$index];
            $before = $this->previous($index - 1);

            if (')' === $token) {
                $opener = $this->openers[$index] ?? null;
                $beforeOpener = null === $opener ? null : $this->previous($opener - 1);
                if (null === $opener) {
                    return;
                }

                // list(...) = ...
                if (null !== $beforeOpener && $this->is($beforeOpener, \T_LIST)) {
                    $this->writeEverything($opener, $index, $scope);

                    return;
                }

                // A call's result; or a parenthesis around the target.
                if (null !== $beforeOpener && $this->endsOperand($beforeOpener)) {
                    return;
                }

                $index = $this->previous($index - 1, $opener + 1);

                continue;
            }

            if (\is_array($token) && \T_VARIABLE === $token[0]) {
                // ->$name: any property of the class.
                if (null !== $before && $this->isObjectOperator($before)) {
                    $this->values->writeAnyProperty($this->classAt($index));
                    $index = $this->previous($before - 1);

                    continue;
                }

                if (null !== $scope && (null === $before || (!$this->is($before, \T_DOUBLE_COLON) && '$' !== $this->tokens[$before]))) {
                    $this->values->bail($scope, substr($token[1], 1));
                }

                return;
            }

            if (']' === $token) {
                $opener = $this->openers[$index] ?? null;
                $beforeOpener = null === $opener ? null : $this->previous($opener - 1);
                if (null === $opener) {
                    return;
                }

                if (null !== $beforeOpener && $this->endsOperand($beforeOpener)) {
                    $index = $beforeOpener;

                    continue;
                }

                $this->writeEverything($opener, $index, $scope);

                return;
            }

            if ('}' === $token) {
                $opener = $this->openers[$index] ?? null;
                $beforeOpener = null === $opener ? null : $this->previous($opener - 1);
                // ->{$name}, ->{'name'}: any property of the class.
                if (null !== $beforeOpener && $this->isObjectOperator($beforeOpener)) {
                    $this->values->writeAnyProperty($this->classAt($index));
                    $index = $this->previous($beforeOpener - 1);

                    continue;
                }

                return;
            }

            $name = PhpTokens::identifier($token);
            if (null !== $name && null !== $before && $this->isObjectOperator($before)) {
                $this->values->writeProperty($name);
                $index = $this->previous($before - 1);

                continue;
            }

            return;
        }
    }

    /**
     * Mark every variable and property name in [$from, $to): a
     * destructuring, a foreach target.
     */
    private function writeEverything(int $from, int $to, ?int $scope): void
    {
        for ($i = $from; $i < $to; $i++) {
            $token = $this->tokens[$i];
            if (PhpTokens::isIgnorable($token)) {
                continue;
            }

            $before = $this->previous($i - 1);
            // ->$name, ->{...}: any property of the class.
            if (null !== $before && $this->isObjectOperator($before) && ('{' === $token || $this->is($i, \T_VARIABLE))) {
                $this->values->writeAnyProperty($this->classAt($i));
            }

            if (\is_array($token) && \T_VARIABLE === $token[0]) {
                if (null !== $scope && (null === $before || !$this->is($before, \T_DOUBLE_COLON))) {
                    $this->values->bail($scope, substr($token[1], 1));
                }

                continue;
            }

            $name = PhpTokens::identifier($token);
            $after = $this->next($i + 1, $to);
            if (null !== $name && null !== $before && $this->isObjectOperator($before) && (null === $after || '(' !== $this->tokens[$after])) {
                $this->values->writeProperty($name);
            }
        }
    }

    private function bailVariables(int $open, int $close, ?int $scope): void
    {
        if (null === $scope) {
            return;
        }

        for ($i = $open + 1; $i < $close; $i++) {
            $token = $this->tokens[$i];
            if (\is_array($token) && \T_VARIABLE === $token[0]) {
                $this->values->bail($scope, substr($token[1], 1));
            }
        }
    }

    private function bailScope(int $index): void
    {
        $scope = $this->scopeAt($index);
        if (null !== $scope) {
            $this->values->bailScope($scope);
        }
    }

    /**
     * The ranges of the items separated by top-level commas in [$start,
     * $end).
     *
     * @return list<array{int, int}>
     */
    private function split(int $start, int $end): array
    {
        $ranges = [];
        $from = $start;
        for ($i = $start; $i < $end; $i++) {
            $token = $this->tokens[$i];
            if (',' === $token) {
                $ranges[] = [$from, $i];
                $from = $i + 1;
            } elseif (PhpTokens::opensNesting($token)) {
                $i = $this->closers[$i] ?? $end;
            }
        }

        $ranges[] = [$from, $end];

        return $ranges;
    }

    /**
     * The first top-level token of [$start, $end) the test accepts.
     *
     * @param \Closure(array{int, string, int}|string): bool $test
     */
    private function find(int $start, int $end, \Closure $test): ?int
    {
        for ($i = $start; $i < $end; $i++) {
            $token = $this->tokens[$i];
            if ($test($token)) {
                return $i;
            }

            if (PhpTokens::opensNesting($token)) {
                $i = $this->closers[$i] ?? $end;
            }
        }

        return null;
    }

    /**
     * The ";" ending the statement that starts at $index; the limit, where
     * the source is cut short before one: the statement runs up to it.
     */
    private function statementEnd(int $index, int $limit): int
    {
        for ($i = $index; $i < $limit; $i++) {
            $token = $this->tokens[$i];
            if (';' === $token) {
                return $i;
            }

            if (PhpTokens::opensNesting($token)) {
                $i = $this->closers[$i] ?? $limit;
            }
        }

        return $limit;
    }

    private function lineAt(int $offset): int
    {
        if (null === $this->newlines) {
            $this->newlines = [];
            for ($at = strpos($this->content, "\n"); false !== $at; $at = strpos($this->content, "\n", $at + 1)) {
                $this->newlines[] = $at;
            }
        }

        // The newlines before the offset, by bisection.
        $low = 0;
        $high = \count($this->newlines);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->newlines[$middle] < $offset) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low + 1;
    }

    /**
     * @param list<array{int, int}> $ranges
     */
    private static function contains(array $ranges, int $index): bool
    {
        foreach ($ranges as [$from, $to]) {
            if ($index >= $from && $index <= $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * The next significant token of the file from $start, before $end.
     */
    private function next(int $start, ?int $end = null): ?int
    {
        return PhpTokens::nextSignificant($this->tokens, $start, $end ?? \PHP_INT_MAX);
    }

    /**
     * The previous significant token of the file from $start, not before $floor.
     */
    private function previous(int $start, int $floor = 0): ?int
    {
        return PhpTokens::previousSignificant($this->tokens, $start, $floor);
    }

    private function isPreceded(int $index, int $id): bool
    {
        $before = $this->previous($index - 1);

        return null !== $before && $this->is($before, $id);
    }

    private function is(int $index, int ...$ids): bool
    {
        $token = $this->tokens[$index];

        return \is_array($token) && \in_array($token[0], $ids, true);
    }

    private function isAmpersand(int $index): bool
    {
        return $this->is($index, \T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG, \T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG);
    }

    private function isObjectOperator(int $index): bool
    {
        return $this->is($index, \T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR);
    }

    /**
     * Whether the token ends an operand an offset may follow: $a[...],
     * $a->b[...], f()[...], "abc"[...].
     */
    private function endsOperand(int $index): bool
    {
        $token = $this->tokens[$index];
        if (\in_array($token, [']', ')', '}'], true) || $this->is($index, \T_VARIABLE, \T_CONSTANT_ENCAPSED_STRING) || null !== PhpTokens::name($token)) {
            return true;
        }

        $before = $this->previous($index - 1);

        return null !== PhpTokens::identifier($token) && null !== $before && PhpTokens::isObjectOrStaticOperator($this->tokens[$before]);
    }
}

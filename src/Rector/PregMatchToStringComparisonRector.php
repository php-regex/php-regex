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

use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Greater;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\LogicalAnd;
use PhpParser\Node\Expr\BinaryOp\LogicalOr;
use PhpParser\Node\Expr\BinaryOp\LogicalXor;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BinaryOp\Smaller;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Cast\Bool_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\While_;
use PHPRegex\Automata\TrivialMatch;
use PHPRegex\Automata\TrivialMatchKind;
use PHPRegex\Rector\Internal\PregCall;
use PHPRegex\Rector\Internal\ProvenLiterals;
use Rector\Php\PhpVersionProvider;
use Rector\Rector\AbstractRector;
use Rector\ValueObject\PhpVersion;
use Rector\VersionBonding\Contract\MinPhpVersionInterface;

/**
 * Rewrites a preg_match() read as a boolean into the string function or the
 * comparison the automata prove it answers alike: "/^foo/" becomes
 * str_starts_with(), "/^(?:GET|POST)\z/" an in_array().
 *
 * Only where the call's int|false is read as true or false: a condition, a
 * "!", a logical operand, a full ternary's condition, a "(bool)" cast, or a
 * strict comparison with 1 or 0 ("> 0" too). Anywhere else, and for any call
 * the proof does not cover, the code is left as it is.
 */
final class PregMatchToStringComparisonRector extends AbstractRector implements MinPhpVersionInterface
{
    private readonly ProvenLiterals $literals;

    public function __construct(private readonly PhpVersionProvider $phpVersionProvider)
    {
        $this->literals = new ProvenLiterals();
    }

    /**
     * str_contains(), str_starts_with() and str_ends_with() arrived in 8.0.
     */
    public function provideMinPhpVersion(): int
    {
        return PhpVersion::PHP_80;
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [
            If_::class, ElseIf_::class, While_::class, Do_::class, For_::class, Ternary::class,
            BooleanNot::class, Bool_::class,
            BooleanAnd::class, BooleanOr::class, LogicalAnd::class, LogicalOr::class, LogicalXor::class,
            Identical::class, NotIdentical::class, Greater::class, Smaller::class,
        ];
    }

    /**
     * @param If_|ElseIf_|While_|Do_|For_|Ternary|BooleanNot|Bool_|BinaryOp $node
     */
    public function refactor(Node $node): ?Node
    {
        return match (true) {
            $node instanceof If_, $node instanceof ElseIf_, $node instanceof While_, $node instanceof Do_ => $this->refactorCondition($node),
            $node instanceof For_ => $this->refactorFor($node),
            $node instanceof Ternary => $this->refactorTernary($node),
            $node instanceof BooleanNot => $this->rewrite($node->expr, false),
            $node instanceof Bool_ => $this->rewrite($node->expr, true),
            $node instanceof BooleanAnd, $node instanceof BooleanOr, $node instanceof LogicalAnd, $node instanceof LogicalOr, $node instanceof LogicalXor => $this->refactorOperands($node),
            $node instanceof Identical, $node instanceof NotIdentical => $this->refactorIdentity($node),
            $node instanceof Greater => self::isInt($node->right, 0) ? $this->rewrite($node->left, true) : null,
            $node instanceof Smaller => self::isInt($node->left, 0) ? $this->rewrite($node->right, true) : null,
            default => null,
        };
    }

    private function refactorCondition(If_|ElseIf_|While_|Do_ $node): ?Node
    {
        $rewritten = $this->rewrite($node->cond, true);
        if (null === $rewritten) {
            return null;
        }
        $node->cond = $rewritten;

        return $node;
    }

    /**
     * The loop goes on while the last expression of its condition is true;
     * the ones before it are evaluated for their effects only.
     */
    private function refactorFor(For_ $node): ?Node
    {
        $last = array_key_last($node->cond);
        $rewritten = null === $last ? null : $this->rewrite($node->cond[$last], true);
        if (null === $rewritten) {
            return null;
        }
        $node->cond[$last] = $rewritten;

        return $node;
    }

    /**
     * Only the full ternary: the short one returns the call's own value.
     */
    private function refactorTernary(Ternary $node): ?Node
    {
        $rewritten = null === $node->if ? null : $this->rewrite($node->cond, true);
        if (null === $rewritten) {
            return null;
        }
        $node->cond = $rewritten;

        return $node;
    }

    private function refactorOperands(BinaryOp $node): ?Node
    {
        $left = $this->rewrite($node->left, true);
        $right = $this->rewrite($node->right, true);
        if (null === $left && null === $right) {
            return null;
        }
        $node->left = $left ?? $node->left;
        $node->right = $right ?? $node->right;

        return $node;
    }

    /**
     * "=== 1" and "!== 0" ask whether it matched, "=== 0" and "!== 1"
     * whether it did not, the literal on either side.
     */
    private function refactorIdentity(Identical|NotIdentical $node): ?Expr
    {
        foreach ([[$node->left, $node->right], [$node->right, $node->left]] as [$call, $literal]) {
            foreach ([1, 0] as $value) {
                if (self::isInt($literal, $value)) {
                    return $this->rewrite($call, ($node instanceof Identical) === (1 === $value));
                }
            }
        }

        return null;
    }

    /**
     * The expression that says what the call says, or what it does not when
     * $matches is false; null when the call is no preg_match() the automata
     * name a function for.
     */
    private function rewrite(Expr $expr, bool $matches): ?Expr
    {
        if (!$expr instanceof FuncCall || !$this->isName($expr, 'preg_match')) {
            return null;
        }

        $arguments = PregCall::arguments($expr, 2, 2);
        if (null === $arguments) {
            return null;
        }
        [$pattern, $subject] = $arguments;

        $match = $this->match($pattern);
        if (null === $match || !$this->acceptsSubject($match->kind, $subject)) {
            return null;
        }

        return $matches ? self::positive($match, $subject) : self::negative($match, $subject);
    }

    private function match(Expr $pattern): ?TrivialMatch
    {
        $value = PregCall::isConstantExpression($pattern) ? $this->constantString($pattern) : null;

        return null === $value ? null : $this->literals->match($value, $this->phpVersionProvider->provide());
    }

    /**
     * The string functions throw on another type under strict_types where
     * preg_match() converts it, so the subject must be a string; "===" and
     * in_array() compare without converting, so it must be one by its
     * declared type, not by a docblock that may be wrong.
     *
     * "===" takes the subject out of the call's parentheses and binds it to
     * the literal, so the subject must be an operand no operator can split:
     * "$a ?: $b", "$a ?? $b", "$a = $b" or "$a . $b" would bind otherwise.
     */
    private function acceptsSubject(TrivialMatchKind $kind, Expr $subject): bool
    {
        return match ($kind) {
            TrivialMatchKind::Contains, TrivialMatchKind::StartsWith, TrivialMatchKind::EndsWith => $this->getType($subject)->isString()->yes(),
            TrivialMatchKind::OneOf => $this->getNativeType($subject)->isString()->yes(),
            TrivialMatchKind::Equals, TrivialMatchKind::IsEmpty => self::isOperand($subject) && $this->getNativeType($subject)->isString()->yes(),
        };
    }

    /**
     * Whether the expression prints as one operand, whatever operator holds
     * it: a variable, a property, an element, a call or a constant.
     */
    private static function isOperand(Expr $expr): bool
    {
        return $expr instanceof Variable
            || $expr instanceof PropertyFetch || $expr instanceof NullsafePropertyFetch || $expr instanceof StaticPropertyFetch
            || $expr instanceof ArrayDimFetch
            || $expr instanceof FuncCall || $expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof StaticCall
            || $expr instanceof ClassConstFetch || $expr instanceof ConstFetch;
    }

    private static function positive(TrivialMatch $match, Expr $subject): Expr
    {
        $literal = PregCall::string($match->literals[0] ?? '');

        return match ($match->kind) {
            TrivialMatchKind::Contains => PregCall::call('str_contains', [$subject, $literal]),
            TrivialMatchKind::StartsWith => PregCall::call('str_starts_with', [$subject, $literal]),
            TrivialMatchKind::EndsWith => PregCall::call('str_ends_with', [$subject, $literal]),
            TrivialMatchKind::Equals, TrivialMatchKind::IsEmpty => new Identical($literal, $subject),
            TrivialMatchKind::OneOf => PregCall::call('in_array', [
                $subject,
                new Array_(array_map(static fn (string $value): ArrayItem => new ArrayItem(PregCall::string($value)), $match->literals), ['kind' => Array_::KIND_SHORT]),
                new ConstFetch(new Name('true')),
            ]),
        };
    }

    private static function negative(TrivialMatch $match, Expr $subject): Expr
    {
        return match ($match->kind) {
            TrivialMatchKind::Equals, TrivialMatchKind::IsEmpty => new NotIdentical(PregCall::string($match->literals[0] ?? ''), $subject),
            default => new BooleanNot(self::positive($match, $subject)),
        };
    }

    /**
     * The value of an expression PHPStan knows to be one string.
     */
    private function constantString(Expr $expr): ?string
    {
        $type = $this->getType($expr);
        $values = $type->getConstantStrings();

        return 1 === \count($values) && $type->isString()->yes() ? $values[0]->getValue() : null;
    }

    private static function isInt(Expr $expr, int $value): bool
    {
        return $expr instanceof Int_ && $value === $expr->value;
    }
}

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

namespace PHPRegex\PHPStan;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use PHPRegex\Parser\Text\Ascii;
use PHPStan\Analyser\Scope;
use PHPStan\Collectors\Collector;
use PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use PHPStan\PhpDocParser\Ast\Node as PhpDocNode;
use PHPStan\PhpDocParser\Ast\NodeTraverser;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;

/**
 * Counts the mixed types a declaration writes, in its native parameter,
 * return and property types and in the types of its PHPDoc tags, under the
 * symbol the declaration names: a class, a function or method, a property,
 * a constant. A closure and an inline PHPDoc count for the function or
 * method around them.
 *
 * @internal the library's check on its own declared types
 *
 * @implements Collector<Node, array{symbol: string, line: int, position: int, count: int}>
 */
final class MixedTypeCollector implements Collector
{
    public function __construct(private readonly Lexer $lexer, private readonly PhpDocParser $phpDocParser) {}

    public function getNodeType(): string
    {
        return Node::class;
    }

    public function processNode(Node $node, Scope $scope): ?array
    {
        $count = match (true) {
            $node instanceof ClassLike, $node instanceof ClassConst => $this->inDocComment($node),
            $node instanceof Property => $this->inDocComment($node) + self::inNativeType($node->type),
            $node instanceof FunctionLike => $this->inFunctionLike($node),
            $node instanceof Stmt => $this->inDocComment($node),
            default => 0,
        };

        if (0 === $count) {
            return null;
        }

        return [
            'symbol' => self::symbol($node, $scope),
            'line' => $node->getStartLine(),
            'position' => $node->getStartFilePos(),
            'count' => $count,
        ];
    }

    private function inFunctionLike(FunctionLike $node): int
    {
        $count = self::inNativeType($node->getReturnType());
        foreach ($node->getParams() as $param) {
            $count += self::inNativeType($param->type) + $this->inDocComment($param);
        }

        // A closure's PHPDoc sits on the statement around it, read there.
        if ($node instanceof ClassMethod || $node instanceof Function_) {
            $count += $this->inDocComment($node);
        }

        return $count;
    }

    /**
     * A native mixed stands alone: PHP refuses it in a union and as nullable.
     */
    private static function inNativeType(Identifier|Name|ComplexType|null $type): int
    {
        return $type instanceof Identifier && 'mixed' === $type->toLowerString() ? 1 : 0;
    }

    private function inDocComment(Node $node): int
    {
        $docComment = $node->getDocComment();
        if (!$docComment instanceof Doc) {
            return 0;
        }

        $phpDoc = $this->phpDocParser->parse(new TokenIterator($this->lexer->tokenize($docComment->getText())));
        $counter = new class extends AbstractNodeVisitor {
            public int $count = 0;

            public function enterNode(PhpDocNode $node): ?PhpDocNode
            {
                if ($node instanceof IdentifierTypeNode && 'mixed' === Ascii::lower($node->name)) {
                    $this->count++;
                }

                return null;
            }
        };
        (new NodeTraverser([$counter]))->traverse([$phpDoc]);

        return $counter->count;
    }

    /**
     * The declaration a node belongs to, named without its line, so that
     * moving code does not move the key.
     */
    private static function symbol(Node $node, Scope $scope): string
    {
        $class = self::className($node, $scope);

        return match (true) {
            $node instanceof ClassLike => $class ?? 'class@anonymous',
            $node instanceof ClassMethod => \sprintf('%s::%s()', $class ?? 'class@anonymous', $node->name->toString()),
            $node instanceof Function_ => \sprintf('%s()', ($node->namespacedName ?? $node->name)->toString()),
            $node instanceof Property => \sprintf('%s::$%s', $class ?? 'class@anonymous', $node->props[0]->name->toString()),
            $node instanceof ClassConst => \sprintf('%s::%s', $class ?? 'class@anonymous', $node->consts[0]->name->toString()),
            default => self::enclosing($scope, $class),
        };
    }

    private static function enclosing(Scope $scope, ?string $class): string
    {
        $function = $scope->getFunction();
        if (null === $function) {
            return $class ?? '';
        }

        return null === $class ? \sprintf('%s()', $function->getName()) : \sprintf('%s::%s()', $class, $function->getName());
    }

    private static function className(Node $node, Scope $scope): ?string
    {
        if ($node instanceof ClassLike) {
            return $node->namespacedName?->toString();
        }

        if ($scope->isInTrait()) {
            return $scope->getTraitReflection()->getName();
        }

        $class = $scope->getClassReflection();
        if (null === $class) {
            return null;
        }

        return $class->isAnonymous() ? 'class@anonymous' : $class->getName();
    }
}

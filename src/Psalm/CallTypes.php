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

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\NullsafePropertyFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PHPRegex\Parser\Analysis\CaptureShape;
use PHPRegex\Parser\RegexParser;
use Psalm\Codebase;
use Psalm\Config;
use Psalm\NodeTypeProvider;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNonEmptyString;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Union;

/**
 * The types a preg_* call writes: $matches, the subject where preg_match()
 * returned 1, the pieces preg_split() returns and the literal preg_quote()
 * of literals returns. Each keeps the data flow of the stub's type.
 *
 * @internal
 *
 * @phpstan-import-type PatternVerdict from PatternVerdicts
 *
 * @phpstan-type CachedType array{type: Union, keys: int}
 */
final class CallTypes
{
    /**
     * @var array<string, CachedType> by target, pattern, function, flags and Psalm's literal length
     */
    private static array $types = [];

    /**
     * preg_quote() of literals is the literal it returns, as the running PHP
     * quotes it: Psalm then folds a pattern built from it into a literal, as
     * it folds any concatenation of literals. The call keeps its data flow.
     *
     * @param array<string, Expr> $arguments
     */
    public static function quote(FuncCall $call, array $arguments, NodeTypeProvider $nodeTypes): void
    {
        $text = isset($arguments['str']) ? CallArguments::literal($arguments['str'], $nodeTypes) : null;
        $delimiterType = isset($arguments['delimiter']) ? $nodeTypes->getType($arguments['delimiter']) : Type::getNull();
        $current = $nodeTypes->getType($call);
        if (null === $text || null === $delimiterType || null === $current || !($delimiterType->isNull() || $delimiterType->isSingleStringLiteral())) {
            return;
        }

        $quoted = preg_quote($text, $delimiterType->isSingleStringLiteral() ? $delimiterType->getSingleStringLiteral()->value : null);
        // Psalm keeps a literal only below maxStringLength bytes.
        if (\strlen($quoted) < Config::getInstance()->max_string_length) {
            $nodeTypes->setType($call, (new Union([TLiteralString::make($quoted)]))->setParentNodes($current->parent_nodes));
        }
    }

    /**
     * preg_split() leaves out the empty pieces under PREG_SPLIT_NO_EMPTY,
     * and writes each piece with its offset in the subject under
     * PREG_SPLIT_OFFSET_CAPTURE; it writes at least one piece otherwise, the
     * whole subject when the pattern never matches. Psalm's stub says the
     * list may be empty under PREG_SPLIT_OFFSET_CAPTURE, and says nothing of
     * the pieces under PREG_SPLIT_NO_EMPTY or of the offsets. The call keeps
     * its false, which Psalm ignores as its stub does, and its data flow.
     *
     * @param array<string, Expr> $arguments
     */
    public static function splitType(FuncCall $call, array $arguments, NodeTypeProvider $nodeTypes): void
    {
        $flagsType = isset($arguments['flags']) ? $nodeTypes->getType($arguments['flags']) : null;
        $current = $nodeTypes->getType($call);
        // PHP reads the three low bits of the flags and ignores the others.
        $flags = null !== $flagsType && $flagsType->isSingleIntLiteral() ? $flagsType->getSingleIntLiteral()->value & (\PREG_SPLIT_NO_EMPTY | \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_OFFSET_CAPTURE) : 0;
        if (null === $current || 0 === ($flags & (\PREG_SPLIT_NO_EMPTY | \PREG_SPLIT_OFFSET_CAPTURE))) {
            return; // The stub says it: a non-empty-list<string>.
        }

        $noEmpty = 0 !== ($flags & \PREG_SPLIT_NO_EMPTY);
        $piece = new Union([$noEmpty ? new TNonEmptyString() : new TString()]);
        if (0 !== ($flags & \PREG_SPLIT_OFFSET_CAPTURE)) {
            $piece = new Union([TKeyedArray::make([$piece, Type::getIntRange(0, null)], null, null, true)]);
        }

        $type = new Union([($noEmpty ? Type::getList($piece) : Type::getNonEmptyList($piece))->getSingleAtomic(), new TFalse()]);
        $nodeTypes->setType($call, $type->setProperties(['ignore_falsable_issues' => $current->ignore_falsable_issues])->setParentNodes($current->parent_nodes));
    }

    /**
     * The type of $matches the call writes, or null when the plugin leaves
     * it to Psalm.
     *
     * @param PatternVerdict      $verdict
     * @param array<string, Expr> $arguments
     */
    public static function matchesType(RegexParser $parser, string $pattern, array $verdict, string $function, array $arguments, NodeTypeProvider $nodeTypes, Codebase $codebase, bool $matched = false): ?Union
    {
        $shape = $verdict['shape'];
        $matchAll = 'preg_match_all' === $function;
        if (null === $shape || ($matchAll && ($verdict['keepsOut'] || !self::offsetNeverPastTheSubject($arguments['offset'] ?? null, $nodeTypes)))) {
            // preg_match_all() returns false and leaves [] for an offset past the
            // subject, and for a match ending before it starts (\K in a lookahead).
            return null;
        }

        $flags = 0;
        if (isset($arguments['flags'])) {
            $flagsType = $nodeTypes->getType($arguments['flags']);
            if (null === $flagsType || !$flagsType->isSingleIntLiteral()) {
                return null;
            }
            $flags = $flagsType->getSingleIntLiteral()->value;
        }

        ['type' => $type, 'keys' => $keys] = self::type($parser, $pattern, $shape, $flags, $matchAll, $codebase, $matched);

        // Past this many keys Psalm keeps no array shape.
        return $keys > $codebase->config->max_shaped_array_size ? null : $type;
    }

    /**
     * What a string subject holds where preg_match() returned 1, or null
     * when it is not narrowed: a subject that may be another type is read as
     * a string, and a subject read from $matches is gone once the call
     * wrote it.
     *
     * From offset 0, a pattern that matches only a few strings makes the
     * subject one of them. Not for a subject that holds literals already:
     * where the call is false, Psalm would take those strings out of it,
     * and a match error returns false on them too.
     *
     * @param PatternVerdict      $verdict
     * @param array<string, Expr> $arguments
     */
    public static function subjectType(string $pattern, array $verdict, array $arguments, NodeTypeProvider $nodeTypes, RegexParser $parser, Codebase $codebase): Atomic|Union|null
    {
        $subject = $arguments['subject'] ?? null;
        $type = null === $subject ? null : $nodeTypes->getType($subject);
        if (null === $subject || null === $type || !$type->isString() || self::readsFromMatches($subject, $arguments['matches'] ?? null)) {
            return null;
        }

        $offset = 0;
        if (isset($arguments['offset'])) {
            $offsetType = $nodeTypes->getType($arguments['offset']);
            $offset = null !== $offsetType && $offsetType->isSingleIntLiteral() ? $offsetType->getSingleIntLiteral()->value : null;
        }

        $strings = $verdict['fewStrings'] && !isset($arguments['offset']) && !$type->hasLiteralString() ? SubjectType::stringsOfMatch($pattern, $parser, $codebase->config->max_string_length) : null;
        if (null !== $strings) {
            return new Union(array_map(TLiteralString::make(...), $strings));
        }

        return SubjectType::ofMatch($pattern, $offset);
    }

    /**
     * Whether the subject may be read from the variable $matches is written
     * to: the same variable, an element or a property of it. When $matches
     * is not a variable, only a variable subject is known to be apart.
     */
    private static function readsFromMatches(Expr $subject, ?Expr $matches): bool
    {
        if (null === $matches) {
            return false;
        }

        $root = $subject;
        while ($root instanceof ArrayDimFetch || $root instanceof PropertyFetch || $root instanceof NullsafePropertyFetch) {
            $root = $root->var;
        }

        if (!$matches instanceof Variable || !\is_string($matches->name)) {
            return !$subject instanceof Variable;
        }

        return !$root instanceof Variable || $root->name === $matches->name;
    }

    /**
     * Whether the offset argument is absent or a constant <= 0: PHP clamps
     * a negative offset to the start of the subject.
     */
    private static function offsetNeverPastTheSubject(?Expr $offset, NodeTypeProvider $nodeTypes): bool
    {
        if (null === $offset) {
            return true;
        }

        $type = $nodeTypes->getType($offset);

        return null !== $type && $type->isSingleIntLiteral() && $type->getSingleIntLiteral()->value <= 0;
    }

    /**
     * @return CachedType
     */
    private static function type(RegexParser $parser, string $pattern, CaptureShape $shape, int $flags, bool $matchAll, Codebase $codebase, bool $matched = false): array
    {
        $key = implode("\0", [CallTarget::key($parser), $pattern, $matchAll ? ($matched ? 'all matched' : 'all') : 'one', $flags, $codebase->config->max_string_length]);

        return self::$types[$key] ??= [
            'type' => $matchAll ? MatchesType::ofMatchAll($shape, $flags, $matched) : MatchesType::ofMatch($shape, $flags),
            'keys' => MatchesType::keyCount($shape, $flags, $matchAll),
        ];
    }
}

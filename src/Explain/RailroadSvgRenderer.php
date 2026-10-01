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

namespace PHPRegex\Explain;

use PHPRegex\Parser\AbstractNodeVisitor;
use PHPRegex\Parser\Internal\DisplayEscaper;
use PHPRegex\Parser\Node\AlternationNode;
use PHPRegex\Parser\Node\AnchorNode;
use PHPRegex\Parser\Node\AssertionNode;
use PHPRegex\Parser\Node\BackrefNode;
use PHPRegex\Parser\Node\CalloutNode;
use PHPRegex\Parser\Node\CharClassNode;
use PHPRegex\Parser\Node\CharLiteralNode;
use PHPRegex\Parser\Node\CharTypeNode;
use PHPRegex\Parser\Node\ClassSetOperationNode;
use PHPRegex\Parser\Node\CommentNode;
use PHPRegex\Parser\Node\ConditionalNode;
use PHPRegex\Parser\Node\ControlCharNode;
use PHPRegex\Parser\Node\DefineNode;
use PHPRegex\Parser\Node\DotNode;
use PHPRegex\Parser\Node\ExtendedCharClassNode;
use PHPRegex\Parser\Node\GroupNode;
use PHPRegex\Parser\Node\GroupType;
use PHPRegex\Parser\Node\KeepNode;
use PHPRegex\Parser\Node\LimitMatchNode;
use PHPRegex\Parser\Node\LiteralNode;
use PHPRegex\Parser\Node\NodeInterface;
use PHPRegex\Parser\Node\PcreVerbNode;
use PHPRegex\Parser\Node\PosixClassNode;
use PHPRegex\Parser\Node\QuantifierBounds;
use PHPRegex\Parser\Node\QuantifierNode;
use PHPRegex\Parser\Node\RangeNode;
use PHPRegex\Parser\Node\RegexNode;
use PHPRegex\Parser\Node\ScriptRunNode;
use PHPRegex\Parser\Node\SequenceNode;
use PHPRegex\Parser\Node\SubroutineNode;
use PHPRegex\Parser\Node\UnicodePropNode;
use PHPRegex\Parser\Node\VersionConditionNode;

/**
 * Generates a basic railroad-style SVG diagram of the regex AST.
 *
 * @phpstan-type SvgPoint array{0: int, 1: int}
 * @phpstan-type SvgPath array{points: list<SvgPoint>, class: string, markerEnd?: bool}
 * @phpstan-type SvgNode array{x: int, y: int, width: int, height: int, label: string, labelLines?: list<string>, class: string}
 * @phpstan-type SvgText array{x: int, y: int, text: string, class: string}
 * @phpstan-type SvgBox array{x: int, y: int, width: int, height: int, rx: int, ry: int, class: string}
 * @phpstan-type SvgMarker array{cx: int, cy: int, r: int, class: string}
 * @phpstan-type SvgLayout array{
 *   width: int,
 *   height: int,
 *   entryX: int,
 *   entryY: int,
 *   exitX: int,
 *   exitY: int,
 *   nodes: list<SvgNode>,
 *   paths: list<SvgPath>,
 *   texts: list<SvgText>,
 *   boxes: list<SvgBox>,
 *   markers: list<SvgMarker>
 * }
 *
 * @extends AbstractNodeVisitor<mixed>
 */
final class RailroadSvgRenderer extends AbstractNodeVisitor
{
    private const FONT_SIZE = 12;
    private const CHAR_WIDTH = 7;
    private const LINE_HEIGHT = 14;
    private const NODE_HEIGHT = 26;
    private const NODE_RADIUS = 7;
    private const NODE_PADDING_X = 12;
    private const NODE_PADDING_Y = 6;
    private const MIN_NODE_WIDTH = 26;
    private const H_GAP = 16;
    private const V_GAP = 14;
    private const BRANCH_GAP = 18;
    private const SIDE_PADDING = 10;
    private const LOOP_HEIGHT = 24;
    private const BYPASS_HEIGHT = 12;
    private const LABEL_HEIGHT = 12;
    private const LABEL_GAP = 6;
    private const CANVAS_PADDING = 18;
    private const TERMINAL_RADIUS = 7;
    private const TERMINAL_GAP = 12;
    private const GROUP_PADDING_X = 16;
    private const GROUP_PADDING_Y = 12;
    private const GROUP_LABEL_HEIGHT = 12;
    private const META_HEIGHT = 12;
    private const MAX_LABEL_CHARS = 28;

    private int $groupCounter = 0;

    private int $charClassDepth = 0;

    private int $negatedCharClassDepth = 0;

    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
        $this->groupCounter = 0;
        $this->charClassDepth = 0;
        $this->negatedCharClassDepth = 0;

        $patternLayout = $this->layoutFor($node->pattern);
        $flags = '' !== $node->flags ? $node->flags : null;
        $layout = $this->wrapWithTerminals($patternLayout, $flags);

        return $this->renderSvg($layout);
    }

    #[\Override]
    public function visitAlternation(AlternationNode $node)
    {
        $layouts = [];
        foreach ($node->alternatives as $child) {
            $layouts[] = $this->layoutFor($child);
        }

        return $this->layoutAlternation($layouts);
    }

    #[\Override]
    public function visitSequence(SequenceNode $node)
    {
        $layouts = [];
        $buffer = '';

        foreach ($node->children as $child) {
            if ($child instanceof LiteralNode) {
                $buffer .= $child->value;

                continue;
            }

            if ($child instanceof CharLiteralNode) {
                $buffer .= $child->originalRepresentation;

                continue;
            }

            if ('' !== $buffer) {
                $value = DisplayEscaper::escape($buffer);
                $layouts[] = $this->createNodeLayout($value, 'node literal', true);
                $buffer = '';
            }
            $layouts[] = $this->layoutFor($child);
        }

        if ('' !== $buffer) {
            $value = DisplayEscaper::escape($buffer);
            $layouts[] = $this->createNodeLayout($value, 'node literal', true);
        }

        return $this->layoutSequence($layouts);
    }

    #[\Override]
    public function visitGroup(GroupNode $node)
    {
        $label = $this->groupLabel($node);
        $result = $this->layoutGroupBox($this->layoutFor($node->child), $label);

        return $result;
    }

    #[\Override]
    public function visitQuantifier(QuantifierNode $node)
    {
        return $this->layoutQuantifier($node);
    }

    #[\Override]
    public function visitLiteral(LiteralNode $node)
    {
        $value = DisplayEscaper::escape($node->value);
        if ('' === $value) {
            $value = '(empty)';
        }

        return $this->createNodeLayout("Literal ('".$value."')", $this->literalClass(), true);
    }

    #[\Override]
    public function visitCharLiteral(CharLiteralNode $node)
    {
        return $this->createNodeLayout('CharLiteral ('.$node->originalRepresentation.')', $this->literalClass(), true);
    }

    #[\Override]
    public function visitCharType(CharTypeNode $node)
    {
        return $this->createNodeLayout('CharType (\\'.$node->value.')', 'node control');
    }

    #[\Override]
    public function visitDot(DotNode $node)
    {
        return $this->createNodeLayout('Dot (.)', 'node anychar');
    }

    #[\Override]
    public function visitAnchor(AnchorNode $node)
    {
        if ($this->charClassDepth > 0) {
            return $this->createNodeLayout($node->value, $this->literalClass());
        }

        return $this->createNodeLayout('Anchor ('.$node->value.')', 'node anchor');
    }

    #[\Override]
    public function visitAssertion(AssertionNode $node)
    {
        return $this->createNodeLayout('Assertion (\\'.$node->value.')', 'node');
    }

    #[\Override]
    public function visitKeep(KeepNode $node)
    {
        return $this->createNodeLayout('Keep (\\K)', 'node');
    }

    #[\Override]
    public function visitCharClass(CharClassNode $node)
    {
        $this->charClassDepth++;
        if ($node->isNegated) {
            $this->negatedCharClassDepth++;
        }

        $expression = $this->layoutFor($node->expression);

        if ($node->isNegated) {
            $this->negatedCharClassDepth--;
        }
        $this->charClassDepth--;

        $label = $node->isNegated ? 'CharClass (negated)' : 'CharClass';

        return $this->layoutLabelAbove($label, $expression, 'class-label');
    }

    #[\Override]
    public function visitRange(RangeNode $node)
    {
        return $this->layoutWithLabel('Range', [
            $this->layoutFor($node->start),
            $this->layoutFor($node->end),
        ]);
    }

    #[\Override]
    public function visitBackref(BackrefNode $node)
    {
        $ref = $node->ref;
        $display = str_starts_with($ref, '\\') ? $ref : '\\'.$ref;

        return $this->createNodeLayout('Backref ('.$display.')', 'node');
    }

    #[\Override]
    public function visitExtendedCharClass(ExtendedCharClassNode $node)
    {
        return $this->layoutWithLabel('ExtendedCharClass', [$this->layoutFor($node->expression)]);
    }

    #[\Override]
    public function visitClassSetOperation(ClassSetOperationNode $node)
    {
        return $this->layoutWithLabel('ClassSetOperation ('.$node->operator->value.')', array_map(
            $this->layoutFor(...),
            array_values(array_filter([$node->left, $node->right])),
        ));
    }

    #[\Override]
    public function visitControlChar(ControlCharNode $node)
    {
        return $this->createNodeLayout('ControlChar (\\c'.$node->char.')', 'node control');
    }

    #[\Override]
    public function visitScriptRun(ScriptRunNode $node)
    {
        return $this->createNodeLayout('ScriptRun ('.$node->script.')', 'node');
    }

    #[\Override]
    public function visitVersionCondition(VersionConditionNode $node)
    {
        return $this->createNodeLayout('VersionCondition ('.$node->operator.' '.$node->version.')', 'node');
    }

    #[\Override]
    public function visitUnicodeProp(UnicodePropNode $node)
    {
        $inner = $node->hasBraces ? trim($node->prop, '{}') : $node->prop;
        $display = '{'.$inner.'}';

        return $this->createNodeLayout('UnicodeProperty (\\p'.$display.')', 'node');
    }

    #[\Override]
    public function visitPosixClass(PosixClassNode $node)
    {
        return $this->createNodeLayout('PosixClass ([:'.$node->class.':])', 'node');
    }

    #[\Override]
    public function visitComment(CommentNode $node)
    {
        return $this->createNodeLayout('Comment', 'node');
    }

    #[\Override]
    public function visitConditional(ConditionalNode $node)
    {
        $branches = [
            $this->layoutFor($node->yes),
            $this->layoutFor($node->no),
        ];

        $branchLayout = $this->layoutAlternation($branches);

        return $this->layoutWithLabel('Conditional', [
            $this->layoutFor($node->condition),
            $branchLayout,
        ]);
    }

    #[\Override]
    public function visitSubroutine(SubroutineNode $node)
    {
        return $this->createNodeLayout('Subroutine ('.$node->reference.')', 'node');
    }

    #[\Override]
    public function visitPcreVerb(PcreVerbNode $node)
    {
        return $this->createNodeLayout('PCREVerb (*'.$node->verb.')', 'node');
    }

    #[\Override]
    public function visitDefine(DefineNode $node)
    {
        return $this->layoutWithLabel('Define', [$this->layoutFor($node->content)]);
    }

    #[\Override]
    public function visitLimitMatch(LimitMatchNode $node)
    {
        return $this->createNodeLayout('LimitMatch (*LIMIT_MATCH='.$node->limit.')', 'node');
    }

    #[\Override]
    public function visitCallout(CalloutNode $node)
    {
        if (null === $node->identifier) {
            $label = 'Callout (?C)';
        } elseif ($node->isStringIdentifier) {
            $label = 'Callout (?C="'.$node->identifier.'")';
        } else {
            $label = 'Callout (?C'.$node->identifier.')';
        }

        return $this->createNodeLayout($label, 'node');
    }

    /**
     * @return SvgLayout
     */
    private function layoutFor(NodeInterface $node): array
    {
        $layout = $node->accept($this);
        $this->assertLayout($layout);

        return $layout;
    }

    /**
     * @phpstan-assert SvgLayout $layout
     */
    private function assertLayout(mixed $layout): void
    {
        if (!\is_array($layout)) {
            throw new \LogicException('Expected layout array.');
        }
    }

    /**
     * @param list<SvgLayout> $layouts
     *
     * @return SvgLayout
     */
    private function layoutSequence(array $layouts): array
    {
        if (0 === \count($layouts)) {
            return $this->createNodeLayout('Empty', 'node');
        }

        if (1 === \count($layouts)) {
            return $layouts[0];
        }

        $maxAscent = 0;
        $maxDescent = 0;

        foreach ($layouts as $layout) {
            $ascent = (int) $layout['entryY'];
            $descent = (int) $layout['height'] - (int) $layout['entryY'];

            $maxAscent = max($maxAscent, $ascent);
            $maxDescent = max($maxDescent, $descent);
        }

        $height = $maxAscent + $maxDescent;
        $baselineY = $maxAscent;

        $width = 0;
        foreach ($layouts as $layout) {
            $width += (int) $layout['width'];
        }
        $width += self::H_GAP * (\count($layouts) - 1);

        /** @var list<SvgNode> $nodes */
        $nodes = [];
        /** @var list<SvgPath> $paths */
        $paths = [];
        /** @var list<SvgText> $texts */
        $texts = [];
        /** @var list<SvgBox> $boxes */
        $boxes = [];
        /** @var list<SvgMarker> $markers */
        $markers = [];

        $x = 0;
        $prevLayout = null;
        $prevExitXAbs = 0;

        foreach ($layouts as $index => $layout) {
            $offsetY = $baselineY - (int) $layout['entryY'];

            $offsetLayout = $this->offsetLayout($layout, $x, $offsetY);
            $nodes = array_merge($nodes, $offsetLayout['nodes']);
            $paths = array_merge($paths, $offsetLayout['paths']);
            $texts = array_merge($texts, $offsetLayout['texts']);
            $boxes = array_merge($boxes, $offsetLayout['boxes']);
            $markers = array_merge($markers, $offsetLayout['markers']);

            if (null !== $prevLayout) {
                $currEntryXAbs = $x + (int) $layout['entryX'];
                $paths[] = $this->line([$prevExitXAbs, $baselineY], [$currEntryXAbs, $baselineY]);
            }

            $prevLayout = $layout;
            $prevExitXAbs = $x + (int) $layout['exitX'];

            $x += (int) $layout['width'] + self::H_GAP;
        }

        return [
            'width' => $width,
            'height' => $height,
            'entryX' => 0,
            'entryY' => $baselineY,
            'exitX' => $width,
            'exitY' => $baselineY,
            'nodes' => $nodes,
            'paths' => $paths,
            'texts' => $texts,
            'boxes' => $boxes,
            'markers' => $markers,
        ];
    }

    /**
     * @param list<SvgLayout> $layouts
     *
     * @return SvgLayout
     */
    private function layoutAlternation(array $layouts): array
    {
        if (0 === \count($layouts)) {
            return $this->createNodeLayout('Empty', 'node');
        }

        if (1 === \count($layouts)) {
            return $layouts[0];
        }

        $maxWidth = 0;
        $height = 0;
        foreach ($layouts as $layout) {
            $maxWidth = max($maxWidth, (int) $layout['width']);
            $height += (int) $layout['height'];
        }
        $height += self::V_GAP * (\count($layouts) - 1);

        $width = $maxWidth + (self::BRANCH_GAP * 2);
        /** @var list<SvgNode> $nodes */
        $nodes = [];
        /** @var list<SvgPath> $paths */
        $paths = [];
        /** @var list<SvgText> $texts */
        $texts = [];
        /** @var list<SvgBox> $boxes */
        $boxes = [];
        /** @var list<SvgMarker> $markers */
        $markers = [];
        $y = 0;
        $topY = null;
        $bottomY = null;
        foreach ($layouts as $layout) {
            $offsetLayout = $this->offsetLayout($layout, self::BRANCH_GAP, $y);
            $nodes = array_merge($nodes, $offsetLayout['nodes']);
            $paths = array_merge($paths, $offsetLayout['paths']);
            $texts = array_merge($texts, $offsetLayout['texts']);
            $boxes = array_merge($boxes, $offsetLayout['boxes']);
            $markers = array_merge($markers, $offsetLayout['markers']);

            $centerY = $y + (int) $layout['entryY'];
            $topY ??= $centerY;
            $bottomY = $centerY;

            $paths[] = $this->line([0, $centerY], [self::BRANCH_GAP + (int) $layout['entryX'], $centerY]);
            $paths[] = $this->line([self::BRANCH_GAP + (int) $layout['exitX'], $centerY], [$width, $centerY]);

            $y += (int) $layout['height'] + self::V_GAP;
        }

        $paths[] = $this->line([0, (int) $topY], [0, (int) $bottomY]);
        $paths[] = $this->line([$width, (int) $topY], [$width, (int) $bottomY]);

        return [
            'width' => $width,
            'height' => $height,
            'entryX' => 0,
            'entryY' => (int) floor($height / 2),
            'exitX' => $width,
            'exitY' => (int) floor($height / 2),
            'nodes' => $nodes,
            'paths' => $paths,
            'texts' => $texts,
            'boxes' => $boxes,
            'markers' => $markers,
        ];
    }

    /**
     * @param list<SvgLayout> $childLayouts
     *
     * @return SvgLayout
     */
    private function layoutWithLabel(string $label, array $childLayouts): array
    {
        $labelLayout = $this->createNodeLayout($label, 'node');

        return $this->layoutSequence(array_merge([$labelLayout], $childLayouts));
    }

    /**
     * @param SvgLayout $childLayout
     *
     * @return SvgLayout
     */
    private function layoutGroupBox(array $childLayout, string $label): array
    {
        $labelWidth = $this->measureTextWidth($label);
        $width = (int) max(
            $childLayout['width'] + (self::GROUP_PADDING_X * 2),
            $labelWidth + (self::GROUP_PADDING_X * 2),
        );
        $boxTop = self::GROUP_LABEL_HEIGHT + self::LABEL_GAP;
        $height = $boxTop + (int) $childLayout['height'] + (self::GROUP_PADDING_Y * 2);

        $childOffsetX = (int) floor(($width - (int) $childLayout['width']) / 2);
        $childOffsetY = $boxTop + self::GROUP_PADDING_Y;

        $offsetLayout = $this->offsetLayout($childLayout, $childOffsetX, $childOffsetY);
        $nodes = $offsetLayout['nodes'];
        $paths = $offsetLayout['paths'];
        $texts = $offsetLayout['texts'];
        $boxes = $offsetLayout['boxes'];
        $markers = $offsetLayout['markers'];

        $boxes[] = [
            'x' => 0,
            'y' => $boxTop,
            'width' => $width,
            'height' => $height - $boxTop,
            'rx' => 10,
            'ry' => 10,
            'class' => 'group-box',
        ];

        $texts[] = [
            'x' => (int) floor($width / 2),
            'y' => (int) floor(self::GROUP_LABEL_HEIGHT / 2),
            'text' => $label,
            'class' => 'group-label',
        ];

        return [
            'width' => $width,
            'height' => $height,
            'entryX' => $childOffsetX + (int) $childLayout['entryX'],
            'entryY' => $childOffsetY + (int) $childLayout['entryY'],
            'exitX' => $childOffsetX + (int) $childLayout['exitX'],
            'exitY' => $childOffsetY + (int) $childLayout['exitY'],
            'nodes' => $nodes,
            'paths' => $paths,
            'texts' => $texts,
            'boxes' => $boxes,
            'markers' => $markers,
        ];
    }

    /**
     * @param SvgLayout $childLayout
     *
     * @return SvgLayout
     */
    private function layoutLabelAbove(string $label, array $childLayout, string $class): array
    {
        $labelWidth = $this->measureTextWidth($label);
        $width = (int) max((int) $childLayout['width'], $labelWidth);
        $height = self::LABEL_HEIGHT + self::LABEL_GAP + (int) $childLayout['height'];

        $childOffsetX = (int) floor(($width - (int) $childLayout['width']) / 2);
        $childOffsetY = self::LABEL_HEIGHT + self::LABEL_GAP;

        $offsetLayout = $this->offsetLayout($childLayout, $childOffsetX, $childOffsetY);
        $nodes = $offsetLayout['nodes'];
        $paths = $offsetLayout['paths'];
        $texts = $offsetLayout['texts'];
        $boxes = $offsetLayout['boxes'];
        $markers = $offsetLayout['markers'];

        $texts[] = [
            'x' => (int) floor($width / 2),
            'y' => (int) floor(self::LABEL_HEIGHT / 2),
            'text' => $label,
            'class' => $class,
        ];

        return [
            'width' => $width,
            'height' => $height,
            'entryX' => $childOffsetX + (int) $childLayout['entryX'],
            'entryY' => $childOffsetY + (int) $childLayout['entryY'],
            'exitX' => $childOffsetX + (int) $childLayout['exitX'],
            'exitY' => $childOffsetY + (int) $childLayout['exitY'],
            'nodes' => $nodes,
            'paths' => $paths,
            'texts' => $texts,
            'boxes' => $boxes,
            'markers' => $markers,
        ];
    }

    /**
     * @param SvgLayout $layout
     *
     * @return SvgLayout
     */
    private function wrapWithTerminals(array $layout, ?string $flags): array
    {
        $metaOffset = null !== $flags ? self::META_HEIGHT + self::LABEL_GAP : 0;
        $minHeight = max((int) $layout['height'], self::TERMINAL_RADIUS * 2);
        $centerOffset = (int) floor(($minHeight - (int) $layout['height']) / 2);
        $topOffset = $metaOffset + $centerOffset;

        $leftPad = (self::TERMINAL_RADIUS * 2) + self::TERMINAL_GAP;
        $rightPad = (self::TERMINAL_RADIUS * 2) + self::TERMINAL_GAP;
        $width = (int) $layout['width'] + $leftPad + $rightPad;
        $height = $minHeight + $metaOffset;

        $offsetLayout = $this->offsetLayout($layout, $leftPad, $topOffset);
        $nodes = $offsetLayout['nodes'];
        $paths = $offsetLayout['paths'];
        $texts = $offsetLayout['texts'];
        $boxes = $offsetLayout['boxes'];
        $markers = $offsetLayout['markers'];

        $trackY = $topOffset + (int) $layout['entryY'];
        $paths[] = $this->line([self::TERMINAL_RADIUS * 2, $trackY], [$leftPad + (int) $layout['entryX'], $trackY]);
        $paths[] = $this->line([$leftPad + (int) $layout['exitX'], $trackY], [$width - (self::TERMINAL_RADIUS * 2), $trackY]);

        $markers[] = [
            'cx' => self::TERMINAL_RADIUS,
            'cy' => $trackY,
            'r' => self::TERMINAL_RADIUS,
            'class' => 'terminal start',
        ];
        $markers[] = [
            'cx' => $width - self::TERMINAL_RADIUS,
            'cy' => $trackY,
            'r' => self::TERMINAL_RADIUS,
            'class' => 'terminal end',
        ];

        if (null !== $flags) {
            $texts[] = [
                'x' => $leftPad,
                'y' => (int) floor(self::META_HEIGHT / 2),
                'text' => 'flags: '.$flags,
                'class' => 'meta',
            ];
        }

        return [
            'width' => $width,
            'height' => $height,
            'entryX' => self::TERMINAL_RADIUS * 2,
            'entryY' => $trackY,
            'exitX' => $width - (self::TERMINAL_RADIUS * 2),
            'exitY' => $trackY,
            'nodes' => $nodes,
            'paths' => $paths,
            'texts' => $texts,
            'boxes' => $boxes,
            'markers' => $markers,
        ];
    }

    /**
     * @return SvgLayout
     */
    private function layoutQuantifier(QuantifierNode $node): array
    {
        $childLayout = $this->layoutFor($node->node);
        $needsLoop = $this->isLoopQuantifier($node->quantifier);
        $needsBypass = $this->isOptionalQuantifier($node->quantifier);
        $topExtra = max($needsLoop ? self::LOOP_HEIGHT + 10 + self::LABEL_GAP : 0, $needsBypass ? self::BYPASS_HEIGHT + 10 + self::LABEL_GAP : 0);
        $labelText = $this->describeQuantifier($node->quantifier);
        $bottomExtra = '' !== $labelText ? self::LABEL_HEIGHT + self::LABEL_GAP : 0;

        $width = (int) $childLayout['width'] + (self::SIDE_PADDING * 2);
        $height = (int) $childLayout['height'] + $topExtra + $bottomExtra;
        $childOffsetX = self::SIDE_PADDING;
        $childOffsetY = $topExtra;

        $offsetLayout = $this->offsetLayout($childLayout, $childOffsetX, $childOffsetY);
        $nodes = $offsetLayout['nodes'];
        $paths = $offsetLayout['paths'];
        $texts = $offsetLayout['texts'];
        $boxes = $offsetLayout['boxes'];
        $markers = $offsetLayout['markers'];

        $midY = $childOffsetY + (int) $childLayout['entryY'];
        $paths[] = $this->line([0, $midY], [$childOffsetX + (int) $childLayout['entryX'], $midY]);
        $paths[] = $this->line([$childOffsetX + (int) $childLayout['exitX'], $midY], [$width, $midY]);

        if ($needsLoop) {
            $loopMargin = 6;
            $loopTopY = $childOffsetY - self::LOOP_HEIGHT - 10;
            $exitX = $childOffsetX + (int) $childLayout['exitX'];
            $entryX = $childOffsetX + (int) $childLayout['entryX'];

            $loopExitX = $exitX + $loopMargin;
            $loopEntryX = $entryX - $loopMargin;

            $paths[] = $this->line([$loopExitX, $midY], [$loopExitX, $loopTopY], 'path loop');
            $paths[] = $this->line([$loopExitX, $loopTopY], [$loopEntryX, $loopTopY], 'path loop');
            $paths[] = $this->line([$loopEntryX, $loopTopY], [$loopEntryX, $midY], 'path loop', true);
        }

        if ($needsBypass) {
            $bypassY = $childOffsetY - self::BYPASS_HEIGHT - 10;
            $paths[] = $this->polyline([
                [0, $midY],
                [0, $bypassY],
                [$width, $bypassY],
                [$width, $midY],
            ], 'path bypass');
        }

        if ('' !== $labelText) {
            $labelX = (int) floor($width / 2);
            $labelY = $childOffsetY + (int) $childLayout['height'] + self::LABEL_GAP + (int) floor(self::LABEL_HEIGHT / 2);
            $texts[] = [
                'x' => $labelX,
                'y' => $labelY,
                'text' => $labelText,
                'class' => 'quantifier-label',
            ];
        }

        return [
            'width' => $width,
            'height' => $height,
            'entryX' => 0,
            'entryY' => $midY,
            'exitX' => $width,
            'exitY' => $midY,
            'nodes' => $nodes,
            'paths' => $paths,
            'texts' => $texts,
            'boxes' => $boxes,
            'markers' => $markers,
        ];
    }

    /**
     * @return SvgLayout
     */
    private function createNodeLayout(string $label, string $class, bool $preserveWhitespace = false): array
    {
        /** @var list<string> $labelLines */
        $labelLines = $this->wrapLabel($label, $preserveWhitespace);
        $textWidth = max(self::MIN_NODE_WIDTH, $this->measureTextLinesWidth($labelLines));
        $width = $textWidth + (self::NODE_PADDING_X * 2);
        $height = $this->measureNodeHeight(\count($labelLines));

        return [
            'width' => $width,
            'height' => $height,
            'entryX' => 0,
            'entryY' => (int) floor($height / 2),
            'exitX' => $width,
            'exitY' => (int) floor($height / 2),
            'nodes' => [[
                'x' => 0,
                'y' => 0,
                'width' => $width,
                'height' => $height,
                'label' => $label,
                'labelLines' => $labelLines,
                'class' => $class,
            ]],
            'paths' => [],
            'texts' => [],
            'boxes' => [],
            'markers' => [],
        ];
    }

    /**
     * @param SvgLayout $layout
     *
     * @return SvgLayout
     */
    private function offsetLayout(array $layout, int $dx, int $dy): array
    {
        /** @var list<SvgNode> $nodes */
        $nodes = [];
        foreach ($layout['nodes'] as $node) {
            $node['x'] += $dx;
            $node['y'] += $dy;
            $nodes[] = $node;
        }

        /** @var list<SvgPath> $paths */
        $paths = [];
        foreach ($layout['paths'] as $path) {
            $points = [];
            foreach ($path['points'] as $point) {
                $points[] = [$point[0] + $dx, $point[1] + $dy];
            }
            $newPath = ['points' => $points, 'class' => $path['class'] ?? 'path'];
            if (isset($path['markerEnd'])) {
                $newPath['markerEnd'] = $path['markerEnd'];
            }
            $paths[] = $newPath;
        }

        /** @var list<SvgText> $texts */
        $texts = [];
        foreach ($layout['texts'] as $text) {
            $text['x'] += $dx;
            $text['y'] += $dy;
            $texts[] = $text;
        }

        /** @var list<SvgBox> $boxes */
        $boxes = [];
        foreach ($layout['boxes'] as $box) {
            $box['x'] += $dx;
            $box['y'] += $dy;
            $boxes[] = $box;
        }

        /** @var list<SvgMarker> $markers */
        $markers = [];
        foreach ($layout['markers'] as $marker) {
            $marker['cx'] += $dx;
            $marker['cy'] += $dy;
            $markers[] = $marker;
        }

        return [
            'width' => $layout['width'],
            'height' => $layout['height'],
            'entryX' => $layout['entryX'] + $dx,
            'entryY' => $layout['entryY'] + $dy,
            'exitX' => $layout['exitX'] + $dx,
            'exitY' => $layout['exitY'] + $dy,
            'nodes' => $nodes,
            'paths' => $paths,
            'texts' => $texts,
            'boxes' => $boxes,
            'markers' => $markers,
        ];
    }

    /**
     * @param SvgPoint $from
     * @param SvgPoint $to
     *
     * @return SvgPath
     */
    private function line(array $from, array $to, string $class = 'path', bool $markerEnd = false): array
    {
        $result = ['points' => [$from, $to], 'class' => $class];
        if ($markerEnd) {
            $result['markerEnd'] = true;
        }

        return $result;
    }

    /**
     * @param list<SvgPoint> $points
     *
     * @return SvgPath
     */
    private function polyline(array $points, string $class = 'path'): array
    {
        return ['points' => $points, 'class' => $class];
    }

    private function measureTextWidth(string $text): int
    {
        return (int) (\strlen($text) * self::CHAR_WIDTH);
    }

    /**
     * @param list<string> $lines
     */
    private function measureTextLinesWidth(array $lines): int
    {
        $max = 0;
        foreach ($lines as $line) {
            $max = max($max, $this->measureTextWidth($line));
        }

        return $max;
    }

    private function measureNodeHeight(int $lineCount): int
    {
        $lineCount = max(1, $lineCount);

        return max(
            self::NODE_HEIGHT,
            (self::LINE_HEIGHT * $lineCount) + (self::NODE_PADDING_Y * 2),
        );
    }

    /**
     * @param SvgLayout $layout
     */
    private function renderSvg(array $layout): string
    {
        $offsetLayout = $this->offsetLayout($layout, self::CANVAS_PADDING, self::CANVAS_PADDING);
        $width = (int) $layout['width'] + (self::CANVAS_PADDING * 2);
        $height = (int) $layout['height'] + (self::CANVAS_PADDING * 2);

        $svg = [];
        $svg[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $svg[] = \sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d">',
            $width,
            $height,
            $width,
            $height,
        );
        $svg[] = '<defs>';
        $svg[] = '<style>';
        $svg[] = '  .path { stroke: #7b8794; stroke-width: 2; fill: none; stroke-linecap: round; stroke-linejoin: round; }';
        $svg[] = '  .path.loop, .path.bypass { stroke-dasharray: 4 4; }';
        $svg[] = '  .node { fill: #e1e7ef; stroke: #a3aebd; stroke-width: 1.6; }';
        $svg[] = '  .node.literal { fill: #dbe2ea; }';
        $svg[] = '  .node.anchor { fill: #b7e8c9; stroke: #5fb184; }';
        $svg[] = '  .node.control { fill: #f6c7d6; stroke: #dd8da7; }';
        $svg[] = '  .node.anychar { fill: #cfe9c8; stroke: #79b879; }';
        $svg[] = '  .node.class-negated { fill: #f6b6b6; stroke: #e07e7e; }';
        $svg[] = '  .node.class-positive { fill: #f4d3a1; stroke: #d7a46b; }';
        $svg[] = '  .label { font-family: monospace; font-size: '.self::FONT_SIZE.'px; fill: #2f3b4c; text-anchor: middle; dominant-baseline: middle; }';
        $svg[] = '  .group-box { fill: none; stroke: #c2ccd7; stroke-width: 1.5; stroke-dasharray: 3 3; }';
        $svg[] = '  .group-label { font-family: monospace; font-size: 11px; fill: #6b7785; text-anchor: middle; dominant-baseline: middle; }';
        $svg[] = '  .class-label { font-family: monospace; font-size: 11px; fill: #6b7785; text-anchor: middle; dominant-baseline: middle; }';
        $svg[] = '  .quantifier-label { font-family: monospace; font-size: 11px; fill: #6b7785; text-anchor: middle; dominant-baseline: middle; }';
        $svg[] = '  .meta { font-family: monospace; font-size: 11px; fill: #6b7785; text-anchor: start; dominant-baseline: middle; }';
        $svg[] = '  .terminal.start { fill: #62d28c; stroke: #4fb173; stroke-width: 2; }';
        $svg[] = '  .terminal.end { fill: #6aa3ff; stroke: #4a83df; stroke-width: 2; }';
        $svg[] = '</style>';
        $svg[] = '<marker id="arrowhead" markerWidth="10" markerHeight="7" refX="9" refY="3.5" orient="auto">';
        $svg[] = '  <polygon points="0 0, 10 3.5, 0 7" fill="#7b8794" />';
        $svg[] = '</marker>';
        $svg[] = '</defs>';
        $svg[] = \sprintf('<rect width="%d" height="%d" fill="#f4f7f8"/>', $width, $height);

        foreach ($offsetLayout['boxes'] as $box) {
            $svg[] = \sprintf(
                '<rect class="%s" x="%d" y="%d" width="%d" height="%d" rx="%d" ry="%d"/>',
                $this->escapeAttribute($box['class']),
                $box['x'],
                $box['y'],
                $box['width'],
                $box['height'],
                $box['rx'],
                $box['ry'],
            );
        }

        foreach ($offsetLayout['paths'] as $path) {
            $markerEnd = isset($path['markerEnd']) && $path['markerEnd'] ? ' marker-end="url(#arrowhead)"' : '';
            $svg[] = \sprintf(
                '<path class="%s"%s d="%s"/>',
                $this->escapeAttribute($path['class'] ?? 'path'),
                $markerEnd,
                $this->renderPath($path['points']),
            );
        }

        foreach ($offsetLayout['markers'] as $marker) {
            $svg[] = \sprintf(
                '<circle class="%s" cx="%d" cy="%d" r="%d"/>',
                $this->escapeAttribute($marker['class']),
                $marker['cx'],
                $marker['cy'],
                $marker['r'],
            );
        }

        foreach ($offsetLayout['nodes'] as $node) {
            $svg[] = \sprintf(
                '<rect class="%s" x="%d" y="%d" width="%d" height="%d" rx="%d" ry="%d"/>',
                $this->escapeAttribute($node['class']),
                $node['x'],
                $node['y'],
                $node['width'],
                $node['height'],
                self::NODE_RADIUS,
                self::NODE_RADIUS,
            );
            $labelLines = $node['labelLines'] ?? [$node['label']];
            $lineCount = \count($labelLines);
            $textBlockHeight = self::LINE_HEIGHT * $lineCount;
            $centerX = $node['x'] + (int) floor($node['width'] / 2);
            $startY = $node['y'] + (int) floor(((int) $node['height'] - $textBlockHeight) / 2);

            foreach ($labelLines as $index => $line) {
                $lineY = $startY + ($index * self::LINE_HEIGHT) + (int) floor(self::LINE_HEIGHT / 2);
                $svg[] = \sprintf(
                    '<text class="label" x="%d" y="%d">%s</text>',
                    $centerX,
                    $lineY,
                    $this->escapeText($line),
                );
            }
        }

        foreach ($offsetLayout['texts'] as $text) {
            $svg[] = \sprintf(
                '<text class="%s" x="%d" y="%d">%s</text>',
                $this->escapeAttribute($text['class']),
                $text['x'],
                $text['y'],
                $this->escapeText($text['text']),
            );
        }

        $svg[] = '</svg>';

        return implode("\n", $svg);
    }

    /**
     * @param list<SvgPoint> $points
     */
    private function renderPath(array $points): string
    {
        if (0 === \count($points)) {
            return '';
        }

        $commands = ['M '.$points[0][0].' '.$points[0][1]];
        for ($i = 1; $i < \count($points); $i++) {
            $commands[] = 'L '.$points[$i][0].' '.$points[$i][1];
        }

        return implode(' ', $commands);
    }

    private function escapeText(string $text): string
    {
        return htmlspecialchars($text, \ENT_XML1 | \ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @return list<string>
     */
    private function wrapLabel(string $label, bool $preserveWhitespace = false): array
    {
        if ('' === $label) {
            return ['(empty)'];
        }

        if (\strlen($label) <= self::MAX_LABEL_CHARS) {
            return [$label];
        }

        if ($preserveWhitespace) {
            return str_split($label, self::MAX_LABEL_CHARS);
        }

        $label = trim($label);
        if ('' === $label) {
            return ['(empty)'];
        }

        $words = preg_split('/\s+/', $label) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = '' === $current ? $word : $current.' '.$word;
            if (\strlen($candidate) <= self::MAX_LABEL_CHARS) {
                $current = $candidate;

                continue;
            }

            if ('' !== $current) {
                $lines[] = $current;
                $current = '';
            }

            if (\strlen($word) <= self::MAX_LABEL_CHARS) {
                $current = $word;

                continue;
            }

            $chunks = str_split($word, self::MAX_LABEL_CHARS);
            $lastIndex = \count($chunks) - 1;
            foreach ($chunks as $index => $chunk) {
                if ($index === $lastIndex) {
                    $current = $chunk;
                } else {
                    $lines[] = $chunk;
                }
            }
        }

        if ('' !== $current) {
            $lines[] = $current;
        }

        return $lines;
    }

    private function describeGroupType(GroupNode $node): string
    {
        return match ($node->type) {
            GroupType::Capturing => 'capturing',
            GroupType::NonCapturing => 'non-capturing',
            GroupType::Named => 'named',
            GroupType::LookaheadPositive => 'positive lookahead',
            GroupType::LookaheadNegative => 'negative lookahead',
            GroupType::LookbehindPositive => 'positive lookbehind',
            GroupType::LookbehindNegative => 'negative lookbehind',
            GroupType::InlineFlags => 'inline flags',
            GroupType::Atomic => 'atomic',
            GroupType::BranchReset => 'branch reset',
            GroupType::ScanSubstring => 'scan of groups '.implode(', ', $node->scannedGroups),
        };
    }

    private function groupLabel(GroupNode $node): string
    {
        if (\in_array($node->type, [GroupType::Capturing, GroupType::Named], true)) {
            $this->groupCounter++;
            $label = 'Group #'.$this->groupCounter;
            if (GroupType::Named === $node->type && null !== $node->name) {
                $label .= ' ('.$node->name.')';
            }

            return $label;
        }

        $label = 'Group ('.$this->describeGroupType($node).')';
        if (GroupType::InlineFlags === $node->type && null !== $node->flags && '' !== $node->flags) {
            $label .= ' flags: '.$node->flags;
        }

        return $label;
    }

    private function literalClass(): string
    {
        if ($this->negatedCharClassDepth > 0) {
            return 'node class-negated';
        }

        if ($this->charClassDepth > 0) {
            return 'node class-positive';
        }

        return 'node literal';
    }

    private function describeQuantifier(string $quantifier): string
    {
        if ('*' === $quantifier) {
            return '0 or more times';
        }

        if ('+' === $quantifier) {
            return '1 or more times';
        }

        if ('?' === $quantifier) {
            return '0 or 1 time';
        }

        $range = $this->parseRangeQuantifier($quantifier);
        if (null === $range) {
            return $quantifier;
        }

        [$min, $max] = $range;
        if (null === $max) {
            return $min.' or more times';
        }

        if (0 === $min) {
            return '0 to '.$max.' times';
        }

        if ($min === $max) {
            return $min.' times';
        }

        return $min.' to '.$max.' times';
    }

    private function isLoopQuantifier(string $quantifier): bool
    {
        if ('*' === $quantifier || '+' === $quantifier) {
            return true;
        }

        $range = $this->parseRangeQuantifier($quantifier);
        if (null === $range) {
            return false;
        }

        [$min, $max] = $range;

        return null === $max;
    }

    private function isOptionalQuantifier(string $quantifier): bool
    {
        if ('?' === $quantifier) {
            return true;
        }

        $range = $this->parseRangeQuantifier($quantifier);
        if (null === $range) {
            return false;
        }

        [$min, $max] = $range;

        return 0 === $min && 1 === $max;
    }

    /**
     * @return array{0: int, 1: int|null}|null
     */
    private function parseRangeQuantifier(string $quantifier): ?array
    {
        if (!str_starts_with($quantifier, '{')) {
            return null;
        }

        $bounds = QuantifierBounds::parse($quantifier);

        return null === $bounds ? null : [$bounds->min, $bounds->max];
    }
}

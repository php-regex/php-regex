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
use PHPRegex\Parser\Node\RegexNode;

/**
 * Draws a pattern as a standalone railroad-diagram SVG, its stylesheet
 * embedded. Accepted on the RegexNode of a parsed pattern it gives the
 * diagram; on any other node, the empty string: a diagram is drawn for a
 * whole pattern.
 *
 * The diagram is laid out by RailroadLayoutVisitor, then written here: the
 * layout on a padded canvas, one SVG element for each of its boxes, paths,
 * terminals, nodes and texts, every text and attribute escaped.
 *
 * @phpstan-import-type SvgLayout from RailroadLayoutVisitor
 * @phpstan-import-type SvgPoint from RailroadLayoutVisitor
 *
 * @extends AbstractNodeVisitor<string>
 */
final class RailroadSvgRenderer extends AbstractNodeVisitor
{
    /**
     * The space around the diagram, on each side.
     */
    private const CANVAS_PADDING = 18;

    private const FONT_SIZE = 12;

    private const NODE_RADIUS = 7;

    #[\Override]
    public function visitRegex(RegexNode $node): string
    {
        return $this->write((new RailroadLayoutVisitor())->visitRegex($node));
    }

    #[\Override]
    protected function defaultReturn(): string
    {
        return '';
    }

    /**
     * @param SvgLayout $layout
     */
    private function write(array $layout): string
    {
        $pad = self::CANVAS_PADDING;
        $width = $layout['width'] + ($pad * 2);
        $height = $layout['height'] + ($pad * 2);

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

        foreach ($layout['boxes'] as $box) {
            $svg[] = \sprintf(
                '<rect class="%s" x="%d" y="%d" width="%d" height="%d" rx="%d" ry="%d"/>',
                self::escapeAttribute($box['class']),
                $box['x'] + $pad,
                $box['y'] + $pad,
                $box['width'],
                $box['height'],
                $box['rx'],
                $box['ry'],
            );
        }

        foreach ($layout['paths'] as $path) {
            $markerEnd = isset($path['markerEnd']) && $path['markerEnd'] ? ' marker-end="url(#arrowhead)"' : '';
            $svg[] = \sprintf(
                '<path class="%s"%s d="%s"/>',
                self::escapeAttribute($path['class']),
                $markerEnd,
                self::pathData($path['points'], $pad),
            );
        }

        foreach ($layout['markers'] as $marker) {
            $svg[] = \sprintf(
                '<circle class="%s" cx="%d" cy="%d" r="%d"/>',
                self::escapeAttribute($marker['class']),
                $marker['cx'] + $pad,
                $marker['cy'] + $pad,
                $marker['r'],
            );
        }

        foreach ($layout['nodes'] as $node) {
            $x = $node['x'] + $pad;
            $y = $node['y'] + $pad;
            $svg[] = \sprintf(
                '<rect class="%s" x="%d" y="%d" width="%d" height="%d" rx="%d" ry="%d"/>',
                self::escapeAttribute($node['class']),
                $x,
                $y,
                $node['width'],
                $node['height'],
                self::NODE_RADIUS,
                self::NODE_RADIUS,
            );

            // The lines of the label, stacked in the middle of the node.
            $labelLines = $node['labelLines'] ?? [$node['label']];
            $centerX = $x + (int) floor($node['width'] / 2);
            $startY = $y + (int) floor(($node['height'] - RailroadLayoutVisitor::LINE_HEIGHT * \count($labelLines)) / 2);
            foreach ($labelLines as $index => $line) {
                $svg[] = \sprintf(
                    '<text class="label" x="%d" y="%d">%s</text>',
                    $centerX,
                    $startY + ($index * RailroadLayoutVisitor::LINE_HEIGHT) + (int) floor(RailroadLayoutVisitor::LINE_HEIGHT / 2),
                    self::escapeText($line),
                );
            }
        }

        foreach ($layout['texts'] as $text) {
            $svg[] = \sprintf(
                '<text class="%s" x="%d" y="%d">%s</text>',
                self::escapeAttribute($text['class']),
                $text['x'] + $pad,
                $text['y'] + $pad,
                self::escapeText($text['text']),
            );
        }

        $svg[] = '</svg>';

        return implode("\n", $svg);
    }

    /**
     * The "d" of a path through some points, each moved by the padding.
     *
     * @param list<SvgPoint> $points
     */
    private static function pathData(array $points, int $pad): string
    {
        $commands = [];
        foreach ($points as $index => [$x, $y]) {
            $commands[] = (0 === $index ? 'M ' : 'L ').($x + $pad).' '.($y + $pad);
        }

        return implode(' ', $commands);
    }

    private static function escapeText(string $text): string
    {
        return htmlspecialchars($text, \ENT_XML1 | \ENT_SUBSTITUTE | \ENT_QUOTES, 'UTF-8');
    }

    private static function escapeAttribute(string $value): string
    {
        return htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
    }
}

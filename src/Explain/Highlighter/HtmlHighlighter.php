<?php

declare(strict_types=1);

/*
 * This file is part of the PhpRegex package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace PhpRegex\Explain\Highlighter;

/**
 * Highlights regex syntax for HTML output using span tags with classes.
 */
final class HtmlHighlighter extends AbstractHighlighter
{
    private const CLASSES = [
        'meta' => ['regex-token', 'regex-meta'],
        'quantifier' => ['regex-token', 'regex-quantifier'],
        'literal' => ['regex-token', 'regex-literal'],
        'anchor' => ['regex-token', 'regex-anchor'],
        'escape' => ['regex-token', 'regex-type', 'regex-escape'],
        'backref' => ['regex-token', 'regex-type', 'regex-backref'],
        'group' => ['regex-token', 'regex-meta', 'regex-group'],
        'comment' => ['regex-token', 'regex-meta', 'regex-comment'],
        'keyword' => ['regex-token', 'regex-meta', 'regex-keyword'],
        'flag' => ['regex-token', 'regex-flag'],
        'identifier' => ['regex-token', 'regex-identifier'],
        'number' => ['regex-token', 'regex-number'],
    ];

    protected function wrap(string $content, string $type): string
    {
        if ('' === $content) {
            return '';
        }

        $classes = self::CLASSES[$type] ?? ['regex-token'];
        $classAttr = implode(' ', $classes);

        return '<span class="'.$classAttr.'">'.$content.'</span>';
    }

    protected function escape(string $string): string
    {
        return htmlspecialchars($string);
    }
}

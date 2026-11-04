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

namespace PHPRegex\Mcp\Tool;

use PHPRegex\Mcp\ToolResult;
use PHPRegex\Parser\Exception\FormattingException;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\LimitException;
use PHPRegex\Parser\Exception\ParserException;

/**
 * regex_format: the pattern laid out over lines under x, or compacted to
 * one line, the document `regex format --json` prints.
 *
 * @internal
 */
final class FormatTool extends AbstractTool
{
    public function execute(array $arguments): ToolResult
    {
        $pattern = self::pattern($arguments);
        if ($pattern instanceof ToolResult) {
            return $pattern;
        }
        $width = $arguments['width'] ?? 80;
        if (!\is_int($width) || $width < 1) {
            return ToolResult::failure('The "width" argument must be a positive integer.', 'usage');
        }
        $compact = $arguments['compact'] ?? false;
        if (!\is_bool($compact)) {
            return ToolResult::failure('The "compact" argument must be true or false.', 'usage');
        }
        $regex = $this->regex($arguments);
        if ($regex instanceof ToolResult) {
            return $regex;
        }

        try {
            $result = $compact ? $regex->compact($pattern) : $regex->format($pattern, $width);
        } catch (LexerException|LimitException|ParserException $e) {
            return ToolResult::failure($e->getMessage(), 'pattern');
        } catch (FormattingException $e) {
            // Not reached by a test: the formatter's self-check refused its own
            // output, a bug no pattern the formatter handles correctly reaches.
            return ToolResult::failure(\sprintf('The formatter could not keep the meaning of this pattern (%s). This is a bug; please report it with the pattern: %s', $e->errorCode->value, $pattern), 'internal');
        }

        $text = $result->pattern;
        if (null !== $result->refusal) {
            $text .= "\nNote: ".$result->refusal->note();
        }

        return ToolResult::document($text, [
            'pattern' => $pattern,
            'formatted' => $result->pattern,
            'changed' => $result->changed,
            'refusal' => $result->refusal?->value,
        ]);
    }
}

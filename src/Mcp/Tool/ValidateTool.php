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
use PHPRegex\Parser\Validation\ValidationErrorCategory;

/**
 * regex_validate: the verdict of the target PHP on a pattern, the document
 * `regex validate --json` prints. An invalid pattern is the answer, not a
 * failure of the tool.
 *
 * @internal
 */
final class ValidateTool extends AbstractTool
{
    public function execute(array $arguments): ToolResult
    {
        $pattern = self::pattern($arguments);
        if ($pattern instanceof ToolResult) {
            return $pattern;
        }
        $regex = $this->regex($arguments);
        if ($regex instanceof ToolResult) {
            return $regex;
        }

        $validation = $regex->validate($pattern);

        if ($validation->isValid) {
            $text = 'Valid.';
        } else {
            $text = ValidationErrorCategory::Limit === $validation->category ? 'Not analyzed: ' : '';
            $text .= $validation->error ?? 'Invalid pattern.';
            if (null !== $validation->hint) {
                $text .= "\n".$validation->hint;
            }
        }

        return ToolResult::document($text, ['pattern' => $pattern, 'validation' => $validation]);
    }
}

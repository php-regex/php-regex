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
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPRegex\Transpiler\TranspileOptions;
use PHPRegex\Transpiler\Transpiler;

/**
 * regex_transpile: the pattern in another dialect with every loss written
 * out, the document `regex transpile --json` prints. A construct the
 * dialect cannot express is a refusal with its message, never a lookalike.
 *
 * @internal
 */
final class TranspileTool extends AbstractTool
{
    public const TARGETS = ['js', 'html', 'python'];

    public function execute(array $arguments): ToolResult
    {
        $pattern = self::pattern($arguments);
        if ($pattern instanceof ToolResult) {
            return $pattern;
        }
        $target = $arguments['target'] ?? null;
        if (!\is_string($target) || !\in_array($target, self::TARGETS, true)) {
            return ToolResult::failure('The "target" argument is required: js, html or python.', 'usage');
        }
        $pythonVersion = $arguments['python_version'] ?? null;
        if (null !== $pythonVersion && !\is_string($pythonVersion)) {
            return ToolResult::failure('The "python_version" argument must be a Python release, as "3.11".', 'usage');
        }

        try {
            $options = new TranspileOptions(pythonVersion: $pythonVersion);
        } catch (InvalidRegexOptionException $e) {
            return ToolResult::failure(strtr($e->getMessage(), ['"pythonVersion"' => '"python_version"']), 'usage');
        }
        $regex = $this->regex($arguments);
        if ($regex instanceof ToolResult) {
            return $regex;
        }
        $refusal = self::refusal($regex, $pattern);
        if (null !== $refusal) {
            return $refusal;
        }

        try {
            $result = (new Transpiler($regex->parser()))->transpile($pattern, $target, $options);
        } catch (LexerException|ParserException|TranspileException $e) {
            return ToolResult::failure($e->getMessage(), 'pattern');
        }

        $lines = [$result->literal];
        foreach ($result->warnings as $warning) {
            $lines[] = 'Warning: '.$warning;
        }
        foreach ($result->notes as $note) {
            $lines[] = 'Note: '.$note;
        }

        return ToolResult::document(implode("\n", $lines), $result->jsonSerialize());
    }
}

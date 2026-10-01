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

namespace PhpRegex\Cli\Command;

use PhpRegex\Cli\ConsoleStyle;
use PhpRegex\Cli\Input;
use PhpRegex\Cli\Output;
use PhpRegex\Explain\Highlighter\ConsoleHighlighter;
use PhpRegex\Parser\Exception\LexerException;
use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Parser\Printer\PatternPrinter;
use PhpRegex\Toolkit\Regex;

final class ParseCommand extends AbstractCommand
{
    public function getName(): string
    {
        return 'parse';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return 'Parse and recompile a regex pattern';
    }

    public function run(Input $input, Output $output): int
    {
        $arguments = $this->readArguments($input->args, ['--validate']);
        if (null !== $arguments['error']) {
            return $this->usageError($output, $arguments['error'], "Usage: regex parse <pattern> [--validate]\n");
        }

        $pattern = $arguments['pattern'];
        $validate = isset($arguments['options']['--validate']);
        $regex = $this->createRegex($output, $input->regexOptions);
        if (null === $regex) {
            return self::INVALID;
        }

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $meta = $this->buildRuntimeMeta($input, $output, $validate);
        $style->renderBanner('parse', $meta);

        try {
            return $this->executeParsing($output, $regex, $style, $pattern, $validate);
        } catch (LexerException|ParserException $e) {
            return $this->handleParseError($output, $e->getMessage());
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildRuntimeMeta(Input $input, Output $output, bool $validate): array
    {
        $meta = [];

        $meta += $this->targetMeta($input, $output);

        if ($validate) {
            $meta['Validation'] = $output->warning('on');
        }

        return $meta;
    }

    private function executeParsing(Output $output, Regex $regex, ConsoleStyle $style, string $pattern, bool $validate): int
    {
        $ast = $regex->parse($pattern);
        $compiled = $ast->accept(new PatternPrinter());
        $highlightedPattern = $output->isAnsi()
            ? $ast->accept(new ConsoleHighlighter())
            : $pattern;

        $steps = $validate ? 2 : 1;

        $this->renderParsingSection($style, $highlightedPattern, $compiled);

        if ($validate && !$this->renderValidationSection($output, $regex, $style, $pattern, $steps)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function renderParsingSection(ConsoleStyle $style, string $highlightedPattern, string $compiled): void
    {
        $style->renderSection('Parsing pattern', 1, 1);
        $style->renderPattern($highlightedPattern);
        $style->renderKeyValueBlock([
            'Parse' => 'OK',
            'Recompiled' => $compiled,
        ]);
    }

    /**
     * @return bool whether the pattern is valid
     */
    private function renderValidationSection(Output $output, Regex $regex, ConsoleStyle $style, string $pattern, int $steps): bool
    {
        if ($style->visualsEnabled()) {
            $output->write("\n");
        }

        $style->renderSection('Validation', 2, $steps);
        $validation = $regex->validate($pattern);
        $status = $validation->isValid ? $output->success('OK') : $output->error('INVALID');
        $style->renderKeyValueBlock([
            'Status' => $status,
        ]);

        if (!$validation->isValid && null !== $validation->error) {
            $output->write('  '.$output->error($validation->error)."\n");
        }

        if (!$validation->isValid && null !== $validation->caretSnippet) {
            $output->write($output->error($validation->caretSnippet)."\n");
        }

        return $validation->isValid;
    }

    private function handleParseError(Output $output, string $errorMessage): int
    {
        $output->write('  '.$output->badge('FAIL', Output::WHITE, Output::BG_RED).' '.$output->error('Parse failed: '.$errorMessage)."\n");

        return self::FAILURE;
    }
}

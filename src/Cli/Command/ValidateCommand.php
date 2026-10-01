<?php

declare(strict_types=1);

/*
 * This file is part of the RegexParser package.
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
use PhpRegex\Parser\Validation\ValidationResult;
use PhpRegex\Toolkit\Regex;

final class ValidateCommand extends AbstractCommand
{
    public function getName(): string
    {
        return 'validate';
    }

    public function getAliases(): array
    {
        return [];
    }

    public function getDescription(): string
    {
        return 'Validate a regex pattern';
    }

    public function run(Input $input, Output $output): int
    {
        $arguments = $this->readArguments($input->args);
        if (null !== $arguments['error']) {
            return $this->usageError($output, $arguments['error'], "Usage: regex validate <pattern>\n");
        }

        $pattern = $arguments['pattern'];
        $regex = $this->createRegex($output, $input->regexOptions);
        if (null === $regex) {
            return self::INVALID;
        }

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $meta = $this->buildRuntimeMeta($input, $output);
        $style->renderBanner('validate', $meta);

        $validation = $regex->validate($pattern);
        $highlightedPattern = $this->highlightPattern($regex, $pattern, $output, $validation);

        $this->renderValidationSection($style, $highlightedPattern);

        if ($validation->isValid) {
            return $this->renderSuccessResult($style, $output);
        }

        return $this->renderErrorResult($style, $output, $validation);
    }

    /**
     * @return array<string, string>
     */
    private function buildRuntimeMeta(Input $input, Output $output): array
    {
        $meta = [];

        $meta += $this->targetMeta($input, $output);

        return $meta;
    }

    private function highlightPattern(Regex $regex, string $pattern, Output $output, ValidationResult $validation): string
    {
        if (!$output->isAnsi() || !$validation->isValid) {
            return $pattern;
        }

        try {
            $ast = $regex->parse($pattern);

            return $ast->accept(new ConsoleHighlighter());
        } catch (LexerException|ParserException) {
            return $pattern;
        }
    }

    private function renderValidationSection(ConsoleStyle $style, string $highlightedPattern): void
    {
        $style->renderSection('Validating pattern', 1, 1);
        $style->renderPattern($highlightedPattern);
    }

    private function renderSuccessResult(ConsoleStyle $style, Output $output): int
    {
        $style->renderKeyValueBlock([
            'Status' => $output->success('OK'),
        ]);

        return self::SUCCESS;
    }

    private function renderErrorResult(ConsoleStyle $style, Output $output, ValidationResult $validation): int
    {
        $style->renderKeyValueBlock([
            'Status' => $output->error('INVALID'),
        ]);

        if (null !== $validation->error) {
            $output->write('  '.$output->error($validation->error)."\n");
        }

        if (null !== $validation->caretSnippet) {
            $output->write($output->error($validation->caretSnippet)."\n");
        }

        return self::FAILURE;
    }
}

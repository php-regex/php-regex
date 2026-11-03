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

namespace PHPRegex\Cli\Command;

use PHPRegex\Cli\ConsoleStyle;
use PHPRegex\Cli\GlobalOptionsParser;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\JsonRequest;
use PHPRegex\Cli\Option\Arguments;
use PHPRegex\Cli\Option\Option;
use PHPRegex\Cli\Option\Options;
use PHPRegex\Cli\Output;
use PHPRegex\Explain\Highlighter\ConsoleHighlighter;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Json\JsonDocument;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Parser\Validation\ValidationResult;
use PHPRegex\Toolkit\Regex;

/**
 * @internal
 */
final class ValidateCommand extends AbstractCommand implements JsonCommandInterface
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

    /**
     * The options of the command, which its help lists.
     */
    public static function options(): Options
    {
        return new Options(Arguments::LoosePattern, [
            Option::value('--format', '<format>', 'Output format (console, json)'),
            Option::flag('--json', 'Same as --format=json'),
            ...GlobalOptionsParser::target(),
        ], "Usage: regex validate <pattern> [--format=console|json]\n");
    }

    public function run(Input $input, Output $output): int
    {
        $json = JsonRequest::in($input->args);
        $usage = self::options()->usage;
        $arguments = self::options()->parse($input->args);
        if (null !== $arguments->error) {
            return $this->usageError($output, $arguments->error, $usage, $json);
        }

        // --json and --format name the same thing: the last one wins.
        $format = JsonRequest::format($input->args) ?? 'console';
        if (!\in_array($format, ['console', 'json'], true)) {
            return $this->usageError($output, 'Invalid value for --format. Use console or json.', $usage, $json);
        }

        $pattern = $arguments->argument();
        $regex = $this->createRegex($output, $input->regexOptions, $json);
        if (null === $regex) {
            return self::INVALID;
        }

        $validation = $regex->validate($pattern);

        // The verdict is the document, valid or not: an invalid pattern is
        // what the command reports on, never a failure of the run.
        if ($json) {
            $output->writeDocument(JsonDocument::encode(['pattern' => $pattern, 'validation' => $validation]));

            return $validation->isValid ? self::SUCCESS : self::FAILURE;
        }

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);
        $meta = $this->buildRuntimeMeta($input, $output);
        $style->renderBanner('validate', $meta);

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
        // A limit of the library: no verdict, the pattern is not invalid.
        if (ValidationErrorCategory::Limit === $validation->category) {
            $style->renderKeyValueBlock([
                'Status' => $output->warning('NOT ANALYZED'),
            ]);

            if (null !== $validation->error) {
                $output->write('  '.$output->warning($validation->error)."\n");
            }

            if (null !== $validation->hint) {
                $output->write('  '.$validation->hint."\n");
            }

            return self::FAILURE;
        }

        $style->renderKeyValueBlock([
            'Status' => $output->error('INVALID'),
        ]);

        if (null !== $validation->error) {
            $output->write('  '.$output->error($validation->error)."\n");
        }

        if (null !== $validation->caretSnippet) {
            $output->write($output->error($validation->caretSnippet)."\n");
        }

        if (null !== $validation->hint) {
            $output->write('  '.$validation->hint."\n");
        }

        return self::FAILURE;
    }
}

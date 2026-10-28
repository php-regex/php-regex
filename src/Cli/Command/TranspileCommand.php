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
use PHPRegex\Cli\Input;
use PHPRegex\Cli\JsonRequest;
use PHPRegex\Cli\Option\Arguments;
use PHPRegex\Cli\Option\Option;
use PHPRegex\Cli\Option\Options;
use PHPRegex\Cli\Output;
use PHPRegex\Parser\Exception\InvalidRegexOptionException;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Json\JsonDocument;
use PHPRegex\Parser\Text\Ascii;
use PHPRegex\Transpiler\Exception\TranspileException;
use PHPRegex\Transpiler\Target\TargetRegistry;
use PHPRegex\Transpiler\TranspileOptions;
use PHPRegex\Transpiler\Transpiler;
use PHPRegex\Transpiler\TranspileResult;

/**
 * @internal
 */
final class TranspileCommand extends AbstractCommand implements JsonCommandInterface
{
    public function getName(): string
    {
        return 'transpile';
    }

    public function getAliases(): array
    {
        return ['t', 'convert'];
    }

    public function getDescription(): string
    {
        return 'Transpile PCRE regex to other dialects (js, html, python)';
    }

    public function run(Input $input, Output $output): int
    {
        $json = JsonRequest::in($input->args);
        $args = $this->parseArguments($input->args);

        if (null !== $args['error']) {
            return $this->usageError($output, $args['error'], self::options()->usage, $json);
        }

        $regex = $this->createRegex($output, $input->regexOptions, $json);
        if (null === $regex) {
            return self::INVALID;
        }

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);

        // Only a valid pattern is translated: a semantic error parses, and
        // would otherwise come out in the other dialect.
        $validation = $regex->validate($args['pattern']);
        if (!$validation->isValid) {
            $message = $validation->error ?? 'Invalid pattern.';
            if ('json' === $args['format']) {
                $output->writeDocument(JsonDocument::error($message, JsonDocument::STAGE_PATTERN, ['validation' => $validation]));

                return self::FAILURE;
            }

            $output->write('  '.$output->error('Transpile failed: '.$message)."\n");

            return self::FAILURE;
        }

        try {
            $transpiler = new Transpiler($regex->parser());

            $result = $transpiler->transpile($args['pattern'], $args['target'], new TranspileOptions(pythonVersion: $args['pythonVersion']));

            if ('json' === $args['format']) {
                $output->writeDocument(JsonDocument::encode($result->jsonSerialize()));

                return self::SUCCESS;
            }

            return $this->renderConsoleOutput($output, $style, $result);
        } catch (LexerException|ParserException|TranspileException $e) {
            // A valid pattern the target cannot express.
            if ('json' === $args['format']) {
                $output->writeDocument(JsonDocument::error($e->getMessage(), JsonDocument::STAGE_PATTERN));

                return self::FAILURE;
            }
            $output->write('  '.$output->error('Transpile failed: '.$e->getMessage())."\n");

            if ($e instanceof TranspileException && null !== $e->position) {
                $output->write('  At offset '.$e->position."\n");
            }

            return self::FAILURE;
        }
    }

    /**
     * The options of the command, which its help lists.
     */
    public static function options(): Options
    {
        return new Options(Arguments::Pattern, [
            Option::value('--target', '<dialect>', 'Target dialect (js, html, python)'),
            Option::value('--python-version', '<x.y>', 'The Python release the pattern runs on (3.11 carries atomic groups)'),
            Option::value('--format', '<format>', 'Output format (console, json)'),
            Option::flag('--json', 'Same as --format=json'),
        ], "Usage: regex transpile <pattern> [--target=js|html|python] [--python-version=<x.y>] [--format=json]\n");
    }

    /**
     * @param array<int, string> $args
     *
     * @return array{pattern: string, target: string, format: string, pythonVersion: ?string, error: ?string}
     */
    private function parseArguments(array $args): array
    {
        $parsed = self::options()->parse($args);
        if (null !== $parsed->error) {
            return ['pattern' => '', 'target' => '', 'format' => '', 'pythonVersion' => null, 'error' => $parsed->error];
        }

        $target = 'js';
        $format = 'console';
        $pythonVersion = null;
        foreach ($parsed->options as $option) {
            if ('--json' === $option->name) {
                $format = 'json';
            } elseif ('--format' === $option->name) {
                $format = Ascii::lower((string) $option->value);
            } elseif ('--python-version' === $option->name) {
                $pythonVersion = (string) $option->value;
            } else {
                $target = Ascii::lower((string) $option->value);
            }
        }

        if (!\in_array($format, ['console', 'json'], true)) {
            return ['pattern' => '', 'target' => '', 'format' => '', 'pythonVersion' => null, 'error' => 'Invalid value for --format. Use console or json.'];
        }

        try {
            (new TargetRegistry())->get($target);
            new TranspileOptions(pythonVersion: $pythonVersion);
        } catch (TranspileException|InvalidRegexOptionException $e) {
            return ['pattern' => '', 'target' => '', 'format' => '', 'pythonVersion' => null, 'error' => $e->getMessage()];
        }

        return [
            'pattern' => $parsed->argument(),
            'target' => $target,
            'format' => $format,
            'pythonVersion' => $pythonVersion,
            'error' => null,
        ];
    }

    private function renderConsoleOutput(Output $output, ConsoleStyle $style, TranspileResult $result): int
    {
        $style->renderSection('Transpilation Result', 1, 1);

        $style->renderKeyValueBlock([
            'Target' => $output->success(Ascii::upper($result->target)),
            'Source' => $result->source,
        ]);

        $output->write("\n");
        $output->write($output->info('  Literal:')."\n");
        $output->write('    '.$result->literal."\n\n");

        $output->write($output->info('  Constructor:')."\n");
        $output->write('    '.$result->constructor."\n");

        if ($result->hasWarnings()) {
            $output->write("\n");
            $output->write($output->warning('  Warnings:')."\n");
            foreach ($result->warnings as $warning) {
                $output->write('   - '.$warning."\n");
            }
        }

        if ($result->hasNotes()) {
            $output->write("\n");
            $output->write($output->info('  Notes:')."\n");
            foreach ($result->notes as $note) {
                $output->write('   - '.$note."\n");
            }
        }

        return self::SUCCESS;
    }
}

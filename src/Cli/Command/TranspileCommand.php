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
use PHPRegex\Cli\Output;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Internal\JsonDocument;
use PHPRegex\Transpiler\Target\TargetRegistry;
use PHPRegex\Transpiler\TranspileException;
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
            return $this->usageError($output, $args['error'], "Usage: regex transpile <pattern> [--target=js|html|python] [--format=json]\n", $json);
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

            $result = $transpiler->transpile($args['pattern'], $args['target']);

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
     * @param array<int, string> $args
     *
     * @return array{pattern: string, target: string, format: string, error: ?string}
     */
    private function parseArguments(array $args): array
    {
        $pattern = '';
        $target = 'js';
        $format = 'console';
        $stopParsing = false;

        for ($i = 0; $i < \count($args); $i++) {
            $arg = $args[$i];

            if (!$stopParsing && '--' === $arg) {
                $stopParsing = true;

                continue;
            }

            if (!$stopParsing && '--json' === $arg) {
                $format = 'json';

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--target=')) {
                $target = strtolower(substr($arg, \strlen('--target=')));

                continue;
            }

            if (!$stopParsing && '--target' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return ['pattern' => '', 'target' => '', 'format' => '', 'error' => 'Missing value for --target.'];
                }
                $target = strtolower($value);
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '--format=')) {
                $format = strtolower(substr($arg, \strlen('--format=')));

                continue;
            }

            if (!$stopParsing && '--format' === $arg) {
                $value = $args[$i + 1] ?? '';
                if ('' === $value || str_starts_with($value, '-')) {
                    return ['pattern' => '', 'target' => '', 'format' => '', 'error' => 'Missing value for --format.'];
                }
                $format = strtolower($value);
                $i++;

                continue;
            }

            if (!$stopParsing && str_starts_with($arg, '-')) {
                return ['pattern' => '', 'target' => '', 'format' => '', 'error' => 'Unknown option: '.$arg];
            }

            if ('' === $pattern) {
                $pattern = $arg;

                continue;
            }
        }

        if ('' === $pattern) {
            return ['pattern' => '', 'target' => '', 'format' => '', 'error' => 'Missing pattern.'];
        }

        if (!\in_array($format, ['console', 'json'], true)) {
            return ['pattern' => '', 'target' => '', 'format' => '', 'error' => 'Invalid value for --format. Use console or json.'];
        }

        try {
            (new TargetRegistry())->get($target);
        } catch (TranspileException $e) {
            return ['pattern' => '', 'target' => '', 'format' => '', 'error' => $e->getMessage()];
        }

        return [
            'pattern' => $pattern,
            'target' => $target,
            'format' => $format,
            'error' => null,
        ];
    }

    private function renderConsoleOutput(Output $output, ConsoleStyle $style, TranspileResult $result): int
    {
        $style->renderSection('Transpilation Result', 1, 1);

        $style->renderKeyValueBlock([
            'Target' => $output->success(strtoupper($result->target)),
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

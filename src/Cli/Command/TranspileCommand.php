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
use PhpRegex\Parser\Exception\LexerException;
use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Transpiler\Target\TargetRegistry;
use PhpRegex\Transpiler\TranspileException;
use PhpRegex\Transpiler\Transpiler;
use PhpRegex\Transpiler\TranspileResult;

final class TranspileCommand extends AbstractCommand
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
        return 'Transpile PCRE regex to other dialects (js, python, etc.)';
    }

    public function run(Input $input, Output $output): int
    {
        $args = $this->parseArguments($input->args);

        if (null !== $args['error']) {
            $output->write($output->error('Error: '.$args['error']."\n"));
            $output->write("Usage: regex transpile <pattern> [--target=js|python] [--format=json]\n");

            return self::INVALID;
        }

        $regex = $this->createRegex($output, $input->regexOptions);
        if (null === $regex) {
            return self::INVALID;
        }

        $style = new ConsoleStyle($output, $input->globalOptions->visuals);

        try {
            // We use a direct instantiation here or via Regex facade if exposed?
            // The Regex facade doesn't seem to expose transpiler directly in the previous Read,
            // but we can instantiate Transpiler manually.
            $transpiler = new Transpiler($regex->parser());

            $result = $transpiler->transpile($args['pattern'], $args['target']);

            if ('json' === $args['format']) {
                return $this->renderJsonOutput($output, $result);
            }

            return $this->renderConsoleOutput($output, $style, $result);
        } catch (LexerException|ParserException|TranspileException $e) {
            if ('json' === $args['format']) {
                $output->write(json_encode(['error' => $e->getMessage()], \JSON_PRETTY_PRINT)."\n");

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

    private function renderJsonOutput(Output $output, TranspileResult $result): int
    {
        $payload = [
            'target' => $result->target,
            'source' => $result->source,
            'pattern' => $result->pattern,
            'flags' => $result->flags,
            'literal' => $result->literal,
            'constructor' => $result->constructor,
            'warnings' => $result->warnings,
            'notes' => $result->notes,
        ];

        $output->write(json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES)."\n");

        return self::SUCCESS;
    }
}

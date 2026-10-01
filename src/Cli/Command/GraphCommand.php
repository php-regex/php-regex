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

use PhpRegex\Automata\Options\SolverOptions;
use PhpRegex\Automata\Transform\AstToNfaTransformer;
use PhpRegex\Cli\Graph\GraphGenerator;
use PhpRegex\Cli\Input;
use PhpRegex\Cli\Output;
use PhpRegex\Parser\Exception\LexerException;
use PhpRegex\Parser\Exception\ParserException;

final class GraphCommand extends AbstractCommand
{
    public function getName(): string
    {
        return 'graph';
    }

    public function getAliases(): array
    {
        return ['automata', 'nfa'];
    }

    public function getDescription(): string
    {
        return 'Generate a graph diagram (DOT/Mermaid) of the NFA';
    }

    public function run(Input $input, Output $output): int
    {
        $usage = "Usage: regex graph <pattern> [--format=dot|mermaid] [--output=<file>]\n";
        $arguments = $this->readArguments($input->args, [], ['--format', '--output']);
        if (null !== $arguments['error']) {
            return $this->usageError($output, $arguments['error'], $usage);
        }

        $pattern = $arguments['pattern'];
        $format = (string) ($arguments['options']['--format'] ?? 'dot');
        $outputFile = $arguments['options']['--output'] ?? null;
        $outputFile = \is_string($outputFile) ? $outputFile : null;
        if (!\in_array($format, ['dot', 'graphviz', 'mermaid'], true)) {
            return $this->usageError($output, \sprintf("Unsupported format '%s'. Use --format=dot or --format=mermaid.", $format), $usage);
        }

        $regex = $this->createRegex($output, $input->regexOptions);
        if (null === $regex) {
            return self::INVALID;
        }

        try {
            $ast = $regex->parse($pattern);

            // We need to transform AST to NFA manually here as it's not exposed via Facade directly for just dumping
            // Assuming AstToNfaTransformer is the way
            $transformer = new AstToNfaTransformer($pattern);
            $nfa = $transformer->transform($ast, new SolverOptions());

            $generator = new GraphGenerator();
            $content = $generator->generate($nfa, $format);

            if (null === $outputFile) {
                $output->write($content);

                return self::SUCCESS;
            }

            if (false === @file_put_contents($outputFile, $content)) {
                $output->write($output->error("Error: Unable to write the graph to '{$outputFile}'.")."\n");

                return self::INVALID;
            }

            $output->write($output->success("Graph written to $outputFile")."\n");

            return self::SUCCESS;
        } catch (LexerException|ParserException $e) {
            $output->write($output->error('Error: '.$e->getMessage())."\n");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $output->write($output->error('Graph generation failed: '.$e->getMessage())."\n");

            return self::FAILURE;
        }
    }
}

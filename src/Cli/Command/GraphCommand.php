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

use PHPRegex\Automata\Options\SolverOptions;
use PHPRegex\Automata\Transform\HirToNfaTransformer;
use PHPRegex\Cli\Graph\GraphGenerator;
use PHPRegex\Cli\Input;
use PHPRegex\Cli\Option\Arguments;
use PHPRegex\Cli\Option\Option;
use PHPRegex\Cli\Option\Options;
use PHPRegex\Cli\Output;
use PHPRegex\Parser\Exception\LexerException;
use PHPRegex\Parser\Exception\LimitException;
use PHPRegex\Parser\Exception\ParserException;
use PHPRegex\Parser\Hir\HirTranslator;
use PHPRegex\Parser\Text\Ascii;

/**
 * @internal
 */
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

    /**
     * The options of the command, which its help lists.
     */
    public static function options(): Options
    {
        return new Options(Arguments::LoosePattern, [
            Option::value('--format', '<format>', 'Output format (dot, graphviz, mermaid)'),
            Option::value('--output', '<file>', 'Write the diagram to a file'),
        ], "Usage: regex graph <pattern> [--format=dot|graphviz|mermaid] [--output=<file>]\n");
    }

    public function run(Input $input, Output $output): int
    {
        $usage = self::options()->usage;
        $arguments = self::options()->parse($input->args);
        if (null !== $arguments->error) {
            return $this->usageError($output, $arguments->error, $usage);
        }

        $pattern = $arguments->argument();
        $format = Ascii::lower($arguments->value('--format') ?? 'dot');
        $outputFile = $arguments->value('--output');
        if (!\in_array($format, ['dot', 'graphviz', 'mermaid'], true)) {
            return $this->usageError($output, \sprintf("Unsupported format '%s'. Use --format=dot, --format=graphviz or --format=mermaid.", $format), $usage);
        }

        $regex = $this->createRegex($output, $input->regexOptions);
        if (null === $regex) {
            return self::INVALID;
        }

        try {
            $ast = $regex->parse($pattern);

            // We need to transform the pattern to an NFA manually here as
            // it's not exposed via the facade for just dumping.
            $transformer = new HirToNfaTransformer($pattern, HirTranslator::unicodeOf($ast));
            $nfa = $transformer->transform((new HirTranslator())->translate($ast), new SolverOptions());

            $generator = new GraphGenerator();
            $content = $generator->generate($nfa, $format);

            if (null === $outputFile) {
                $output->write($content);

                return self::SUCCESS;
            }

            if (false === @file_put_contents($outputFile, $content)) {
                $output->writeError($output->error("Error: Unable to write the graph to '{$outputFile}'.")."\n");

                return self::INVALID;
            }

            $output->write($output->success("Graph written to $outputFile")."\n");

            return self::SUCCESS;
        } catch (LexerException|LimitException|ParserException $e) {
            $output->write($output->error('Error: '.$e->getMessage())."\n");

            return self::FAILURE;
        } catch (\Throwable $e) {
            $output->write($output->error('Graph generation failed: '.$e->getMessage())."\n");

            return self::FAILURE;
        }
    }
}

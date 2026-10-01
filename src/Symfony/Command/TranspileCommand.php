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

namespace PhpRegex\Symfony\Command;

use PhpRegex\Parser\Exception\LexerException;
use PhpRegex\Parser\Exception\ParserException;
use PhpRegex\Toolkit\Regex;
use PhpRegex\Transpiler\Target\TargetRegistry;
use PhpRegex\Transpiler\TranspileException;
use PhpRegex\Transpiler\Transpiler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'regex:transpile',
    description: 'Transpile PCRE regex to other dialects.',
)]
final class TranspileCommand extends Command
{
    public function __construct(private readonly Regex $regex)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pattern', InputArgument::REQUIRED, 'The PCRE pattern to transpile.')
            ->addOption('target', 't', InputOption::VALUE_OPTIONAL, 'Target dialect (js, python)', 'js')
            ->addOption('format', null, InputOption::VALUE_OPTIONAL, 'Output format (console, json)', 'console');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pattern = $input->getArgument('pattern');
        if (!\is_string($pattern)) {
            $io->error('Pattern must be a string.');

            return Command::INVALID;
        }

        $target = $input->getOption('target');
        if (!\is_string($target)) {
            $target = 'js'; // Default fallback if something weird happens, though definition says default is 'js'
        }

        try {
            (new TargetRegistry())->get($target);
        } catch (TranspileException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }

        $format = $input->getOption('format');

        try {
            $transpiler = new Transpiler($this->regex->parser());
            $result = $transpiler->transpile($pattern, $target);

            if ('json' === $format) {
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
                $json = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

                if (false === $json) {
                    $output->writeln((string) json_encode(['error' => 'JSON encoding failed']));

                    return Command::FAILURE;
                }

                $output->writeln($json);

                return Command::SUCCESS;
            }

            $io->title('Transpilation Result');

            $io->text('<info>Target:</info> '.strtoupper($result->target));
            $io->text('<info>Source:</info> '.$result->source);
            $io->newLine();

            $io->section('Literal');
            $io->text('    '.$result->literal);

            $io->section('Constructor');
            $io->text('    '.$result->constructor);

            if ($result->hasWarnings()) {
                $io->warning($result->warnings);
            }

            if ($result->hasNotes()) {
                $io->note($result->notes);
            }

            return Command::SUCCESS;
        } catch (LexerException|ParserException|TranspileException $e) {
            if ('json' === $format) {
                $output->writeln((string) json_encode(['error' => $e->getMessage()], \JSON_PRETTY_PRINT));

                return Command::FAILURE;
            }

            $io->error($e->getMessage());

            return Command::FAILURE;
        }
    }
}

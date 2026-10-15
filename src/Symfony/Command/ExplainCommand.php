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

namespace PHPRegex\Symfony\Command;

use PHPRegex\Parser\Exception\ExceptionInterface;
use PHPRegex\Parser\Validation\ValidationErrorCategory;
use PHPRegex\Toolkit\Regex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The pattern in plain language, as the Laravel command and `regex explain`
 * give it, with what the analysis finds beside it.
 *
 * @internal
 */
#[AsCommand(
    name: 'regex:explain',
    description: 'Explain a regular expression in plain language.',
)]
final class ExplainCommand extends Command
{
    public function __construct(private readonly Regex $regex)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('pattern', InputArgument::REQUIRED, 'The regex pattern to explain (with delimiters)')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format (console, html)', 'console')
            ->addOption('highlight', null, InputOption::VALUE_NONE, 'Include syntax highlighting')
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command explains a pattern in plain language.

                <info>php %command.full_name% "/^[a-z]+$/"</info>
                <info>php %command.full_name% "/^[a-z]+$/" --highlight</info>
                <info>php %command.full_name% "/^[a-z]+$/" --format=html</info>
                EOF);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pattern = $input->getArgument('pattern');
        $format = $input->getOption('format');
        $format = \is_string($format) ? strtolower($format) : '';

        if (!\is_string($pattern)) {
            $io->error('The pattern must be a string.');

            return Command::INVALID;
        }

        if (!\in_array($format, ['console', 'html'], true)) {
            $io->error("Invalid format '{$format}'. Supported formats: console, html");

            return Command::INVALID;
        }

        $validation = $this->regex->validate($pattern);
        if (!$validation->isValid) {
            $limit = ValidationErrorCategory::Limit === $validation->category;
            $io->writeln($limit ? '<comment>Pattern not analyzed:</comment>' : '<error>Invalid regex pattern:</error>');
            $io->writeln(OutputFormatter::escape($validation->render()));

            return Command::FAILURE;
        }

        $io->writeln('<fg=white;options=bold>Pattern:</>');
        // Highlighting is for a decorated output only: piped or under
        // --no-ansi, its escape sequences would be written as they are.
        if ($input->getOption('highlight') && $output->isDecorated()) {
            $io->writeln('  '.$this->regex->highlight($pattern, 'console'));
        } else {
            $io->writeln('  <fg=yellow>'.OutputFormatter::escape($pattern).'</>');
        }
        $io->newLine();

        $io->writeln('<fg=white;options=bold>Explanation:</>');
        $io->writeln(OutputFormatter::escape($this->regex->explain($pattern, 'console' === $format ? 'text' : 'html')));
        $io->newLine();

        $analysis = $this->regex->analyze($pattern);

        if ($analysis->optimization->original !== $analysis->optimization->optimized) {
            $io->writeln('<fg=white;options=bold>Optimization Suggestion:</>');
            $io->writeln('  <fg=red>-</> '.OutputFormatter::escape($analysis->optimization->original));
            $io->writeln('  <fg=green>+</> '.OutputFormatter::escape($analysis->optimization->optimized));
            $io->newLine();
        }

        if (!$analysis->redos->isSafe()) {
            $io->writeln('<fg=white;options=bold>Security Warning:</>');
            $io->writeln('  <fg=red>'.OutputFormatter::escape($analysis->redos->headline()).'</>');
            $io->writeln('  Severity: <fg=yellow>'.$analysis->redos->severity->value.'</>');
            if (null !== $analysis->redos->witness) {
                $io->writeln('  Attack: <fg=yellow>'.OutputFormatter::escape($analysis->redos->witness->render()).'</>');
            }
            if (null !== $analysis->redos->vulnerablePart) {
                $io->writeln('  Vulnerable part: <fg=yellow>'.OutputFormatter::escape($analysis->redos->vulnerablePart).'</>');
            }
            foreach ($analysis->redos->recommendations as $recommendation) {
                $io->writeln('    - '.OutputFormatter::escape($recommendation));
            }
            $io->newLine();
        }

        if ([] !== $analysis->lintIssues) {
            $io->writeln('<fg=white;options=bold>Lint Issues:</>');
            foreach ($analysis->lintIssues as $issue) {
                $io->writeln('  - '.OutputFormatter::escape($issue->message));
            }
            $io->newLine();
        }

        $io->writeln('<fg=white;options=bold>Sample Match:</>');

        try {
            $io->writeln('  <fg=green>'.OutputFormatter::escape($this->regex->generate($pattern)).'</>');
        } catch (ExceptionInterface) {
            $io->writeln('  <fg=gray>(unable to generate sample)</>');
        }
        $io->newLine();

        return Command::SUCCESS;
    }
}

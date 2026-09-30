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

namespace RegexParser\Bridge\Symfony\Command;

use RegexParser\Automata\Determinization\DeterminizationAlgorithm;
use RegexParser\Automata\LanguageSolver;
use RegexParser\Automata\Minimization\MinimizationAlgorithm;
use RegexParser\Automata\Options\MatchMode;
use RegexParser\Automata\Options\SolverOptions;
use RegexParser\Exception\ComplexityException;
use RegexParser\Internal\DisplayEscaper;
use RegexParser\Regex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'regex:compare',
    description: 'Compare two regex patterns using automata logic.',
)]
final class CompareCommand extends Command
{
    private const METHOD_INTERSECTION = 'intersection';
    private const METHOD_SUBSET = 'subset';
    private const METHOD_EQUIVALENCE = 'equivalence';

    public function __construct(
        private readonly Regex $regex,
        private readonly string $defaultMinimizer = MinimizationAlgorithm::HOPCROFT->value,
        private readonly string $defaultDeterminizer = DeterminizationAlgorithm::SUBSET_INDEXED->value,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setAliases(['debug:compare'])
            ->addArgument('pattern1', InputArgument::REQUIRED, 'The first regex pattern (with delimiters)')
            ->addArgument('pattern2', InputArgument::REQUIRED, 'The second regex pattern (with delimiters)')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'intersection, subset, or equivalence', self::METHOD_INTERSECTION)
            ->addOption('minimizer', null, InputOption::VALUE_REQUIRED, 'hopcroft or moore', $this->defaultMinimizer)
            ->addOption('determinizer', null, InputOption::VALUE_REQUIRED, 'subset or subset-indexed', $this->defaultDeterminizer)
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command compares two regex patterns using automata logic.

                <info>php %command.full_name% "/[a-z]+/" "/edit/"</info>

                Choose a method:
                <info>php %command.full_name% "/[a-z]+/" "/edit/" --method=subset</info>
                <info>php %command.full_name% "/foo|bar/" "/bar|foo/" --method=equivalence</info>
                <info>php %command.full_name% "/foo/" "/bar/" --minimizer=moore</info>
                <info>php %command.full_name% "/foo/" "/bar/" --determinizer=subset-indexed</info>
                EOF);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pattern1 = $input->getArgument('pattern1');
        $pattern2 = $input->getArgument('pattern2');
        $methodOption = $input->getOption('method');
        $minimizerOption = $input->getOption('minimizer');
        $determinizerOption = $input->getOption('determinizer');

        if (!\is_string($pattern1) || '' === $pattern1) {
            $io->error('The first pattern must be a non-empty string.');

            return Command::INVALID;
        }

        if (!\is_string($pattern2) || '' === $pattern2) {
            $io->error('The second pattern must be a non-empty string.');

            return Command::INVALID;
        }

        if (!\is_string($methodOption) || '' === $methodOption) {
            $io->error('Invalid --method. Choose intersection, subset, or equivalence.');

            return Command::INVALID;
        }

        $method = \strtolower($methodOption);

        if (!\in_array($method, [self::METHOD_INTERSECTION, self::METHOD_SUBSET, self::METHOD_EQUIVALENCE], true)) {
            $io->error('Invalid --method. Choose intersection, subset, or equivalence.');

            return Command::INVALID;
        }

        $minimizer = $this->resolveMinimizationAlgorithm($minimizerOption, $io);
        if (null === $minimizer) {
            return Command::INVALID;
        }

        $determinizer = $this->resolveDeterminizationAlgorithm($determinizerOption, $io);
        if (null === $determinizer) {
            return Command::INVALID;
        }

        $solver = new LanguageSolver($this->regex->parser());
        $options = new SolverOptions(
            matchMode: MatchMode::FULL,
            minimizationAlgorithm: $minimizer,
            determinizationAlgorithm: $determinizer,
        );

        try {
            if (self::METHOD_INTERSECTION === $method) {
                return $this->handleIntersection($solver, $options, $pattern1, $pattern2, $io);
            }

            if (self::METHOD_SUBSET === $method) {
                return $this->handleSubset($solver, $options, $pattern1, $pattern2, $io);
            }

            return $this->handleEquivalence($solver, $options, $pattern1, $pattern2, $io);
        } catch (ComplexityException) {
            $io->error('Comparison not supported: Pattern contains advanced features (e.g., lookarounds).');

            return Command::FAILURE;
        } catch (\Throwable $exception) {
            $io->error('Comparison failed: '.$exception->getMessage());

            return Command::FAILURE;
        }
    }

    private function handleIntersection(
        LanguageSolver $solver,
        SolverOptions $options,
        string $pattern1,
        string $pattern2,
        SymfonyStyle $io,
    ): int {
        $result = $solver->intersection($pattern1, $pattern2, $options);

        if ($result->isEmpty) {
            $io->success('No intersection found. These regexes are disjoint.');

            return Command::SUCCESS;
        }

        $io->error('Conflict detected!');
        $io->writeln('Example: '.$this->formatExample($result->example ?? ''));

        return Command::FAILURE;
    }

    private function handleSubset(
        LanguageSolver $solver,
        SolverOptions $options,
        string $pattern1,
        string $pattern2,
        SymfonyStyle $io,
    ): int {
        $result = $solver->subsetOf($pattern1, $pattern2, $options);

        if ($result->isSubset) {
            $io->success('Pattern 1 is a strict subset of Pattern 2.');

            return Command::SUCCESS;
        }

        $io->error('Pattern 1 allows strings that Pattern 2 forbids.');
        $io->writeln('Counter-example: '.$this->formatExample($result->counterExample ?? ''));

        return Command::FAILURE;
    }

    private function handleEquivalence(
        LanguageSolver $solver,
        SolverOptions $options,
        string $pattern1,
        string $pattern2,
        SymfonyStyle $io,
    ): int {
        $result = $solver->equivalent($pattern1, $pattern2, $options);

        if ($result->isEquivalent) {
            $io->success('Patterns are mathematically equivalent.');

            return Command::SUCCESS;
        }

        $io->error('Patterns are different.');
        if (null !== $result->leftOnlyExample) {
            $io->writeln('Pattern 1 only: '.$this->formatExample($result->leftOnlyExample));
        }
        if (null !== $result->rightOnlyExample) {
            $io->writeln('Pattern 2 only: '.$this->formatExample($result->rightOnlyExample));
        }

        return Command::FAILURE;
    }

    private function formatExample(string $example): string
    {
        return DisplayEscaper::quote($example);
    }

    private function resolveMinimizationAlgorithm(mixed $value, SymfonyStyle $io): ?MinimizationAlgorithm
    {
        $normalized = \is_string($value) ? strtolower(trim($value)) : '';

        if ('' === $normalized) {
            $normalized = $this->defaultMinimizer;
        }

        $algorithm = MinimizationAlgorithm::tryFrom($normalized);
        if (null === $algorithm) {
            $io->error('Invalid --minimizer. Choose hopcroft or moore.');

            return null;
        }

        return $algorithm;
    }

    private function resolveDeterminizationAlgorithm(mixed $value, SymfonyStyle $io): ?DeterminizationAlgorithm
    {
        $normalized = \is_string($value) ? strtolower(trim($value)) : '';

        if ('' === $normalized) {
            $normalized = $this->defaultDeterminizer;
        }

        $algorithm = DeterminizationAlgorithm::tryFrom($normalized);
        if (null === $algorithm) {
            $io->error('Invalid --determinizer. Choose subset or subset-indexed.');

            return null;
        }

        return $algorithm;
    }
}

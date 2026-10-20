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

use PHPRegex\Symfony\Analyzer\AnalysisContext;
use PHPRegex\Symfony\Analyzer\Formatter\ConsoleReportFormatter;
use PHPRegex\Symfony\Analyzer\RoutesAnalyzer;
use PHPRegex\Symfony\Analyzer\SecurityReport;
use PHPRegex\Symfony\Routing\RouteConflictAnalyzer;
use PHPRegex\Symfony\Routing\RouteConflictSuggestionBuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\RouterInterface;

/**
 * The routes section of regex:analyze, on its own: the same analyzer,
 * printed the same way.
 *
 * @internal
 */
#[AsCommand(
    name: 'regex:routes',
    description: 'Analyze Symfony routes for ordering conflicts and overlaps.',
)]
final class RoutesCommand extends Command
{
    private readonly RoutesAnalyzer $routes;

    private readonly ConsoleReportFormatter $formatter;

    public function __construct(
        RouteConflictAnalyzer $analyzer,
        RouteConflictSuggestionBuilder $suggestionBuilder = new RouteConflictSuggestionBuilder(),
        private readonly ?RouterInterface $router = null,
    ) {
        $this->routes = new RoutesAnalyzer($analyzer, $suggestionBuilder, $router);
        $this->formatter = new ConsoleReportFormatter();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('check-conflicts', null, InputOption::VALUE_NONE, 'Check for route conflicts (default).')
            ->addOption('show-overlaps', null, InputOption::VALUE_NONE, 'Include partial overlaps in the report.')
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command inspects Symfony routes and detects ordering conflicts.

                By default, it reports shadowed (unreachable) routes.
                Use --show-overlaps to include partial overlaps.
                With -v, it also prints the counts of the analysis and why a route was skipped.

                <info>php %command.full_name%</info>
                <info>php %command.full_name% --show-overlaps</info>
                EOF);
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (null === $this->router) {
            $io->error('Router service is not available. Install Symfony Routing or enable the router service.');

            return Command::INVALID;
        }

        $context = new AnalysisContext(
            null,
            null,
            includeOverlaps: (bool) $input->getOption('show-overlaps'),
            debug: $output->isVerbose(),
        );
        $report = new SecurityReport($this->routes->analyze($context));

        $this->formatter->render($report, $io, true, $context->debug);

        return $report->hasAnyIssues() ? Command::FAILURE : Command::SUCCESS;
    }
}

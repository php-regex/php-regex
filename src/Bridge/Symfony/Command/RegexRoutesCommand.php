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

use RegexParser\Bridge\Symfony\Routing\RouteConflictAnalyzer;
use RegexParser\Bridge\Symfony\Routing\RouteConflictReport;
use RegexParser\Bridge\Symfony\Routing\RouteConflictSuggestionBuilder;
use RegexParser\Internal\DisplayEscaper;
use RegexParser\Regex;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Routing\RouterInterface;

/**
 * @phpstan-import-type RouteDescriptor from RouteConflictReport
 * @phpstan-import-type RouteConflict from RouteConflictReport
 */
#[AsCommand(
    name: 'regex:routes',
    description: 'Analyze Symfony routes for ordering conflicts and overlaps.',
)]
final class RegexRoutesCommand extends Command
{
    private const ARROW_LABEL = "\u{21B3}";
    private const TYPE_SHADOWED = 'shadowed';
    private const BADGE_FAIL = '<bg=red;fg=white;options=bold> FAIL </>';
    private const BADGE_WARN = '<bg=yellow;fg=black;options=bold> WARN </>';
    private const BADGE_PASS = '<bg=green;fg=white;options=bold> PASS </>';

    public function __construct(
        private readonly RouteConflictAnalyzer $analyzer,
        private readonly RouteConflictSuggestionBuilder $suggestionBuilder = new RouteConflictSuggestionBuilder(),
        private readonly ?RouterInterface $router = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHidden(true);

        $this
            ->addOption('check-conflicts', null, InputOption::VALUE_NONE, 'Check for route conflicts (default).')
            ->addOption('show-overlaps', null, InputOption::VALUE_NONE, 'Include partial overlaps in the report.')
            ->setHelp(<<<'EOF'
                The <info>%command.name%</info> command inspects Symfony routes and detects ordering conflicts.

                By default, it reports shadowed (unreachable) routes.
                Use --show-overlaps to include partial overlaps.

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

            return Command::FAILURE;
        }

        $collection = $this->router->getRouteCollection();
        if (0 === \count($collection->all())) {
            $this->showBanner($io);
            $io->writeln('  '.self::BADGE_PASS.' <fg=white>No routes found.</>');
            $this->showFooter($io);

            return Command::SUCCESS;
        }

        $includeOverlaps = (bool) $input->getOption('show-overlaps');
        $report = $this->analyzer->analyze($collection, $includeOverlaps);

        $this->showBanner($io);
        $this->renderWarnings($io, $output, $report);
        $this->renderMeta($io, $report, $includeOverlaps);
        $this->renderStatus($io, $report, $includeOverlaps);

        if (0 === $report->stats['conflicts']) {
            if (!$includeOverlaps && $report->stats['overlaps'] > 0) {
                $message = 'Partial overlaps detected. Re-run with --show-overlaps to see details.';
                $io->writeln('  <fg=gray>'.self::ARROW_LABEL.' '.$message.'</>');
                $io->newLine();
            }

            $this->showFooter($io);

            return Command::SUCCESS;
        }

        $this->renderConflictsList($io, $report);

        $suggestions = $this->suggestionBuilder->collect($report->conflicts);
        if ([] !== $suggestions) {
            $io->section('Suggestions');
            foreach ($suggestions as $suggestion) {
                $io->writeln('  <fg=gray>'.self::ARROW_LABEL.'</> '.$suggestion);
            }
            $io->newLine();
        }

        $this->showFooter($io);

        return Command::FAILURE;
    }

    private function showBanner(SymfonyStyle $io): void
    {
        $io->writeln('<fg=cyan;options=bold>RegexParser</> <fg=yellow>'.Regex::VERSION.'</> by Younes ENNAJI');
        $io->newLine();
    }

    private function renderWarnings(SymfonyStyle $io, OutputInterface $output, RouteConflictReport $report): void
    {
        if ([] !== $report->skippedRoutes) {
            $message = \sprintf('%d routes skipped due to unsupported regex features.', \count($report->skippedRoutes));
            $io->writeln('  '.self::BADGE_WARN.' <fg=white>'.$message.'</>');

            if ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $details = [];
                foreach ($report->skippedRoutes as $skip) {
                    $details[] = $skip['route'].': '.$skip['reason'];
                }
                foreach ($details as $detail) {
                    $io->writeln('      <fg=gray>'.self::ARROW_LABEL.' '.$detail.'</>');
                }
            }

            $io->newLine();
        }

        if ([] !== $report->routesWithConditions) {
            $message = \sprintf(
                '%d routes use conditions; conditions are not evaluated during conflict analysis.',
                \count(array_unique($report->routesWithConditions)),
            );
            $io->writeln('  '.self::BADGE_WARN.' <fg=white>'.$message.'</>');
            $io->newLine();
        }

        if ([] !== $report->routesWithUnsupportedHosts) {
            $message = \sprintf(
                '%d routes use host requirements that could not be analyzed.',
                \count(array_unique($report->routesWithUnsupportedHosts)),
            );
            $io->writeln('  '.self::BADGE_WARN.' <fg=white>'.$message.'</>');
            $io->newLine();
        }
    }

    private function renderMeta(SymfonyStyle $io, RouteConflictReport $report, bool $includeOverlaps): void
    {
        $mode = $includeOverlaps ? 'Shadowed + overlaps' : 'Shadowed only';

        $labels = ['Routes', 'Mode', 'Shadowed', 'Overlaps'];
        $maxLabelLength = max(array_map(strlen(...), $labels));
        $io->writeln($this->formatMetaLine('Routes', (string) $report->stats['routes'], $maxLabelLength));
        $io->writeln($this->formatMetaLine('Mode', $mode, $maxLabelLength));
        $io->writeln($this->formatMetaLine('Shadowed', (string) $report->stats['shadowed'], $maxLabelLength));
        $io->writeln($this->formatMetaLine('Overlaps', (string) $report->stats['overlaps'], $maxLabelLength));
        $io->newLine();
    }

    private function renderStatus(SymfonyStyle $io, RouteConflictReport $report, bool $includeOverlaps): void
    {
        $shadowed = $report->stats['shadowed'];
        $overlaps = $report->stats['overlaps'];

        if (0 === $shadowed && 0 === $overlaps) {
            $io->writeln('  '.self::BADGE_PASS.' <fg=white>No route conflicts detected.</>');
            $io->newLine();

            return;
        }

        if ($shadowed > 0) {
            $io->writeln(\sprintf(
                '  %s <fg=white>%d shadowed routes detected.</>',
                self::BADGE_FAIL,
                $shadowed,
            ));
        }

        if ($overlaps > 0) {
            $suffix = $includeOverlaps ? 'Listed below.' : 'Use --show-overlaps to include them.';
            $io->writeln(\sprintf(
                '  %s <fg=white>%d overlapping routes detected.</> <fg=gray>%s</>',
                self::BADGE_WARN,
                $overlaps,
                $suffix,
            ));
        }

        $io->newLine();
    }

    private function renderConflictsList(SymfonyStyle $io, RouteConflictReport $report): void
    {
        $io->section('Route Conflicts Detected');
        foreach ($report->conflicts as $conflict) {
            $route = $conflict['route'];
            $other = $conflict['conflict'];
            $typeLabel = $this->formatType($conflict);
            $scope = $this->formatScope($conflict['methods'], $conflict['schemes']);
            $example = $this->formatExample($conflict['example']);

            $header = \sprintf(
                '  %s <fg=white>%s</> <fg=gray>(#%d)</> <fg=gray>%s</> <fg=white>%s</> <fg=gray>(#%d)</>',
                $typeLabel,
                $route['name'],
                $route['index'],
                self::ARROW_LABEL,
                $other['name'],
                $other['index'],
            );
            $io->writeln($header);
            $io->writeln('      <fg=gray>'.self::ARROW_LABEL.' '.$route['path'].'</>');
            $io->writeln('      <fg=gray>'.self::ARROW_LABEL.' '.$other['path'].'</>');
            $io->writeln('      <fg=gray>'.self::ARROW_LABEL.' Scope:</> '.$scope);
            $io->writeln('      <fg=gray>'.self::ARROW_LABEL.' Example:</> '.$example);

            if ([] !== $conflict['notes']) {
                foreach ($conflict['notes'] as $note) {
                    $io->writeln('      <fg=gray>'.self::ARROW_LABEL.' Note:</> '.$note);
                }
            }

            $io->newLine();
        }
    }

    /**
     * @phpstan-param RouteConflict $conflict
     */
    private function formatType(array $conflict): string
    {
        $type = self::TYPE_SHADOWED === $conflict['type']
            ? self::BADGE_FAIL.' <fg=red>Shadowed</>'
            : self::BADGE_WARN.' <fg=yellow>Overlap</>';

        if ([] !== $conflict['notes']) {
            $type .= ' <fg=gray>(approx)</>';
        }

        return $type;
    }

    /**
     * @param array<int, string> $methods
     * @param array<int, string> $schemes
     */
    private function formatScope(array $methods, array $schemes): string
    {
        if ([] === $methods && [] === $schemes) {
            return 'any';
        }

        $parts = [];
        if ([] !== $methods) {
            $parts[] = implode('|', $methods);
        }
        if ([] !== $schemes) {
            $parts[] = implode('|', $schemes);
        }

        return implode(' • ', $parts);
    }

    private function formatExample(?string $example): string
    {
        if (null === $example) {
            return '-';
        }

        return DisplayEscaper::quote($example, '<fg=cyan>', '</>');
    }

    private function showFooter(SymfonyStyle $io): void
    {
        $message = 'If RegexParser helps, a GitHub star is appreciated: ';
        $io->writeln('  <fg=gray>'.$message.'https://github.com/php-regex/regex-parser</>');
        $io->newLine();
    }

    private function formatMetaLine(string $label, string $value, int $maxLabelLength): string
    {
        return \sprintf(
            '<fg=white;options=bold>%s</> : <fg=yellow>%s</>',
            str_pad($label, $maxLabelLength),
            $value,
        );
    }
}

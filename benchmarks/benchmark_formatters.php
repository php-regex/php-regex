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

use PhpRegex\Linter\AnalysisService;
use PhpRegex\Linter\Diagnostic;
use PhpRegex\Linter\DiagnosticType;
use PhpRegex\Linter\Formatter\ConsoleFormatter;
use PhpRegex\Linter\Formatter\GithubFormatter;
use PhpRegex\Linter\Formatter\LinkFormatter;
use PhpRegex\Linter\Formatter\OutputConfiguration;
use PhpRegex\Linter\Formatter\RelativePathHelper;
use PhpRegex\Linter\LintReport;
use PhpRegex\Linter\LintSeverity;
use PhpRegex\Optimizer\OptimizationResult;
use PhpRegex\Symfony\Output\SymfonyConsoleFormatter;
use PhpRegex\Toolkit\Regex;

/*
 * This file is part of the RegexParser package.
 *
 * (c) Younes ENNAJI <younes.ennaji.pro@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require_once __DIR__.'/../vendor/autoload.php';

echo "Benchmarking formatter output performance...\n\n";

$report = buildReport(1000);

$formatters = [
    'ConsoleFormatter (ansi off)' => new ConsoleFormatter(null, new OutputConfiguration(ansi: false)),
    'GithubFormatter' => new GithubFormatter(),
];

if (\class_exists('Symfony\\Component\\Console\\Formatter\\OutputFormatter')) {
    $formatters['SymfonyConsoleFormatter (decorated off)'] = new SymfonyConsoleFormatter(
        new AnalysisService(Regex::create()),
        new LinkFormatter(null, new RelativePathHelper()),
        false,
    );
} else {
    echo "SymfonyConsoleFormatter skipped (symfony/console not installed).\n\n";
}

foreach ($formatters as $label => $formatter) {
    if (\function_exists('memory_reset_peak_usage')) {
        memory_reset_peak_usage();
    }

    $start = microtime(true);
    $memoryBefore = memory_get_usage(true);

    $output = $formatter->format($report);

    $duration = microtime(true) - $start;
    $memoryAfter = memory_get_usage(true);
    $memoryPeak = memory_get_peak_usage(true);

    echo "=== {$label} ===\n";
    echo \sprintf("Output size: %d bytes\n", \strlen($output));
    echo \sprintf("Time: %.4f seconds\n", $duration);
    echo \sprintf("Memory delta: %.2f MB\n", ($memoryAfter - $memoryBefore) / 1024 / 1024);
    echo \sprintf("Peak memory: %.2f MB\n", $memoryPeak / 1024 / 1024);
    echo "\n";

    unset($output);
    gc_collect_cycles();
}

echo 'Total memory usage: '.(memory_get_peak_usage(true) / 1024 / 1024)." MB\n";

function buildReport(int $count): LintReport
{
    $results = [];
    $errors = 0;
    $warnings = 0;
    $optimizations = 0;

    $pattern = '/(foo|bar)+/';
    $optimization = new OptimizationResult($pattern, '/(?:foo|bar)+/', ['group']);
    $problem = new Diagnostic(
        DiagnosticType::Lint,
        LintSeverity::Warning,
        'Nested quantifier detected',
        'regex.lint.quantifier.nested',
        1,
        '^',
        'Consider making the quantifier possessive',
    );

    for ($i = 1; $i <= $count; $i++) {
        $issueType = 0 === $i % 2 ? 'warning' : 'error';
        if ('error' === $issueType) {
            $errors++;
        } else {
            $warnings++;
        }
        $optimizations++;

        $file = 'src/File'.$i.'.php';

        $results[] = [
            'file' => $file,
            'line' => $i,
            'source' => 'preg_match',
            'pattern' => $pattern,
            'location' => 'in function call',
            'issues' => [
                [
                    'type' => $issueType,
                    'message' => 'Issue '.$i,
                    'file' => $file,
                    'line' => $i,
                    'issueId' => 'regex.lint.demo',
                    'hint' => 'Use a non-capturing group',
                ],
            ],
            'optimizations' => [
                [
                    'file' => $file,
                    'line' => $i,
                    'optimization' => $optimization,
                    'savings' => 1,
                    'source' => 'preg_match',
                ],
            ],
            'problems' => [$problem],
        ];
    }

    return new LintReport(
        $results,
        [
            'errors' => $errors,
            'warnings' => $warnings,
            'optimizations' => $optimizations,
        ],
    );
}

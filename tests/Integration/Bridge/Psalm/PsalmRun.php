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

namespace PHPRegex\Tests\Integration\Bridge\Psalm;

use PHPRegex\Psalm\Plugin;
use Psalm\Config;
use Psalm\Internal\Analyzer\IssueData;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\Internal\IncludeCollector;
use Psalm\Internal\Provider\FileProvider;
use Psalm\Internal\Provider\Providers;
use Psalm\IssueBuffer;
use Psalm\Progress\VoidProgress;
use Psalm\Report\ReportOptions;

/**
 * Psalm run in this process on fixture files, as `psalm --no-cache
 * --threads=1` would run it, with the plugin registered or not. Each
 * configuration runs once per process: the issues are kept, keyed by the
 * configuration, so the tests that read one run share it whatever their
 * order.
 *
 * Psalm keeps its issues and its project in static state: a run clears the
 * issue buffer before and after, so no run sees another's issues.
 */
final class PsalmRun
{
    public const PLUGIN = Plugin::class;

    public const FIXTURES = __DIR__.'/Fixtures';

    /**
     * @var array<string, list<IssueData>>
     */
    private static array $runs = [];

    /**
     * @param list<string>               $files         paths relative to the fixture directory
     * @param array<string, string>|null $pluginOptions the child elements of the plugin's <pluginClass>, or null to run Psalm without the plugin
     * @param array<string, string>      $attributes    attributes of <psalm>, maxShapedArraySize for one
     *
     * @return list<IssueData>
     */
    public static function issues(array $files, string $phpVersion = '8.4', ?array $pluginOptions = [], array $attributes = [], bool $taint = false, string $baseDir = self::FIXTURES): array
    {
        $key = serialize([$files, $phpVersion, $pluginOptions, $attributes, $taint, $baseDir]);

        return self::$runs[$key] ??= self::run($files, $phpVersion, $pluginOptions, $attributes, $taint, $baseDir);
    }

    /**
     * The fixture files of a directory, relative to the fixture directory.
     *
     * @return list<string>
     */
    public static function filesIn(string $directory): array
    {
        $files = array_map(static fn (string $path): string => $directory.'/'.basename($path), glob(self::FIXTURES.'/'.$directory.'/*.php') ?: []);
        sort($files);

        return $files;
    }

    /**
     * Every type check of the fixture files: a docblock holding one
     * "@psalm-check-type-exact $var = type" and a "@psalm-trace $var" of the
     * same variable, so a run proves it reached the line. Psalm reports both
     * on the statement the docblock belongs to: its first line, or the line
     * that follows it.
     *
     * @param list<string> $files
     *
     * @return iterable<string, array{file: string, from: int, to: int, variable: string, expected: string}>
     */
    public static function typeChecks(array $files): iterable
    {
        foreach ($files as $file) {
            $code = (string) file_get_contents(self::FIXTURES.'/'.$file);
            preg_match_all('~/\*\*(?:(?!\*/).)*?@psalm-check-type-exact\s+(\$\w+)\s*=\s*([^\n]+?)\s*\n(?:(?!\*/).)*\*/~s', $code, $blocks, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($blocks as $block) {
                [$text, $offset] = $block[0];
                $variable = $block[1][0];
                if (1 !== preg_match('~@psalm-trace\s+'.preg_quote($variable, '~').'\b~', $text)) {
                    throw new \LogicException(\sprintf('%s: the check of %s at offset %d has no "@psalm-trace %s".', $file, $variable, $offset, $variable));
                }
                $from = substr_count($code, "\n", 0, $offset) + 1;
                $to = $from + substr_count($text, "\n");
                $function = preg_match_all('~function (\w+)~', substr($code, 0, $offset), $functions) > 0 ? end($functions[1]) : '?';

                yield \sprintf('%s:%d %s() %s', $file, $from, $function, $variable) => [
                    'file' => $file,
                    'from' => $from,
                    'to' => $to,
                    'variable' => $variable,
                    'expected' => $block[2][0],
                ];
            }
        }
    }

    /**
     * The issues of one file, ordered by line then column.
     *
     * @param list<IssueData> $issues
     *
     * @return list<IssueData>
     */
    public static function inFile(array $issues, string $file, string $baseDir = self::FIXTURES): array
    {
        $path = realpath($baseDir.'/'.$file);
        $found = array_values(array_filter($issues, static fn (IssueData $issue): bool => realpath($issue->file_path) === $path));
        usort($found, static fn (IssueData $a, IssueData $b): int => [$a->line_from, $a->column_from] <=> [$b->line_from, $b->column_from]);

        return $found;
    }

    /**
     * "Type line:column message", the way a failure prints an issue.
     */
    public static function describe(IssueData $issue): string
    {
        return \sprintf('%s %d:%d %s', $issue->type, $issue->line_from, $issue->column_from, $issue->message);
    }

    /**
     * Whether the PCRE2 running this process compiles the pattern: the
     * plugin narrows only a pattern it compiles.
     */
    public static function runningEngineCompiles(string $pattern): bool
    {
        return false !== @preg_match($pattern, '');
    }

    /**
     * @param list<string>               $files
     * @param array<string, string>|null $pluginOptions
     * @param array<string, string>      $attributes
     *
     * @return list<IssueData>
     */
    private static function run(array $files, string $phpVersion, ?array $pluginOptions, array $attributes, bool $taint, string $baseDir): array
    {
        // Psalm reads the global $argv as the paths of a CLI run: PHPUnit's
        // arguments would become files to analyse, or a path it cannot find
        // would make it exit.
        $argv = $GLOBALS['argv'] ?? null;
        $GLOBALS['argv'] = [];

        try {
            return self::analyse($files, $phpVersion, $pluginOptions, $attributes, $taint, $baseDir);
        } finally {
            $GLOBALS['argv'] = $argv;
        }
    }

    /**
     * @param list<string>               $files
     * @param array<string, string>|null $pluginOptions
     * @param array<string, string>      $attributes
     *
     * @return list<IssueData>
     */
    private static function analyse(array $files, string $phpVersion, ?array $pluginOptions, array $attributes, bool $taint, string $baseDir): array
    {
        $config = Config::loadFromXML($baseDir, self::xml($files, $pluginOptions, $attributes), $baseDir);
        $config->cache_directory = null;
        $config->setIncludeCollector(new IncludeCollector());

        $project = new ProjectAnalyzer($config, new Providers(new FileProvider()), new ReportOptions(), [], 1, 1, new VoidProgress());
        $project->setPhpVersion($phpVersion, 'tests');
        if ($taint) {
            $project->trackTaintedInputs();
        }

        IssueBuffer::clear();

        try {
            $project->checkPaths(array_map(static fn (string $file): string => $baseDir.'/'.$file, $files));
            $issues = [];
            foreach (IssueBuffer::getIssuesData() as $fileIssues) {
                array_push($issues, ...$fileIssues);
            }

            return $issues;
        } finally {
            IssueBuffer::clear();
        }
    }

    /**
     * @param list<string>               $files
     * @param array<string, string>|null $pluginOptions
     * @param array<string, string>      $attributes
     *
     * @return non-empty-string
     */
    private static function xml(array $files, ?array $pluginOptions, array $attributes): string
    {
        $attributes += [
            'errorLevel' => '1',
            'findUnusedCode' => 'false',
            'findUnusedBaselineEntry' => 'false',
        ];

        $xml = "<?xml version=\"1.0\"?>\n<psalm";
        foreach ($attributes as $name => $value) {
            $xml .= \sprintf(' %s="%s"', $name, htmlspecialchars($value, \ENT_XML1));
        }
        $xml .= ">\n    <projectFiles>\n";
        foreach ($files as $file) {
            $xml .= \sprintf("        <file name=\"%s\"/>\n", htmlspecialchars($file, \ENT_XML1));
        }
        $xml .= "    </projectFiles>\n";

        if (null !== $pluginOptions) {
            $xml .= \sprintf('    <plugins><pluginClass class="%s">', self::PLUGIN);
            foreach ($pluginOptions as $name => $value) {
                $xml .= \sprintf('<%1$s>%2$s</%1$s>', $name, htmlspecialchars($value, \ENT_XML1));
            }
            $xml .= "</pluginClass></plugins>\n";
        }

        return $xml.'</psalm>';
    }
}

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

namespace PHPRegex\Tests\Integration\Bridge\PHPStan;

use Composer\InstalledVersions;
use PHPRegex\Parser\Analysis\CaptureShapeAnalyzer;
use PHPRegex\Parser\RegexParser;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Testing\TypeInferenceTestCase;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * Puts the type PHPStan infers for $matches next to the shape PHPRegex
 * writes, for every pattern of the parity corpus and every flag set, and
 * writes the comparison to var/capture-shape-parity.md.
 *
 * PHPStan's answer is recorded, never asserted: a change in PHPStan moves the
 * report, not the result. Only PHPRegex's soundness is asserted.
 *
 * Run with: tools/phpunit/vendor/bin/phpunit --group capture-shape-parity
 *
 * @phpstan-type ParityCase array{source: string, pattern: string, subjects: non-empty-list<string>, flags: 0|256|512|768, flagNames: string}
 * @phpstan-type ParityRow array{source: string, pattern: string, subjects: non-empty-list<string>, flags: 0|256|512|768, flagNames: string, phpstan: string, phpstanReread: string, phpregex: string, phpstanRefusal: ?string, phpregexRefusal: ?string, relation: string}
 */
#[Group('capture-shape-parity')]
final class CaptureShapeParityReportTest extends TypeInferenceTestCase
{
    private const REPORT = __DIR__.'/../../../../var/capture-shape-parity.md';

    private const RELATIONS = ['equal', 'narrower', 'wider', 'incomparable'];

    #[Test]
    public function test_parity_report_compares_phpstan_and_phpregex_on_the_corpus(): void
    {
        $cases = [];
        foreach (EngineMatches::corpus() as $row) {
            foreach (EngineMatches::FLAG_SETS as $flags => $flagNames) {
                $cases[] = $row + ['flags' => $flags, 'flagNames' => $flagNames];
            }
        }

        $directory = sys_get_temp_dir().'/capture-shape-parity-'.bin2hex(random_bytes(4));
        mkdir($directory);
        $fixture = $directory.'/fixture.php';
        $lines = self::writeFixture($fixture, $cases);

        try {
            $inferred = [];
            foreach (self::gatherAssertTypes($fixture) as $assert) {
                if (\is_string($assert[3])) {
                    $inferred[$assert[4]] = $assert[3];
                }
            }
        } finally {
            unlink($fixture);
            rmdir($directory);
        }

        $resolver = self::getContainer()->getByType(TypeStringResolver::class);
        $rows = [];
        foreach ($cases as $index => $case) {
            $this->assertArrayHasKey($lines[$index], $inferred, 'No type gathered for '.$case['pattern']);
            $phpstanText = $inferred[$lines[$index]];
            $phpstan = $resolver->resolve($phpstanText);
            // A fixed target, as the digests are read with one: the shapes
            // this report compares must not change with the PCRE2 running
            // the tests.
            $phpregexText = (new CaptureShapeAnalyzer())->analyze(RegexParser::create(['php_version' => '8.4', 'pcre_version' => '10.44'])->parse($case['pattern']))->matchShape($case['flags']);
            $phpregex = $resolver->resolve($phpregexText);

            $rows[] = $case + [
                'phpstan' => $phpstanText,
                'phpstanReread' => $phpstan->describe(VerbosityLevel::precise()),
                'phpregex' => $phpregexText,
                'phpstanRefusal' => self::firstRefusal($phpstan, $case),
                'phpregexRefusal' => self::firstRefusal($phpregex, $case),
                'relation' => self::relation($phpstan, $phpregex),
            ];
        }

        $report = self::report($rows);
        if (!is_dir(\dirname(self::REPORT))) {
            mkdir(\dirname(self::REPORT), 0o777, true);
        }
        file_put_contents(self::REPORT, $report);

        $unsound = array_values(array_map(
            static fn (array $row): string => \sprintf('%s with %s: %s', $row['pattern'], $row['flagNames'], $row['phpregexRefusal']),
            array_filter($rows, static fn (array $row): bool => null !== $row['phpregexRefusal']),
        ));
        $this->assertSame([], $unsound);
    }

    /**
     * One function per case, its assertType() call on its own line.
     *
     * @param list<ParityCase> $cases
     *
     * @return list<int> the line of each case's assertType() call
     */
    private static function writeFixture(string $file, array $cases): array
    {
        $code = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            'namespace PHPRegex\Tests\CaptureShapeParityFixture;',
            '',
            'use function PHPStan\Testing\assertType;',
            '',
        ];

        $lines = [];
        foreach ($cases as $index => $case) {
            $flags = '0' === $case['flagNames'] ? '' : ', '.implode(' | ', array_map(static fn (string $name): string => '\\'.$name, explode(' | ', $case['flagNames'])));
            $code[] = \sprintf(
                'function case%d(string $s): void { if (1 === preg_match(%s, $s, $m%s)) { assertType(\'?\', $m); } }',
                $index,
                self::literal($case['pattern']),
                $flags,
            );
            $lines[] = \count($code);
        }

        file_put_contents($file, implode("\n", $code)."\n");

        return $lines;
    }

    /**
     * @param ParityCase $case
     */
    private static function firstRefusal(Type $type, array $case): ?string
    {
        foreach ($case['subjects'] as $subject) {
            $matches = [];
            preg_match($case['pattern'], $subject, $matches, $case['flags']);
            $refusals = EngineMatches::refusals($type, $matches);
            if ([] !== $refusals) {
                return \sprintf(
                    'on %s the engine writes %s: %s',
                    self::literal($subject),
                    json_encode($matches, \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE),
                    implode('; ', $refusals),
                );
            }
        }

        return null;
    }

    /**
     * PHPRegex's type against PHPStan's: narrower is a strict subtype.
     */
    private static function relation(Type $phpstan, Type $phpregex): string
    {
        $phpstanHoldsPhpregex = $phpstan->isSuperTypeOf($phpregex)->yes();
        $phpregexHoldsPhpstan = $phpregex->isSuperTypeOf($phpstan)->yes();

        return match (true) {
            $phpstanHoldsPhpregex && $phpregexHoldsPhpstan => 'equal',
            $phpstanHoldsPhpregex => 'narrower',
            $phpregexHoldsPhpstan => 'wider',
            default => 'incomparable',
        };
    }

    /**
     * @param list<ParityRow> $rows
     */
    private static function report(array $rows): string
    {
        $out = [];
        $out[] = '# Capture-shape parity: PHPStan and PHPRegex on preg_match()';
        $out[] = '';
        $out[] = \sprintf(
            'Generated %s with PHP %s, PCRE2 %s, PHPStan %s.',
            date('Y-m-d'),
            \PHP_VERSION,
            \PCRE_VERSION,
            self::phpstanVersion(),
        );
        $out[] = '';
        $out[] = 'One row per pattern of the parity corpus (tests/Fixtures/CaptureShapeParity/cases.php) and flag set.';
        $out[] = 'PHPStan is the type PHPStan infers for $matches after `1 === preg_match()`; PHPRegex is `matchShape()` read by PHPStan.';
        $out[] = 'A type is sound when it holds every $matches the engine writes for the row\'s subjects: each key written is one of its keys, each key it requires is written, each value is one its key accepts.';
        $out[] = 'The relation compares PHPRegex\'s type with PHPStan\'s through `isSuperTypeOf()` both ways: narrower means PHPRegex\'s type is a strict subtype of PHPStan\'s.';
        $out[] = '';

        $out[] = '## Summary';
        $out[] = '';
        $out[] = '| rows | PHPStan unsound | PHPRegex unsound | equal | PHPRegex narrower | PHPRegex wider | incomparable |';
        $out[] = '|---|---|---|---|---|---|---|';
        $out[] = self::summaryLine('all', $rows);
        foreach (array_unique(array_column($rows, 'flagNames')) as $flagNames) {
            $out[] = self::summaryLine($flagNames, array_filter($rows, static fn (array $row): bool => $row['flagNames'] === $flagNames));
        }
        foreach (['analyzer', 'php-src', 'parity'] as $source) {
            $out[] = self::summaryLine('source: '.$source, array_filter($rows, static fn (array $row): bool => str_starts_with($row['source'], $source)));
        }
        $out[] = '';

        $reread = array_filter($rows, static fn (array $row): bool => $row['phpstan'] !== $row['phpstanReread']);
        if ([] !== $reread) {
            $out[] = \sprintf('%d PHPStan types do not read back as written; the comparison uses the type read back.', \count($reread));
            $out[] = '';
        }

        foreach (['PHPStan unsound' => 'phpstanRefusal', 'PHPRegex unsound' => 'phpregexRefusal'] as $title => $field) {
            $unsound = array_filter($rows, static fn (array $row): bool => null !== $row[$field]);
            $out[] = '## '.$title.' ('.\count($unsound).')';
            $out[] = '';
            if ([] === $unsound) {
                $out[] = 'None.';
            } else {
                $out[] = '| pattern | flags | PHPStan | PHPRegex | why |';
                $out[] = '|---|---|---|---|---|';
                foreach ($unsound as $row) {
                    $out[] = \sprintf('| %s | %s | %s | %s | %s |', self::code(self::display($row['pattern'])), self::cell($row['flagNames']), self::code($row['phpstan']), self::code($row['phpregex']), self::cell((string) $row[$field]));
                }
            }
            $out[] = '';
        }

        $wider = array_filter($rows, static fn (array $row): bool => null === $row['phpstanRefusal'] && \in_array($row['relation'], ['wider', 'incomparable'], true));
        $out[] = '## PHPStan sound, PHPRegex wider or incomparable ('.\count($wider).')';
        $out[] = '';
        $out[] = '| pattern | flags | PHPStan | PHPRegex | relation |';
        $out[] = '|---|---|---|---|---|';
        foreach ($wider as $row) {
            $out[] = \sprintf('| %s | %s | %s | %s | %s |', self::code(self::display($row['pattern'])), self::cell($row['flagNames']), self::code($row['phpstan']), self::code($row['phpregex']), $row['relation']);
        }
        $out[] = '';

        $out[] = '## All rows';
        $out[] = '';
        $out[] = '| source | pattern | flags | PHPStan | PHPRegex | PHPStan sound | PHPRegex sound | relation |';
        $out[] = '|---|---|---|---|---|---|---|---|';
        foreach ($rows as $row) {
            $out[] = \sprintf(
                '| %s | %s | %s | %s | %s | %s | %s | %s |',
                self::cell($row['source']),
                self::code(self::display($row['pattern'])),
                self::cell($row['flagNames']),
                self::code($row['phpstan']),
                self::code($row['phpregex']),
                null === $row['phpstanRefusal'] ? 'yes' : 'no',
                null === $row['phpregexRefusal'] ? 'yes' : 'no',
                $row['relation'],
            );
        }

        return implode("\n", $out)."\n";
    }

    private static function phpstanVersion(): string
    {
        foreach (InstalledVersions::getAllRawData() as $installed) {
            $version = $installed['versions']['phpstan/phpstan']['pretty_version'] ?? null;
            if (\is_string($version) && '' !== $version) {
                return $version;
            }
        }

        return 'unknown';
    }

    /**
     * @param array<ParityRow> $rows
     */
    private static function summaryLine(string $label, array $rows): string
    {
        $relations = array_count_values(array_column($rows, 'relation'));
        $counts = array_map(static fn (string $relation): int => $relations[$relation] ?? 0, self::RELATIONS);

        return \sprintf(
            '| %s: %d | %d | %d | %s |',
            self::cell($label),
            \count($rows),
            \count(array_filter($rows, static fn (array $row): bool => null !== $row['phpstanRefusal'])),
            \count(array_filter($rows, static fn (array $row): bool => null !== $row['phpregexRefusal'])),
            implode(' | ', $counts),
        );
    }

    /**
     * A PHP string literal on one line.
     */
    private static function literal(string $text): string
    {
        if (1 !== preg_match('/[\x00-\x1F\x7F]/', $text) && mb_check_encoding($text, 'UTF-8')) {
            return "'".strtr($text, ['\\' => '\\\\', "'" => "\\'"])."'";
        }

        $utf8 = mb_check_encoding($text, 'UTF-8');
        $escaped = '';
        foreach (str_split($text) as $byte) {
            $ord = \ord($byte);
            $escaped .= match (true) {
                '\\' === $byte => '\\\\',
                '"' === $byte => '\\"',
                '$' === $byte => '\\$',
                "\n" === $byte => '\\n',
                "\t" === $byte => '\\t',
                "\r" === $byte => '\\r',
                $ord < 0x20, 0x7F === $ord, !$utf8 && $ord >= 0x80 => \sprintf('\\x%02X', $ord),
                default => $byte,
            };
        }

        return '"'.$escaped.'"';
    }

    private static function display(string $pattern): string
    {
        $literal = self::literal($pattern);

        return str_starts_with($literal, '"') ? $literal : $pattern;
    }

    private static function code(string $text): string
    {
        $fence = str_contains($text, '`') ? '`` ' : '`';

        return $fence.str_replace('|', '\\|', $text).strrev($fence);
    }

    private static function cell(string $text): string
    {
        return str_replace('|', '\\|', $text);
    }
}

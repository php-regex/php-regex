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

namespace PHPRegex\Tests\Unit\Lint;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Extraction\ExtractorInterface;
use PHPRegex\Linter\Extraction\PhpParserExtractionStrategy;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A double-quoted "\t" reaches the pattern as a raw tab, though the source
 * spells it: the lint command reads the PHP literal before it reports a raw
 * character, and keeps the report only where the literal holds it as
 * written. The code below is built with PHP's own escapes, so that this
 * file holds no raw tab.
 */
final class InvisibleCharacterSourceTest extends TestCase
{
    private const RULE = 'regex.lint.literal.invisible';

    private ?string $file = null;

    protected function tearDown(): void
    {
        if (null !== $this->file && is_file($this->file)) {
            unlink($this->file);
        }
    }

    /**
     * @param class-string<ExtractorInterface> $extractor
     */
    #[Test]
    #[DataProvider('provideSpellings')]
    public function test_a_raw_character_is_reported_only_where_the_source_holds_it_raw(string $extractor, string $literal, int $expected): void
    {
        $occurrences = $this->extract($extractor, "<?php\n\npreg_match(".$literal.", \$subject);\n");
        $this->assertCount(1, $occurrences);

        $this->assertCount($expected, $this->invisibleIssues($occurrences));
    }

    /**
     * @return iterable<string, array{extractor: class-string<ExtractorInterface>, literal: string, expected: int}>
     */
    public static function provideSpellings(): iterable
    {
        foreach (['php-parser' => PhpParserExtractionStrategy::class, 'tokens' => TokenBasedExtractionStrategy::class] as $name => $extractor) {
            yield $name.': a raw tab in single quotes' => ['extractor' => $extractor, 'literal' => "'/a\tb/'", 'expected' => 1];
            yield $name.': a tab escape in double quotes' => ['extractor' => $extractor, 'literal' => '"/a\tb/"', 'expected' => 0];
            yield $name.': a raw tab beside a tab escape' => ['extractor' => $extractor, 'literal' => "\"/a\tb\\x01/\"", 'expected' => 1];
            yield $name.': a zero-width space escape in double quotes' => ['extractor' => $extractor, 'literal' => '"/a\u{200B}b/u"', 'expected' => 0];
            yield $name.': a raw zero-width space' => ['extractor' => $extractor, 'literal' => "'/a\u{200B}b/u'", 'expected' => 1];
            yield $name.': a raw tab after a backslash' => ['extractor' => $extractor, 'literal' => "'/a\\\tb/'", 'expected' => 1];
        }
    }

    /**
     * Without the PHP source, as for a route requirement, the spelling is
     * unknown: nothing is reported.
     */
    #[Test]
    public function test_a_pattern_without_its_source_is_not_reported(): void
    {
        $this->assertSame([], $this->invisibleIssues([new PatternOccurrence("/a\tb/", 'routes.yaml', 3, 'route:home:id')]));
    }

    /**
     * @param class-string<ExtractorInterface> $extractor
     *
     * @return list<PatternOccurrence>
     */
    private function extract(string $extractor, string $code): array
    {
        $this->file = (string) tempnam(sys_get_temp_dir(), 'regex-invisible-');
        file_put_contents($this->file, $code);

        return array_values((new $extractor())->extract([$this->file]));
    }

    /**
     * @param list<PatternOccurrence> $occurrences
     *
     * @return list<array<string, mixed>>
     */
    private function invisibleIssues(array $occurrences): array
    {
        $service = new AnalysisService(RegexParser::create(['cache' => null]), null, 50, 'critical', [], [], 'theoretical', null, false);

        return array_values(array_filter(
            $service->lint($occurrences),
            static fn (array $issue): bool => self::RULE === ($issue['issueId'] ?? null),
        ));
    }
}

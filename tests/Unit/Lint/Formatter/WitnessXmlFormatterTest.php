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

namespace PHPRegex\Tests\Unit\Lint\Formatter;

use PHPRegex\Linter\AnalysisService;
use PHPRegex\Linter\Formatter\CheckstyleFormatter;
use PHPRegex\Linter\Formatter\JsonFormatter;
use PHPRegex\Linter\Formatter\JunitFormatter;
use PHPRegex\Linter\Formatter\OutputFormatterInterface;
use PHPRegex\Linter\LintReport;
use PHPRegex\Linter\LintRequest;
use PHPRegex\Linter\LintService;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Parser\RegexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A witness made of control or high bytes reaches the machine-readable
 * reports in its escaped form: the XML stays well-formed, the JSON encodes,
 * and no raw control byte leaks.
 *
 * Each pattern fails preg_match() at 19 pumps of its byte followed by "!"
 * on PCRE2 10.49.
 */
final class WitnessXmlFormatterTest extends TestCase
{
    #[Test]
    #[DataProvider('provideXmlCases')]
    public function test_witness_keeps_xml_report_well_formed(OutputFormatterInterface $formatter, string $pattern, string $escapedPump): void
    {
        $xml = $formatter->format(self::report($pattern));
        if ('' === $xml) {
            $this->fail('Empty report for '.$pattern);
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml);
            $errors = libxml_get_errors();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        $this->assertTrue($loaded, $xml);
        $this->assertSame([], $errors);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $xml);
        $this->assertStringContainsString('Attack: ', $xml);
        $this->assertStringContainsString($escapedPump, $xml);
    }

    /**
     * @return iterable<string, array{formatter: OutputFormatterInterface, pattern: string, escapedPump: string}>
     */
    public static function provideXmlCases(): iterable
    {
        foreach (['checkstyle' => new CheckstyleFormatter(), 'junit' => new JunitFormatter()] as $name => $formatter) {
            yield $name.', escape control character' => ['formatter' => $formatter, 'pattern' => '/(\x1B+)+$/', 'escapedPump' => '\x1B'];
            yield $name.', nul byte' => ['formatter' => $formatter, 'pattern' => '/(\x00+)+$/', 'escapedPump' => '\x00'];
            yield $name.', high byte' => ['formatter' => $formatter, 'pattern' => '/(\xFF+)+$/', 'escapedPump' => '\xFF'];
        }
    }

    #[Test]
    public function test_witness_keeps_json_report_encodable(): void
    {
        $json = (new JsonFormatter())->format(self::report('/(\xFF+)+$/'));

        $payload = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $pumps = [];
        foreach ((array) ($payload['results'] ?? []) as $result) {
            foreach ((array) (\is_array($result) ? ($result['issues'] ?? []) : []) as $issue) {
                if (\is_array($issue) && 'regex.lint.redos' === ($issue['issueId'] ?? null)) {
                    $analysis = $issue['analysis'] ?? null;
                    $witness = \is_array($analysis) ? ($analysis['witness'] ?? null) : null;
                    $pumps[] = \is_array($witness) ? ($witness['pump'] ?? null) : null;
                }
            }
        }

        $this->assertCount(1, $pumps, $json);
        $this->assertIsString($pumps[0], $json);
        $this->assertMatchesRegularExpression('/^(?:\\\\xFF)+$/', $pumps[0]);
    }

    private static function report(string $pattern): LintReport
    {
        $analysis = new AnalysisService(RegexParser::create(), redosThreshold: 'low', redosEnabled: true);
        $lint = new LintService($analysis, new PatternSourceCollection([]));

        return $lint->analyze(
            [new PatternOccurrence($pattern, 'file.php', 1, 'php:preg_match()')],
            new LintRequest(['.'], [], 0, checkRedos: true, checkOptimizations: false),
        );
    }
}

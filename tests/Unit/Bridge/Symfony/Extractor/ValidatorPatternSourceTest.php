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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Extractor;

use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Symfony\Extractor\ValidatorPatternSource;
use PHPRegex\Tests\Fixtures\Symfony\ValidatorMapping\Entity\Account;
use PHPRegex\Tests\Fixtures\Symfony\ValidatorMapping\Entity\Customer;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The source reads each mapping file with the validator's own loader and
 * places each pattern on the line that writes it.
 */
final class ValidatorPatternSourceTest extends TestCase
{
    use TemporaryProject;

    private const CUSTOMER = Customer::class;

    private const MAPPING_XML = "<?xml version=\"1.0\" encoding=\"UTF-8\" ?>\n"
        ."<constraint-mapping xmlns=\"http://symfony.com/schema/dic/constraint-mapping\">\n";

    #[Test]
    public function test_the_source_is_named_validators(): void
    {
        $this->assertSame('validators', (new ValidatorPatternSource())->getName());
    }

    #[Test]
    public function test_the_source_reads_nothing_without_mapping_files(): void
    {
        $source = new ValidatorPatternSource();

        $this->assertFalse($source->isSupported());
        $this->assertSame([], $source->extract(new PatternSourceContext([], [])));
    }

    /**
     * @param list<array{int, string, string}> $expected each pattern's line, member and pattern
     */
    #[Test]
    #[DataProvider('provideYamlMappings')]
    public function test_a_yaml_pattern_is_placed_on_the_line_that_writes_it(string $mapping, array $expected): void
    {
        $this->assertSame($expected, $this->read('customer.yaml', self::CUSTOMER.":\n".$mapping));
    }

    /**
     * @return iterable<string, array{mapping: string, expected: list<array{int, string, string}>}>
     */
    public static function provideYamlMappings(): iterable
    {
        yield 'plain' => [
            'mapping' => "    properties:\n        name:\n            - Regex: /[a-z/\n",
            'expected' => [[4, '::$name', '/[a-z/']],
        ];

        yield 'single-quoted, a quote doubled' => [
            'mapping' => "    properties:\n        name:\n            - Regex: { pattern: '/it''s(/' }\n",
            'expected' => [[4, '::$name', "/it's(/"]],
        ];

        yield 'double-quoted, a backslash escaped' => [
            'mapping' => "    properties:\n        name:\n            - Regex:\n                pattern: \"/\\\\d+(/\"\n",
            'expected' => [[5, '::$name', '/\d+(/']],
        ];

        yield 'one pattern written twice' => [
            'mapping' => "    properties:\n        name:\n            - Regex: '/(a/'\n        nickname:\n            - Regex: '/(a/'\n",
            'expected' => [[4, '::$name', '/(a/'], [6, '::$nickname', '/(a/']],
        ];

        yield 'a pattern that is the start of a longer one' => [
            'mapping' => "    properties:\n        name:\n            - Regex: '/(a/i'\n        nickname:\n            - Regex: '/(a/'\n",
            'expected' => [[4, '::$name', '/(a/i'], [6, '::$nickname', '/(a/']],
        ];

        yield 'written over several lines, on the line of its class' => [
            'mapping' => "    properties:\n        name:\n            - Regex:\n                pattern: >-\n                    /[a-z\n                    (/\n",
            'expected' => [[1, '::$name', '/[a-z (/']],
        ];

        yield 'nested in a composite constraint' => [
            'mapping' => "    properties:\n        tags:\n            - All:\n                - Regex: '/#(/'\n            - Collection:\n                fields:\n                    first:\n                        - Regex: '/1(/'\n",
            'expected' => [[5, '::$tags', '/#(/'], [9, '::$tags', '/1(/']],
        ];

        yield 'on a getter' => [
            'mapping' => "    getters:\n        email:\n            - Regex: '/[@/'\n",
            'expected' => [[4, '::getEmail()', '/[@/']],
        ];
    }

    /**
     * @param list<array{int, string, string}> $expected each pattern's line, member and pattern
     */
    #[Test]
    #[DataProvider('provideXmlMappings')]
    public function test_an_xml_pattern_is_placed_on_the_line_that_writes_it(string $mapping, array $expected): void
    {
        $this->assertSame($expected, $this->read('account.xml', self::MAPPING_XML.'    <class name="'.Account::class."\">\n".$mapping."    </class>\n</constraint-mapping>\n"));
    }

    /**
     * @return iterable<string, array{mapping: string, expected: list<array{int, string, string}>}>
     */
    public static function provideXmlMappings(): iterable
    {
        yield 'an option' => [
            'mapping' => "        <property name=\"code\">\n            <constraint name=\"Regex\">\n                <option name=\"pattern\">/(abc/</option>\n            </constraint>\n        </property>\n",
            'expected' => [[6, '::$code', '/(abc/']],
        ];

        yield 'the text of the constraint, an entity in it' => [
            'mapping' => "        <property name=\"iban\">\n            <constraint name=\"Regex\">/[A-Z&amp;&lt;/</constraint>\n        </property>\n",
            'expected' => [[5, '::$iban', '/[A-Z&</']],
        ];

        yield 'a CDATA section' => [
            'mapping' => "        <property name=\"iban\">\n            <constraint name=\"Regex\"><![CDATA[/[<&/]]></constraint>\n        </property>\n",
            'expected' => [[5, '::$iban', '/[<&/']],
        ];

        yield 'written over several lines, on the line of its class' => [
            'mapping' => "        <property name=\"code\">\n            <constraint name=\"Regex\">\n                <option name=\"pattern\"><![CDATA[/(a\nb/]]></option>\n            </constraint>\n        </property>\n",
            'expected' => [[3, '::$code', "/(a\nb/"]],
        ];
    }

    /**
     * A class whose line cannot be found either, its name written in a
     * double-quoted key, leaves the pattern on the first line of the file.
     */
    #[Test]
    public function test_a_pattern_no_line_spells_under_a_class_no_line_names_is_placed_on_the_first_line(): void
    {
        $key = '"'.str_replace('\\', '\\\\', self::CUSTOMER).'"';

        $this->assertSame([[1, '::$name', '/[a-z (/']], $this->read('customer.yaml', "# The customers.\n".$key.":\n    properties:\n        name:\n            - Regex:\n                pattern: >-\n                    /[a-z\n                    (/\n"));
    }

    #[Test]
    public function test_a_file_the_validator_cannot_read_is_reported_as_unread(): void
    {
        $project = $this->makeProject([
            'broken.yaml' => self::CUSTOMER.":\n    properties: [\n",
            'unknown.yaml' => self::CUSTOMER.":\n    properties:\n        nope:\n            - Regex: '/a/'\n",
        ]);

        $occurrences = (new ValidatorPatternSource([$project.'/broken.yaml', $project.'/unknown.yaml']))->extract(new PatternSourceContext([], []));

        $this->assertCount(2, $occurrences);
        $this->assertSame([$project.'/broken.yaml', $project.'/unknown.yaml'], [$occurrences[0]->file, $occurrences[1]->file]);
        $this->assertStringContainsString('does not contain valid YAML', (string) $occurrences[0]->unread);
        $this->assertStringContainsString('"nope"', (string) $occurrences[1]->unread);
        $this->assertSame('', $occurrences[1]->pattern);
    }

    #[Test]
    public function test_a_file_under_an_excluded_directory_is_left_out(): void
    {
        $mapping = self::CUSTOMER.":\n    properties:\n        name:\n            - Regex: '/(a/'\n";
        $project = $this->makeProject(['config/validator/customer.yaml' => $mapping, 'vendor/acme/bundle/config/validation.yaml' => $mapping]);
        $source = new ValidatorPatternSource([$project.'/config/validator/customer.yaml', $project.'/vendor/acme/bundle/config/validation.yaml']);

        $files = static fn (PatternSourceContext $context): array => array_map(static fn (PatternOccurrence $occurrence): string => $occurrence->file, $source->extract($context));

        $this->assertSame([$project.'/config/validator/customer.yaml'], $files(new PatternSourceContext(['src'], ['vendor'], excludeBase: $project)));
        $this->assertSame([$project.'/config/validator/customer.yaml'], $files(new PatternSourceContext(['src'], ['', '/', 'vendor'])));
        // An exclude names a directory of the project, not one above it.
        $this->assertCount(2, $files(new PatternSourceContext(['src'], [basename(\dirname($project))], excludeBase: $project)));
    }

    #[Test]
    public function test_the_source_of_a_pattern_names_its_class_and_member(): void
    {
        $occurrences = $this->occurrences('customer.yaml', self::CUSTOMER.":\n    properties:\n        name:\n            - Regex: '/(a/'\n");

        $this->assertSame('validator:'.Customer::class.'::$name', $occurrences[0]->source);
        $this->assertNull($occurrences[0]->unread);
    }

    /**
     * @return array<array{int, string, string}> each pattern's line, member and pattern
     */
    private function read(string $name, string $contents): array
    {
        return array_map(
            static fn (PatternOccurrence $occurrence): array => [$occurrence->line, (string) strstr($occurrence->source, '::'), $occurrence->pattern],
            $this->occurrences($name, $contents),
        );
    }

    /**
     * @return array<PatternOccurrence>
     */
    private function occurrences(string $name, string $contents): array
    {
        $project = $this->makeProject([$name => $contents]);
        $occurrences = (new ValidatorPatternSource([$project.'/'.$name]))->extract(new PatternSourceContext([], []));

        foreach ($occurrences as $occurrence) {
            $this->assertNull($occurrence->unread, (string) $occurrence->unread);
            $this->assertSame($project.'/'.$name, $occurrence->file);
        }

        return $occurrences;
    }
}

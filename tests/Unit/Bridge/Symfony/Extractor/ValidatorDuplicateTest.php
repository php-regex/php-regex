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

use PHPRegex\Linter\Extraction\PatternFunctionRegistry;
use PHPRegex\Linter\Extraction\TokenBasedExtractionStrategy;
use PHPRegex\Linter\PatternExtractor;
use PHPRegex\Linter\PatternOccurrence;
use PHPRegex\Linter\Source\PatternSourceCollection;
use PHPRegex\Linter\Source\PatternSourceContext;
use PHPRegex\Linter\Source\PhpFilePatternSource;
use PHPRegex\Symfony\Extractor\ValidatorPatternSource;
use PHPRegex\Tests\Fixtures\Symfony\ValidatorDuplicate\RegexConstrainedEntity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Mapping\Loader\LoaderInterface;
use Symfony\Component\Validator\Mapping\PropertyMetadataInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * A #[Regex] attribute is read twice in a Symfony application: from the
 * file, by the symfony-validator preset, and from the validator's metadata.
 * The collection keeps the file's occurrence, at its literal, and drops the
 * validator's one for the same pattern of the same file; the validator's
 * line is a running counter, so the file and the pattern are compared, not
 * the line. A constraint mapped in YAML or XML is the validator's alone and
 * stays.
 */
final class ValidatorDuplicateTest extends TestCase
{
    private const FIXTURE_DIRECTORY = __DIR__.'/../../../../Fixtures/Symfony/ValidatorDuplicate';

    #[Test]
    public function test_a_constraint_read_from_the_file_and_from_the_validator_is_reported_once(): void
    {
        require_once self::FIXTURE_DIRECTORY.'/RegexConstrainedEntity.php';

        $property = $this->createStub(PropertyMetadataInterface::class);
        $property->method('getPropertyName')->willReturn('name');
        $property->method('getConstraints')->willReturn([new Regex('/attribute-and-validator/')]);

        $metadata = $this->createStub(ClassMetadataInterface::class);
        $metadata->method('getConstraints')->willReturn([new Regex('/mapped-in-yaml/')]);
        $metadata->method('getConstrainedProperties')->willReturn(['name']);
        $metadata->method('getPropertyMetadata')->willReturn([$property]);

        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('getMetadataFor')->willReturn($metadata);

        $loader = new class implements LoaderInterface {
            public function loadClassMetadata(ClassMetadata $metadata): bool
            {
                return true;
            }

            /**
             * @return list<class-string>
             */
            public function getMappedClasses(): array
            {
                return [RegexConstrainedEntity::class];
            }
        };

        $collection = new PatternSourceCollection([
            new PhpFilePatternSource(new PatternExtractor(new TokenBasedExtractionStrategy([], PatternFunctionRegistry::create(['symfony-validator'])))),
            new ValidatorPatternSource($validator, $loader),
        ]);

        $read = array_map(
            static fn (PatternOccurrence $occurrence): array => [$occurrence->pattern, str_starts_with($occurrence->source, 'validator:') ? 'validator' : 'file'],
            $collection->collect(new PatternSourceContext([(string) realpath(self::FIXTURE_DIRECTORY)], [])),
        );
        sort($read);

        $this->assertSame([
            ['/attribute-and-validator/', 'file'],
            ['/mapped-in-yaml/', 'validator'],
        ], $read);
    }
}

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

namespace PhpRegex\Tests\Unit\Bridge\Symfony\Extractor;

use PhpRegex\Linter\Source\PatternSourceContext;
use PhpRegex\Symfony\Extractor\ValidatorPatternSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Component\Validator\Mapping\ClassMetadata;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Mapping\Loader\LoaderInterface;
use Symfony\Component\Validator\Mapping\PropertyMetadataInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ValidatorRegexPatternSourceTest extends TestCase
{
    protected function setUp(): void
    {
        if (!interface_exists(ValidatorInterface::class)) {
            $this->markTestSkipped('Symfony Validator component is not available');
        }
    }

    public function test_construct(): void
    {
        $source = new ValidatorPatternSource();
        $this->assertSame('validators', $source->getName());
    }

    public function test_construct_with_validator(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $loader = $this->createLoaderStub();

        $source = new ValidatorPatternSource($validator, $loader);
        $this->assertTrue($source->isSupported());
    }

    public function test_get_name(): void
    {
        $source = new ValidatorPatternSource();
        $this->assertSame('validators', $source->getName());
    }

    public function test_is_supported_returns_false_when_no_validator(): void
    {
        $source = new ValidatorPatternSource();
        $this->assertFalse($source->isSupported());
    }

    public function test_is_supported_returns_false_when_no_loader(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $source = new ValidatorPatternSource($validator);
        $this->assertFalse($source->isSupported());
    }

    public function test_is_supported_returns_true_when_both_present(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $loader = $this->createLoaderStub();

        $source = new ValidatorPatternSource($validator, $loader);
        $this->assertTrue($source->isSupported());
    }

    public function test_extract_returns_empty_when_not_supported(): void
    {
        $source = new ValidatorPatternSource();
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertSame([], $result);
    }

    public function test_extract_with_empty_mapped_classes(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $loader = $this->createLoaderStub([]);

        $source = new ValidatorPatternSource($validator, $loader);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertSame([], $result);
    }

    public function test_extract_skips_non_string_mapped_classes(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->never())->method('getMetadataFor');
        $loader = $this->createLoaderStub([null, 123, '']);

        $source = new ValidatorPatternSource($validator, $loader);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertSame([], $result);
    }

    public function test_extract_with_regex_constraint(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $loader = $this->createLoaderStub([TestEntity::class]);

        // Mock metadata for a class
        $metadata = $this->createStub(ClassMetadataInterface::class);
        $metadata->method('getConstraints')->willReturn([]);
        $metadata->method('getConstrainedProperties')->willReturn(['email']);

        $propertyMetadata = $this->createStub(PropertyMetadataInterface::class);
        $propertyMetadata->method('getPropertyName')->willReturn('email');
        $propertyMetadata->method('getConstraints')->willReturn([
            new Regex(pattern: '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/'),
        ]);

        $metadata->method('getPropertyMetadata')->willReturn([$propertyMetadata]);
        $validator->method('getMetadataFor')->willReturn($metadata);
        $source = new ValidatorPatternSource($validator, $loader);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertCount(1, $result);
        $this->assertSame('/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/', $result[0]->pattern);
        $this->assertSame(__FILE__, $result[0]->file);
        $this->assertSame('validator:'.TestEntity::class.'::$email', $result[0]->source);
    }

    public function test_extract_with_class_level_regex_constraint(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $loader = $this->createLoaderStub([TestEntity::class]);

        $regexConstraint = new Regex(pattern: '/^valid$/');

        $metadata = $this->createStub(ClassMetadataInterface::class);
        $metadata->method('getConstraints')->willReturn([$regexConstraint]);
        $metadata->method('getConstrainedProperties')->willReturn([]);

        $validator->method('getMetadataFor')->willReturn($metadata);
        $source = new ValidatorPatternSource($validator, $loader);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertCount(1, $result);
        $this->assertSame('/^valid$/', $result[0]->pattern);
        $this->assertSame('validator:'.TestEntity::class, $result[0]->source);
    }

    public function test_extract_ignores_null_patterns(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $loader = $this->createLoaderStub([TestEntity::class]);

        $regexConstraint = new Regex(pattern: '/placeholder/');
        $regexConstraint->pattern = null;

        $metadata = $this->createStub(ClassMetadataInterface::class);
        $metadata->method('getConstraints')->willReturn([$regexConstraint]);
        $metadata->method('getConstrainedProperties')->willReturn([]);

        $validator->method('getMetadataFor')->willReturn($metadata);
        $source = new ValidatorPatternSource($validator, $loader);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertSame([], $result);
    }

    public function test_extract_ignores_empty_patterns(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $loader = $this->createLoaderStub([TestEntity::class]);

        $regexConstraint = new Regex(pattern: '');

        $metadata = $this->createStub(ClassMetadataInterface::class);
        $metadata->method('getConstraints')->willReturn([$regexConstraint]);
        $metadata->method('getConstrainedProperties')->willReturn([]);

        $validator->method('getMetadataFor')->willReturn($metadata);
        $source = new ValidatorPatternSource($validator, $loader);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertSame([], $result);
    }

    public function test_extract_ignores_non_regex_constraints(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $loader = $this->createLoaderStub([TestEntity::class]);

        $notConstraint = new NotBlank();

        $metadata = $this->createStub(ClassMetadataInterface::class);
        $metadata->method('getConstraints')->willReturn([$notConstraint]);
        $metadata->method('getConstrainedProperties')->willReturn([]);

        $validator->method('getMetadataFor')->willReturn($metadata);
        $source = new ValidatorPatternSource($validator, $loader);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertSame([], $result);
    }

    public function test_extract_handles_metadata_exceptions(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $loader = $this->createLoaderStub([TestEntity::class]);

        $validator->method('getMetadataFor')->willThrowException(new \Exception('Metadata not found'));
        $source = new ValidatorPatternSource($validator, $loader);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertSame([], $result);
    }

    public function test_extract_handles_invalid_mapped_classes(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $loader = $this->createLoaderStub([
            TestEntity::class, // Valid
            '', // Empty string
            null, // Null
            123, // Not a string
        ]);

        $source = new ValidatorPatternSource($validator, $loader);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        // Should not crash and return empty since metadata retrieval will fail
        $this->assertSame([], $result);
    }

    public function test_extract_with_class_file(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $loader = $this->createLoaderStub([TestEntity::class]);

        $metadata = $this->createStub(ClassMetadataInterface::class);
        $metadata->method('getConstraints')->willReturn([
            new Regex(pattern: '/test/'),
        ]);
        $metadata->method('getConstrainedProperties')->willReturn([]);

        $validator->method('getMetadataFor')->willReturn($metadata);
        $source = new ValidatorPatternSource($validator, $loader);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertCount(1, $result);
        $this->assertStringEndsWith('ValidatorRegexPatternSourceTest.php', $result[0]->file);
    }

    public function test_extract_handles_loader_exceptions(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $loader = $this->createLoaderStub([], new \Exception('Loader error'));

        $source = new ValidatorPatternSource($validator, $loader);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertSame([], $result);
    }

    public function test_extract_handles_loader_without_method(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $loader = $this->createLoaderWithoutMappedClasses();

        $source = new ValidatorPatternSource($validator, $loader);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertSame([], $result);
    }

    public function test_extract_skips_when_symfony_not_available(): void
    {
        if (interface_exists(ValidatorInterface::class)) {
            $this->markTestSkipped('Symfony Validator is available, skipping test');
        }

        // This test will only run when Symfony is not available
        $source = new ValidatorPatternSource();
        $this->assertFalse($source->isSupported());
        $this->assertSame('validators', $source->getName());
    }

    public function test_get_mapped_classes_returns_empty_when_loader_missing(): void
    {
        $source = new ValidatorPatternSource(null, null);

        $reflection = new \ReflectionClass($source);
        $method = $reflection->getMethod('getMappedClasses');

        $this->assertSame([], $method->invoke($source));
    }

    public function test_get_mapped_classes_returns_empty_for_invalid_loader_response(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $loader = new class implements LoaderInterface {
            public function loadClassMetadata(ClassMetadata $metadata): bool
            {
                return true;
            }

            public function getMappedClasses(): string
            {
                return 'invalid';
            }
        };

        $source = new ValidatorPatternSource($validator, $loader);
        $reflection = new \ReflectionClass($source);
        $method = $reflection->getMethod('getMappedClasses');

        $this->assertSame([], $method->invoke($source));
    }

    public function test_extract_skips_non_property_metadata_entries(): void
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $loader = $this->createLoaderStub([TestEntity::class]);

        $metadata = $this->createStub(ClassMetadataInterface::class);
        $metadata->method('getConstraints')->willReturn([]);
        $metadata->method('getConstrainedProperties')->willReturn(['email']);
        $metadata->method('getPropertyMetadata')->willReturn([new \stdClass()]);

        $validator->method('getMetadataFor')->willReturn($metadata);

        $source = new ValidatorPatternSource($validator, $loader);
        $context = new PatternSourceContext(['.'], []);

        $result = $source->extract($context);

        $this->assertSame([], $result);
    }

    public function test_get_class_file_returns_null_for_unknown_class(): void
    {
        $source = new ValidatorPatternSource();

        $reflection = new \ReflectionClass($source);
        $method = $reflection->getMethod('getClassFile');

        $this->assertNull($method->invoke($source, 'UnknownClassName'));
    }

    /**
     * @param array<int, string|int|null> $mappedClasses
     */
    private function createLoaderStub(array $mappedClasses = [], ?\Throwable $exception = null): LoaderInterface
    {
        return new class($mappedClasses, $exception) implements LoaderInterface {
            /**
             * @param array<int, string|int|null> $mappedClasses
             */
            public function __construct(private readonly array $mappedClasses, private readonly ?\Throwable $exception) {}

            public function loadClassMetadata(ClassMetadata $metadata): bool
            {
                return true;
            }

            /**
             * @return array<int, string|int|null>
             */
            public function getMappedClasses(): array
            {
                if (null !== $this->exception) {
                    throw $this->exception;
                }

                return $this->mappedClasses;
            }
        };
    }

    private function createLoaderWithoutMappedClasses(): LoaderInterface
    {
        return new class implements LoaderInterface {
            public function loadClassMetadata(ClassMetadata $metadata): bool
            {
                return true;
            }
        };
    }
}

/**
 * Test entity class for testing validator extraction.
 */
class TestEntity
{
    public string $email;
}

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

namespace PHPRegex\Tests\Unit\Bridge\Symfony\Validator;

use PHPRegex\Symfony\Validator\ValidatedClassLocator;
use PHPRegex\Tests\Fixtures\Symfony\ValidatorAudit\src\Entity\Signup;
use PHPRegex\Tests\Fixtures\Symfony\ValidatorMapping\Entity\Customer;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Mapping\ClassMetadataInterface;
use Symfony\Component\Validator\Validation;

/**
 * The classes regex:validator audits: those the mapping files name, those
 * a PHP file declares when it writes the Regex constraint, each read
 * through the validator's own metadata factory.
 */
final class ValidatedClassLocatorTest extends TestCase
{
    use TemporaryProject;

    private const MAPPING = __DIR__.'/../../../../Fixtures/Symfony/ValidatorMapping/config/validator/customer.yaml';

    private const SOURCES = __DIR__.'/../../../../Fixtures/Symfony/ValidatorAudit/src';

    #[Test]
    public function test_the_mapped_and_the_declared_classes_are_read(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->addYamlMapping(self::MAPPING)->getValidator();

        [$metadata, $unread] = (new ValidatedClassLocator($validator, [self::MAPPING]))->locate([], [self::SOURCES]);

        // Plain.php declares a class but writes no constraint.
        $this->assertSame([Customer::class, Signup::class], array_map(static fn (ClassMetadataInterface $class): string => $class->getClassName(), $metadata));
        $this->assertSame([], $unread);
    }

    #[Test]
    public function test_the_given_classes_are_read_alone(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        [$metadata, $unread] = (new ValidatedClassLocator($validator, [self::MAPPING]))->locate([Signup::class, 'App\Missing'], [self::SOURCES]);

        $this->assertCount(1, $metadata);
        $this->assertSame(['App\Missing: the class does not exist.'], $unread);
    }

    #[Test]
    public function test_without_a_validator_nothing_is_read(): void
    {
        $locator = new ValidatedClassLocator(null, [self::MAPPING]);

        $this->assertFalse($locator->isSupported());
        $this->assertSame([[], []], $locator->locate([], [self::SOURCES]));
    }

    /**
     * A mapping file the loader cannot read is no class to skip in
     * silence: the locator says which file, and why.
     */
    #[Test]
    public function test_an_unreadable_mapping_file_is_reported(): void
    {
        $project = $this->makeProject(['bad.yaml' => "App\\Foo: [\n", 'bad.xml' => '<constraint-mapping><class name="App\\Foo">']);
        $validator = Validation::createValidatorBuilder()->getValidator();

        [$metadata, $unread] = (new ValidatedClassLocator($validator, [$project.'/bad.yaml', self::MAPPING, $project.'/bad.xml']))->locate();

        $this->assertSame([Customer::class], array_map(static fn (ClassMetadataInterface $class): string => $class->getClassName(), $metadata));
        // The loaders' own words vary with the YAML and libxml releases.
        $this->assertCount(2, $unread);
        $this->assertStringStartsWith($project.'/bad.yaml: The file "'.$project.'/bad.yaml" does not contain valid YAML: ', $unread[0]);
        $this->assertStringStartsWith($project.'/bad.xml: ', $unread[1]);
        $this->assertGreaterThan(\strlen($project.'/bad.xml: '), \strlen($unread[1]));
    }

    #[Test]
    public function test_a_missing_file_or_directory_is_skipped(): void
    {
        $validator = Validation::createValidatorBuilder()->getValidator();

        $this->assertSame([[], []], (new ValidatedClassLocator($validator, ['/nowhere/validator.yaml']))->locate([], ['/nowhere/src']));
    }
}

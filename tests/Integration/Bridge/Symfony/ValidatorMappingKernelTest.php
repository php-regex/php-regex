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

namespace PHPRegex\Tests\Integration\Bridge\Symfony;

use PHPRegex\Tests\Fixtures\Symfony\ValidatorMapping\Entity\Account;
use PHPRegex\Tests\Fixtures\Symfony\ValidatorMapping\Entity\Customer;
use PHPRegex\Tests\Support\FrameworkKernel;
use PHPRegex\Tests\Support\JsonContract;
use PHPRegex\Tests\Support\TemporaryProject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * In an application, regex:lint reads the Regex constraints of the
 * validator mapping files FrameworkBundle hands the validator
 * (config/validator/, the framework.validation.mapping.paths, the bundles'
 * validation files), each reported at the file and the line that write
 * it. A constraint written as an attribute is read from the PHP file, once.
 */
final class ValidatorMappingKernelTest extends TestCase
{
    use TemporaryProject;

    private const PROJECT = __DIR__.'/../../../Fixtures/Symfony/ValidatorMapping';

    #[Test]
    public function test_the_regex_constraints_of_yaml_xml_and_attribute_mappings_are_linted_where_they_are_written(): void
    {
        $this->assertSame([
            ['Entity/Profile.php', 23, '#[Regex]', '/[0-9/'],
            ['config/validator/account.xml', 8, 'validator:'.Account::class.'::$code', '/(abc/'],
            ['config/validator/account.xml', 12, 'validator:'.Account::class.'::$iban', '/[A-Z&/'],
            ['config/validator/customer.yaml', 5, 'validator:'.Customer::class.'::$name', '/[a-z/'],
            ['config/validator/customer.yaml', 8, 'validator:'.Customer::class.'::$nickname', '/\d+(/'],
            ['config/validator/customer.yaml', 11, 'validator:'.Customer::class.'::$tags', '/#[a-z/'],
            ['config/validator/customer.yaml', 14, 'validator:'.Customer::class.'::getEmail()', '/[@/'],
        ], $this->lint([]));
    }

    #[Test]
    public function test_the_mapping_files_named_in_the_framework_configuration_are_read(): void
    {
        $results = $this->lint(['validation' => ['mapping' => ['paths' => [realpath(self::PROJECT).'/config/validator/account.xml']]]]);

        $this->assertContains(['config/validator/account.xml', 8, 'validator:'.Account::class.'::$code', '/(abc/'], $results);
    }

    #[Test]
    public function test_nothing_is_read_from_the_validator_when_validation_is_off(): void
    {
        $this->assertSame([
            ['Entity/Profile.php', 23, '#[Regex]', '/[0-9/'],
        ], $this->lint(['validation' => ['enabled' => false]]));
    }

    #[Test]
    public function test_no_validators_leaves_the_mapping_files_out(): void
    {
        $this->assertSame([
            ['Entity/Profile.php', 23, '#[Regex]', '/[0-9/'],
        ], $this->lint([], ['--no-validators' => true]));
    }

    /**
     * @param array<string, mixed> $framework
     * @param array<string, mixed> $options
     *
     * @return list<array{string, int, string|null, string|null}> each result's file, from the project, line, source and pattern
     */
    private function lint(array $framework, array $options = []): array
    {
        $project = (string) realpath(self::PROJECT);
        $kernel = new FrameworkKernel($project, $this->makeProject(), ['paths' => ['Entity']], $framework);

        try {
            $application = new Application($kernel);
            $tester = new CommandTester($application->find('regex:lint'));
            $tester->execute($options + ['--format' => 'json', '--jobs' => '1'], ['capture_stderr_separately' => true]);
        } finally {
            $kernel->shutdown();
        }

        $results = [];
        foreach (JsonContract::asArray(JsonContract::decodeDocument($tester->getDisplay())['results'] ?? null) as $result) {
            $result = JsonContract::asArray($result);
            $file = $result['file'] ?? null;
            $line = $result['line'] ?? null;
            $this->assertIsString($file);
            $this->assertIsInt($line);
            $absolute = realpath($file);
            $this->assertIsString($absolute, $file);
            $source = $result['source'] ?? null;
            $pattern = $result['pattern'] ?? null;
            $results[] = [
                substr($absolute, \strlen($project) + 1),
                $line,
                \is_string($source) ? $source : null,
                \is_string($pattern) ? $pattern : null,
            ];
        }
        sort($results);

        return $results;
    }
}

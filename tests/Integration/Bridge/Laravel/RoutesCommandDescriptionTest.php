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

namespace PHPRegex\Tests\Integration\Bridge\Laravel;

use Illuminate\Support\Facades\Artisan;
use PHPRegex\Laravel\PHPRegexServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * regex:routes lists the routes that have a constraint and validates the
 * constraints; it detects no conflict between routes, and its description,
 * its help and the docs no longer promise one.
 */
#[Group('documentation')]
final class RoutesCommandDescriptionTest extends LaravelTestCase
{
    #[Test]
    public function test_description_and_help_say_what_the_command_does(): void
    {
        $command = $this->registeredCommand('regex:routes');

        $this->assertSame('List the routes that have a regex constraint, and validate the constraints', $command->getDescription());
        $this->assertStringContainsString('--validate', $command->getHelp());
        $this->assertStringNotContainsStringIgnoringCase('conflict', $command->getDescription().$command->getHelp());
    }

    #[Test]
    #[DataProvider('provideDocuments')]
    public function test_docs_describe_the_command_as_it_is(string $document): void
    {
        $contents = file_get_contents(\dirname(__DIR__, 4).'/'.$document);
        $this->assertIsString($contents);

        $this->assertStringContainsString('| `regex:routes`    | List and validate route constraints          |', $contents);
    }

    /**
     * @return iterable<string, array{document: string}>
     */
    public static function provideDocuments(): iterable
    {
        yield 'guide' => ['document' => 'docs/guides/laravel.md'];
        yield 'package README' => ['document' => 'src/Laravel/README.md'];
    }

    /**
     * @return array<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PHPRegexServiceProvider::class];
    }
}
